{{--
    GP247 TailAdmin form field (ADR-005).

    A label + input + error/help wrapper. Extra attributes (wire:model, placeholder,
    type, required, x-…) pass through to the <input> element, so this stays a thin,
    Livewire-friendly replacement for the brownfield `gp247_form_render_*` helpers.

    Date types (date / datetime / datetime-local / time) are upgraded to the
    TailAdmin-standard datepicker (flatpickr, MIT, self-hosted per ADR-004) instead
    of the browser's native control: a text input enhanced by an Alpine factory.
    Because flatpickr is not a native input bound by Livewire, the field is wrapped
    in wire:ignore and the bound property is synced through $wire (seed on init,
    write on change, re-seed when the server mutates it) — mirroring <x-gp247::rich-editor>.

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-UI-001
    @aidlc-adr ADR-005

    @props array
      - label (string|null): field label text.
      - name (string|null): input name; also used to derive the id when absent.
      - type (string): input type. Default text. date/datetime/datetime-local/time → flatpickr.
      - value (string|null): initial value (ignored when wire:model is bound).
      - error (string|null): validation message shown in red under the field.
      - help (string|null): muted helper text.
      - required (bool): mark the label with an asterisk. Default false.
--}}
@props([
    'label' => null,
    'name' => null,
    'type' => 'text',
    'value' => null,
    'error' => null,
    'help' => null,
    'required' => false,
])

@php
    $id = $attributes->get('id', $name);

    // WHY: map each date-ish type to a flatpickr config; presence in this map is
    // also the flag that flips the field from native input to the datepicker.
    $fpOptions = [
        'date' => ['dateFormat' => 'Y-m-d'],
        'datetime' => ['enableTime' => true, 'dateFormat' => 'Y-m-d H:i', 'time_24hr' => true],
        'datetime-local' => ['enableTime' => true, 'dateFormat' => 'Y-m-d H:i', 'time_24hr' => true],
        'time' => ['enableTime' => true, 'noCalendar' => true, 'dateFormat' => 'H:i', 'time_24hr' => true],
    ];
    $isDate = array_key_exists($type, $fpOptions);

    // WHY: flatpickr drives the value via $wire, so the wire:model binding must NOT
    // also sit on the native input (it would fight flatpickr on DOM morph). Lift the
    // bound property name out of the attribute bag and strip wire:model from passthrough.
    $wireModel = null;
    if ($isDate) {
        foreach ($attributes->getAttributes() as $attrKey => $attrVal) {
            if (str_starts_with($attrKey, 'wire:model')) {
                $wireModel = $attrVal;
                break;
            }
        }
    }
    $passAttributes = $isDate ? $attributes->whereDoesntStartWith('wire:model') : $attributes;

    $inputClasses = 'block w-full rounded-lg border px-3 py-2 text-sm shadow-sm '
        . ($isDate ? 'pr-10 ' : '')
        . 'focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 '
        . 'dark:bg-gray-700 dark:text-gray-100 dark:placeholder-gray-400 '
        . ($error
            ? 'border-red-400 dark:border-red-500'
            : 'border-gray-300 dark:border-gray-600');
@endphp

