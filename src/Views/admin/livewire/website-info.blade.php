{{--
    Website information (store_info) screen (ADR-005): an Active toggle, a left
    definition-table of store fields (media, contact details, language/domain/
    currency/template selects — live inline edit) and a right table of multilingual
    descriptions (name/keyword/description per active language). Bordered, striped
    two-column layout consistent with the config screens, mirroring the legacy
    store_info. The maintenance copy (maintain_content / maintain_note) from the
    legacy store_maintain screen is folded into each language panel. All labels via
    gp247_language_render.

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-UI-009
    @aidlc-adr ADR-001, ADR-005

    Variables:
      - $languages (array<code, AdminLanguage>)
      - $mediaFields (string[]), $fields (string[]), $isRoot (bool)
      - $languageOptions / $currencyOptions / $templateOptions (array<value,label>)
--}}
@php
    $meta = [
        'logo' => ['label' => 'store.logo', 'icon' => 'far fa-image'],
        'icon' => ['label' => 'store.icon', 'icon' => 'far fa-image'],
        'og_image' => ['label' => 'store.og_image', 'icon' => 'far fa-image'],
        'phone' => ['label' => 'store.phone', 'icon' => 'fas fa-phone-alt'],
        'long_phone' => ['label' => 'store.long_phone', 'icon' => 'fas fa-phone-square'],
        'email' => ['label' => 'store.email', 'icon' => 'fas fa-envelope'],
        'time_active' => ['label' => 'store.time_active', 'icon' => 'far fa-calendar-alt'],
        'address' => ['label' => 'store.address', 'icon' => 'fas fa-map-marked'],
        'office' => ['label' => 'store.office', 'icon' => 'fas fa-location-arrow'],
        'warehouse' => ['label' => 'store.warehouse', 'icon' => 'fas fa-warehouse'],
        'language' => ['label' => 'store.language', 'icon' => 'fas fa-language'],
        'domain' => ['label' => 'admin.store.domain', 'icon' => 'fab fa-chrome'],
        'currency' => ['label' => 'store.currency', 'icon' => 'far fa-money-bill-alt'],
        'template' => ['label' => 'admin.store.template', 'icon' => 'fas fa-object-ungroup'],
    ];

    // Build the ordered left-column row list (mirrors the legacy field order).
    $rows = [];
    foreach ($mediaFields as $f) { $rows[] = ['field' => $f, 'type' => 'media']; }
    foreach ($fields as $f) { $rows[] = ['field' => $f, 'type' => $f === 'time_active' ? 'textarea' : 'text']; }
    $rows[] = ['field' => 'language', 'type' => 'select', 'options' => $languageOptions];
    if ($isRoot) { $rows[] = ['field' => 'domain', 'type' => 'text']; }
    if (!empty($currencyOptions)) { $rows[] = ['field' => 'currency', 'type' => 'select', 'options' => $currencyOptions]; }
    if (!empty($templateOptions)) { $rows[] = ['field' => 'template', 'type' => 'select', 'options' => $templateOptions]; }

    $labelCell = 'w-2/5 border-r border-gray-200 px-5 py-3.5 align-middle text-sm font-medium text-gray-600 dark:border-gray-700 dark:text-gray-300';
    $valueCell = 'px-5 py-3 align-middle';
    $input = 'w-full rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100';
@endphp

