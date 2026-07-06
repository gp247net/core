{{--
    GP247 TailAdmin data table shell (ADR-005).

    Provides the styled, horizontally-scrollable wrapper + a themed <thead> built
    from `headers`; rows go in the default slot as <tr> elements. This is the
    presentational base that DataTableComponent (Sub-bolt 2) renders into.

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-UI-001
    @aidlc-adr ADR-005

    @props array
      - headers (array): column header labels. Default [].
      - empty (string|null): message shown when the slot renders no rows.
    @slots default (table rows), head (override the auto <thead>)
--}}
@props([
    'headers' => [],
    'empty' => null,
])

<div {{ $attributes->merge(['class' => 'overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700']) }}>
    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
        @isset($head)
            <thead class="bg-gray-50 dark:bg-gray-800">{{ $head }}</thead>
        @elseif (count($headers))
            <thead class="bg-gray-50 dark:bg-gray-800">
                <tr>
                    @foreach ($headers as $header)
                        <th scope="col"
                            class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ $header }}
                        </th>
                    @endforeach
                </tr>
            </thead>
        @endif

        <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800">
            {{ $slot }}
        </tbody>
    </table>

    @if ($empty)
        {{-- WHY: rendered after the body so a caller can show it when no rows were emitted. --}}
        <p class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">{{ $empty }}</p>
    @endif
</div>