@if ($isDate)
    {{-- Load flatpickr once per page and register the Alpine factory before Alpine
         boots, regardless of how many date fields a screen renders. Loaded only on
         screens that actually use a date input (this block is conditional). --}}
    @assets
        <link rel="stylesheet" href="{{ gp247_file('GP247/Core/AdminShell/vendor/flatpickr/flatpickr.min.css') }}">
        <style>
            /* Dark-mode skin for the flatpickr popup (default theme is light-only). */
            .dark .flatpickr-calendar{background:#1f2937;box-shadow:0 3px 13px rgba(0,0,0,.6);color:#e5e7eb}
            .dark .flatpickr-calendar.arrowTop:before,.dark .flatpickr-calendar.arrowTop:after{border-bottom-color:#1f2937}
            .dark .flatpickr-calendar.arrowBottom:before,.dark .flatpickr-calendar.arrowBottom:after{border-top-color:#1f2937}
            .dark .flatpickr-months .flatpickr-month,.dark .flatpickr-current-month .flatpickr-monthDropdown-months,.dark .flatpickr-weekday{color:#e5e7eb;fill:#e5e7eb}
            .dark .flatpickr-day{color:#e5e7eb}
            .dark .flatpickr-day.flatpickr-disabled,.dark .flatpickr-day.prevMonthDay,.dark .flatpickr-day.nextMonthDay{color:rgba(229,231,235,.3)}
            .dark .flatpickr-day:hover,.dark .flatpickr-day.today:hover{background:#374151;border-color:#374151}
            .dark .flatpickr-day.selected{background:#2563eb;border-color:#2563eb;color:#fff}
            .dark .flatpickr-months .flatpickr-prev-month svg,.dark .flatpickr-months .flatpickr-next-month svg{fill:#e5e7eb}
            .dark .flatpickr-months .flatpickr-prev-month:hover svg,.dark .flatpickr-months .flatpickr-next-month:hover svg{fill:#60a5fa}
            .dark .numInputWrapper span{border-color:#374151}
            .dark .flatpickr-time input,.dark .flatpickr-time .flatpickr-time-separator,.dark .flatpickr-time .flatpickr-am-pm{color:#e5e7eb}
            .dark .flatpickr-time input:hover,.dark .flatpickr-time .flatpickr-am-pm:hover{background:#374151}
        </style>
        <script src="{{ gp247_file('GP247/Core/AdminShell/vendor/flatpickr/flatpickr.min.js') }}"></script>
        <script>
            // WHY: this asset block evaluates AFTER Alpine boots on Livewire
            // wire:navigate (SPA) visits, so the alpine:init event has already
            // fired and an alpine:init listener would never run — leaving
            // gp247Datepicker undefined until a hard reload. Register the
            // factory immediately when Alpine already exists, otherwise wait
            // for alpine:init (the full-page-load path). Same fix as the rich-editor component.
            //
            // NOTE: never write a literal "at-assets"/"at-endassets" token in this
            // inline script — Blade parses those as directives even inside JS, which
            // truncates the script and breaks the page.
            (function () {
                const define = () => {
                    window.Alpine.data('gp247Datepicker', (model, options) => ({
                        fp: null,

                        init() {
                            if (typeof flatpickr === 'undefined') {
                                console.error('GP247 datepicker: flatpickr build not loaded.');
                                return;
                            }

                            const input = this.$refs.input;
                            this.fp = flatpickr(input, {
                                allowInput: false,
                                defaultDate: this.$wire.get(model) || null,
                                ...options,
                                // WHY: flatpickr is not a native Livewire input, so push the
                                // formatted string into the bound property on each change.
                                onChange: (dates, str) => this.$wire.set(model, str),
                            });

                            // WHY: reflect server-driven value changes (edit/reset) back into
                            // the picker without re-firing onChange (second arg = false).
                            this.$wire.$watch(model, (value) => {
                                if (this.fp) {
                                    this.fp.setDate(value || null, false);
                                }
                            });
                        },

                        destroy() {
                            if (this.fp) {
                                this.fp.destroy();
                                this.fp = null;
                            }
                        },
                    }));
                };

                if (window.Alpine) {
                    define();
                } else {
                    document.addEventListener('alpine:init', define);
                }
            })();
        </script>
    @endassets
@endif

<div class="space-y-1">
    @if ($label)
        <label @if ($id) for="{{ $id }}" @endif
            class="block text-sm font-medium text-gray-700 dark:text-gray-200">
            {!! $label !!}
            @if ($required)<span class="text-red-500">*</span>@endif
        </label>
    @endif

    @if ($isDate)
        {{-- wire:ignore so Livewire DOM morphing never destroys the flatpickr instance;
             $wire.$watch above keeps it in sync with the server instead. --}}
        <div class="relative" wire:ignore
            x-data="gp247Datepicker(@js($wireModel), @js((object) $fpOptions[$type]))">
            <input
                type="text"
                x-ref="input"
                @if ($name) name="{{ $name }}" @endif
                @if ($id) id="{{ $id }}" @endif
                @if (! is_null($value)) value="{{ $value }}" @endif
                {{ $passAttributes->except('id')->merge(['class' => $inputClasses]) }}
            />
            <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-gray-400">
                <i class="fas fa-calendar-alt"></i>
            </span>
        </div>
    @else
        <input
            type="{{ $type }}"
            @if ($name) name="{{ $name }}" @endif
            @if ($id) id="{{ $id }}" @endif
            @if (! is_null($value)) value="{{ $value }}" @endif
            {{ $passAttributes->except('id')->merge(['class' => $inputClasses]) }}
        />
    @endif

    @if ($error)
        <p class="text-sm text-red-600 dark:text-red-400">{{ $error }}</p>
    @elseif ($help)
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $help }}</p>
    @endif
</div>
