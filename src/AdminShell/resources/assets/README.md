# GP247 admin shell — asset build (core maintainers only)

These are the **source** assets for the modern admin shell (ADR-002/004). They are
compiled into the package's `src/public/GP247/Core/AdminShell/` which is **committed
and shipped with `gp247/core`**.

A consuming Laravel app never builds anything — it just publishes the core static
assets (see `src/AdminShell/INSTALL.md` §3.2):

```bash
php artisan vendor:publish --tag=gp247:core-public --force
```

which copies `src/public/GP247` → `public/GP247`. The layout loads them via
`gp247_file('GP247/Core/AdminShell/css/admin.css')` and `…/js/admin.js`.

## Rebuilding (after changing views/components or these sources)

Requires Node + a dev `tailwindcss`. Run from the **host Laravel project root**
(the GP247 dev environment, where the package lives under `vendor/gp247/core`):

```bash
# CSS (Tailwind, minified, scans the package's own Blade + safelist)
npx tailwindcss \
  -c vendor/gp247/core/src/AdminShell/resources/assets/tailwind.config.js \
  -i vendor/gp247/core/src/AdminShell/resources/assets/css/admin.css \
  -o vendor/gp247/core/src/public/GP247/Core/AdminShell/css/admin.css --minify

# JS (plain ES — no bundling, just copy)
cp vendor/gp247/core/src/AdminShell/resources/assets/js/admin.js \
   vendor/gp247/core/src/public/GP247/Core/AdminShell/js/admin.js
```

Then commit `src/public/GP247/Core/AdminShell/` to the package git. Alpine.js is
provided by Livewire 4's bundle, so it is intentionally **not** part of this build.
