<?php
namespace GP247\Core\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;
use Illuminate\Notifications\Notifiable;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Facades\Auth;
use GP247\Core\Permission;

class AdminUser extends Authenticatable
{
    use \GP247\Core\Models\UuidTrait;
    use  Notifiable, HasApiTokens;
    public $table      = GP247_DB_PREFIX.'admin_user';
    protected $connection = GP247_DB_CONNECTION;
    protected $guarded = [];
    protected $hidden  = [
        'password', 'remember_token',
    ];
    protected static $allPermissions = null;
    protected static $allViewPermissions = null;
    protected static $canChangeConfig = null;
    protected static $listStoreId = null;
    protected static $listStore = null;

    /**
     * A user has and belongs to many roles.
     *
     * @return BelongsToMany
     */
    public function roles()
    {
        return $this->belongsToMany(AdminRole::class, GP247_DB_PREFIX.'admin_role_user', 'user_id', 'role_id');
    }

    /**
     * A User has and belongs to many permissions.
     *
     * @return BelongsToMany
     */
    public function permissions()
    {
        return $this->belongsToMany(AdminPermission::class, GP247_DB_PREFIX.'admin_user_permission', 'user_id', 'permission_id');
    }

    /**
     * Update info customer
     * @param  [array] $dataUpdate
     * @param  [int] $id
     */
    public static function updateInfo($dataUpdate, $id)
    {
        $dataUpdate = gp247_clean(data:$dataUpdate, hight: true);
        $obj        = self::find($id);
        return $obj->update($dataUpdate);
    }

    /**
     * Detach models from the relationship.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($user) {
            if (in_array($user->id, GP247_GUARD_ADMIN)) {
                return false;
            }
            $user->roles()->detach();
            $user->permissions()->detach();
            if (function_exists('gp247_event_admin_deleting')) {
                gp247_event_admin_deleting($user);
            }
        });

        //Uuid
        static::creating(function ($user) {
            if (empty($user->{$user->getKeyName()})) {
                $user->{$user->getKeyName()} = gp247_generate_id('AU');
            }
        });

        static::created(function ($user) {
            if (function_exists('gp247_event_admin_created')) {
                gp247_event_admin_created($user);
            }
            // ...
        });
    }

    /**
     * Create new customer
     * @return [type] [description]
     */
    public static function createUser($dataInsert)
    {
        $dataInsert = gp247_clean(data:$dataInsert, hight: true);
        return self::create($dataInsert);
    }

    /**
     * Get all permissions of user.
     *
     * @return mixed
     */
    public static function allPermissions()
    {
        if (self::$allPermissions === null) {
            $user                 = admin()->user();
            self::$allPermissions = $user->roles()->with('permissions')
                ->get()->pluck('permissions')->flatten() //permissions of roles
                ->merge($user->permissions); //permissions of user
        }
        return self::$allPermissions;
    }

    /**
     * Get all view permissions of user.
     *
     * @return mixed
     */
    protected static function allViewPermissions()
    {
        if (self::$allViewPermissions === null) {
            $arrView = [];
            $allPermissionTmp = self::allPermissions();
            $allPermissionTmp = $allPermissionTmp->pluck('http_uri')->toArray();
            if ($allPermissionTmp) {
                foreach ($allPermissionTmp as  $actionList) {
                    foreach (explode(',', $actionList) as  $action) {
                        if (strpos($action, 'ANY::') === 0 || strpos($action, 'GET::') === 0) {
                            $arrPrefix = ['ANY::', 'GET::'];
                            $arrScheme = ['https://', 'http://'];
                            $arrView[] = str_replace($arrScheme, '', url(str_replace($arrPrefix, '', $action)));
                        }
                    }
                }
            }
            self::$allViewPermissions = $arrView;
        }
        return self::$allViewPermissions;
    }

    /**
     * Check url menu can display
     *
     * @param   [type]  $url  [$url description]
     *
     * @return  [type]        [return description]
     */
    public function checkUrlAllowAccess($url)
    {
        if ($this->isAdministrator() || $this->isViewAll()) {
            return true;
        }

        $allowRoute = Permission::listRouteDefaultPassThrough();
        foreach ($allowRoute as $route) {
            if (gp247_route_admin($route) === $url) {
                return true;
            }
        }
        $allowPath = Permission::listPathDefaultPassThrough();
        foreach ($allowPath as $path) {
            if (url($path) === $url) {
                return true;
            }
        }


        $arrScheme = ['https://', 'http://'];
        $pathCheck = strtolower(str_replace($arrScheme, '', $url));
        $listUrlAllowAccess = self::allViewPermissions();
        if ($listUrlAllowAccess) {
            foreach ($listUrlAllowAccess as  $pathAllow) {
                if ($pathCheck === $pathAllow
                    || $pathCheck  === $pathAllow.'/'
                    || (Str::endsWith($pathAllow, '*') && ($pathCheck === str_replace('/*', '', $pathAllow) || strpos($pathCheck, str_replace('*', '', $pathAllow)) === 0))
                    || (Str::endsWith($pathAllow, '{id}') && ($pathCheck === str_replace('/{id}', '', $pathAllow) || strpos($pathCheck, str_replace('{id}', '', $pathAllow)) === 0))
                    ) {
                    return true;
                }
            }
        }
        return false;
    }


