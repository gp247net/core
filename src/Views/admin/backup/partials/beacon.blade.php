{{--
    Header widget of the "when an admin visits" schedule (BackupBeaconWidget): background ticks to
    $tickUrl after the page has loaded, while the server says "more"; stops when the tab is hidden,
    on any error, or when the backup screen's own runner is on the page (it drives the same jobs).
    Renders an empty marker when inactive (nothing is sent).

    @aidlc-unit backup-admin
    @aidlc-story US-backup-admin-schedule
    @aidlc-adr backup-admin_browser-beacon-scheduling

    Variables: $active (bool), $tickUrl (string).
--}}
<div class="hidden" data-testid="backup-beacon" data-active="{{ $active ? '1' : '0' }}">
    @if ($active)
        <div wire:ignore
            x-data="{
                async run() {
                    if (document.querySelector('[data-testid=backup-runner]')) return;
                    try {
                        while (document.visibilityState === 'visible') {
                            const r = await fetch(@js($tickUrl), { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': @js(csrf_token()), 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                            if (!r.ok) return;
                            const d = await r.json();
                            if (!d.more) return;
                            await new Promise((resolve) => setTimeout(resolve, Math.max(1, d.next_in || 1) * 1000));
                        }
                    } catch (e) {}
                }
            }"
            x-init="window.addEventListener('load', () => setTimeout(() => run(), 1500), { once: true }); if (document.readyState === 'complete') setTimeout(() => run(), 1500)"></div>
    @endif
</div>
