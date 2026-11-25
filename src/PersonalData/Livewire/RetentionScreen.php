<?php

namespace GP247\Core\PersonalData\Livewire;

use Carbon\CarbonImmutable;
use GP247\Core\AdminShell\Domain\AdminUserContract;
use GP247\Core\AdminShell\Domain\AuthorizationException;
use GP247\Core\AdminShell\Infrastructure\GP247AdminComponent;
use GP247\Core\PersonalData\Application\ErasureStepUp;
use GP247\Core\PersonalData\Application\PersonalDataRegistry;
use GP247\Core\PersonalData\Application\Retention\InactiveAccounts;
use GP247\Core\PersonalData\Application\Retention\RetentionPolicies;
use GP247\Core\PersonalData\Application\Retention\RetentionSweeper;
use GP247\Core\PersonalData\Domain\Retention\RetentionAction;
use GP247\Core\PersonalData\Domain\Retention\RetentionItem;
use GP247\Core\PersonalData\Infrastructure\PersonalDataLanguage;
use GP247\Core\PersonalData\Infrastructure\Retention\RetentionStateRepository;
use GP247\Core\Support\PreChange\PreChange;
use GP247\Core\Support\PreChange\PreChangeGate;
use GP247\Core\Support\StepUp\Concerns\ConfirmsStepUp;
use GP247\Core\Support\StepUp\StepUpGate;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;

/**
 * "Data retention" screen: how long each kind of personal data is kept, for the whole site —
 * on / off and days per item, when it last ran, rows handled, when it may clean for the first
 * time; "run now" cleans one slice. Turning on an item that removes business data (or shortening
 * its period) shows how many records it concerns, asks for the password again and lets the backup
 * save the data first. Administrators only (administratorOnly(), checked here again). Every action
 * goes to the operation log of the admin area.
 *
 * @aidlc-unit personal-data-retention
 * @aidlc-story US-personal-data-retention-policies, US-personal-data-retention-lazy-run, US-personal-data-retention-order-anonymise, US-personal-data-retention-inactive-accounts
 * @aidlc-adr personal-data-retention_retention-registry, personal-data-retention_sweep-runner, personal-data-retention_record-anonymise
 */
class RetentionScreen extends GP247AdminComponent
{
    use ConfirmsStepUp;

    protected ?string $permission = 'admin_personal_data_retention';

    /** @var array<string, bool> Field of the item (fieldOf()) => on. */
    public array $enabled = [];
    /** @var array<string, int|string> Field of the item (fieldOf()) => days typed. */
    public array $days = [];
    /** Item waiting for the owner to confirm a change that removes business data. */
    #[Locked]
    public ?string $confirmKey = null;
    #[Locked]
    public int $confirmCount = 0;
    /** Days shown in the preview: what is saved once confirmed (the browser cannot change it). */
    #[Locked]
    public int $confirmDays = 0;
    /** Item waiting for the owner to confirm cleaning before its first allowed date. */
    #[Locked]
    public ?string $earlyKey = null;
    /** @var list<string> Why the backup did not let the change go ahead, or what it did. */
    public array $preChangeMessages = [];

    /**
     * @return void
     * @throws AuthorizationException When the current admin is not an Administrator.
     */
    public function mount(): void
    {
        $this->authorizeAdministrator();
        parent::mount();
        $policies = app(RetentionPolicies::class);
        foreach (PersonalDataRegistry::retentionItems() as $item) {
            $this->enabled[self::fieldOf($item->key)] = $policies->enabled($item);
            $this->days[self::fieldOf($item->key)] = $policies->days($item);
        }
    }