    /**
     * Check whether the user may access an admin path with the given HTTP method,
     * evaluated against the `http_uri` entries of all their permissions (roles +
     * direct grants). This is the v1 URI+method model reused by the admin shell
     * (ADR-001 Layer-2): the permission slug is a label; access is decided by URI.
     *
     * @param string $path   Admin path without scheme/host (e.g. "gp247_admin/order").
     * @param string $method HTTP method ("GET" to view a screen, "POST" to mutate).
     * @return bool True when a granted permission's http_uri covers the path/method.
     *
     * @aidlc-unit admin-shell-rbac
     * @aidlc-story US-RBAC-001
     * @aidlc-adr ADR-001
     */
    public function canAccessUrl(string $path, string $method): bool
    {
        if ($this->isAdministrator()) {
            return true;
        }

        $path   = trim($path, '/');
        $method = strtoupper($method);

        // All permissions granted to the user: via roles + assigned directly.
        $permissions = $this->roles->pluck('permissions')->flatten()->merge($this->permissions);

        foreach ($permissions as $permission) {
            if (empty($permission->http_uri)) {
                continue;
            }
            foreach (explode(',', $permission->http_uri) as $action) {
                $parts = explode('::', trim($action), 2);
                if (count($parts) !== 2) {
                    continue;
                }
                [$entryMethod, $pattern] = $parts;
                $entryMethod = strtoupper(trim($entryMethod));
                if ($entryMethod !== 'ANY' && $entryMethod !== $method) {
                    continue;
                }
                if ($this->matchUriPattern($path, trim($pattern, '/'))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Match an admin path against a single http_uri pattern, honoring the wildcard
     * (`/*`, `*`) and `{id}` suffix conventions used by the permission catalog.
     *
     * @param string $path    Normalized admin path (no leading/trailing slash).
     * @param string $pattern Normalized pattern from an http_uri entry.
     * @return bool True when the path is covered by the pattern.
     *
     * @aidlc-unit admin-shell-rbac
     * @aidlc-story US-RBAC-001
     */
    protected function matchUriPattern(string $path, string $pattern): bool
    {
        if ($pattern === $path) {
            return true;
        }
        if (Str::endsWith($pattern, '/*')) {
            $base = rtrim(substr($pattern, 0, -2), '/');
            return $path === $base || Str::startsWith($path, $base . '/');
        }
        if (Str::endsWith($pattern, '*')) {
            return Str::startsWith($path, substr($pattern, 0, -1));
        }
        if (Str::endsWith($pattern, '{id}')) {
            $base = rtrim(substr($pattern, 0, -4), '/');
            return $path === $base || Str::startsWith($path, $base . '/');
        }

        return false;
    }

    /**
     * Check if user has permission.
     *
     * @param $ability
     * @param array $arguments
     *
     * @return bool
     */
    public function can($ability, $arguments = []): bool
    {
        if ($this->isAdministrator()) {
            return true;
        }

        if ($this->permissions->pluck('slug')->contains($ability)) {
            return true;
        }

        return $this->roles->pluck('permissions')->flatten()->pluck('slug')->contains($ability);
    }

    /**
     * Check if user has no permission.
     *
     * @param $permission
     *
     * @return bool
     */
    public function cannot($permission, $arguments = []): bool
    {
        return !$this->can($permission);
    }

    /**
     * Check if user is administrator.
     *
     * @return mixed
     */
    public function isAdministrator(): bool
    {
        return $this->isRole('administrator');
    }

    /**
     * Check if user is view_all.
     *
     * @return mixed
     */
    public function isViewAll(): bool
    {
        return $this->isRole('view.all');
    }

    /**
     * Check if user is $role.
     *
     * @param string $role
     *
     * @return mixed
     */
    public function isRole(string $role): bool
    {
        return $this->roles->pluck('slug')->contains($role);
    }

    /**
     * Check user can change config value
     *
     * @return  [type]  [return description]
     */
    public static function checkPermissionConfig()
    {
        if (self::$canChangeConfig === null) {
            if (admin()->user()->isAdministrator()) {
                return self::$canChangeConfig = true;
            }

            if (self::allPermissions()->first(function ($permission) {
                if (!$permission->http_uri) {
                    return false;
                }
                $actions = explode(',', $permission->http_uri);
                foreach ($actions as $key => $action) {
                    $method = explode('::', $action);
                    if (
                        in_array($method[0], ['ANY', 'POST'])
                        && (
                            GP247_ADMIN_PREFIX . '/config/*' == $method[1]
                        || GP247_ADMIN_PREFIX . '/config/update_info' == $method[1]
                        || GP247_ADMIN_PREFIX . '/config' == $method[1]
                        )
                    ) {
                        return true;
                    }
                }
            })) {
                return self::$canChangeConfig = true;
            } else {
                return self::$canChangeConfig = false;
            }
        } else {
            return self::$canChangeConfig;
        }
    }


    /**
     * Send email reset password
     * @param  [type] $token [description]
     * @return [type]        [description]
     */
    public function sendPasswordResetNotification($token)
    {
        $emailReset = $this->getEmailForPasswordReset();
        return gp247_mail_admin_send_reset_notification($token, $emailReset);
    }
}
