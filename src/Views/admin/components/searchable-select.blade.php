{{--
    GP247 searchable select (ADR-005 / ADR-006) — an Alpine.js combobox that
    replaces Select2/jQuery for both single and multi-select use-cases.

    Options are resolved client-side (passed as a PHP array), so no AJAX round-trip
    is needed for typical admin selects (hundreds of items). The component is wrapped
    in wire:ignore and syncs with Livewire through $wire.set / $wire.$watch — the same
    pattern used by <x-gp247::rich-editor> and the flatpickr datepicker.

    @aidlc-unit admin-shell
    @aidlc-story US-AUI-001
    @aidlc-adr ADR-005, ADR-006, ADR-007

    @props array
      - model (string|null): Livewire property to bind (e.g. "categoryId"). When set,
        $wire.set() writes on change and $wire.$watch() syncs server mutations back.
        Omit for plain-HTML form usage (hidden input carries the value instead).
      - name (string|null): HTML name for the hidden input (form fallback / non-Livewire).
      - options (array): [['id' => mixed, 'label' => string], ...].
      - value (mixed|null): initial value — scalar for single, array of ids for multiple.
        Ignored when `model` is set (seed comes from $wire.get).
      - multiple (bool): enable multi-select with tag UI. Default false.
      - label (string|null): field label.
      - placeholder (string|null): search-box placeholder. Defaults to lang key.
      - error (string|null): validation message (red).
      - help (string|null): muted helper text.
      - required (bool): mark label with asterisk.
      - clearable (bool): single-mode only — show the "×" button that clears the
        selection back to empty. Default true. Set false for fields that must
        always hold a value (e.g. store language/currency/template) — only
        picking a different option is then allowed, never clearing to empty.
--}}
@props([
    'model'       => null,
    'name'        => null,
    'options'     => [],
    'value'       => null,
    'multiple'    => false,
    'label'       => null,
    'placeholder' => null,
    'error'       => null,
    'help'        => null,
    'required'    => false,
    'disabled'    => false,
    'clearable'   => true,
])

@php
    $id          = $attributes->get('id', $name ?? ('ss_' . uniqid()));
    $placeholder = $placeholder ?? gp247_language_render('admin.search');
    // Seed value: prefer $wire when model is bound; fall back to the `value` prop.
    // The Alpine init() re-seeds from $wire anyway, so this is only for SSR preview.
    $seedValue   = $model ? null : $value;
@endphp

