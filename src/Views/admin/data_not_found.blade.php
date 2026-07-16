{{--
    Data-not-found page (US-AUI-008 gap) — ported to the AdminShell TailAdmin
    plain layout. Keeps the legacy pushState behaviour for the original URL.

    @aidlc-unit admin-shell
    @aidlc-story US-AUI-008
    @aidlc-adr ADR-002

    Variables: $url.
--}}
@extends('gp247-admin::layouts.plain')

@section('main')
<div class="flex min-h-[40vh] flex-col items-center justify-center gap-3 text-center">
    <span class="flex h-14 w-14 items-center justify-center rounded-full bg-amber-50 text-amber-500 dark:bg-amber-500/10">
        <i class="fas fa-exclamation text-xl"></i>
    </span>
    <h4 class="text-lg font-semibold text-gray-700 dark:text-gray-200">{{ gp247_language_render('admin.display.data_not_found_msg') }}</h4>
    @if ($url)
        <code class="rounded bg-gray-100 px-2 py-1 text-sm text-gray-600 dark:bg-gray-700 dark:text-gray-300">{{ $url }}</code>
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
