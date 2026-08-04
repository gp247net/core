{{--
    Email / SMTP settings (ADR-005), mirroring the legacy two-column layout:
      - "Email mode" card: email_action_* keys + the global "Use SMTP" toggle.
      - "SMTP configuration" card: smtp_* keys, shown only while SMTP mode is on
        (Alpine x-show bound to the live $smtpMode property — no reload).
    Each store-scoped value persists live (checkbox / number-blur / text-blur);
    the SMTP-mode toggle persists to the global config group.

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-UI-005, US-UI-008
    @aidlc-adr ADR-001, ADR-005

    Variables:
      - $configs (Collection of AdminConfig keyed by key)
      - $types (array<string,string>) key => bool|number|text
      - $modeKeys (string[]), $smtpKeys (string[])
      - $mailGuide (array{state,connection,cron}) — environment-aware delivery guidance
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
@endphp
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
                            </td>
                        </tr>
                    @endif
                @endforeach

                {{-- Global SMTP-mode toggle: controls visibility of the SMTP card. --}}
                <tr wire:key="cfg-smtp_mode">
                    <td class="py-3 pr-4 align-middle text-sm text-gray-700 dark:text-gray-200">
                        {{ gp247_language_render('admin.use_smtp') }}
                    </td>
                    <td class="py-3 align-middle">
                        <x-gp247::checkbox wire:model.live="smtpMode" />
                    </td>
                </tr>
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
                                    @include('gp247-admin::partials.config-field', ['key' => $key, 'type' => $types[$key] ?? 'text'])
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </x-gp247::card>
    </div>
</div>
