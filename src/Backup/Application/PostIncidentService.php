<?php

namespace GP247\Core\Backup\Application;

use GP247\Core\Backup\Infrastructure\Database\SchemaInspector;
use GP247\Core\Backup\Infrastructure\Site\SessionPurger;
use Illuminate\Support\Facades\DB;

/**
 * The automatic part of a restore after a security incident (US-backup-restore-post-incident-mode),
 * run right after the tables were put in place. Each step reports its own result; a failing step
 * never stops the next one:
 *
 *  1. sessions   every web session ends (admins and customers, the person restoring included);
 *  2. remember   "remember me" tokens of every table of this site that has the column (found from
 *                the schema — the core does not name tables of front / shop);
 *  3. tokens     every API / MCP token, API session and refresh token, backup download link;
 *  4. password   every admin must choose a new password at the next sign-in.
 *
 * Rotating the site encryption key, reinstalling the code, changing hosting / database passwords
 * stay with the person (PostIncidentChecklist) — the system never writes .env.
 *
 * @aidlc-unit backup-restore
 * @aidlc-story US-backup-restore-post-incident-mode
 * @aidlc-adr backup-restore_shadow-table-swap
 */
final class PostIncidentService
{
    public const PASSWORD_FLAG = 'password_change_required';

    /** @var list<string> Token tables emptied (names without GP247_DB_PREFIX). */
    public const TOKEN_TABLES = ['personal_access_tokens', 'api_session', 'api_refresh_token', 'backup_download_token'];

    /**
     * @param SessionPurger|null $sessions
     */
    public function __construct(private ?SessionPurger $sessions = null)
    {
        $this->sessions ??= new SessionPurger;
    }

    /**
     * @return list<array{step: string, status: string, count: int, detail: string}> status ok | failed | manual.
     *
     * @aidlc-story US-backup-restore-post-incident-mode
     */
    public function run(): array
    {
        $schema = new SchemaInspector(DB::connection(GP247_DB_CONNECTION));

        return [
            $this->attempt('sessions', fn () => $this->endSessions()),
            $this->attempt('remember', fn () => $this->clearRememberTokens($schema)),
            $this->attempt('tokens', fn () => $this->revokeTokens($schema)),
            $this->attempt('password', fn () => $this->requirePasswordChange($schema)),
        ];
    }

    /**
     * Every admin session signed in before now ends on its next request (any driver, including
     * a request still in flight that writes its session back); stored sessions are also removed
     * where the driver allows it — customers' sessions on other drivers are reported as "manual".
     *
     * @return array{status: string, count: int, detail: string}
     */
    private function endSessions(): array
    {
        PostIncidentChecklist::endAdminSessions();

        return $this->sessions->purge();
    }

    /**
     * @param string                         $step
     * @param callable(): array{status?: string, count?: int, detail?: string} $run
     * @return array{step: string, status: string, count: int, detail: string}
     */
    private function attempt(string $step, callable $run): array
    {
        try {
            $result = $run();

            return ['step' => $step, 'status' => (string) ($result['status'] ?? 'ok'), 'count' => (int) ($result['count'] ?? 0), 'detail' => (string) ($result['detail'] ?? '')];
        } catch (\Throwable $e) {
            BackupLog::failure('Post-incident step ' . $step . ' failed: ' . class_basename($e));

            return ['step' => $step, 'status' => 'failed', 'count' => 0, 'detail' => class_basename($e)];
        }
    }

    /**
     * @param SchemaInspector $schema
     * @return array{count: int, detail: string}
     */
    private function clearRememberTokens(SchemaInspector $schema): array
    {
        $count = 0;
        $tables = [];
        foreach (array_keys($schema->tables()) as $table) {
            if (!self::ownTable($table) || preg_match('/__bk[a-z0-9]*$/', $table) === 1 || !in_array('remember_token', $schema->columns($table), true)) {
                continue;
            }
            $count += $schema->db()->table($table)->whereNotNull('remember_token')->update(['remember_token' => null]);
            $tables[] = $table;
        }

        return ['count' => $count, 'detail' => implode(',', $tables)];
    }

    /**
     * @param SchemaInspector $schema
     * @return array{count: int, detail: string}
     */
    private function revokeTokens(SchemaInspector $schema): array
    {
        $count = 0;
        // WHY through the model: Sanctum's table keeps its own name (no GP247_DB_PREFIX) and may
        // live on its own connection.
        $model = new (\Laravel\Sanctum\Sanctum::personalAccessTokenModel());
        if ($model->getConnection()->getSchemaBuilder()->hasTable($model->getTable())) {
            $count += $model->getConnection()->table($model->getTable())->delete();
        }
        foreach (self::TOKEN_TABLES as $table) {
            if ($table !== 'personal_access_tokens' && $schema->hasTable(GP247_DB_PREFIX . $table)) {
                $count += $schema->db()->table(GP247_DB_PREFIX . $table)->delete();
            }
        }

        return ['count' => $count, 'detail' => ''];
    }

    /**
     * @param SchemaInspector $schema
     * @return array{status: string, count: int, detail: string}
     */
    private function requirePasswordChange(SchemaInspector $schema): array
    {
        if (!in_array(self::PASSWORD_FLAG, $schema->columns(GP247_DB_PREFIX . 'admin_user'), true)) {
            return ['status' => 'failed', 'count' => 0, 'detail' => 'column_missing'];
        }

        return ['status' => 'ok', 'count' => $schema->db()->table(GP247_DB_PREFIX . 'admin_user')->update([self::PASSWORD_FLAG => 1]), 'detail' => ''];
    }

    /**
     * @param string $real
     * @return bool A table of this site (GP247_DB_PREFIX; every table when the prefix is empty).
     */
    private static function ownTable(string $real): bool
    {
        return GP247_DB_PREFIX === '' || str_starts_with($real, GP247_DB_PREFIX);
    }
}