<div class="space-y-6">
    {{-- Active toggle --}}
    <label class="inline-flex cursor-pointer items-center gap-3">
        <input type="checkbox" wire:model.live="active" class="peer sr-only">
        <span class="relative h-6 w-11 rounded-full bg-gray-300 transition-colors after:absolute after:left-0.5 after:top-0.5 after:h-5 after:w-5 after:rounded-full after:bg-white after:shadow after:transition-all peer-checked:bg-blue-600 peer-checked:after:translate-x-5 dark:bg-gray-600"></span>
        <span class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ gp247_language_render('admin.active') }}</span>
    </label>

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
        {{-- Left: store fields --}}
        <div class="overflow-hidden rounded-xl border border-gray-200 shadow-sm dark:border-gray-700">
            <table class="min-w-full">
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach ($rows as $i => $row)
                        @php $field = $row['field']; @endphp
                        <tr class="{{ $i % 2 ? 'bg-gray-50/60 dark:bg-gray-800/40' : 'bg-white dark:bg-gray-800' }}">
                            <td class="{{ $labelCell }}">
                                @if (!empty($meta[$field]['icon']))<i class="{{ $meta[$field]['icon'] }} mr-1.5 w-4 text-center text-gray-400"></i>@endif
                                {{ gp247_language_render($meta[$field]['label']) }}
                            </td>
                            <td class="{{ $valueCell }}">
                                @switch($row['type'])
                                    @case('media')
                                        <x-gp247::media-input :name="$field" :type="$meta[$field]['lfm'] ?? 'logo'" wire:model.live="store.{{ $field }}" :value="$store[$field] ?? null" />
                                        @break
                                    @case('textarea')
                                        <textarea wire:model.live.blur="store.{{ $field }}" rows="2" class="{{ $input }}"></textarea>
                                        @break
                                    @case('select')
                                        {{-- WHY: store language/currency/template must always hold a
                                             value — only switching to another option is allowed, never
                                             clearing to empty. --}}
                                        <x-gp247::searchable-select
                                            model="store.{{ $field }}"
                                            :options="collect($row['options'])->map(fn ($label, $value) => ['id' => (string) $value, 'label' => $label])->values()->all()"
                                            :clearable="false"
                                        />
                                        @break
                                    @default
                                        <input type="text" wire:model.live.blur="store.{{ $field }}" class="{{ $input }}">
                                @endswitch
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Right: multilingual descriptions, grouped under one tab per language --}}
        @php $langTabs = []; foreach ($languages as $code => $lang) { $langTabs[$code] = $lang->name; } @endphp
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <x-gp247::tabs :tabs="$langTabs">
                @foreach ($languages as $code => $lang)
                    <div x-show="tab === @js((string) $code)" x-cloak class="space-y-4">
                        {{-- WHY: field key is `name` (matches the renamed admin_store_description.name column /
                             $desc[lang]['name'] state) but the label i18n key stays `store.title` (unchanged,
                             pre-existing translation string, not tied to the column name). --}}
                        @php $descLabels = ['name' => 'store.title', 'keyword' => 'store.keyword', 'description' => 'store.description']; @endphp
                        @foreach (['name' => 'text', 'keyword' => 'text', 'description' => 'textarea'] as $df => $control)
                            <div class="space-y-1">
                                <label class="flex items-center gap-1.5 text-sm font-medium text-gray-700 dark:text-gray-200">
                                    @if ($lang->icon)<img src="{{ gp247_file($lang->icon) }}" alt="{{ $lang->name }}" class="h-4 w-auto rounded-sm">@endif
                                    {{ gp247_language_render($descLabels[$df]) }}
                                </label>
                                @if ($control === 'textarea')
                                    <textarea wire:model.live.blur="desc.{{ $code }}.{{ $df }}" rows="4" class="{{ $input }}"></textarea>
                                @else
                                    <input type="text" wire:model.live.blur="desc.{{ $code }}.{{ $df }}" class="{{ $input }}">
                                @endif
                            </div>
                        @endforeach

                        {{-- Maintenance copy, folded in from the legacy store_maintain screen --}}
                        <div class="space-y-4 border-t border-gray-200 pt-4 dark:border-gray-700">
                            <div class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                                <i class="fas fa-tools"></i>
                                {{ gp247_language_render('admin.maintain.title') }}
                            </div>
                            <x-gp247::rich-editor
                                :model="'desc.' . $code . '.maintain_content'"
                                :label="gp247_language_render('admin.maintain.description')" />
                            <div class="space-y-1">
                                <label class="flex items-center gap-1.5 text-sm font-medium text-gray-700 dark:text-gray-200">
                                    @if ($lang->icon)<img src="{{ gp247_file($lang->icon) }}" alt="{{ $lang->name }}" class="h-4 w-auto rounded-sm">@endif
                                    {{ gp247_language_render('admin.maintain.description_note') }}
                                </label>
                                <input type="text" wire:model.live.blur="desc.{{ $code }}.maintain_note" class="{{ $input }}">
                            </div>
                        </div>
                    </div>
                @endforeach
            </x-gp247::tabs>
        </div>
    </div>
</div>
