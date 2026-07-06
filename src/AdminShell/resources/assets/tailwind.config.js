import defaultTheme from 'tailwindcss/defaultTheme';

/*
 * Tailwind config for the GP247 admin shell — SELF-CONTAINED to the core module
 * (ADR-004). It scans only the module's own Blade (core is standalone — no
 * front/shop dep) and safelists DB-driven status/badge color classes that never
 * appear literally in source and would otherwise be purged (logical design §9 /
 * RISK-TECH-002).
 *
 * Content globs are relative to the CWD: the documented build command is run
 * from the host Laravel project root (the GP247 dev environment), where the
 * package lives under vendor/gp247/core (see resources/assets/README.md).
 */

/** @type {import('tailwindcss').Config} */
export default {
    // Dark mode toggled by an Alpine-managed `.dark` class (see admin.js), not OS media query.
    darkMode: 'class',
    content: [
        // Both the modern admin-shell Blade (layouts/, components/, livewire/,
        // partials/) and the legacy AdminLTE-ported views (screen/, auth/, error
        // pages) live under this one tree (gp247-admin:: and gp247-core:: share
        // it as two namespaces over the same root) — a single glob covers both.
        'vendor/gp247/core/src/Views/admin/**/*.blade.php',
        'vendor/gp247/core/src/AdminShell/resources/assets/js/**/*.js',
    ],
    safelist: [
        {
            pattern: /^(bg|text|border)-(gray|slate|red|orange|amber|yellow|green|emerald|teal|sky|blue|indigo|purple|pink)-(50|100|200|300|500|600|700|800|900)$/,
            variants: ['dark', 'hover'],
        },
        {
            // WHY: admin_home_layout's `size` (1-12) is interpolated into
            // `xl:col-span-{n}` at render time (Dashboard::blocks(), ADR-007),
            // so it never appears literally in scanned Blade source.
            pattern: /^col-span-(1[0-2]|[1-9])$/,
            variants: ['xl'],
        },
        {
            // WHY: responsive stat/list grids (e.g. gp247-shop-admin::component.top_info)
            // live in the shop/front packages, which this config doesn't scan (core
            // stays standalone). Safelisting the common column counts + breakpoints
            // keeps those blocks' Tailwind classes working without adding a
            // cross-package content dependency (RISK-TECH-002).
            pattern: /^grid-cols-[1-4]$/,
            variants: ['sm', 'md', 'lg', 'xl'],
        },
        {
            // WHY: same cross-package gap as grid-cols above — shop/front blocks
            // pick arbitrary gap-{n} spacing that may not coincidentally already
            // appear in core's own scanned Blade (e.g. gap-5 in top_info.blade.php).
            pattern: /^gap-[1-8]$/,
        },
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
        },
    },
    plugins: [],
};
