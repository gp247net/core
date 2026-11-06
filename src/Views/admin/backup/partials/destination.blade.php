{{--
    "External destination" block of the backup settings (BackupDestinationSettings, Administrator only):
    type, fields, write-only secrets, connection test with the rights found, write-only guidance.

    @aidlc-unit backup-storage
    @aidlc-story US-backup-storage-s3-compatible, US-backup-storage-sftp, US-backup-storage-credential-secret, US-backup-storage-immutable-guide
    @aidlc-adr backup-storage_optional-adapters

    Variables: $types (array<string, DestinationType>), $definition (?DestinationType), $current (?array),
    $storedSecrets (list<string>), $shown (?ConnectionReport), $retention (?array), $stepUpNeedsCode (bool).
--}}
@php($inputCls = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100')
<div data-testid="backup-destination">
    <x-gp247::card :title="gp247_language_render('admin.backup.offsite.title')">
        <div class="space-y-4">
            @if ($notice)
                <x-gp247::alert :type="$noticeType" data-testid="backup-destination-notice">{{ $notice }}</x-gp247::alert>
            @endif
            <p class="text-sm text-gray-600 dark:text-gray-300">{{ gp247_language_render('admin.backup.offsite.intro') }}</p>

            <form wire:submit="save" class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <label class="block text-sm text-gray-700 dark:text-gray-200" style="grid-column: 1 / -1">
                    <span class="mb-1 block font-medium">{{ gp247_language_render('admin.backup.offsite.type') }}</span>
                    <select wire:model.live="type" class="{{ $inputCls }}" data-testid="backup-destination-type">
                        <option value="">{{ gp247_language_render('admin.backup.offsite.type.none') }}</option>
                        @foreach ($types as $key => $option)
                            <option value="{{ $key }}" @disabled(!$option->available() && ($current['type'] ?? '') !== $key)>
                                {{ gp247_language_render($option->label) }}{{ $option->available() ? '' : ' — ' . gp247_language_render('admin.backup.offsite.unavailable') }}
                            </option>
                        @endforeach
                    </select>
                </label>
                @foreach ($types as $key => $option)
                    @if (!$option->available())
                        <p class="text-xs text-gray-500 dark:text-gray-400" style="grid-column: 1 / -1" data-testid="backup-destination-unavailable-{{ $key }}">
                            {{ gp247_language_render('admin.backup.offsite.install', ['type' => gp247_language_render($option->label)]) }}
                            <code class="font-mono">{{ $option->install }}</code>
                        </p>
                    @endif
                @endforeach

                @if ($definition)
                    @foreach ($definition->fields as $name => $field)
                        @if ($field['kind'] === 'bool')
                            <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200" style="grid-column: 1 / -1">
                                <x-gp247::checkbox wire:model="fields.{{ $name }}" data-testid="backup-destination-field-{{ $name }}" />
                                {{ gp247_language_render('admin.backup.offsite.field.' . $name) }}
                            </label>
                        @else
                            <x-gp247::input :type="$field['kind'] === 'number' ? 'number' : 'text'" name="fields.{{ $name }}" wire:model="fields.{{ $name }}"
                                :label="gp247_language_render('admin.backup.offsite.field.' . $name)" :required="!empty($field['required'])"
                                :help="gp247_language_render('admin.backup.offsite.help.' . $name) === 'admin.backup.offsite.help.' . $name ? null : gp247_language_render('admin.backup.offsite.help.' . $name)"
                                :error="$errors->first('fields.' . $name)" data-testid="backup-destination-field-{{ $name }}" />
                        @endif
                    @endforeach
                    @foreach ($definition->secrets as $name => $field)
                        <div @if ($field['kind'] === 'textarea') style="grid-column: 1 / -1" @endif>
                            @if ($field['kind'] === 'textarea')
                                <label class="block text-sm text-gray-700 dark:text-gray-200">
                                    <span class="mb-1 block font-medium">{{ gp247_language_render('admin.backup.offsite.field.' . $name) }}</span>
                                    <textarea wire:model="secretInputs.{{ $name }}" rows="4" autocomplete="off" spellcheck="false" class="{{ $inputCls }} font-mono"
                                        placeholder="{{ in_array($name, $storedSecrets, true) ? gp247_language_render('admin.backup.offsite.secret_kept') : '' }}"
                                        data-testid="backup-destination-secret-{{ $name }}"></textarea>
                                </label>
                            @else
                                <x-gp247::input type="password" name="secretInputs.{{ $name }}" wire:model="secretInputs.{{ $name }}" autocomplete="new-password"
                                    :label="gp247_language_render('admin.backup.offsite.field.' . $name)"
                                    :placeholder="in_array($name, $storedSecrets, true) ? gp247_language_render('admin.backup.offsite.secret_kept') : ''"
                                    :error="$errors->first('secretInputs.' . $name)" data-testid="backup-destination-secret-{{ $name }}" />
                            @endif
                            @if (in_array($name, $storedSecrets, true))
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400" data-testid="backup-destination-secret-stored-{{ $name }}">{{ gp247_language_render('admin.backup.offsite.secret_stored') }}</p>
                            @endif
                        </div>
                    @endforeach
                    <p class="text-xs text-gray-500 dark:text-gray-400" style="grid-column: 1 / -1">{{ gp247_language_render('admin.backup.offsite.secret_help') }}</p>
                @endif

                <div class="flex flex-wrap gap-2" style="grid-column: 1 / -1">
                    @if ($definition)
                        <x-gp247::button type="submit" data-testid="backup-destination-save">{{ gp247_language_render('admin.backup.offsite.save') }}</x-gp247::button>
                        <x-gp247::button type="button" variant="secondary" wire:click="testConnection" data-testid="backup-destination-test">{{ gp247_language_render('admin.backup.offsite.test') }}</x-gp247::button>
                    @endif
                    @if ($current)
                        <x-gp247::button type="button" variant="danger" wire:click="remove" wire:confirm="{{ gp247_language_render('admin.backup.offsite.remove_confirm') }}" data-testid="backup-destination-remove">{{ gp247_language_render('admin.backup.offsite.remove') }}</x-gp247::button>
                    @endif
                </div>
            </form>

            @if ($shown)
                <div class="space-y-3 rounded-lg border border-gray-200 p-4 dark:border-gray-700" data-testid="backup-destination-report">
                    <p class="text-sm font-medium text-gray-800 dark:text-gray-100">{{ gp247_language_render('admin.backup.offsite.report') }}</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach (['write', 'read', 'list', 'delete'] as $right)
                            @php($has = $shown->capabilities->toArray()[$right])
                            <x-gp247::badge :color="$has ? ($right === 'delete' ? 'amber' : 'green') : ($right === 'delete' ? 'green' : 'red')" data-testid="backup-destination-right-{{ $right }}">
                                {{ gp247_language_render('admin.backup.offsite.right.' . $right) }}: {{ gp247_language_render($has ? 'admin.backup.offsite.yes' : 'admin.backup.offsite.no') }}
                            </x-gp247::badge>
                        @endforeach
                    </div>
                    @if ($shown->error)
                        <x-gp247::alert type="error" data-testid="backup-destination-error">{{ gp247_language_render('admin.backup.offsite.error.' . $shown->error) }}</x-gp247::alert>
                    @elseif (!$shown->capabilities->usable())
                        <x-gp247::alert type="error" data-testid="backup-destination-missing">{{ gp247_language_render('admin.backup.offsite.missing', ['rights' => implode(', ', array_map(fn ($r) => gp247_language_render('admin.backup.offsite.right.' . $r), $shown->capabilities->missing()))]) }}</x-gp247::alert>
                    @elseif ($shown->capabilities->delete)
                        <x-gp247::alert type="warning" data-testid="backup-destination-recommend-write-only">{{ gp247_language_render('admin.backup.offsite.recommend_write_only') }}</x-gp247::alert>
                    @else
                        <x-gp247::alert type="success" data-testid="backup-destination-write-only">{{ gp247_language_render('admin.backup.offsite.write_only_ok') }}</x-gp247::alert>
                    @endif
                    @if ($shown->fingerprint)
                        <p class="text-xs text-gray-600 dark:text-gray-300">{{ gp247_language_render('admin.backup.offsite.fingerprint') }}
                            <span class="break-all font-mono" data-testid="backup-destination-fingerprint">{{ $shown->fingerprint }}</span></p>
                        @if (($current['fingerprint'] ?? null) && $current['fingerprint'] !== $shown->fingerprint)
                            <p class="text-xs text-gray-600 dark:text-gray-300">{{ gp247_language_render('admin.backup.offsite.fingerprint_pinned') }}
                                <span class="break-all font-mono">{{ $current['fingerprint'] }}</span></p>
                            <x-gp247::button size="sm" variant="danger" wire:click="acceptHostKey" data-testid="backup-destination-hostkey-accept">{{ gp247_language_render('admin.backup.offsite.host_key_accept') }}</x-gp247::button>
                        @endif
                    @endif
                </div>
            @endif

            @if ($retention && $retention['skipped'] > 0)
                <x-gp247::alert type="info" data-testid="backup-destination-retention-skipped">{{ gp247_language_render('admin.backup.offsite.retention_skipped', ['count' => $retention['skipped']]) }}</x-gp247::alert>
            @endif

            <details class="rounded-lg border border-gray-200 p-4 text-sm text-gray-700 dark:border-gray-700 dark:text-gray-200" data-testid="backup-destination-guide">
                <summary class="cursor-pointer font-medium">{{ gp247_language_render('admin.backup.offsite.guide.title') }}</summary>
                <div class="mt-3 space-y-3">
                    <p>{{ gp247_language_render('admin.backup.offsite.guide.why') }}</p>
                    <p>{{ gp247_language_render('admin.backup.offsite.guide.aws') }}</p>
                    <pre class="overflow-x-auto rounded-lg border border-gray-200 p-3 font-mono text-xs dark:border-gray-700" data-testid="backup-destination-policy">{{ json_encode(['Version' => '2012-10-17', 'Statement' => [
                        ['Effect' => 'Allow', 'Action' => ['s3:PutObject', 's3:GetObject'], 'Resource' => 'arn:aws:s3:::BUCKET/*'],
                        ['Effect' => 'Allow', 'Action' => ['s3:ListBucket'], 'Resource' => 'arn:aws:s3:::BUCKET'],
                    ]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    <p>{{ gp247_language_render('admin.backup.offsite.guide.r2') }}</p>
                    <p>{{ gp247_language_render('admin.backup.offsite.guide.b2') }}</p>
                    <p>{{ gp247_language_render('admin.backup.offsite.guide.sftp') }}</p>
                    <p>{{ gp247_language_render('admin.backup.offsite.guide.lifecycle') }}</p>
                </div>
            </details>
        </div>
    </x-gp247::card>

    @include('gp247-admin::backup.partials.step-up')
</div>
