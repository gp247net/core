<?php

namespace GP247\Core\AdminShell\Http\Livewire;

use GP247\Core\AdminShell\Infrastructure\ResourcePanel;
use GP247\Core\Models\AdminLanguage;
use Illuminate\Validation\Rule;

/**
 * Two-panel language manager (ADR-005): add/edit form on the left, list on the
 * right, on one page (matching the legacy layout). Gated by `admin_language`;
 * built-in languages (GP247_GUARD_LANGUAGE) cannot be deleted.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-UI-002
 * @aidlc-adr ADR-001, ADR-002, ADR-005
 */
class LanguageManager extends ResourcePanel
{
    protected ?string $permission = 'admin_language';

    /**
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function baseQuery()
    {
        return AdminLanguage::query();
    }

    /**
     * @return array<int, string>
     */
    protected function searchable(): array
    {
        return ['name', 'code'];
    }

    /**
     * @return array<int, string>
     */
    protected function sortableColumns(): array
    {
        return ['name', 'code', 'sort', 'status'];
    }

    /**
     * @return array<int, string>
     */
    protected function defaultSort(): array
    {
        return ['sort', 'asc'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function formDefaults(): array
    {
        return ['name' => '', 'code' => '', 'icon' => '', 'sort' => 0, 'status' => 1, 'rtl' => 0];
    }

    /**
     * @param \GP247\Core\Models\AdminLanguage $model
     * @return array<string, mixed>
     */
    protected function fillForm($model): array
    {
        return $model->only(['name', 'code', 'icon', 'sort', 'status', 'rtl']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $table = (new AdminLanguage())->getTable();

        return [
            'form.name' => ['required', 'string', 'max:100'],
            'form.code' => ['required', 'string', 'max:10', Rule::unique($table, 'code')->ignore($this->editingId)],
            'form.icon' => ['required', 'string'],
            'form.sort' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return void
     */
    protected function persist(array $data): void
    {
        $data['rtl'] = empty($data['rtl']) ? 0 : 1;
        $data['status'] = empty($data['status']) ? 0 : 1;

        // Brownfield rule: always keep at least one active language.
        $otherActive = AdminLanguage::where('status', 1)
            ->when($this->editingId !== null, fn ($q) => $q->where('id', '<>', $this->editingId))
            ->count();
        if ($otherActive === 0) {
            $data['status'] = 1;
        }

        if ($this->editingId !== null) {
            AdminLanguage::where('id', $this->editingId)->update($data);

            return;
        }

        AdminLanguage::create($data);
    }

    /**
     * @param int|string $id
     * @return void
     */
    protected function deleteModel($id): void
    {
        $guarded = defined('GP247_GUARD_LANGUAGE') ? array_map('intval', (array) GP247_GUARD_LANGUAGE) : [];
        if (in_array((int) $id, $guarded, true)) {
            $this->notify('warning', gp247_language_render('admin.language.protected'));

            return;
        }

        AdminLanguage::find($id)?->delete();
    }

    /**
     * @return string
     */
    protected function panelView(): string
    {
        return 'gp247-admin::livewire.language-manager';
    }

    /**
     * @return string
     */
    protected function pageTitle(): string
    {
        return gp247_language_render('admin.language.title');
    }

    /**
     * @return string
     */
    protected function baseRoute(): string
    {
        return 'admin_language.index';
    }

    /**
     * @return array<int, int> Guarded ids (exposed to the view).
     */
    public function guardedIds(): array
    {
        return defined('GP247_GUARD_LANGUAGE') ? array_map('intval', (array) GP247_GUARD_LANGUAGE) : [];
    }
}
