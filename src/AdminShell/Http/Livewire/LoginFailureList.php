<?php

namespace GP247\Core\AdminShell\Http\Livewire;

use GP247\Core\AdminShell\Domain\AuthorizationException;
use GP247\Core\AdminShell\Support\AdminAlertService;
use GP247\Core\Support\LoginFailure\LoginFailureSchema;
use GP247\Core\Support\LoginFailure\LoginFailureSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * "Login failures" screen: the recorded failed sign-ins, newest first, filtered by
 * group / address / name / dates, plus the choice of recorded account groups.
 * Administrator only — the route path is in PermissionMiddleware::administratorOnly()
 * and this component gates itself too. Read-only: rows leave by the keep period.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-login-failure-list
 * @aidlc-adr admin-shell_login-failure-log
 */
class LoginFailureList extends Component
{
    use WithPagination;

    private const PER_PAGE = 30;

    public string $filterAudience = '';
    public string $filterIp = '';
    public string $filterIdentity = '';
    public string $filterFrom = '';
    public string $filterTo = '';

    /** @var array<int, string> Recorded group keys being edited. */
    public array $audiences = [];

    public bool $saved = false;

    /**
     * @return void
     * @throws AuthorizationException When the current admin is not an Administrator.
     */
    public function mount(): void
    {
        $this->authorizeAdministrator();
        $this->audiences = LoginFailureSettings::audiences();
    }

    /**
     * Any filter change starts again from the first page.
     *
     * @param string $property
     * @return void
     */
    public function updated(string $property): void
    {
        if (str_starts_with($property, 'filter')) {
            $this->resetPage();
        }
        if ($property !== 'saved') {
            $this->saved = false;
        }
    }

    /**
     * Store which account groups are recorded (only registered groups are kept).
     *
     * @return void
     * @throws AuthorizationException When the current admin is not an Administrator.
     */
    public function saveAudiences(): void
    {
        $this->authorizeAdministrator();
        $this->audiences = LoginFailureSettings::save($this->audiences);
        $this->saved = true;
    }

    /**
     * @return View
     * @throws AuthorizationException When the current admin is not an Administrator.
     */
    public function render(): View
    {
        $this->authorizeAdministrator();

        return view('gp247-admin::livewire.login-failure-list', [
            'rows' => $this->rows(),
            'groups' => LoginFailureSettings::available(),
            'keepDays' => max(1, (int) config('gp247-config.admin.login_failure_keep_days', 30)),
        ])->layout('gp247-admin::layouts.admin', [
            'title' => gp247_language_render('admin.login_failures.title'),
        ]);
    }

    /**
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    private function rows()
    {
        $query = DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . LoginFailureSchema::TABLE)
            ->orderByDesc('created_at')
            ->orderByDesc('id');
        if ($this->filterAudience !== '') {
            $query->where('audience', $this->filterAudience);
        }
        if (trim($this->filterIp) !== '') {
            $query->where('ip', 'like', '%' . addcslashes(trim($this->filterIp), '%_\\') . '%');
        }
        if (trim($this->filterIdentity) !== '') {
            $query->where('identity', 'like', '%' . addcslashes(trim($this->filterIdentity), '%_\\') . '%');
        }
        if ($from = $this->date($this->filterFrom)) {
            $query->where('created_at', '>=', $from . ' 00:00:00');
        }
        if ($to = $this->date($this->filterTo)) {
            $query->where('created_at', '<=', $to . ' 23:59:59');
        }

        return $query->paginate(self::PER_PAGE);
    }

    /**
     * A Y-m-d date from a filter, or null when it is not one.
     *
     * @param string $value
     * @return string|null
     */
    private function date(string $value): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return $value;
    }

    /**
     * @return void
     * @throws AuthorizationException When the current admin is not an Administrator.
     */
    private function authorizeAdministrator(): void
    {
        if (!app(AdminAlertService::class)->allowed()) {
            throw AuthorizationException::fromReason('login failures are shown to administrators only');
        }
    }
}
