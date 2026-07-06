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
--}}
<div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
    {{-- Email mode --}}
    <x-gp247::card :title="gp247_language_render('admin.core.email_mode')">
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
                        {{ gp247_language_render('admin.core.use_smtp') }}
                    </td>
                    <td class="py-3 align-middle">
                        <x-gp247::checkbox wire:model.live="smtpMode" />
                    </td>
                </tr>
            </tbody>
            <tfoot>
                <tr><td colspan="2" class="pt-3 text-xs text-gray-400 dark:text-gray-500">{{ gp247_language_render('admin.core.smtp_help') }}</td></tr>
            </tfoot>
        </table>
    </x-gp247::card>

    {{-- SMTP configuration (shown only while SMTP mode is on) --}}
    <div x-show="$wire.smtpMode" x-cloak x-transition>
        <x-gp247::card :title="gp247_language_render('admin.core.smtp_config')">
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
