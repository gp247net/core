{{--
    "Personal data requests" screen (US-personal-data-requests-screen): requests of every channel
    with status and due date, filters, the detail of one request, an administrator creating an
    erasure request for a customer, and the tables of customer data nobody declared.
    Only class combinations already in the prebuilt admin bundle are used (gp247.md §3a).

    @aidlc-unit personal-data-requests
    @aidlc-story US-personal-data-requests-screen, US-personal-data-requests-admin-create, US-personal-data-registry-undeclared-detection
    @aidlc-adr personal-data-requests_request-model

    Variables: $ready (bool), $rows (paginator|null), $statuses, $detail (array|null), $undeclared, $channels, $stepUpMinutes.
--}}
@php($inputCls = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100')
@php($color = fn ($status) => match ($status) { 'completed' => 'green', 'failed' => 'red', 'on_hold', 'pending_approval' => 'amber', 'rejected', 'cancelled' => 'gray', default => 'blue' })
<div class="space-y-6" data-testid="personal-data-requests-page">
    @unless ($ready)
        <x-gp247::alert type="warning">{{ gp247_language_render('admin.personal_data.not_ready') }}</x-gp247::alert>
    @else
        {{-- New erasure request for a customer --}}
        <x-gp247::card :title="gp247_language_render('admin.personal_data.requests.create_title')">
            <div class="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-4">
                <input type="search" wire:model="createCustomer" data-testid="personal-data-requests-create-customer"
                    placeholder="{{ gp247_language_render('admin.personal_data.requests.customer_search') }}" class="{{ $inputCls }}">
                <x-gp247::button size="sm" variant="secondary" wire:click="findCustomer" data-testid="personal-data-requests-create-find">{{ gp247_language_render('admin.personal_data.requests.find') }}</x-gp247::button>
            </div>
            @if ($createFound)
                <div class="mt-4 space-y-3 text-sm text-gray-700 dark:text-gray-200" data-testid="personal-data-requests-create-form">
                    <p data-testid="personal-data-requests-create-found">{{ $createFound['email'] ?: ('#' . $createFound['id']) }} @if ($createFound['name']) — {{ $createFound['name'] }} @endif</p>
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                        <select wire:model.live="createKind" data-testid="personal-data-requests-create-kind" class="{{ $inputCls }}">
                            <option value="erase">{{ gp247_language_render('admin.personal_data.requests.kind.erase') }}</option>
                            <option value="export">{{ gp247_language_render('admin.personal_data.requests.kind.export') }}</option>
                        </select>
                        <select wire:model="createChannel" data-testid="personal-data-requests-create-channel" class="{{ $inputCls }}">
                            @foreach ($channels as $channel)
                                <option value="{{ $channel }}">{{ gp247_language_render('admin.personal_data.requests.channel.' . $channel) }}</option>
                            @endforeach
                        </select>
                        <input type="text" wire:model="createNote" maxlength="1000" data-testid="personal-data-requests-create-note"
                            placeholder="{{ gp247_language_render('admin.personal_data.requests.note') }}" class="{{ $inputCls }}">
                    </div>
                    @if ($createKind === 'export')
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.personal_data.requests.export_hint') }}</p>
                        <x-gp247::button size="sm" variant="primary" wire:click="createRequest" data-testid="personal-data-requests-create-submit">{{ gp247_language_render('admin.personal_data.requests.create_export') }}</x-gp247::button>
                    @else
                        <label class="inline-flex items-center gap-2">
                            <x-gp247::checkbox wire:model="createNow" value="1" data-testid="personal-data-requests-create-now" />
                            {{ gp247_language_render('admin.personal_data.requests.now') }}
                        </label>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.personal_data.requests.now_hint') }}</p>
                        <x-gp247::button size="sm" variant="danger" wire:click="createRequest" data-testid="personal-data-requests-create-submit">{{ gp247_language_render('admin.personal_data.requests.create') }}</x-gp247::button>
                    @endif
                </div>
            @endif
            @if ($blockers !== [])
                <div class="mt-4" data-testid="personal-data-requests-blocked">
                    <x-gp247::alert type="warning">{{ gp247_language_render('admin.customer.erase.blocked') }}</x-gp247::alert>
                    <ul class="mt-2 list-disc space-y-1 ps-5 text-sm">
                        @foreach ($blockers as $blocker)
                            <li>{{ $blocker['label'] }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </x-gp247::card>

        {{-- Requests --}}
        <x-gp247::card :title="gp247_language_render('admin.personal_data.requests.title')">
            <div class="mb-4 grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-4">
                <select wire:model.live="filterStatus" data-testid="personal-data-requests-filter-status" class="{{ $inputCls }}">
                    <option value="">{{ gp247_language_render('admin.personal_data.requests.all_statuses') }}</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status }}">{{ gp247_language_render('admin.personal_data.requests.status.' . $status) }}</option>
                    @endforeach
                </select>
                <x-gp247::search-box :placeholder="gp247_language_render('admin.personal_data.requests.search')" data-testid="personal-data-requests-filter-keyword" />
                <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                    <x-gp247::checkbox wire:model.live="filterOverdue" value="1" data-testid="personal-data-requests-filter-overdue" />
                    {{ gp247_language_render('admin.personal_data.requests.overdue_only') }}
                </label>
            </div>

            <x-gp247::table :empty="$rows->isEmpty() ? gp247_language_render('admin.personal_data.requests.empty') : null">
                <x-slot:head>
                    <tr>
                        @foreach (['id', 'kind', 'source', 'customer', 'status', 'received_at', 'due_at'] as $column)
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.personal_data.requests.col.' . $column) }}</th>
                        @endforeach
                    </tr>
                </x-slot:head>
                @foreach ($rows as $row)
                    <tr class="cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700/50" wire:key="pdr-{{ $row->id }}" wire:click="select('{{ $row->id }}')" data-testid="personal-data-requests-row">
                        <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400">{{ $row->id }}</td>
                        <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-200">{{ gp247_language_render('admin.personal_data.requests.kind.' . $row->kind) }}</td>
                        <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-200">{{ gp247_language_render('admin.personal_data.requests.source.' . $row->source) }}</td>
                        <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-200">{{ $row->customer_id !== null ? '#' . $row->customer_id : '—' }}</td>
                        <td class="px-4 py-3"><x-gp247::badge :color="$color($row->status)">{{ gp247_language_render('admin.personal_data.requests.status.' . $row->status) }}</x-gp247::badge></td>
                        <td class="whitespace-nowrap px-4 py-3 text-xs text-gray-500 dark:text-gray-400">{{ $row->received_at }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-xs {{ !in_array($row->status, ['completed', 'rejected', 'cancelled'], true) && $row->due_at < now() ? 'text-red-600' : 'text-gray-500 dark:text-gray-400' }}">{{ $row->due_at }}</td>
                    </tr>
                @endforeach
            </x-gp247::table>
            <div class="mt-4">{{ $rows->links('gp247-admin::partials.pagination') }}</div>
        </x-gp247::card>

        {{-- One request --}}
        @if ($detail)
            @php($req = $detail['request'])
            <x-gp247::card :title="gp247_language_render('admin.personal_data.requests.detail_title', ['id' => $req->id])" data-testid="personal-data-requests-detail">
                <div class="space-y-4 text-sm text-gray-700 dark:text-gray-200">
                    <p>
                        <x-gp247::badge :color="$color($req->status)">{{ gp247_language_render('admin.personal_data.requests.status.' . $req->status) }}</x-gp247::badge>
                        @if ($req->wait_until && $req->status === 'waiting') · {{ gp247_language_render('admin.personal_data.requests.runs_at', ['at' => $req->wait_until]) }} @endif
                    </p>
                    @if ($detail['customer'])
                        <p>{{ $detail['customer']['email'] ?: ('#' . $detail['customer']['id']) }} @if ($detail['customer']['name']) — {{ $detail['customer']['name'] }} @endif</p>
                    @endif
                    @if ($req->note)
                        <p class="text-gray-500 dark:text-gray-400">{{ $req->note }}</p>
                    @endif
                    @if ($detail['data'] !== [])
                        <ul class="space-y-1" data-testid="personal-data-requests-detail-data">
                            @foreach ($detail['data'] as $item)
                                <li class="flex justify-between gap-4"><span>{{ $item['label'] }}</span><span class="text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.customer.erase.rule_' . $item['rule'], ['rows' => $item['rows']]) }}</span></li>
                            @endforeach
                        </ul>
                    @endif
                    @if ($detail['package'])
                        @php($package = $detail['package'])
                        <div data-testid="personal-data-requests-package" @if ($package->status === 'building') wire:poll.3s="advanceExport('{{ $req->id }}')" @endif>
                            <p>{{ gp247_language_render('admin.personal_data.requests.package.' . $package->status) }}
                                @if ($package->status === 'ready' && $package->expires_at) · {{ gp247_language_render('admin.personal_data.requests.package_expires', ['at' => $package->expires_at]) }} @endif</p>
                            @if ($package->status === 'ready')
                                <x-gp247::button size="sm" variant="primary" class="mt-2" wire:click="downloadExport('{{ $req->id }}')" data-testid="personal-data-requests-download">{{ gp247_language_render('admin.personal_data.requests.download') }}</x-gp247::button>
                            @endif
                        </div>
                    @endif
                    <div class="flex flex-wrap gap-2">
                        @if ($req->kind !== 'erase')
                        @elseif (in_array($req->status, ['waiting', 'on_hold'], true))
                            <x-gp247::button size="sm" variant="danger" wire:click="executeRequest('{{ $req->id }}')" data-testid="personal-data-requests-execute">{{ gp247_language_render('admin.personal_data.requests.execute') }}</x-gp247::button>
                            <x-gp247::button size="sm" variant="secondary" wire:click="cancelRequest('{{ $req->id }}')" data-testid="personal-data-requests-cancel">{{ gp247_language_render('admin.personal_data.requests.cancel') }}</x-gp247::button>
                        @elseif ($req->status === 'failed')
                            <x-gp247::button size="sm" variant="warning" wire:click="retryRequest('{{ $req->id }}')" data-testid="personal-data-requests-retry">{{ gp247_language_render('admin.personal_data.requests.retry') }}</x-gp247::button>
                        @endif
                    </div>
                    <div>
                        <p class="mb-2 font-medium">{{ gp247_language_render('admin.personal_data.requests.history') }}</p>
                        <ul class="space-y-1 text-xs text-gray-500 dark:text-gray-400" data-testid="personal-data-requests-history">
                            @foreach ($detail['log'] as $entry)
                                <li>{{ $entry->created_at }} · {{ $entry->event }} · {{ $entry->actor_type }}@if ($entry->actor_id) #{{ $entry->actor_id }}@endif @if ($entry->reason) · {{ $entry->reason }}@endif</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </x-gp247::card>
        @endif

        {{-- Tables of customer data nobody declared --}}
        <x-gp247::card :title="gp247_language_render('admin.personal_data.requests.undeclared_title')">
            @if (!$showUndeclared)
                <x-gp247::button size="sm" variant="secondary" wire:click="checkUndeclared" data-testid="personal-data-requests-undeclared-show">{{ gp247_language_render('admin.personal_data.requests.undeclared_check') }}</x-gp247::button>
            @elseif ($undeclared === [])
                <p class="text-sm text-gray-600 dark:text-gray-300" data-testid="personal-data-requests-undeclared-none">{{ gp247_language_render('admin.personal_data.requests.undeclared_none') }}</p>
            @else
                <p class="mb-3 text-sm text-gray-600 dark:text-gray-300">{{ gp247_language_render('admin.personal_data.requests.undeclared_help') }}</p>
                <ul class="space-y-2 text-sm" data-testid="personal-data-requests-undeclared">
                    @foreach ($undeclared as $index => $item)
                        <li class="flex flex-wrap items-center justify-between gap-2">
                            <span><span class="font-medium">{{ $item['table'] }}</span> <span class="text-xs text-gray-500 dark:text-gray-400">({{ implode(', ', $item['columns']) }})</span></span>
                            <x-gp247::button size="sm" variant="secondary" wire:click="markTable({{ (int) $index }}, '{{ $item['table'] }}')" data-testid="personal-data-requests-undeclared-mark">{{ gp247_language_render('admin.personal_data.requests.not_customer_data') }}</x-gp247::button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-gp247::card>
    @endunless

    <x-gp247::step-up-box :open="$stepUpOpen" :needs-code="$this->stepUpAsksCode()" :error="$stepUpError"
        :minutes="$stepUpMinutes" testid="personal-data-requests-step-up" />
</div>
