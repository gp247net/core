<?php

namespace GP247\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\Request;

class AdminPermission extends Model
{
    public $table = GP247_DB_PREFIX.'admin_permission';
    protected $connection = GP247_DB_CONNECTION;
    protected $fillable = ['name', 'slug', 'http_uri'];

    /**
     * Permission belongs to many roles.
     *
     * @return BelongsToMany
     */
    public function roles()
    {
        return $this->belongsToMany(AdminRole::class, GP247_DB_PREFIX.'admin_role_permission', 'permission_id', 'role_id');
    }

    /**
     * If request should pass through the current permission.
     *
     * @param Request $request
     *
     * @return bool
     */
    public function passRequest(Request $request): bool
    {
        return $this->passesFor(
            $request->method(),
            \Route::getCurrentRoute()->uri,
            $request->path()
        );
    }

    /**
     * Pure form of passRequest(): decide on an explicit (method, route URI, path)
     * so a caller that is not the matched route — the REST API checking the admin
     * screen an endpoint maps to — reuses exactly the web rule.
     *
     * @param string $method   HTTP method, e.g. "GET".
     * @param string $routeUri Route URI template, e.g. "gp247_admin/shop_order".
     * @param string $path     Concrete request path without leading slash.
     * @return bool True when one of the permission's http_uri actions matches.
     *
     * @aidlc-unit rbac
     * @aidlc-story US-RBAC-api-route-permission
     * @aidlc-adr compat-foundation_rest-api-hardening
     */
    public function passesFor(string $method, string $routeUri, string $path): bool
    {
        if (empty($this->http_uri)) {
            return false;
        }

        // WHY: Request::is() matches the url-decoded path; keep the same semantics.
        $decodedPath = rawurldecode($path);
        foreach (explode(',', $this->http_uri) as $action) {
            $parts = explode('::', $action);
            if ($parts[0] === 'ANY' && isset($parts[1])
                && ($path . '/*' == $parts[1] || \Illuminate\Support\Str::is($parts[1], $decodedPath))) {
                return true;
            }
            if ($method . '::' . $routeUri == $action) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detach models from the relationship.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($model) {
            $model->roles()->detach();
        });
    }

    /**
     * Update info
     * @param  [array] $dataUpdate
     * @param  [int] $id
     */
    public static function updateInfo($dataUpdate, $id)
    {
        $dataUpdate = gp247_clean(data:$dataUpdate, hight: true);
        $obj = self::find($id);
        return $obj->update($dataUpdate);
    }

    /**
     * Create new permission
     * @return [type] [description]
     */
    public static function createPermission($dataCreate)
    {
        $dataCreate = gp247_clean(data:$dataCreate, hight: true);
        return self::create($dataCreate);
    }
}
