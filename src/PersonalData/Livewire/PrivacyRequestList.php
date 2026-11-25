<?php

namespace GP247\Core\PersonalData\Livewire;

use GP247\Core\AdminShell\Domain\AdminUserContract;
use GP247\Core\AdminShell\Domain\AuthorizationException;
use GP247\Core\AdminShell\Domain\HidesFromOperationLog;
use GP247\Core\AdminShell\Infrastructure\GP247AdminComponent;
use GP247\Core\AdminShell\Infrastructure\HasStoreScopeUi;
use GP247\Core\PersonalData\Application\ErasureStepUp;
use GP247\Core\PersonalData\Application\PersonalDataRegistry;
use GP247\Core\PersonalData\Application\Export\ExportService;
use GP247\Core\PersonalData\Application\Requests\PrivacyExports;
use GP247\Core\PersonalData\Application\Requests\PrivacyNotices;
use GP247\Core\PersonalData\Application\Requests\PrivacyRequests;
use GP247\Core\PersonalData\Application\Requests\RequestScheduler;
use GP247\Core\PersonalData\Application\Requests\UndeclaredSources;
use GP247\Core\PersonalData\Application\SubjectLookup;
use GP247\Core\PersonalData\Domain\DataSubject;
use GP247\Core\PersonalData\Domain\Requests\RequestKind;
use GP247\Core\PersonalData\Domain\Requests\RequestSource;
use GP247\Core\PersonalData\Domain\Requests\RequestStatus;
use GP247\Core\PersonalData\Infrastructure\Export\ExportRepository;
use GP247\Core\PersonalData\Infrastructure\PersonalDataLanguage;
use GP247\Core\PersonalData\Infrastructure\Requests\RequestLog;
use GP247\Core\PersonalData\Infrastructure\Requests\RequestRepository;
use GP247\Core\Support\StepUp\Concerns\ConfirmsStepUp;
use GP247\Core\Support\StepUp\StepUpGate;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Livewire\WithPagination;

/**
 * "Personal data requests" screen: the requests of every channel with their status and due
 * date, filters, the detail of one request (what the site holds about the customer, history,
 * actions), an administrator creating an erasure request for a customer, and the tables of
 * customer data nobody declared. Gated by its own permission (given to no role at install:
 * Administrators only until the owner assigns it). Erasing at once, running and retrying ask
 * the administrator to confirm who they are. A store administrator sees the requests of their
 * store only.
 *
 * @aidlc-unit personal-data-requests
 * @aidlc-story US-personal-data-requests-screen, US-personal-data-requests-permission, US-personal-data-requests-admin-create, US-personal-data-registry-undeclared-detection
 * @aidlc-adr personal-data-requests_request-model, personal-data-requests_access-and-settings, personal-data-requests_undeclared-sources
 */
class PrivacyRequestList extends GP247AdminComponent implements HidesFromOperationLog
{
    use ConfirmsStepUp;
    use HasStoreScopeUi;
    use WithPagination;

    protected ?string $permission = 'admin_personal_data_requests';

    public string $filterStatus = '';
    public bool $filterOverdue = false;
    public string $keyword = '';
    public ?string $selectedId = null;

    public string $createCustomer = '';
    public string $createKind = 'erase';
    public string $createChannel = 'email';
    public string $createNote = '';
    public bool $createNow = false;
    /** @var array{id: string, email: string|null, name: string|null, store_id: string|null}|null */
    public ?array $createFound = null;
    /** @var list<array{reason: string, ref: string, label: string}> */
    public array $blockers = [];
    public bool $showUndeclared = false;
    public string $rejectReason = '';

    /**
     * @return void
     * @throws AuthorizationException When the admin only has View.all.
     */
    public function mount(): void
    {
        $user = app(AdminUserContract::class);
        // WHY: View.all reads every screen; this one shows customers' personal data and is
        // given by its own permission only — the same rule as viewWithout() on the route.
        if (!$user->isAdministrator() && $user->isViewAll()) {
            throw AuthorizationException::fromReason('personal data requests are not part of View.all');
        }
        parent::mount();
    }

