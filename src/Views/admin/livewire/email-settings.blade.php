{{--
    Email / SMTP settings (ADR-005, amended 2026-09-05), mirroring the legacy
    two-column layout:
      - "Email mode" card: email_action_* keys + the global "Use SMTP" toggle.
      - "SMTP configuration" card: smtp_* keys, shown only while SMTP mode is on
        (Alpine x-show reads $wire.smtpMode on the client — instant show/hide, no
        server round-trip needed).
    All fields are edited in a deferred buffer and written only when the admin
    clicks Save: the store-scoped email_action_*/smtp_* keys via the inherited
    save(), and the global smtp_mode flag via the EmailSettingsForm::save() override.
    Nothing persists on change (no accidental writes from a stray toggle/typo).

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-UI-005, US-UI-008
    @aidlc-adr ADR-001, ADR-005

    Variables:
      - $configs (Collection of AdminConfig keyed by key)
      - $types (array<string,string>) key => bool|number|select|password|text
      - $options (array<string,array>) key => [value => label, ...], for "select" keys (smtp_security)
      - $modeKeys (string[]), $smtpKeys (string[])
      - $mailGuide (array{state,connection,cron}) — environment-aware delivery guidance
      - $storeScope (bool) — store-scope chrome visible (ROOT admin + multi-store; US-admin-shell-store-scope-ui-root-only)
      - $subStoreScope (bool) — ROOT admin is viewing a picked sub-store (drives inherit/reset badges)
      - $showGlobalSmtpToggle (bool) — show the global "Use SMTP" switch (ROOT base scope only)
--}}
@php
    // WHY: environment-aware reminder so the site owner picks the right delivery
    // setup (shared host vs VPS) without guessing (NFR-AVAIL-001, US-DEP-mail-queue-runner).
    $guideStyles = [
        'direct'       => 'border-gray-200 bg-gray-50 text-gray-700 dark:border-gray-700 dark:bg-gray-800/40 dark:text-gray-300',
        'queue_sync'   => 'border-sky-300 bg-sky-50 text-sky-800 dark:border-sky-500/40 dark:bg-sky-500/10 dark:text-sky-300',
        'queue_auto'   => 'border-emerald-300 bg-emerald-50 text-emerald-800 dark:border-emerald-500/40 dark:bg-emerald-500/10 dark:text-emerald-300',
        'queue_manual' => 'border-amber-300 bg-amber-50 text-amber-800 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-300',
    ];
    $guideStyle = $guideStyles[$mailGuide['state']] ?? $guideStyles['direct'];

    // WHY: placeholders tell the site owner the expected value/format per SMTP field
    // (esp. smtp_security tokens and smtp_port defaults, which map via SmtpTransport).
    // gp247_language_quickly keeps them translatable but needs no DB seed (English default).
    $smtpPlaceholders = [
        'smtp_host'     => gp247_language_quickly('email.config_smtp.smtp_host_ph', 'e.g. smtp.gmail.com'),
        'smtp_user'     => gp247_language_quickly('email.config_smtp.smtp_user_ph', 'e.g. you@example.com'),
        'smtp_password' => gp247_language_quickly('email.config_smtp.smtp_password_ph', 'Leave blank to keep the current password'),
        // smtp_security is a <select> (see fieldOptions) — no free-text placeholder.
        'smtp_port'     => gp247_language_quickly('email.config_smtp.smtp_port_ph', 'Blank = auto (465 for ssl, 587 for tls)'),
        'smtp_name'     => gp247_language_quickly('email.config_smtp.smtp_name_ph', 'Sender name, e.g. My Shop'),
        'smtp_from'     => gp247_language_quickly('email.config_smtp.smtp_from_ph', 'e.g. no-reply@example.com'),
    ];
@endphp
{{-- WHY: Livewire v3 requires exactly ONE root element per component. This view
     has two siblings (the delivery-guide banner + the settings grid), so wrap
     them in a single root <div> or it throws MultipleRootElementsDetectedException
     both as a full-page route and when embedded in SettingsHub. --}}
<div>
<div class="mb-4 rounded-lg border px-4 py-3 text-sm {{ $guideStyle }}">
    <div class="font-semibold">{{ gp247_language_render('admin.email_guide_heading') }}</div>
    <div class="mt-1">
        {{ gp247_language_render('admin.email_guide_current') }}:
        <code class="rounded bg-black/5 px-1 dark:bg-white/10">QUEUE_CONNECTION={{ $mailGuide['connection'] }}</code>
    </div>
    <div class="mt-1">{!! gp247_language_render('admin.email_guide_' . $mailGuide['state']) !!}</div>
    @if ($mailGuide['state'] === 'queue_auto')
        <pre class="mt-2 overflow-x-auto rounded bg-black/5 px-3 py-2 text-xs dark:bg-white/10"><code>{{ $mailGuide['cron'] }}</code></pre>
        <div class="mt-1 text-xs opacity-80">{{ gp247_language_render('admin.email_guide_optout') }}</div>
    @endif
</div>
@if ($storeScope)
    {{-- Store scope picker (US-AUI-core-config-store-scope): base tier = ROOT, so the
         first item is the root config; a bound store-admin sees their store read-only. --}}
    <div class="mb-4 rounded-xl border border-blue-200 bg-blue-50/60 p-4 dark:border-blue-900 dark:bg-blue-900/10">
        <label class="mb-1 block text-sm font-semibold text-gray-700 dark:text-gray-200">
            <i class="fas fa-store text-gray-400"></i> {{ gp247_language_render('admin.store.scope_label') }}
        </label>
        @if ($this->showStorePicker())
            <select wire:model.live="formStoreId" data-testid="config-form-store-select"
                class="w-full max-w-sm rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                <option value="">— {{ $this->scopeBaseLabel() }} —</option>
                @foreach ($this->storePickerOptions() as $sid => $stitle)
                    <option value="{{ $sid }}">{{ $stitle }}</option>
                @endforeach
            </select>
        @else
            <div class="max-w-sm rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300">
                <i class="fas fa-store text-gray-400"></i> {{ $this->currentStoreLabel() }}
            </div>
        @endif
    </div>
