{{--
    Translation-string manager (ADR-005): filter bar, inline-editable table,
    add-new modal. Defaults to lang=en — the list is always populated on first
    visit without needing any user action.

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-UI-002, US-UI-007
    @aidlc-adr ADR-002, ADR-005

    Variables:
      - $rows        (LengthAwarePaginator of Languages)
      - $englishMap  (array  code => text, only when $lang !== 'en')
      - $languages   (AdminLanguage collection, keyed by code)
      - $positions   (string[] list of positions)
--}}
<div>

    {{-- ─── Filter toolbar ────────────────────────────────────────────── --}}
    <div class="mb-4 flex flex-wrap items-end gap-3">

        {{-- Language selector --}}
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium text-gray-500 dark:text-gray-400">
                {{ gp247_language_render('admin.language.select_lang') }}
            </label>
            <select wire:model.live="lang"
                class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-700 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200">
                @foreach ($languages as $code => $langItem)
                    <option value="{{ $code }}">{{ $langItem->name }} ({{ $code }})</option>
                @endforeach
            </select>
        </div>

        {{-- Position selector --}}
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium text-gray-500 dark:text-gray-400">
                {{ gp247_language_render('admin.language.select_position') }}
            </label>
            <select wire:model.live="position"
                class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-700 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200">
                <option value="">— {{ gp247_language_render('admin.language.select_position') }} —</option>
                @foreach ($positions as $pos)
                    <option value="{{ $pos }}">{{ $pos }}</option>
                @endforeach
            </select>
        </div>

        {{-- Keyword search --}}
        <div class="flex flex-1 flex-col gap-1">
            <label class="text-xs font-medium text-gray-500 dark:text-gray-400">
                {{ gp247_language_render('admin.language_manager.code') }}
            </label>
            <div class="relative">
                <i class="fas fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
                <input type="search" wire:model.live.debounce.300ms="keyword"
                    placeholder="{{ gp247_language_render('admin.language.search_place') }}"
                    class="w-full rounded-lg border border-gray-300 bg-white py-1.5 pl-8 pr-3 text-sm text-gray-700 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:placeholder-gray-400">
            </div>
        </div>

        {{-- Add new --}}
        <x-gp247::button wire:click="openAdd" size="sm">
            <i class="fas fa-plus"></i> {{ gp247_language_render('admin.language_manager.add') }}
        </x-gp247::button>
    </div>

    {{-- ─── Result count ────────────────────────────────────────────────── --}}
    <p class="mb-2 text-xs text-gray-400 dark:text-gray-500">
        {{ $rows->total() }} {{ gp247_language_render('admin.language.no_found') }}
    </p>

    {{-- ─── Table ───────────────────────────────────────────────────────── --}}
    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-700/50">
                    <tr>
                        <th class="w-36 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ gp247_language_render('admin.language_manager.position') }}
                        </th>
                        <th class="w-64 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ gp247_language_render('admin.language_manager.code') }}
                        </th>
                        @if ($lang !== 'en')
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            English
                        </th>
                        @endif
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ $languages[$lang]->name ?? $lang }}
                            <span class="ml-1 text-gray-400 normal-case">({{ gp247_language_render('admin.language_manager.click_to_edit') }})</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700/50">
                    @forelse ($rows as $row)
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50"
                            wire:key="lstr-{{ $row->id }}">

                            {{-- Position --}}
                            <td class="px-4 py-3">
                                <span class="inline-block rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                                    {{ $row->position }}
                                </span>
                            </td>

                            {{-- Code --}}
                            <td class="px-4 py-3">
                                <code class="break-all text-xs text-gray-700 dark:text-gray-300">{{ $row->code }}</code>
                            </td>

                            {{-- English reference (only for non-English view) --}}
                            @if ($lang !== 'en')
                            <td class="px-4 py-3 text-xs text-gray-400 dark:text-gray-500 whitespace-pre-wrap max-w-xs">
                                {{ $englishMap[$row->code] ?? '' }}
                            </td>
                            @endif

                            {{-- Editable text cell --}}
                            <td class="px-4 py-3"
                                x-data="{
                                    open:     false,
                                    saving:   false,
                                    text:     @js($row->text ?? ''),
                                    original: @js($row->text ?? ''),
                                    code:     @js($row->code),
                                    position: @js($row->position ?? ''),
                                    async doSave() {
                                        this.saving = true;
                                        await $wire.saveString(this.code, this.position, this.text);
                                        this.original = this.text;
                                        this.open = false;
                                        this.saving = false;
                                    },
                                    cancel() {
                                        this.text = this.original;
                                        this.open = false;
                                    },
                                }">

                                {{-- Display mode --}}
                                <div x-show="!open"
                                     x-on:click="open = true"
                                     class="min-h-[1.75rem] cursor-pointer rounded p-1.5 text-sm whitespace-pre-wrap text-gray-700 transition hover:bg-blue-50 dark:text-gray-200 dark:hover:bg-blue-900/20"
                                     :class="!text ? 'italic text-gray-300 dark:text-gray-600' : ''"
                                     x-text="text || '{{ gp247_language_render('admin.language_manager.click_to_edit') }}'">
                                </div>

                                {{-- Edit mode --}}
                                <div x-show="open" x-cloak class="flex items-start gap-2">
                                    <textarea x-model="text" rows="3"
                                        x-on:keydown.ctrl.enter.prevent="doSave()"
                                        x-on:keydown.escape.prevent="cancel()"
                                        class="w-full rounded-lg border border-blue-400 bg-white px-2.5 py-1.5 text-sm text-gray-700 shadow-inner focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-blue-500 dark:bg-gray-700 dark:text-gray-100">
                                    </textarea>
                                    <div class="flex shrink-0 flex-col gap-1">
                                        <button type="button"
                                            x-on:click="doSave()"
                                            :disabled="saving"
                                            class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-blue-600 text-white transition hover:bg-blue-700 disabled:opacity-50 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                            <i class="fas text-xs" :class="saving ? 'fa-spinner fa-spin' : 'fa-check'"></i>
                                        </button>
                                        <button type="button"
                                            x-on:click="cancel()"
                                            class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-gray-300 bg-white text-gray-500 transition hover:bg-gray-50 focus:outline-none dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300">
                                            <i class="fas fa-times text-xs"></i>
                                        </button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $lang !== 'en' ? 4 : 3 }}" class="px-4 py-12 text-center">
                                <i class="fas fa-language mb-3 text-3xl text-gray-300 dark:text-gray-600"></i>
                                <p class="text-sm text-gray-400 dark:text-gray-500">
                                    {{ gp247_language_render('admin.language.no_found') }}
                                </p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- ─── Pagination ──────────────────────────────────────────────────── --}}
    <div class="mt-4">{{ $rows->links('gp247-admin::partials.pagination') }}</div>

    {{-- ─── Add-new modal ──────────────────────────────────────────────── --}}
    <div x-data x-on:open-modal.window="if ($event.detail === 'add-lang-string') $wire.set('showAdd', true)"></div>

    <div
        x-data="{ get open() { return $wire.showAdd } }"
        x-show="open"
        x-on:keydown.escape.window="$wire.closeAdd()"
        x-cloak
        class="fixed inset-0 z-50 flex items-center justify-center p-4"
    >
        <div class="absolute inset-0 bg-gray-900/50" x-on:click="$wire.closeAdd()"></div>

        <div x-show="open" x-transition
             class="relative w-full max-w-xl rounded-xl border border-gray-200 bg-white shadow-xl dark:border-gray-700 dark:bg-gray-800">

            {{-- Modal header --}}
            <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4 dark:border-gray-700">
                <h3 class="text-base font-semibold text-gray-800 dark:text-gray-100">
                    <i class="fas fa-plus-circle mr-1.5 text-blue-500"></i>
                    {{ gp247_language_render('admin.language_manager.add') }}
                </h3>
                <button type="button" wire:click="closeAdd"
                    class="text-gray-400 transition hover:text-gray-600 dark:hover:text-gray-200 text-xl leading-none"
                    aria-label="{{ gp247_language_render('admin.close') }}">&times;</button>
            </div>

            {{-- Modal body --}}
            <div class="space-y-4 p-5">

                {{-- Position --}}
                <div class="space-y-1">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">
                        {{ gp247_language_render('admin.language_manager.select_position') }}
                        <span class="text-red-500">*</span>
                    </label>
                    <select wire:model="newForm.position"
                        class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200
                               {{ $errors->has('newForm.position') ? 'border-red-400' : '' }}">
                        <option value="">— {{ gp247_language_render('admin.language_manager.select_position') }} —</option>
                        @foreach ($positions as $pos)
                            <option value="{{ $pos }}">{{ $pos }}</option>
                        @endforeach
                    </select>
                    @error('newForm.position')
                        <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                {{-- New position --}}
                <div class="space-y-1">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">
                        {{ gp247_language_render('admin.language_manager.new_position') }}
                    </label>
                    <input type="text" wire:model="newForm.position_new"
                        placeholder="{{ gp247_language_render('admin.language_manager.position') }}"
                        class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200">
                </div>

                {{-- Code --}}
                <div class="space-y-1">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">
                        {{ gp247_language_render('admin.language_manager.code') }}
                        <span class="text-red-500">*</span>
                    </label>
                    <input type="text" wire:model="newForm.code" placeholder="admin.my_module.key"
                        class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 font-mono text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200
                               {{ $errors->has('newForm.code') ? 'border-red-400' : '' }}">
                    @error('newForm.code')
                        <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Text (English) --}}
                <div class="space-y-1">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">
                        {{ gp247_language_render('admin.language_manager.text') }} (English)
                        <span class="text-red-500">*</span>
                    </label>
                    <textarea wire:model="newForm.text" rows="3"
                        class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200
                               {{ $errors->has('newForm.text') ? 'border-red-400' : '' }}"></textarea>
                    @error('newForm.text')
                        <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                    <p class="text-xs text-gray-400">{!! gp247_language_render('admin.language_manager.text_help', ['link' => gp247_route_admin('admin_language_manager.index')]) !!}</p>
                </div>
            </div>

            {{-- Modal footer --}}
            <div class="flex justify-end gap-2 border-t border-gray-200 px-5 py-4 dark:border-gray-700">
                <x-gp247::button variant="secondary" wire:click="closeAdd">
                    {{ gp247_language_render('admin.reset') }}
                </x-gp247::button>
                <x-gp247::button wire:click="addString" wire:loading.attr="disabled">
                    <i class="fas fa-save"></i>
                    {{ gp247_language_render('admin.submit') }}
                </x-gp247::button>
            </div>
        </div>
    </div>
</div>