    /**
     * @return list<string> What the admin typed or found about a customer stays out of admin_log.
     */
    public function operationLogHidden(): array
    {
        return ['createCustomer', 'createFound', 'createNote', 'keyword', 'rejectReason'];
    }

    /**
     * @return void
     */
    public function updating(): void
    {
        $this->resetPage();
    }

    /**
     * @param string $id
     * @return void
     */
    public function select(string $id): void
    {
        $this->selectedId = $this->scoped()->where('id', $id)->exists() ? $id : null;
        $this->blockers = [];
    }

    /**
     * Find the customer the request is for (by id or email), within the admin's store.
     *
     * @return void
     */
    public function findCustomer(): void
    {
        $this->authorizeAction('create');
        $found = PersonalDataRegistry::findCustomer($this->createCustomer);
        $this->createFound = $found !== null && $this->inScope((string) ($found['store_id'] ?? '')) ? $found : null;
        if ($this->createFound === null) {
            $this->notify('error', gp247_language_render('admin.personal_data.requests.customer_not_found'));
        }
    }

    /**
     * @return void
     */
    public function createRequest(): void
    {
        $this->authorizeAction('create');
        $found = $this->createFound === null ? null : PersonalDataRegistry::findCustomer((string) $this->createFound['id']);
        if ($found === null || !$this->inScope((string) ($found['store_id'] ?? ''))) {
            $this->notify('error', gp247_language_render('admin.personal_data.requests.customer_not_found'));

            return;
        }
        // WHY always: a waiting request still erases the customer when its wait ends, and a data
        // package shows everything the site holds about the customer.
        if (!$this->requireStepUp('createRequest')) {
            return;
        }
        if ($this->createKind === RequestKind::Export->value) {
            $this->createExport($found);

            return;
        }
        $result = app(PrivacyRequests::class)->adminErase(
            (string) $found['id'],
            $this->createNow,
            (string) ($found['store_id'] ?? $this->storeContext()),
            $this->actor(),
            in_array($this->createChannel, RequestSource::ADMIN_CHANNELS, true) ? $this->createChannel : null,
            $this->createNote,
        );
        if (($result['status'] ?? '') === PrivacyRequests::BLOCKED) {
            $this->blockers = (array) $result['blockers'];

            return;
        }
        $this->reset(['createCustomer', 'createNote', 'createNow', 'createFound', 'blockers']);
        $this->selectedId = $result['request_id'] ?? null;
        $this->notify('success', gp247_language_render('admin.personal_data.requests.created'));
    }

    /**
     * @param array{id: string, store_id: string|null} $found
     * @return void
     */
    private function createExport(array $found): void
    {
        try {
            $result = app(PrivacyExports::class)->request((string) $found['id'], (string) ($found['store_id'] ?? $this->storeContext()), RequestSource::Admin, $this->actor());
        } catch (\RuntimeException $e) {
            $this->notify('error', gp247_language_render('admin.personal_data.requests.export_unavailable'));

            return;
        }
        $this->reset(['createCustomer', 'createNote', 'createNow', 'createFound', 'blockers', 'createKind']);
        $this->selectedId = $result['request_id'];
        $this->notify('success', gp247_language_render($result['reused'] ? 'admin.personal_data.requests.export_reused' : 'admin.personal_data.requests.created'));
    }

    /**
     * Build one more slice of the data package of a request (the detail polls while it builds).
     *
     * @param string $id
     * @return void
     */
    public function advanceExport(string $id): void
    {
        $this->authorizeAction('update');
        $package = $this->packageOf($id);
        if ($package !== null && (string) $package->status === ExportRepository::BUILDING) {
            app(ExportService::class)->continue((string) $package->id);
        }
    }

