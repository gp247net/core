{{--
    Drives the job in progress from the open screen: POST one slice to $tickUrl, refresh the
    component, repeat while the server says "more" (US-backup-engine-resumable-run on hosts without
    cron / CLI). Starts on load when a job is running, and on the `gp247-backup-run` event after an
    action started one. Stops when the tab closes or on any error (no burst of retries).

    @aidlc-unit backup-admin
    @aidlc-story US-backup-admin-dashboard
    @aidlc-adr backup-admin_browser-beacon-scheduling

    Variables: $tickUrl (string), $active (bool).
--}}
<div wire:ignore data-testid="backup-runner"
    x-data="{
        running: false,
        async start() {
            if (this.running) return;
            this.running = true;
            try {
                while (true) {
                    const r = await fetch(@js($tickUrl), { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': @js(csrf_token()), 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                    if (!r.ok) break;
                    const d = await r.json();
                    $wire.$refresh();
                    if (!d.more) break;
                    await new Promise((resolve) => setTimeout(resolve, Math.max(1, d.next_in || 1) * 1000));
                }
            } catch (e) {}
            this.running = false;
            $wire.$refresh();
        }
    }"
    x-init="{{ $active ? 'start()' : '' }}"
    x-on:gp247-backup-run.window="start()">
    <template x-if="running">
        <p class="text-xs text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.backup.job.keep_open') }}</p>
    </template>
</div>
