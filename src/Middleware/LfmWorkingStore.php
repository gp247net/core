<?php

namespace GP247\Core\Middleware;

use Closure;
use GP247\Core\Models\AdminStore;

/**
 * Capture the opening form's working store into the session for LFM.
 *
 * The media-input opens the file manager with ?working_store=<store>. LFM's later
 * upload / list requests are separate AJAX calls that drop the query param, so the value
 * is stored here on the popup-load request and read back by gp247_process_private_folder()
 * on those AJAX calls — keeping every request of one popup on the same per-store folder.
 * A present-but-empty value (root content) resets it, so opening a store form then a root
 * form can never leave a stale store (ADR admin-shell_lfm-working-dir).
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-lfm-working-dir
 * @aidlc-adr admin-shell_lfm-working-dir
 */
class LfmWorkingStore
{
    /**
     * @param \Illuminate\Http\Request $request
     * @param \Closure                 $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if ($request->has('working_store')) {
            $requested = (string) $request->query('working_store', '');
            // WHY server-authoritative validation: working_store is user-supplied and
            // becomes a folder name. Remember it ONLY when it is a real store id — anything
            // else (empty, junk, a path-traversal attempt like "../x", a non-existent id)
            // resolves to '' → shared. This blocks folder injection; it is NOT a privilege
            // gate — a root admin already reaches every store, and a store-admin's folder is
            // forced from session('adminStoreId'), never from this param.
            $valid = ($requested !== '' && AdminStore::where('id', $requested)->exists())
                ? $requested : '';
            session(['lfmWorkingStore' => $valid]);
        }

        return $next($request);
    }
}