    /**
     * Download the data package of a request: a link for this administrator only, used once.
     *
     * @param string $id
     * @return void
     */
    public function downloadExport(string $id): void
    {
        $this->authorizeAction('update');
        $package = $this->packageOf($id);
        if ($package === null || !$this->requireStepUp('downloadExport', [$id])) {
            return;
        }
        $user = function_exists('admin') ? admin()->user() : null;
        $token = $user === null ? null : app(ExportService::class)->issueToken((string) $package->id, 'admin:' . $user->id);
        if ($token === null) {
            $this->notify('error', gp247_language_render('admin.personal_data.requests.link_invalid'));

            return;
        }
        $this->redirect(gp247_route_admin('admin.personal_data_requests.download', ['export' => (string) $package->id, 'token' => $token]));
    }

    /**
     * @param string $id Request id (within the admin's store).
     * @return object|null The data package of an export request.
     */
    private function packageOf(string $id): ?object
    {
        $request = $this->findScoped($id);
        if ($request === null || (string) $request->kind !== RequestKind::Export->value) {
            return null;
        }

        return app(ExportRepository::class)->forRequest($id);
    }

    /**
     * Run a waiting request now (skips the rest of the wait).
     *
     * @param string $id
     * @return void
     */
    public function executeRequest(string $id): void
    {
        $this->authorizeAction('update');
        $request = $this->findScoped($id);
        if ($request === null || !$this->requireStepUp('executeRequest', [$id])) {
            return;
        }
        $this->report(fn () => app(PrivacyRequests::class)->execute($id, $this->actor()));
    }

    /**
     * @param string $id
     * @return void
     */
    public function retryRequest(string $id): void
    {
        $this->authorizeAction('update');
        if ($this->findScoped($id) === null || !$this->requireStepUp('retryRequest', [$id])) {
            return;
        }
        $this->report(fn () => app(PrivacyRequests::class)->retry($id, $this->actor()));
    }

    /**
     * @param string $id
     * @return void
     */
    public function cancelRequest(string $id): void
    {
        $this->authorizeAction('update');
        if ($this->findScoped($id) === null) {
            return;
        }
        $this->report(function () use ($id) {
            app(PrivacyRequests::class)->cancel($id, $this->actor());

            return ['status' => RequestStatus::Cancelled->value];
        });
    }

    /**
     * Approve the erasure a shopper without an account asked for (email verified): it then waits
     * the days set for the site, like any erasure.
     *
     * @param string $id
     * @return void
     */
    public function approveRequest(string $id): void
    {
        $this->authorizeAction('update');
        if ($this->findScoped($id) === null || !$this->requireStepUp('approveRequest', [$id])) {
            return;
        }
        $this->report(function () use ($id) {
            app(PrivacyRequests::class)->approve($id, $this->actor());

            return ['status' => RequestStatus::Waiting->value];
        });
    }

    /**
     * Turn down a guest erasure; the reason is emailed to the shopper.
     *
     * @param string $id
     * @return void
     */
    public function rejectRequest(string $id): void
    {
        $this->authorizeAction('update');
        if ($this->findScoped($id) === null) {
            return;
        }
        if (trim($this->rejectReason) === '') {
            $this->notify('error', gp247_language_render('admin.personal_data.requests.reason_required'));

            return;
        }
        $this->report(function () use ($id) {
            app(PrivacyRequests::class)->reject($id, $this->actor(), $this->rejectReason);
            $this->rejectReason = '';

            return ['status' => RequestStatus::Rejected->value];
        });
    }

    /**
     * Show the undeclared tables, read from the database now.
     *
     * @return void
     */
    public function checkUndeclared(): void
    {
        app(UndeclaredSources::class)->forget();
        $this->showUndeclared = true;
    }