@endif
<div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
    {{-- Email mode --}}
    <x-gp247::card :title="gp247_language_render('admin.email_mode')">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                @foreach ($modeKeys as $key)
                    @php $config = $configs[$key] ?? null; @endphp
                    @if ($config)
                        <tr wire:key="cfg-{{ $key }}">
                            <td class="py-3 pr-4 align-middle text-sm text-gray-700 dark:text-gray-200">
                                {!! $config->detail ? gp247_language_render($config->detail) : e($key) !!}
                            </td>
                            <td class="py-3 align-middle">
                                @include('gp247-admin::partials.config-field', ['key' => $key, 'type' => $types[$key] ?? 'text'])
                                @include('gp247-admin::partials.config-store-badge', ['key' => $key, 'subStoreScope' => $subStoreScope])
                            </td>
                        </tr>
                    @endif
                @endforeach

                {{-- Global SMTP-mode toggle: "does this site use SMTP at all?" stays a
                     single GLOBAL flag managed only at the root (base) scope. It is
                     hidden at a sub-store scope so a store-admin never toggles the
                     system-wide switch (US-AUI-core-config-store-scope, decision 5.1).
                     Persisted only on Save (deferred); the SMTP card still shows/hides
                     instantly because Alpine reads $wire.smtpMode on the client. --}}
                @if ($showGlobalSmtpToggle)
                <tr wire:key="cfg-smtp_mode">
                    <td class="py-3 pr-4 align-middle text-sm text-gray-700 dark:text-gray-200">
                        {{ gp247_language_render('admin.use_smtp') }}
                    </td>
                    <td class="py-3 align-middle">
                        <label class="relative inline-flex h-6 w-11 cursor-pointer items-center">
                            <input type="checkbox" wire:model="smtpMode" class="peer sr-only">
                            <span class="absolute inset-0 rounded-full bg-gray-200 transition-colors peer-checked:bg-blue-600 peer-focus:ring-2 peer-focus:ring-blue-500 dark:bg-gray-600"></span>
                            <span class="absolute left-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform peer-checked:translate-x-5"></span>
                        </label>
                    </td>
                </tr>
                @endif

                {{-- Global mail-queue toggle: like smtp_mode, `email_action_queue` is a single
                     site-wide flag (queuing depends on the server's worker/cron, not per-store),
                     managed only at the ROOT base scope. Hidden at any sub-store so a store-admin
                     never flips it. Persisted only on Save. --}}
                @if ($showGlobalQueueToggle)
                <tr wire:key="cfg-email_action_queue">
                    <td class="py-3 pr-4 align-middle text-sm text-gray-700 dark:text-gray-200">
                        {!! gp247_language_render('email.email_action.email_action_queue') !!}
                    </td>
                    <td class="py-3 align-middle">
                        <label class="relative inline-flex h-6 w-11 cursor-pointer items-center">
                            <input type="checkbox" wire:model="queueMode" class="peer sr-only">
                            <span class="absolute inset-0 rounded-full bg-gray-200 transition-colors peer-checked:bg-blue-600 peer-focus:ring-2 peer-focus:ring-blue-500 dark:bg-gray-600"></span>
                            <span class="absolute left-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform peer-checked:translate-x-5"></span>
                        </label>
                    </td>
                </tr>
                @endif
            </tbody>
            <tfoot>
                <tr><td colspan="2" class="pt-3 text-xs text-gray-400 dark:text-gray-500">{{ gp247_language_render('admin.smtp_help') }}</td></tr>
            </tfoot>
        </table>
    </x-gp247::card>

    {{-- SMTP configuration (shown only while SMTP mode is on) --}}
    <div x-show="$wire.smtpMode" x-cloak x-transition>
        <x-gp247::card :title="gp247_language_render('admin.smtp_config')">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach ($smtpKeys as $key)
                        @php $config = $configs[$key] ?? null; @endphp
                        @if ($config)
                            <tr wire:key="cfg-{{ $key }}">
                                <td class="py-3 pr-4 align-middle text-sm text-gray-700 dark:text-gray-200">
                                    {!! $config->detail ? gp247_language_render($config->detail) : e($key) !!}
                                </td>
                                <td class="py-3 align-middle">
                                    @include('gp247-admin::partials.config-field', ['key' => $key, 'type' => $types[$key] ?? 'text', 'placeholder' => $smtpPlaceholders[$key] ?? '', 'options' => $options[$key] ?? []])
                                    @include('gp247-admin::partials.config-store-badge', ['key' => $key, 'subStoreScope' => $subStoreScope])
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </x-gp247::card>
    </div>
</div>

{{-- Explicit save: the email_action_* and smtp_* fields are edited in a deferred
     buffer (config-field uses wire:model, not .live) and only written on click, so
     nothing hits the DB by accident (ADR-005 amended 2026-09-05). The "Use SMTP"
     toggle above persists immediately on its own (deliberate single control). --}}
<div class="mt-6 flex justify-end">
    <button type="button" wire:click="save" data-testid="email-settings-submit"
        class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-medium text-white transition hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:opacity-60 dark:focus:ring-offset-gray-800"
        wire:loading.attr="disabled" wire:target="save">
        <i class="fas fa-save"></i>
        {{ gp247_language_render('admin.save') }}
    </button>
</div>
</div>
