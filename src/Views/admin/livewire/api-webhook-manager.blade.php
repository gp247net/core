{{--
    Webhooks block of the API screen (administrators only): form (URL + followed event
    types + on/off), the signing secret shown once, the list and the last deliveries.
    Classes reuse the API screen's own (the core CSS bundle is prebuilt).

    @aidlc-unit api-sync
    @aidlc-story US-api-sync-webhook-register
    @aidlc-adr api-sync_webhook-deferred-delivery

    Variables: $webhooks, $types (string[]), $deliveries, $revealedSecret (string|null).
--}}
<div data-testid="api-sync-webhook-panel">
    <x-gp247::card>
        <div class="mb-4 flex items-start justify-between gap-4 border-b border-gray-100 pb-4 dark:border-gray-700">
            <div>
                <p class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ gp247_language_render('admin.api_webhook.title') }}</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.api_webhook.help') }}</p>
            </div>
            <x-gp247::form-new-button :editing="$editingId" action="resetForm" data-testid="api-sync-webhook-form-new" />
        </div>

        <form wire:submit="save" class="space-y-4">
            <x-gp247::input type="text" wire:model="url" :label="gp247_language_render('admin.api_webhook.url')"
                placeholder="https://erp.example.com/gp247-webhook" :error="$errors->first('url')" data-testid="api-sync-webhook-url" />

            <div>
                <p class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ gp247_language_render('admin.api_webhook.event_types') }}</p>
                <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                    @foreach ($types as $type)
                        <label class="flex items-center gap-3 text-sm text-gray-700 dark:text-gray-200">
                            <input type="checkbox" wire:model="eventTypes" value="{{ $type }}" data-testid="api-sync-webhook-type-{{ $type }}">
                            <code class="font-mono">{{ $type }}</code>
                        </label>
                    @endforeach
                </div>
                @if ($errors->first('eventTypes') || $errors->first('eventTypes.*'))
                    <p class="mt-1 text-xs text-red-600">{{ $errors->first('eventTypes') ?: $errors->first('eventTypes.*') }}</p>
                @endif
            </div>

            <div class="flex items-center gap-3">
                <span class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ gp247_language_render('admin.api_webhook.status') }}</span>
                <label class="relative inline-flex h-6 w-11 cursor-pointer items-center">
                    <input type="checkbox" wire:model="active" class="peer sr-only" data-testid="api-sync-webhook-active">
                    <span class="absolute inset-0 rounded-full bg-gray-200 transition-colors peer-checked:bg-blue-600 peer-focus:ring-2 peer-focus:ring-blue-500 dark:bg-gray-600"></span>
                    <span class="absolute left-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform peer-checked:translate-x-5"></span>
                </label>
            </div>

            @if ($revealedSecret)
                <div class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-600 dark:bg-amber-900/20 dark:text-amber-200"
                    data-testid="api-sync-webhook-secret-once">
                    <p class="font-medium">{{ gp247_language_render('admin.api_webhook.secret_once') }}</p>
                    <code class="mt-1 block break-all font-mono">{{ $revealedSecret }}</code>
                </div>
            @endif

            <div class="flex justify-end gap-2 border-t border-gray-100 pt-4 dark:border-gray-700">
                <x-gp247::button variant="primary" size="sm" type="submit" data-testid="api-sync-webhook-save">
                    {{ gp247_language_render('action.submit') }}
                </x-gp247::button>
            </div>
        </form>

        <div class="mt-4 overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
            <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-200">{{ gp247_language_render('admin.api_webhook.url') }}</th>
                        <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-200">{{ gp247_language_render('admin.api_webhook.event_types') }}</th>
                        <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-200">{{ gp247_language_render('admin.api_webhook.status') }}</th>
                        <th class="px-3 py-2 text-right font-semibold text-gray-700 dark:text-gray-200">{{ gp247_language_render('action.title') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse ($webhooks as $webhook)
                        <tr wire:key="webhook-{{ $webhook->id }}" data-testid="api-sync-webhook-row-{{ $webhook->id }}"
                            class="{{ $editingId === (int) $webhook->id ? 'bg-blue-50 dark:bg-blue-900/20' : '' }}">
                            <td class="px-3 py-2 text-gray-700 dark:text-gray-200"><span class="break-all">{{ $webhook->url }}</span></td>
                            <td class="px-3 py-2 text-gray-500 dark:text-gray-400">{{ implode(', ', (array) $webhook->event_types) }}</td>
                            <td class="px-3 py-2">
                                @if ($webhook->status)
                                    <x-gp247::badge color="green">{{ gp247_language_render('admin.on') }}</x-gp247::badge>
                                @else
                                    <x-gp247::badge color="red">{{ gp247_language_render('admin.off') }}</x-gp247::badge>
                                    @if ($webhook->disabled_reason)
                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.api_webhook.auto_disabled') }}</p>
                                    @endif
                                @endif
                            </td>
                            <td class="px-3 py-2 text-right">
                                <div class="inline-flex gap-1">
                                    <x-gp247::button variant="secondary" size="sm" wire:click="toggleHistory({{ $webhook->id }})" data-testid="api-sync-webhook-history-{{ $webhook->id }}">
                                        <i class="fas fa-list"></i>
                                    </x-gp247::button>
                                    <x-gp247::button variant="secondary" size="sm" wire:click="edit({{ $webhook->id }})">
                                        <i class="fas fa-edit"></i>
                                    </x-gp247::button>
                                    <x-gp247::button variant="secondary" size="sm" wire:click="regenerateSecret({{ $webhook->id }})"
                                        wire:confirm="{{ gp247_language_render('admin.api_webhook.regenerate_confirm') }}" data-testid="api-sync-webhook-regenerate-{{ $webhook->id }}">
                                        <i class="fas fa-key"></i>
                                    </x-gp247::button>
                                    <x-gp247::button variant="danger" size="sm" wire:click="delete({{ $webhook->id }})"
                                        wire:confirm="{{ gp247_language_render('admin.confirm_delete') }}" data-testid="api-sync-webhook-delete-{{ $webhook->id }}">
                                        <i class="fas fa-trash-alt"></i>
                                    </x-gp247::button>
                                </div>
                            </td>
                        </tr>
                        @if ($historyFor === (int) $webhook->id)
                            <tr wire:key="webhook-history-{{ $webhook->id }}">
                                <td colspan="4" class="px-3 py-2">
                                    <p class="text-xs font-semibold text-gray-600 dark:text-gray-300">{{ gp247_language_render('admin.api_webhook.deliveries') }}</p>
                                    @forelse ($deliveries as $delivery)
                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400" data-testid="api-sync-webhook-delivery">
                                            #{{ $delivery->event_id }} · {{ gp247_language_render('admin.api_webhook.delivery_' . $delivery->status) }}
                                            · {{ $delivery->attempts }}× · {{ $delivery->last_status_code ?? '-' }} {{ $delivery->last_error }} · {{ $delivery->updated_at }}
                                        </p>
                                    @empty
                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.no_records') }}</p>
                                    @endforelse
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr><td colspan="4" class="px-3 py-4 text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.no_records') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-gp247::card>
</div>
