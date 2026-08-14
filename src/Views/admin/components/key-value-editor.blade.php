{{--
    GP247 key/value editor — a reusable Alpine.js table for editing a JSON object
    ({"key":"label", ...}) as rows of key/value pairs, instead of hand-typing JSON.

    Built for the custom-field "Default" options (select/radio/checkbox), but kept
    generic so any admin screen (core/front/shop/plugin) can reuse it. Syncs with
    Livewire through $wire.set / $wire.$watch on the bound `model` property.

    WHY inline x-data (not a named Alpine.data factory in @assets like
    <x-gp247::searchable-select>): this component is often rendered CONDITIONALLY
    (e.g. only when Option is select/radio/checkbox). When it first appears during a
    Livewire re-render, Livewire injects its markup via DOM morph — and a <script> in
    @assets injected that way does NOT execute (HTML rule: scripts set via innerHTML
    don't run), so a factory registered there would be missing and x-data would fail.
    An inline x-data object has no external-script dependency, so it initializes
    reliably whether the element is present at page load OR injected on re-render.
    The @assets <style> is fine — injected CSS applies regardless.

    @aidlc-unit admin-shell
    @aidlc-story US-UI-007

    @props
      - model (string): Livewire property to bind — a JSON object string. Required.
      - label (string|null): field label.
      - keyLabel (string|null): header for the key column.
      - valueLabel (string|null): header for the value column.
      - addLabel (string|null): text for the "add row" button.
      - error (string|null): validation message (red).
      - help (string|null): muted helper text.
      - required (bool): mark label with asterisk.
--}}
@props([
    'model'      => null,
    'label'      => null,
    'keyLabel'   => null,
    'valueLabel' => null,
    'addLabel'   => null,
    'error'      => null,
    'help'       => null,
    'required'   => false,
])

@php
    $keyLabel    = $keyLabel ?? gp247_language_render('admin.custom_field.option_key');
    $valueLabel  = $valueLabel ?? gp247_language_render('admin.custom_field.option_label');
    $addLabel    = $addLabel ?? gp247_language_render('admin.custom_field.option_add_row');
    $removeTitle = gp247_language_render('admin.custom_field.option_remove_row');
@endphp

@assets
    <style>
        /* ── GP247 Key/Value editor ────────────────────────────────────────── */
        .gp247-kv { width: 100%; }
        .gp247-kv-table { width: 100%; border-collapse: separate; border-spacing: 0; }
        .gp247-kv-table th {
            text-align: left; font-size: .75rem; font-weight: 500;
            color: rgb(107 114 128); padding: 0 0 .35rem .1rem;
        }
        .dark .gp247-kv-table th { color: rgb(156 163 175); }
        .gp247-kv-table th.gp247-kv-th-act { width: 2.5rem; }
        .gp247-kv-table td { padding: 0 0 .5rem 0; vertical-align: top; }
        .gp247-kv-table td.gp247-kv-td-key { padding-right: .5rem; width: 40%; }
        .gp247-kv-table td.gp247-kv-td-act { padding-left: .5rem; width: 2.5rem; text-align: center; }

        .gp247-kv-input {
            width: 100%; box-sizing: border-box;
            border: 1px solid rgb(209 213 219); border-radius: .5rem; background: #fff;
            padding: .45rem .75rem; font-size: .875rem; color: rgb(30 41 59);
            outline: none; transition: border-color .15s, box-shadow .15s;
        }
        .dark .gp247-kv-input { background: rgb(55 65 81); border-color: rgb(75 85 99); color: rgb(243 244 246); }
        .gp247-kv-input:focus {
            border-color: rgb(59 130 246);
            box-shadow: 0 0 0 3px rgba(59,130,246,.18);
        }
        .gp247-kv.gp247-error .gp247-kv-input { border-color: rgb(248 113 113); }

        .gp247-kv-del {
            display: inline-flex; align-items: center; justify-content: center;
            width: 2rem; height: 2rem; border-radius: .5rem;
            border: 1px solid rgb(226 232 240); background: #fff;
            color: rgb(148 163 184); cursor: pointer; line-height: 1;
            transition: color .12s, border-color .12s, background .12s;
        }
        .dark .gp247-kv-del { background: rgb(55 65 81); border-color: rgb(75 85 99); color: rgb(148 163 184); }
        .gp247-kv-del:hover { color: rgb(220 38 38); border-color: rgb(252 165 165); background: rgb(254 242 242); }
        .dark .gp247-kv-del:hover { color: rgb(248 113 113); border-color: rgb(153 27 27); background: rgb(69 26 26); }

        .gp247-kv-add {
            display: inline-flex; align-items: center; gap: .35rem;
            font-size: .8rem; font-weight: 500; cursor: pointer;
            color: rgb(29 78 216); background: none; border: none; padding: .2rem 0;
            transition: color .12s;
        }
        .gp247-kv-add:hover { color: rgb(30 58 138); }
        .dark .gp247-kv-add { color: rgb(147 197 253); }
        .dark .gp247-kv-add:hover { color: rgb(191 219 254); }
    </style>
@endassets

<div class="space-y-1">
    @if ($label)
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">
            {{ $label }}
            @if ($required)<span class="text-red-500">*</span>@endif
        </label>
    @endif

    {{-- Inline x-data (see WHY block above) — no external factory dependency. --}}
    <div
        class="gp247-kv{{ $error ? ' gp247-error' : '' }}"
        x-data="{
            model: @js($model),
            rows: [],
            uid: 0,
            init() {
                this.seed(this.$wire.get(this.model));
                this.$wire.$watch(this.model, v => { if (v !== this.serialize()) this.seed(v); });
            },
            blank() { return { _id: this.uid++, key: '', value: '' }; },
            seed(val) {
                let obj = {};
                try {
                    const parsed = JSON.parse(val || '{}');
                    if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) obj = parsed;
                } catch (e) {}
                const rows = Object.keys(obj).map(k => ({ _id: this.uid++, key: String(k), value: obj[k] == null ? '' : String(obj[k]) }));
                this.rows = rows.length ? rows : [this.blank()];
            },
            addRow() { this.rows.push(this.blank()); },
            removeRow(idx) {
                this.rows.splice(idx, 1);
                if (!this.rows.length) this.rows.push(this.blank());
                this.commit();
            },
            serialize() {
                const obj = {};
                this.rows.forEach(r => { const k = (r.key ?? '').trim(); if (k !== '') obj[k] = r.value ?? ''; });
                return JSON.stringify(obj);
            },
            commit() { this.$wire.set(this.model, this.serialize()); }
        }">

        <table class="gp247-kv-table">
            <thead>
                <tr>
                    <th class="gp247-kv-td-key">{{ $keyLabel }}</th>
                    <th>{{ $valueLabel }}</th>
                    <th class="gp247-kv-th-act"></th>
                </tr>
            </thead>
            <tbody>
                <template x-for="(row, idx) in rows" :key="row._id">
                    <tr data-testid="gp247-kv-row">
                        <td class="gp247-kv-td-key">
                            <input type="text" class="gp247-kv-input"
                                x-model="row.key" @input="commit()"
                                data-testid="gp247-kv-key" />
                        </td>
                        <td>
                            <input type="text" class="gp247-kv-input"
                                x-model="row.value" @input="commit()"
                                data-testid="gp247-kv-value" />
                        </td>
                        <td class="gp247-kv-td-act">
                            <button type="button" class="gp247-kv-del"
                                @click="removeRow(idx)"
                                title="{{ $removeTitle }}"
                                data-testid="gp247-kv-del">&times;</button>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>

        <button type="button" class="gp247-kv-add" @click="addRow()" data-testid="gp247-kv-add">
            <i class="fas fa-plus"></i> {{ $addLabel }}
        </button>
    </div>

    @if ($error)
        <p class="text-sm text-red-600 dark:text-red-400">{{ $error }}</p>
    @elseif ($help)
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $help }}</p>
    @endif
</div>