    /**
     * @param string $key Item key ("shop.carts").
     * @return string Its field in $enabled / $days: Livewire reads a dot in a property path as nesting.
     */
    public static function fieldOf(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    /**
     * Save one item; a change that removes business data waits for confirmation first.
     *
     * @param string $key
     * @return void
     * @throws AuthorizationException When the current admin is not an Administrator.
     */
    public function save(string $key): void
    {
        $this->authorizeAdministrator();
        $item = $this->item($key);
        if ($item === null) {
            return;
        }
        $policies = app(RetentionPolicies::class);
        $field = self::fieldOf($key);
        $on = (bool) ($this->enabled[$field] ?? false);
        $days = $item->clampDays($this->days[$field] ?? $item->defaultDays());
        $this->days[$field] = $days;
        $widens = $on && (!$policies->enabled($item) || $days < $policies->days($item));
        if ($item->isRisky() && $widens) {
            $this->confirmKey = $key;
            $this->confirmDays = $days;
            $this->confirmCount = app(RetentionSweeper::class)->dueCount($item, null, $days);
            $this->preChangeMessages = [];

            return;
        }
        $this->persist($item, $on, $days);
    }

    /**
     * The owner confirmed a change that removes business data: password again, then the backup
     * may save the data first, then it is saved.
     *
     * @return void
     * @throws AuthorizationException When the current admin is not an Administrator.
     */
    public function confirmRisky(): void
    {
        $this->authorizeAdministrator();
        $item = $this->confirmKey === null ? null : $this->item($this->confirmKey);
        if ($item === null || !$this->requireStepUp('confirmRisky')) {
            return;
        }
        $outcome = (new PreChangeGate)->run(new PreChange(PreChange::PERSONAL_DATA_RETENTION, ['groups' => $item->backupGroups(), 'item' => $item->key]));
        $this->preChangeMessages = array_values(array_merge($outcome->reasons(), $outcome->notices()));
        if (!$outcome->proceed()) {
            return;
        }
        // WHY the previewed values: what the owner confirmed is what was counted, not what the page holds now.
        $this->persist($item, true, $item->clampDays($this->confirmDays));
        $this->days[self::fieldOf($item->key)] = $item->clampDays($this->confirmDays);
        $this->confirmKey = null;
    }

    /**
     * @return void
     */
    public function cancelRisky(): void
    {
        if ($this->confirmKey !== null && ($item = $this->item($this->confirmKey)) !== null) {
            $policies = app(RetentionPolicies::class);
            $this->enabled[self::fieldOf($item->key)] = $policies->enabled($item);
            $this->days[self::fieldOf($item->key)] = $policies->days($item);
        }
        $this->confirmKey = null;
        $this->preChangeMessages = [];
    }

    /**
     * One slice now, whatever the hour; before the first allowed clean the owner is asked first.
     *
     * @param string $key
     * @return void
     * @throws AuthorizationException When the current admin is not an Administrator.
     */
    public function runNow(string $key): void
    {
        $this->authorizeAdministrator();
        $item = $this->item($key);
        if ($item === null || !RetentionSweeper::ready()) {
            return;
        }
        $result = app(RetentionSweeper::class)->runNow($item);
        if ($result['blocked'] === 'not_yet') {
            $this->earlyKey = $key;

            return;
        }
        $this->report($result);
    }

    /**
     * The owner confirmed cleaning before the first allowed date (password again).
     *
     * @return void
     * @throws AuthorizationException When the current admin is not an Administrator.
     */
    public function runEarly(): void
    {
        $this->authorizeAdministrator();
        $item = $this->earlyKey === null ? null : $this->item($this->earlyKey);
        if ($item === null || !$this->requireStepUp('runEarly')) {
            return;
        }
        $this->earlyKey = null;
        $this->report(app(RetentionSweeper::class)->runNow($item, true));
    }

    /**
     * @return void
     */
    public function cancelEarly(): void
    {
        $this->earlyKey = null;
    }

    /**
     * @param RetentionItem $item
     * @param bool          $on
     * @param int           $days
     * @return void
     */
    private function persist(RetentionItem $item, bool $on, int $days): void
    {
        $policies = app(RetentionPolicies::class);
        $wasOn = $policies->enabled($item);
        $policies->save($item, $on, $days);
        if (RetentionStateRepository::ready() && $wasOn !== $on) {
            $state = app(RetentionStateRepository::class);
            app(RetentionSweeper::class)->states(CarbonImmutable::now());
            // WHY: unused accounts count from the day the item is on — activity before it was not all recorded.
            $state->setEnabledAt($item->key, $on ? CarbonImmutable::now() : null);
        }
        $this->notify('success', gp247_language_render('admin.setting_saved'));
    }

    /**
     * @param array{rows: int, more: bool, error: string|null, blocked?: string|null} $result
     * @return void
     */
    private function report(array $result): void
    {
        if (($result['blocked'] ?? null) === 'off') {
            $this->notify('error', gp247_language_render('admin.personal_data.retention.is_off'));
        } elseif ($result['error'] !== null) {
            $this->notify('error', gp247_language_render('admin.personal_data.retention.run_failed'));
        } else {
            $this->notify('success', gp247_language_render($result['more'] ? 'admin.personal_data.retention.ran_more' : 'admin.personal_data.retention.ran', ['rows' => $result['rows']]));
        }
    }

    /**
     * @param string $key
     * @return RetentionItem|null
     */
    private function item(string $key): ?RetentionItem
    {
        return PersonalDataRegistry::retentionItem($key);
    }

    /**
     * @return void
     * @throws AuthorizationException When the current admin is not an Administrator.
     */
    private function authorizeAdministrator(): void
    {
        // WHY: one policy for the whole site that deletes data — View.all or a role is not enough.
        if (!app(AdminUserContract::class)->isAdministrator()) {
            throw AuthorizationException::fromReason('data retention is for administrators only');
        }
    }

    /**
     * @return list<string>
     */
    protected function stepUpActions(): array
    {
        return ['confirmRisky', 'runEarly'];
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
        $ready = RetentionSweeper::ready();
        $states = $ready ? app(RetentionSweeper::class)->states(CarbonImmutable::now()) : [];
        $rows = [];
        foreach (PersonalDataRegistry::retentionItems() as $item) {
            $state = $states[$item->key] ?? null;
            $rows[] = [
                'key' => $item->key,
                'field' => self::fieldOf($item->key),
                'label' => PersonalDataLanguage::label($item->label()) ?: $item->key,
                'owner' => $item->owner(),
                'action' => $item->action->value,
                'risky' => $item->isRisky(),
                'fixed' => $item->hasFixedExpiry(),
                'min' => $item->minDays(),
                'max' => $item->maxDays(),
                'first_allowed_at' => $state?->first_allowed_at,
                'waiting' => $state !== null && !RetentionSweeper::allowed($state, CarbonImmutable::now()),
                'last_run_at' => $state?->last_run_at,
                'last_rows' => (int) ($state->last_rows ?? 0),
                'total_rows' => (int) ($state->total_rows ?? 0),
                'last_error' => $state?->last_error,
            ];
        }

        return view('gp247-admin::personal_data.retention', [
            'ready' => $ready,
            'items' => $rows,
            'noticeDays' => InactiveAccounts::NOTICE_DAYS,
            'firstCleanDays' => RetentionStateRepository::FIRST_CLEAN_DAYS,
            'confirmAction' => $this->confirmKey === null ? null : $this->item($this->confirmKey)?->action->value,
            'accountsAction' => RetentionAction::EraseAccounts->value,
            'stepUpMinutes' => intdiv(ErasureStepUp::SECONDS, 60),
        ])->layout('gp247-admin::layouts.admin', ['title' => gp247_language_render('admin.personal_data.retention.title')]);
    }
}
