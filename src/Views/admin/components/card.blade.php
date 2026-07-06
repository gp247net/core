{{--
    GP247 TailAdmin card / panel (ADR-005).

    A surface container with an optional header (driven by `title`/`subtitle` or a
    `header` slot) and an optional `footer` slot. Body is the default slot.

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-UI-001
    @aidlc-adr ADR-005

    @props array
      - title (string|null): header heading.
      - subtitle (string|null): muted line under the title.
    @slots header, footer
--}}
@props([
    'title' => null,
    'subtitle' => null,
])

<div {{ $attributes->merge(['class' => 'rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800']) }}>
    @if (isset($header) || $title)
        <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4 dark:border-gray-700">
            @isset($header)
                {{ $header }}
            @else
                <div>
                    {{-- WHY: titles come from language packs (gp247_language_render) which translators may decorate with inline HTML — same convention as legacy {!! gp247_language_render() !!}. --}}
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-100">{!! $title !!}</h3>
                    @if ($subtitle)
                        <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">{!! $subtitle !!}</p>
                    @endif
                </div>
            @endisset
        </div>
    @endif

    <div class="p-5">
        {{ $slot }}
    </div>

    @isset($footer)
        <div class="border-t border-gray-200 px-5 py-4 dark:border-gray-700">
            {{ $footer }}
        </div>
    @endisset
</div>