@assets
    <style>
        /* ── GP247 Searchable Select ───────────────────────────────────────── */
        .gp247-ss { position: relative; }

        /* Single-mode trigger row */
        .gp247-ss-row {
            display: flex; align-items: center;
            border: 1px solid rgb(209 213 219);
            border-radius: .5rem; background: #fff;
            transition: border-color .15s, box-shadow .15s;
        }
        .dark .gp247-ss-row { background: rgb(55 65 81); border-color: rgb(75 85 99); }
        .gp247-ss-row:focus-within {
            border-color: rgb(59 130 246);
            box-shadow: 0 0 0 3px rgba(59,130,246,.18);
        }
        .gp247-ss-row.gp247-error { border-color: rgb(248 113 113); }

        .gp247-ss-input {
            flex: 1; min-width: 0; border: none; outline: none; background: transparent;
            padding: .45rem .75rem; font-size: .875rem; color: rgb(30 41 59);
        }
        .dark .gp247-ss-input { color: rgb(243 244 246); }

        .gp247-ss-btn {
            padding: 0 .5rem; background: none; border: none; cursor: pointer;
            line-height: 1; color: rgb(148 163 184); transition: color .1s; flex-shrink: 0;
        }
        .gp247-ss-btn:hover { color: rgb(71 85 105); }
        .dark .gp247-ss-btn:hover { color: rgb(209 213 219); }

        .gp247-ss-arrow {
            padding: 0 .6rem; color: rgb(148 163 184);
            pointer-events: none; flex-shrink: 0;
            transition: transform .15s;
        }
        .gp247-ss[data-open] .gp247-ss-arrow { transform: rotate(180deg); }

        /* Multi-mode tag wrap */
        .gp247-ss-tags {
            display: flex; flex-wrap: wrap; gap: .25rem; align-items: flex-start;
            padding: .3rem .45rem; border: 1px solid rgb(209 213 219); border-radius: .5rem;
            background: #fff; min-height: 2.5rem; cursor: text;
            transition: border-color .15s, box-shadow .15s;
        }
        .dark .gp247-ss-tags { background: rgb(55 65 81); border-color: rgb(75 85 99); }
        .gp247-ss-tags:focus-within {
            border-color: rgb(59 130 246);
            box-shadow: 0 0 0 3px rgba(59,130,246,.18);
        }
        .gp247-ss-tags.gp247-error { border-color: rgb(248 113 113); }

        .gp247-ss-tag {
            display: inline-flex; align-items: center; gap: .2rem;
            background: rgb(239 246 255); color: rgb(29 78 216);
            font-size: .75rem; font-weight: 500;
            padding: .15rem .45rem; border-radius: .25rem; line-height: 1.5;
        }
        .dark .gp247-ss-tag { background: rgb(30 58 138); color: rgb(147 197 253); }
        .gp247-ss-tag-x {
            background: none; border: none; cursor: pointer; padding: 0; line-height: 1;
            color: rgb(147 197 253); font-size: .85rem; transition: color .1s;
        }
        .gp247-ss-tag-x:hover { color: rgb(29 78 216); }
        .dark .gp247-ss-tag-x:hover { color: rgb(255 255 255); }

        .gp247-ss-tag-input {
            flex: 1; min-width: 80px; border: none; outline: none; background: transparent;
            padding: .1rem .25rem; font-size: .845rem; color: rgb(30 41 59);
        }
        .dark .gp247-ss-tag-input { color: rgb(243 244 246); }

        /* Dropdown — fixed (not absolute) so it isn't clipped by an ancestor
           `overflow-hidden` card (e.g. the last row of a rounded settings table).
           Position/width are computed from the trigger's bounding rect in JS. */
        .gp247-ss-drop {
            position: fixed; z-index: 9999;
            background: #fff; border: 1px solid rgb(226 232 240);
            border-radius: .5rem; box-shadow: 0 8px 24px rgba(0,0,0,.09);
            overflow: hidden;
            opacity: 0; transform: scaleY(.96); transform-origin: top;
            transition: opacity .1s, transform .1s; pointer-events: none;
        }
        .dark .gp247-ss-drop {
            background: rgb(31 41 55); border-color: rgb(55 65 81);
            box-shadow: 0 8px 24px rgba(0,0,0,.3);
        }
        .gp247-ss-drop[data-open] { opacity: 1; transform: scaleY(1); pointer-events: auto; }

        .gp247-ss-list { max-height: 220px; overflow-y: auto; padding: .25rem 0; }

        .gp247-ss-opt {
            display: flex; align-items: center; justify-content: space-between;
            padding: .45rem .85rem; font-size: .845rem; cursor: pointer;
            color: rgb(30 41 59); transition: background .08s; user-select: none;
        }
        .dark .gp247-ss-opt { color: rgb(226 232 240); }
        .gp247-ss-opt:hover, .gp247-ss-opt[aria-selected="true"] {
            background: rgb(239 246 255); color: rgb(29 78 216);
        }
        .dark .gp247-ss-opt:hover, .dark .gp247-ss-opt[aria-selected="true"] {
            background: rgb(30 58 138); color: rgb(147 197 253);
        }
        .gp247-ss-check { font-size: .7rem; opacity: 0; }
        .gp247-ss-opt[aria-selected="true"] .gp247-ss-check { opacity: 1; }

        .gp247-ss-empty {
            padding: .7rem .85rem; font-size: .8rem;
            color: rgb(148 163 184); text-align: center;
        }

        .gp247-ss.ss-disabled { opacity: .5; pointer-events: none; cursor: not-allowed; }
    </style>

    <script>
        // WHY: this asset block evaluates AFTER Alpine boots on Livewire
        // wire:navigate (SPA) visits, so the alpine:init event has already
        // fired and an alpine:init listener would never run — leaving
        // gp247SearchableSelect undefined until a hard reload. Register the
        // factory immediately when Alpine already exists, otherwise wait
        // for alpine:init (the full-page-load path). Same fix as the rich-editor component.
        //
        // NOTE: never write a literal "at-assets"/"at-endassets" token in this
        // inline script — Blade parses those as directives even inside JS, which
        // truncates the script and breaks the page.
        (function () {
            const define = () => {
                window.Alpine.data('gp247SearchableSelect', (model, opts, multiple, ph) => ({
                open:     false,
                query:    '',
                opts:     opts,
                single:   null,   // single-mode: {id, label} | null
                multi:    [],     // multi-mode:  [{id, label}, ...]
                ph:       ph,
                dropStyle: '',    // fixed-position coords for .gp247-ss-drop, computed on open

                // ── Seed + watch ──────────────────────────────────────────────
                init() {
                    if (model) {
                        this._seed(this.$wire.get(model));
                        this.$wire.$watch(model, v => this._seed(v));
                    }
                    this.$watch('query', () => { if (!this.open) this._openDropdown(); });
                },

                // WHY: the dropdown is `position: fixed` (not `absolute`) so it
                // isn't clipped by an ancestor `overflow-hidden` card (e.g. the
                // last row of a rounded settings table) — see gp247-ss-drop CSS.
                // Fixed positioning needs manual top/left/width from the trigger's
                // bounding rect. Recomputed each time the dropdown opens.
                _openDropdown() {
                    const r = this.$el.getBoundingClientRect();

                    // WHY: the dropdown always opened downward regardless of
                    // available space, so a trigger near the bottom of the
                    // viewport (e.g. the last row of a settings table) got its
                    // option list pushed off-screen. Flip upward when there's
                    // not enough room below but there is above.
                    const estHeight = 260; // gp247-ss-list max-height (220px) + padding/border
                    const spaceBelow = window.innerHeight - r.bottom;
                    const openUpward = spaceBelow < estHeight && r.top > spaceBelow;

                    this.dropStyle = openUpward
                        ? `bottom:${window.innerHeight - r.top + 4}px; left:${r.left}px; width:${r.width}px; transform-origin:bottom;`
                        : `top:${r.bottom + 4}px; left:${r.left}px; width:${r.width}px; transform-origin:top;`;
                    this.open = true;
                },

                _seed(val) {
                    if (multiple) {
                        const ids = Array.isArray(val) ? val : (val ? [val] : []);
                        this.multi = ids.map(id => this.opts.find(o => o.id == id)).filter(Boolean);
                    } else {
                        this.single = val != null ? (this.opts.find(o => o.id == val) ?? null) : null;
                        this.query  = this.single ? this.single.label : '';
                    }
                },

                // ── Filtered list ─────────────────────────────────────────────
                get filtered() {
                    const q = this.query.toLowerCase();
                    return this.opts.filter(o => {
                        const match = o.label.toLowerCase().includes(q);
                        return multiple ? match && !this.multi.find(s => s.id === o.id) : match;
                    });
                },

                // ── Actions ───────────────────────────────────────────────────
                select(opt) {
                    if (multiple) {
                        this.multi = [...this.multi, opt];
                        this.query = '';
                        this.open  = this.filtered.length > 0;
                        this._commit(this.multi.map(s => s.id));
                    } else {
                        this.query = opt.label;
                        this.open  = false;

                        // WHY: re-picking the already-selected option must be a
                        // no-op — skip $wire.set so nothing fires (no redundant
                        // "saved" notification / server write) when the value
                        // hasn't actually changed.
                        if (this.single && this.single.id === opt.id) return;

                        this.single = opt;
                        this._commit(opt.id);
                    }
                },

                removeTag(id) {
                    this.multi = this.multi.filter(s => s.id !== id);
                    this._commit(this.multi.map(s => s.id));
                },

                clear() {
                    this.single = null;
                    this.query  = '';
                    this.open   = false;
                    this._commit(null);
                },

                _commit(val) {
                    if (model) this.$wire.set(model, val);
                    // Sync hidden input(s) for non-Livewire form submission.
                    const root = this.$root;
                    root.querySelectorAll('input[data-ss-hidden]').forEach(el => el.remove());
                    const name = root.dataset.ssName;
                    if (!name) return;
                    const vals = Array.isArray(val) ? val : (val != null ? [val] : []);
                    vals.forEach(v => {
                        const inp = document.createElement('input');
                        inp.type = 'hidden';
                        inp.name = multiple ? name + '[]' : name;
                        inp.value = v;
                        inp.dataset.ssHidden = '';
                        root.appendChild(inp);
                    });
                },

                // ── Helpers for template ──────────────────────────────────────
                isSelected(id) {
                    return multiple
                        ? !!this.multi.find(s => s.id === id)
                        : this.single?.id === id;
                },

                onInputFocus() {
                    this._openDropdown();
                    // WHY: `query` still holds the current selection's label, so
                    // leaving it intact would filter the list down to just that
                    // match. Clear it (after opening, so this doesn't re-trigger
                    // the query $watch above) so the full option list shows right
                    // away — matches native <select> behavior. onInputBlur
                    // restores the label if the user leaves without picking.
                    if (!multiple) this.query = '';
                },

                onInputBlur() {
                    // Delay so a dropdown click fires before the blur closes the list.
                    setTimeout(() => {
                        this.open = false;
                        // Restore display text if user typed but didn't pick anything.
                        if (!multiple && this.single) this.query = this.single.label;
                        if (!multiple && !this.single) this.query = '';
                    }, 130);
                },

                onBackspace() {
                    if (!this.query && this.multi.length) {
                        const last = this.multi[this.multi.length - 1];
                        this.removeTag(last.id);
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

<div class="space-y-1">
    @if ($label)
        <label for="{{ $id }}"
            class="block text-sm font-medium text-gray-700 dark:text-gray-200">
            {{ $label }}
            @if ($required)<span class="text-red-500">*</span>@endif
        </label>
    @endif

    {{-- wire:ignore: Alpine owns this DOM; $wire.$watch keeps it in sync. --}}
    <div wire:ignore
        class="gp247-ss{{ $disabled ? ' ss-disabled' : '' }}"
        data-ss-name="{{ $name }}"
        x-data="gp247SearchableSelect(
            @js($model),
            @js($options),
            @js($multiple),
            @js($placeholder)
        )"
        x-bind:data-open="open || undefined"
        @click.outside="open = false"
        @scroll.window="if (open) open = false">

        @if ($multiple)
            {{-- Multi: tag wrap ──────────────────────────────────── --}}
            <div class="gp247-ss-tags {{ $error ? 'gp247-error' : '' }}"
                @click="$refs.tagInput.focus(); open = filtered.length > 0">

                <template x-for="tag in multi" :key="tag.id">
                    <span class="gp247-ss-tag">
                        <span x-text="tag.label"></span>
                        <button type="button" class="gp247-ss-tag-x"
                            @click.stop="removeTag(tag.id)">×</button>
                    </span>
                </template>

                <input x-ref="tagInput"
                    type="text"
                    class="gp247-ss-tag-input"
                    :placeholder="multi.length ? '' : ph"
                    x-model="query"
                    @focus="onInputFocus"
                    @blur="onInputBlur"
                    @keydown.backspace="onBackspace"
                    autocomplete="off"
                    id="{{ $id }}" />
            </div>
        @else
            {{-- Single: text input row ───────────────────────────── --}}
            <div class="gp247-ss-row {{ $error ? 'gp247-error' : '' }}"
                @click="$refs.input.focus(); onInputFocus()">
                <input x-ref="input"
                    type="text"
                    id="{{ $id }}"
                    class="gp247-ss-input"
                    :placeholder="ph"
                    x-model="query"
                    @focus="onInputFocus"
                    @blur="onInputBlur"
                    autocomplete="off" />

                @if ($clearable)
                    <button type="button" class="gp247-ss-btn"
                        x-show="single"
                        @click.stop="clear()"
                        title="{{ gp247_language_render('admin.delete') }}">×</button>
                @endif

                <span class="gp247-ss-arrow">
                    <svg width="12" height="12" viewBox="0 0 12 12" fill="none">
                        <path d="M2 4l4 4 4-4" stroke="currentColor" stroke-width="1.6"
                            stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </span>
            </div>
        @endif

        {{-- Dropdown ─────────────────────────────────────────────── --}}
        <div class="gp247-ss-drop" x-bind:data-open="open || undefined" x-bind:style="dropStyle">
            <div class="gp247-ss-list">
                <template x-if="filtered.length === 0">
                    <div class="gp247-ss-empty">
                        {{ gp247_language_render('admin.no_records') }}
                    </div>
                </template>
                <template x-for="opt in filtered" :key="opt.id">
                    <div class="gp247-ss-opt"
                        :aria-selected="isSelected(opt.id) ? 'true' : 'false'"
                        @mousedown.prevent="select(opt)">
                        <span x-text="opt.label"></span>
                        <span class="gp247-ss-check">✓</span>
                    </div>
                </template>
            </div>
        </div>

        {{-- Hidden inputs for initial value (non-Livewire form fallback) --}}
        @if (! $model && $seedValue !== null)
            @foreach ((array) $seedValue as $v)
                <input type="hidden" data-ss-hidden
                    name="{{ $multiple ? ($name . '[]') : $name }}"
                    value="{{ $v }}">
            @endforeach
        @endif
    </div>

    @if ($error)
        <p class="text-sm text-red-600 dark:text-red-400">{{ $error }}</p>
    @elseif ($help)
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $help }}</p>
    @endif
</div>
