{{--
    GP247 environment-identity badge (admin shell).

    Shows the current runtime environment (production vs. non-production) so an
    operator can tell at a glance which environment they are in — a visual guard
    against destructive actions on a live site (security, not cosmetics).

    Two independent signals, split across the shell via the `only` prop:
      - env pill  → rendered in the FOOTER (only="env"): production is neutral
        (gray); anything else shares one non-production style (amber) with the raw
        env name as label. Classification is binary on purpose — see
        gp247_env_badge() / config('gp247.env_badge').
      - DEBUG ON chip → rendered in the HEADER (only="debug"): shown whenever
        APP_DEBUG is on, regardless of env. Debug mode leaks stack traces, env
        vars and SQL on error pages and is independent of APP_ENV, so it is the
        actually security-critical signal (a "production" env can still run with
        debug on) and belongs where it is always in view.

    Profiles (label / color / icon) are resolved by gp247_env_badge() from
    config('gp247.env_badge'). Renders nothing when the badge is disabled via
    config, or when the requested part has nothing to show (e.g. only="debug"
    while APP_DEBUG is off). All color utilities are on the admin-shell Tailwind
    safelist (tailwind.config.js), so no CSS rebuild is required.

    Reusable across the whole ecosystem (core/front/shop/plugin) via the shared
    `gp247::` component namespace (ui-tailadmin P3).

    @aidlc-unit admin-shell
    @aidlc-story US-AUI-env-identity
    @aidlc-adr ADR-002, ADR-004

    @props array
      - compact (bool): icon only, no text labels (for tight top bars). Default false.
      - only (string|null): 'env' renders only the env pill; 'debug' renders only
        the debug chip; null (default) renders both.
--}}
@props([
    'compact' => false,
    'only'    => null,
])

@php
    $badge = gp247_env_badge();

    // WHY: a single builder keeps the env pill and the debug chip visually
    // identical and guarantees only safelisted color tokens reach class names —
    // the color comes from config and must never inject arbitrary classes.
    $tintFor = function (string $color) {
        $color = preg_match('/^[a-z]+$/', $color) ? $color : 'gray';
        return "bg-{$color}-100 text-{$color}-800 border-{$color}-300"
            . " dark:bg-{$color}-900 dark:text-{$color}-200 dark:border-{$color}-700";
    };
    $pill = 'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-semibold uppercase tracking-wide';

    $envProfile   = ($badge && $only !== 'debug') ? $badge : null;
    $debugProfile = ($badge && ! empty($badge['debug']) && $only !== 'env')
        ? config('gp247.env_badge.debug')
        : null;
@endphp

@if ($envProfile || $debugProfile)
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2']) }}>
        @if ($envProfile)
            <span
                class="{{ $pill }} {{ $tintFor($envProfile['color']) }}"
                title="{{ gp247_language_render('admin.env.APP_ENV') }}: {{ $envProfile['env'] }}"
                data-testid="admin-shell-env-badge"
            >
                <i class="{{ $envProfile['icon'] }} text-[10px] leading-none"></i>
                @unless ($compact)
                    <span>{{ gp247_language_render($envProfile['label']) }}</span>
                @endunless
            </span>
        @endif

        @if ($debugProfile)
            <span
                class="{{ $pill }} {{ $tintFor($debugProfile['color'] ?? 'red') }}"
                title="{{ gp247_language_render('admin.env.APP_DEBUG') }}: on"
                data-testid="admin-shell-debug-badge"
            >
                <i class="{{ $debugProfile['icon'] ?? 'fas fa-bug' }} text-[10px] leading-none"></i>
                @unless ($compact)
                    <span>{{ gp247_language_render($debugProfile['label'] ?? 'DEBUG ON') }}</span>
                @endunless
            </span>
        @endif
    </span>
@endif
