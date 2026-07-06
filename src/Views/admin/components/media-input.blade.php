{{--
    GP247 media input (ADR-005 / ADR-006) — thin wrapper over the GP247 file
    manager (Laravel File Manager). It keeps LFM behind a single component so the
    picker can be swapped later without touching any screen.

    The picker runs in its own popup window and calls back via `window.SetUrl`;
    we set that callback per click (vanilla JS — no jQuery on the modern page) and
    dispatch a native `input` event so Livewire's wire:model stays in sync.

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-UI-004
    @aidlc-adr ADR-005

    @props array
      - name (string): input name; also derives the id.
      - label (string|null): field label.
      - value (string|null): current stored path (for the preview).
      - type (string): LFM folder category (drives upload folder + allowed mime),
        e.g. "product" | "category" | "brand" | "banner" | "page" | "logo" |
        "avatar" | "language" | "content". Must match a key in
        config('lfm.folder_categories'). Default "other" (valid catch-all) — every
        screen should pass its own category; there is no "image" category.
      - error (string|null): validation message.
      - help (string|null): muted helper text.
      - required (bool): mark label with asterisk.
--}}
@props([
    'name' => null,
    'label' => null,
    'value' => null,
    'type' => 'other',
    'error' => null,
    'help' => null,
    'required' => false,
])

@php
    $id = $attributes->get('id', $name);
    // Same LFM endpoint the legacy admin uses (admin base + lfm url prefix).
    $lfmPrefix = gp247_route_admin('admin.home') . '/' . config('lfm.url_prefix');
    $initialPreview = $value ? gp247_file($value) : '';
    $inputClasses = 'block w-full rounded-l-lg border px-3 py-2 text-sm shadow-sm '
        . 'focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 '
        . 'dark:bg-gray-700 dark:text-gray-100 '
        . ($error ? 'border-red-400 dark:border-red-500' : 'border-gray-300 dark:border-gray-600');
@endphp

<div class="space-y-1" x-data="{ preview: @js($initialPreview) }">
    @if ($label)
        <label @if ($id) for="{{ $id }}" @endif
            class="block text-sm font-medium text-gray-700 dark:text-gray-200">
            {{ $label }}
            @if ($required)<span class="text-red-500">*</span>@endif
        </label>
    @endif

    <div class="flex">
        <input type="text"
            @if ($name) name="{{ $name }}" @endif
            @if ($id) id="{{ $id }}" @endif
            @if (! is_null($value)) value="{{ $value }}" @endif
            {{ $attributes->except('id')->merge(['class' => $inputClasses]) }}
        />
        <button type="button"
            x-on:click="
                const input = $root.querySelector('#{{ $id }}');
                window.SetUrl = (items) => {
                    const url = items.map(i => i.url).join(',');
                    input.value = url;
                    // WHY: native input event so Livewire wire:model captures the value.
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                    preview = items.length ? items[0].thumb_url : '';
                };
                window.open('{{ $lfmPrefix }}?type={{ $type }}', 'GP247FileManager', 'width=900,height=600');
            "
            class="inline-flex shrink-0 items-center gap-1.5 rounded-r-lg border border-l-0 border-gray-300 bg-white px-4 py-2 text-sm font-medium text-blue-600 whitespace-nowrap transition hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-1 dark:border-gray-600 dark:bg-gray-700 dark:text-blue-400 dark:hover:bg-gray-600">
            <i class="fa fa-image text-xs"></i>
            <span>{{ gp247_language_render('admin.core.choose_image') }}</span>
        </button>
    </div>

    <div class="mt-1" x-show="preview" x-cloak>
        <img :src="preview" alt="" class="h-10 w-auto rounded border border-gray-200 dark:border-gray-600">
    </div>

    @if ($error)
        <p class="text-sm text-red-600 dark:text-red-400">{{ $error }}</p>
    @elseif ($help)
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $help }}</p>
    @endif
</div>