    /**
     * An Administrator says a table holds no customer data, or takes it back.
     *
     * @param int    $index Row of the undeclared list shown — the table marked is the one the server lists.
     * @param string $shown Table name the admin saw on that row; a list that changed meanwhile refuses.
     * @param bool   $marked
     * @return void
     */
    public function markTable(int $index, string $shown, bool $marked = true): void
    {
        $this->authorizeAction('update');
        if (!app(AdminUserContract::class)->isAdministrator()) {
            $this->notify('error', gp247_language_render('admin.personal_data.requests.administrator_only'));

            return;
        }
        $sources = app(UndeclaredSources::class);
        $table = $sources->find()[$index]['table'] ?? null;
        try {
            if ($table === null || $table !== $shown) {
                throw new \InvalidArgumentException('Unknown row');
            }
            $sources->mark((string) $table, $marked, $this->actor());
        } catch (\InvalidArgumentException $e) {
            $this->notify('error', gp247_language_render('admin.personal_data.requests.not_allowed'));

            return;
        }
        $this->notify('success', gp247_language_render('admin.personal_data.requests.table_marked'));
    }

    /**
     * @return list<string>
     */
    protected function stepUpActions(): array
    {
        return ['createRequest', 'executeRequest', 'retryRequest', 'downloadExport', 'approveRequest'];
    }

    /**
     * @return StepUpGate
     */
    protected function stepUpGate(): StepUpGate
    {
        return ErasureStepUp::admin();
    }

    /**
     * @return bool
     */
    public function stepUpAsksCode(): bool
    {
        return $this->stepUpOpen && $this->stepUpNeedsCode();
    }

    /**
     * @return View
     */
    public function render(): View
    {
        $ready = RequestScheduler::ready();
        $query = $ready ? $this->scoped() : null;
        if ($query !== null) {
            if ($this->filterStatus !== '' && RequestStatus::tryFrom($this->filterStatus) !== null) {
                $query->where('status', $this->filterStatus);
            }
            if ($this->filterOverdue) {
                $query->whereIn('status', RequestStatus::openValues())->where('due_at', '<', now());
            }
            $this->applyKeyword($query);
        }

        $rows = $query?->orderByDesc('received_at')->paginate(20);

        return view('gp247-admin::personal_data.requests', [
            'ready' => $ready,
            'rows' => $rows,
            'customers' => $rows === null ? [] : $this->customerLabels($rows->items()),
            'statuses' => array_map(fn (RequestStatus $s) => $s->value, RequestStatus::cases()),
            'detail' => $ready && $this->selectedId !== null ? $this->detail($this->selectedId) : null,
            'undeclared' => $ready && $this->showUndeclared ? app(UndeclaredSources::class)->find() : [],
            'channels' => RequestSource::ADMIN_CHANNELS,
            'stepUpMinutes' => intdiv(ErasureStepUp::SECONDS, 60),
            'eventLabel' => static function (string $event): string {
                $key = str_starts_with($event, 'status:') ? 'admin.personal_data.requests.status.' . substr($event, 7) : 'admin.personal_data.requests.event.' . $event;
                $text = (string) gp247_language_render($key);

                // WHY the code as a fallback: an extension may write events of its own.
                return $text === $key ? $event : $text;
            },
            'reasonLabel' => static function (string $reason): string {
                // WHY only plain codes: a reason an administrator typed (a refusal) is shown as written.
                if (preg_match('/^[a-z_]+$/', $reason) !== 1) {
                    return $reason;
                }
                $key = 'admin.personal_data.requests.reason.' . $reason;
                $text = (string) gp247_language_render($key);

                return $text === $key ? $reason : $text;
            },
        ])->layout('gp247-admin::layouts.admin', ['title' => gp247_language_render('admin.personal_data.requests.title')]);
    }

    /**
     * @param list<object> $rows Requests of the page.
     * @return array<string, array{name: string|null, email: string|null}|null> Customer id => who it is (null: no longer exists).
     */
    private function customerLabels(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if ($row->customer_id !== null) {
                $ids[] = (string) $row->customer_id;
            }
        }
        // WHY in one go: the list is drawn again after every filter or click.
        $found = PersonalDataRegistry::findCustomers($ids);
        $out = [];
        foreach (array_unique($ids) as $id) {
            $out[$id] = isset($found[$id]) ? ['name' => $found[$id]['name'] ?? null, 'email' => $found[$id]['email'] ?? null] : null;
        }

