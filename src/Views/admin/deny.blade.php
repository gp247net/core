{{--
    Access-denied (403) page (US-AUI-008 gap) — ported to the AdminShell TailAdmin
    plain layout. Keeps the legacy pushState behaviour for the original URL.

    @aidlc-unit admin-shell
    @aidlc-story US-AUI-008
    @aidlc-adr ADR-002

    Variables: $url, $method.
--}}
@extends('gp247-admin::layouts.plain')

@section('main')
<div class="flex min-h-[40vh] flex-col items-center justify-center gap-3 text-center">
    <span class="flex h-14 w-14 items-center justify-center rounded-full bg-red-50 text-red-500 dark:bg-red-500/10">
        <i class="fas fa-ban text-xl"></i>
    </span>
    <h2 class="text-xl font-semibold text-red-600 dark:text-red-400">403 — {{ gp247_language_render('admin.deny_content') }}</h2>
    @if ($url)
        <p class="text-sm text-gray-600 dark:text-gray-300">{{ gp247_language_render('admin.deny_msg') }}</p>
        <p class="text-xs text-gray-500 dark:text-gray-400">
            <strong>URL:</strong> <code class="rounded bg-gray-100 px-1.5 py-0.5 dark:bg-gray-700">{{ $url }}</code>
            <strong class="ml-2">Method:</strong> <code class="rounded bg-gray-100 px-1.5 py-0.5 dark:bg-gray-700">{{ $method }}</code>
        </p>
    @endif
</div>
@endsection

@push('scripts')
@if ($url)
<script>
  window.history.pushState("", "", '{{ $url }}');
</script>
@endif
@endpush
