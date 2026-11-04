{{--
    Placeholder of the lazy "System alerts" block while checks run (US-admin-shell-system-alerts).

    @aidlc-unit admin-shell
    @aidlc-story US-admin-shell-system-alerts
--}}
<div>
    <x-gp247::card data-testid="admin-alerts-loading">
        <div class="flex items-center gap-4">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-sky-50 text-sky-600 dark:bg-sky-500/10 dark:text-sky-400">
                <i class="fas fa-spinner fa-spin"></i>
            </span>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ gp247_language_render('admin.alerts.title') }}</p>
                <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.alerts.checking') }}</p>
            </div>
        </div>
    </x-gp247::card>
</div>