        return $out;
    }

    /**
     * @param string $id
     * @return array{request: object, log: list<object>, data: list<array{label: string, rows: int, rule: string}>, customer: array<string, mixed>|null, package: object|null}|null
     */
    private function detail(string $id): ?array
    {
        $request = $this->findScoped($id);
        if ($request === null) {
            return null;
        }
        $data = [];
        $customer = null;
        $open = !RequestStatus::from((string) $request->status)->isClosed();
        $subject = null;
        if ($request->customer_id !== null && $open) {
            $customer = PersonalDataRegistry::findCustomer((string) $request->customer_id);
            $subject = app(SubjectLookup::class)->expand(DataSubject::customer((string) $request->customer_id));
        } elseif ($open && ($email = PrivacyNotices::emailOf($request)) !== null) {
            // WHY shown: the administrator approving a guest erasure must see whose data it is.
            $customer = ['id' => '', 'email' => $email, 'name' => null];
            $subject = DataSubject::guest($email);
        }
        if ($subject !== null) {
            foreach (app(SubjectLookup::class)->counts($subject) as $count) {
                if ($count['rows'] > 0) {
                    $data[] = ['label' => PersonalDataLanguage::label($count['label']), 'rows' => (int) $count['rows'], 'rule' => (string) $count['rule']];
                }
            }
        }

        $package = (string) $request->kind === RequestKind::Export->value ? app(ExportRepository::class)->forRequest($id) : null;

        return ['request' => $request, 'log' => app(RequestLog::class)->forRequest($id), 'data' => $data, 'customer' => $customer, 'package' => $package];
    }

    /**
     * @param \Closure(): array<string, mixed> $action
     * @return void
     */
    private function report(\Closure $action): void
    {
        try {
            $result = $action();
        } catch (\DomainException $e) {
            $this->notify('error', gp247_language_render('admin.personal_data.requests.not_allowed'));

            return;
        }
        $this->blockers = (array) ($result['blockers'] ?? []);
        $this->notify($this->blockers === [] ? 'success' : 'warning', gp247_language_render('admin.personal_data.requests.status.' . ($result['status'] ?? 'processing')));
    }

    /**
     * @return Builder Requests the admin may see (their store, or all at the root store).
     */
    private function scoped(): Builder
    {
        // WHY: a submission of the public form is shown once it was looked up (it may be nothing).
        $query = app(RequestRepository::class)->table()
            ->where(fn (Builder $q) => $q->where('source', '!=', RequestSource::Guest->value)->orWhereNotNull('checked_at'));
        if (!$this->isRootScope()) {
            $query->where('store_id', (string) $this->storeContext());
        }

        return $query;
    }

    /**
     * @param string $id
     * @return object|null
     */
    private function findScoped(string $id): ?object
    {
        return $this->scoped()->where('id', $id)->first();
    }

    /**
     * @param string $storeId
     * @return bool
     */
    private function inScope(string $storeId): bool
    {
        return $this->isRootScope() || $storeId === (string) $this->storeContext();
    }

    /**
     * @param Builder $query
     * @return void
     */
    private function applyKeyword(Builder $query): void
    {
        $keyword = trim($this->keyword);
        if ($keyword === '') {
            return;
        }
        $customer = PersonalDataRegistry::findCustomer($keyword);
        $query->where(function (Builder $q) use ($keyword, $customer) {
            $q->where('id', $keyword)->orWhere('customer_id', $keyword);
            if ($customer !== null) {
                $q->orWhere('customer_id', (string) $customer['id']);
            }
        });
    }

    /**
     * @return array{type: string, id: string|null}
     */
    private function actor(): array
    {
        $user = function_exists('admin') ? admin()->user() : null;

        return ['type' => RequestLog::ACTOR_ADMIN, 'id' => $user === null ? null : (string) $user->getAuthIdentifier()];
    }
}
