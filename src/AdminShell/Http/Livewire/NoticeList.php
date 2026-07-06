<?php

namespace GP247\Core\AdminShell\Http\Livewire;

use GP247\Core\AdminShell\Infrastructure\DataTableComponent;
use GP247\Core\Models\AdminNotice;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Admin notifications list (US-AUI-008, ADR-001/002/005): the modern Livewire
 * port of the legacy AdminNoticeController index. Rows are scoped to the current
 * admin (admin_id) like the legacy getNoticeListAdmin(), with keyword search,
 * sorting, mark-all-read and per-row / bulk delete. Gated by `admin_notice`.
 *
 * Owner-scoping is enforced in both rows() and deleteRows() as defense-in-depth:
 * an admin can never list or delete another admin's notices through this screen.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-AUI-008
 * @aidlc-adr ADR-001, ADR-002, ADR-005
 */
class NoticeList extends DataTableComponent
{
    protected ?string $permission = 'admin_notice';

    protected ?string $titleKey = 'admin_notice.title';

    /**
     * @return AdminNotice
     */
    protected function query()
    {
        return new AdminNotice();
    }

    /**
     * Sortable columns; doubles as the sort whitelist. Content/creator are shown
     * but not sortable (free text / foreign key).
     *
     * @return array<string, string>
     */
    protected function columns(): array
    {
        return [
            'type' => gp247_language_render('admin_notice.type'),
            'type_id' => gp247_language_render('admin_notice.type_id'),
            'status' => gp247_language_render('order.status'),
            'created_at' => gp247_language_render('admin_notice.created_at'),
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function searchable(): array
    {
        return ['content', 'type', 'type_id'];
    }

    /**
     * @return array<int, string>
     */
    protected function relations(): array
    {
        return ['admin'];
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function defaultSort(): array
    {
        return ['id', 'desc'];
    }

    /**
     * @return string
     */
    protected function listView(): string
    {
        return 'gp247-admin::livewire.notice-list';
    }

    /**
     * The current admin id used to scope notices (recipient = admin_id). Null
     * when unauthenticated, which fails closed (the scope matches no rows).
     *
     * @return int|string|null
     */
    protected function currentAdminId()
    {
        return admin()->user()?->id;
    }

    /**
     * Build the paginated list scoped to the current admin, applying the keyword
     * filter and sort. Mirrors DataTableComponent::rows() with the owner scope.
     *
     * @return LengthAwarePaginator
     */
    protected function rows(): LengthAwarePaginator
    {
        $query = $this->query()->newQuery()
            ->where('admin_id', $this->currentAdminId())
            ->with($this->relations());

        if ($this->keyword !== '') {
            $needle = '%' . $this->keyword . '%';
            $columns = $this->searchable();
            $query->where(function ($w) use ($columns, $needle): void {
                foreach ($columns as $i => $column) {
                    $i === 0 ? $w->where($column, 'like', $needle) : $w->orWhere($column, 'like', $needle);
                }
            });
        }

        if ($this->sortField !== '' && array_key_exists($this->sortField, $this->columns())) {
            $query->orderBy($this->sortField, $this->sortDir);
        } else {
            [$field, $dir] = $this->defaultSort();
            $query->orderBy($field, $dir);
        }

        return $query->paginate($this->perPage);
    }

    /**
     * Delete notices by id, scoped to the current admin so foreign rows are never
     * removed. Per-model deletion keeps model events firing.
     *
     * @param array<int, mixed> $ids Selected notice ids.
     * @return void
     */
    protected function deleteRows(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $this->query()->newQuery()
            ->where('admin_id', $this->currentAdminId())
            ->whereIn('id', $ids)
            ->get()
            ->each
            ->delete();
    }

    /**
     * Mark every notice of the current admin as read (status = 1). Layer-2 gated;
     * mutating, so view.all is denied. Mirrors the legacy markRead().
     *
     * @return void
     * @throws \GP247\Core\AdminShell\Domain\AuthorizationException When denied.
     */
    public function markRead(): void
    {
        $this->authorizeAction('markRead');

        AdminNotice::where('admin_id', $this->currentAdminId())->update(['status' => 1]);

        $this->notify('success', gp247_language_render('admin.core.setting_saved'));
    }
}
