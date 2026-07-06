<?php

namespace GP247\Core\AdminShell\Http\Livewire;

use GP247\Core\AdminShell\Infrastructure\GP247AdminComponent;
use GP247\Core\Models\AdminLanguage;
use GP247\Core\Models\Languages;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\WithPagination;

/**
 * Translation-string manager (ADR-005): filter by language / position / keyword,
 * inline-edit any string, and add new English keys via a modal — all without a
 * page reload. Defaults to lang=en so the list is never empty on first visit.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-UI-002, US-UI-007
 * @aidlc-adr ADR-002, ADR-005
 */
class LanguageStringManager extends GP247AdminComponent
{
    use WithPagination;

    protected ?string $permission = 'admin_language';

    public string $lang     = 'en';
    public string $position = '';
    public string $keyword  = '';
    protected int  $perPage = 50;

    public bool  $showAdd = false;
    /** @var array<string, string> */
    public array $newForm = ['position' => '', 'position_new' => '', 'code' => '', 'text' => ''];

    // -------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------

    /**
     * @return void
     */
    public function mount(): void
    {
        parent::mount();
        // WHY: honour ?lang= from the legacy URL so old menu links keep working.
        if (request()->has('lang')) {
            $this->lang = (string) request('lang');
        }
    }

    // -------------------------------------------------------------------
    // Filter hooks — reset pagination on any filter change
    // -------------------------------------------------------------------

    /** @return void */
    public function updatedKeyword(): void  { $this->resetPage(); }
    /** @return void */
    public function updatedPosition(): void { $this->resetPage(); }
    /** @return void */
    public function updatedLang(): void     { $this->resetPage(); }

    // -------------------------------------------------------------------
    // Inline-edit save (called by Alpine via $wire)
    // -------------------------------------------------------------------

    /**
     * Persist a single translation string for the currently selected language.
     *
     * @param string $code     Translation key.
     * @param string $position Group/section identifier.
     * @param string $text     Translated value (may contain basic HTML markup).
     * @return void
     * @throws \GP247\Core\AdminShell\Domain\AuthorizationException
     */
    public function saveString(string $code, string $position, string $text): void
    {
        $this->authorizeAction('update');

        $langCode = $this->lang ?: 'en';
        $codes    = AdminLanguage::getCodeAll();
        if (!array_key_exists($langCode, $codes)) {
            $this->notify('error', gp247_language_render('admin.method_not_allow'));
            return;
        }

        Languages::updateOrCreate(
            ['location' => $langCode, 'code' => gp247_clean($code)],
            ['text' => gp247_clean($text), 'position' => gp247_clean($position)]
        );

        $this->notify('success', gp247_language_render('action.update_success'));
    }

    // -------------------------------------------------------------------
    // Add-new modal
    // -------------------------------------------------------------------

    /** @return void */
    public function openAdd(): void
    {
        $this->newForm = ['position' => '', 'position_new' => '', 'code' => '', 'text' => ''];
        $this->resetValidation();
        $this->showAdd = true;
    }

    /** @return void */
    public function closeAdd(): void
    {
        $this->showAdd = false;
    }

    /**
     * Validate and insert a new English translation key (new keys are always en).
     *
     * @return void
     * @throws \GP247\Core\AdminShell\Domain\AuthorizationException
     * @throws \Illuminate\Validation\ValidationException
     */
    public function addString(): void
    {
        $this->authorizeAction('store');

        $this->validate([
            'newForm.code'         => ['required', 'string', 'max:100',
                Rule::unique((new Languages)->getTable(), 'code')],
            'newForm.text'         => ['required', 'string'],
            'newForm.position'     => ['required_without:newForm.position_new'],
            'newForm.position_new' => ['nullable', 'string', 'max:100'],
        ]);

        $pos = !empty($this->newForm['position_new'])
            ? $this->newForm['position_new']
            : $this->newForm['position'];

        Languages::insert([
            'code'     => gp247_clean(trim($this->newForm['code'])),
            'text'     => gp247_clean(trim($this->newForm['text'])),
            'position' => gp247_clean(trim($pos)),
            'location' => 'en',
        ]);

        $this->showAdd  = false;
        $this->newForm  = ['position' => '', 'position_new' => '', 'code' => '', 'text' => ''];
        $this->resetPage();
        $this->notify('success', gp247_language_render('action.create_success'));
    }

    // -------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------

    /**
     * @return View
     */
    public function render(): View
    {
        $langCode = $this->lang ?: 'en';

        $rows = Languages::where('location', $langCode)
            ->when($this->position, fn ($q) => $q->where('position', $this->position))
            ->when($this->keyword,  fn ($q) => $q->where(function ($q): void {
                $q->where('code', 'like', '%' . $this->keyword . '%')
                  ->orWhere('text', 'like', '%' . $this->keyword . '%');
            }))
            ->orderBy('position')
            ->orderBy('code')
            ->paginate($this->perPage);

        // WHY: show the English source alongside non-English translations so
        // translators know what to write without switching tabs.
        $englishMap = [];
        if ($langCode !== 'en' && $rows->isNotEmpty()) {
            $englishMap = Languages::where('location', 'en')
                ->whereIn('code', $rows->pluck('code')->toArray())
                ->pluck('text', 'code')
                ->toArray();
        }

        return view('gp247-admin::livewire.language-string-manager', [
            'rows'       => $rows,
            'englishMap' => $englishMap,
            'languages'  => AdminLanguage::getListAll(),
            'positions'  => Languages::getPosition(),
        ])->layout('gp247-admin::layouts.admin', [
            'title' => gp247_language_render('admin.language_manager.title'),
        ]);
    }
}
