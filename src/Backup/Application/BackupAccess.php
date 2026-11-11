<?php

namespace GP247\Core\Backup\Application;

use GP247\Core\AdminShell\Domain\AdminUserContract;
use GP247\Core\AdminShell\Domain\AuthorizationException;

/**
 * The three backup rights (create · download · restore) and the Administrator-only settings,
 * checked in the server for every action — hidden buttons are only a convenience. Rights are
 * granted by the admin URIs of the three backup permissions (RBAC layer 2); "view all" grants
 * none of them, because AdminUserContract::canAccessUrl() reads explicit grants only.
 *
 * @aidlc-unit backup-admin
 * @aidlc-story US-backup-admin-permissions
 * @aidlc-adr backup-admin_step-up-and-signed-download
 */
final class BackupAccess
{
    public const CREATE = 'create';
    public const DOWNLOAD = 'download';
    public const RESTORE = 'restore';
    public const SETTINGS = 'settings';

    /**
     * @param string $right One of the constants above.
     * @return bool
     */
    public static function allows(string $right): bool
    {
        try {
            $user = app(AdminUserContract::class);
        } catch (\Throwable $e) {
            // WHY: no resolvable admin (guest, console) holds no backup right — fail closed.
            return false;
        }
        if ($user->isAdministrator()) {
            return true;
        }
        $check = self::uris()[$right] ?? null;

        return $check !== null && $user->canAccessUrl($check[0], $check[1]);
    }

    /**
     * @return bool The current admin is an Administrator (false when there is none — console, guest).
     *
     * @aidlc-story US-backup-restore-import-archive
     */
    public static function isAdministrator(): bool
    {
        try {
            return app(AdminUserContract::class)->isAdministrator();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Refuse (and log the refusal) when the current admin lacks a right.
     *
     * @param string $right
     * @param string $action Audit action name.
     * @param array<string, mixed> $context
     * @return void
     * @throws AuthorizationException
     */
    public static function authorize(string $right, string $action, array $context = []): void
    {
        if (self::allows($right)) {
            return;
        }
        BackupAudit::record($action, BackupAudit::DENIED, $context + ['right' => $right]);

        throw AuthorizationException::fromReason('backup.' . $right . ' required');
    }

    /**
     * @return array<string, array{0: string|null, 1: string}> Right => [admin path, method]; null path = Administrator only.
     */
    private static function uris(): array
    {
        $p = GP247_ADMIN_PREFIX;

        return [
            self::CREATE => [$p . '/backup/archives', 'POST'],
            self::DOWNLOAD => [$p . '/backup/download/link', 'GET'],
            self::RESTORE => [$p . '/backup/restore', 'POST'],
        ];
    }
}
