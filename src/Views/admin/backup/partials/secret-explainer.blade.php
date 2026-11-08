{{--
    What the backup password and the recovery key are for, shown where they are chosen (first set-up)
    and changed (Backup settings) — people pick them in a hurry and forget them otherwise.
    @aidlc-unit backup-admin
    @aidlc-story US-backup-engine-key-setup
--}}
<div class="space-y-4 rounded-lg border border-gray-200 p-4 text-sm text-gray-600 dark:border-gray-700 dark:text-gray-300" style="grid-column: 1 / -1" data-testid="backup-secret-explainer">
    <p><span class="font-medium text-gray-800 dark:text-gray-100">{{ gp247_language_render('admin.backup.secret.password') }}</span> — {{ gp247_language_render('admin.backup.secret.explain_password') }}</p>
    <p><span class="font-medium text-gray-800 dark:text-gray-100">{{ gp247_language_render('admin.backup.secret.recovery') }}</span> — {{ gp247_language_render('admin.backup.secret.explain_recovery') }}</p>
    <x-gp247::alert type="warning">{{ gp247_language_render('admin.backup.secret.explain_lost_both') }}</x-gp247::alert>
</div>
