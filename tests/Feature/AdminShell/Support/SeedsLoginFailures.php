<?php

namespace GP247\Core\Tests\Feature\AdminShell\Support;

use Illuminate\Support\Facades\DB;

/**
 * Test helper: rows of the login-failure log and its "which groups are recorded"
 * setting, written inside the test transaction (DML only).
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-login-failure-log
 */
trait SeedsLoginFailures
{
    /**
     * @return \Illuminate\Database\Query\Builder
     */
    protected function failureTable()
    {
        return DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'admin_login_failure');
    }

    /**
     * Start from an empty log so counts only see this test's rows.
     *
     * @return void
     */
    protected function emptyFailureLog(): void
    {
        $this->failureTable()->delete();
    }

    /**
     * Set which account groups are recorded (comma separated keys).
     *
     * @param string $audiences
     * @return void
     */
    protected function recordAudiences(string $audiences): void
    {
        $table = DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'admin_config');
        $match = ['key' => 'login_failure_audiences', 'store_id' => (string) GP247_STORE_ID_GLOBAL];
        if ($table->clone()->where($match)->exists()) {
            $table->clone()->where($match)->update(['value' => $audiences]);

            return;
        }
        $table->insert($match + ['group' => 'global', 'code' => 'login_failure', 'value' => $audiences, 'sort' => 0, 'detail' => 'admin.login_failures.audiences']);
    }

    /**
     * Insert $count failure rows.
     *
     * @param int                  $count
     * @param array<string, mixed> $attributes
     * @return void
     */
    protected function seedFailures(int $count, array $attributes = []): void
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = $attributes + [
                'audience'   => 'admin',
                'channel'    => 'web',
                'identity'   => 'someone',
                'account_id' => null,
                'reason'     => 'invalid_credentials',
                'ip'         => '198.51.100.7',
                'user_agent' => 'phpunit',
                'created_at' => now(),
            ];
        }
        $this->failureTable()->insert($rows);
    }
}
