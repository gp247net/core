<?php

namespace GP247\Core\AdminShell\Support;

use GP247\Core\AdminShell\Infrastructure\AdminAlert;

/**
 * Icon and colour of an alert, shared by the dashboard block and the "System alerts" page.
 *
 * WHY fixed class strings: the admin Tailwind bundle is prebuilt, so only class combinations
 * written literally somewhere it scans get CSS — keep them here, in one place.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-system-alerts, US-admin-shell-system-alerts-page
 * @aidlc-adr admin-shell_system-alerts-page
 */
final class AdminAlertStyle
{
    private const ICONS = [
        'app_debug' => 'fas fa-bug',
        'https' => 'fas fa-lock',
        'default_admin_password' => 'fas fa-user-shield',
        'password_policy' => 'fas fa-key',
        'encryption_key_dedicated' => 'fas fa-key',
        'secret_decryptable' => 'fas fa-key',
        'mfa_enabled' => 'fas fa-mobile-alt',
        'api_exposure' => 'fas fa-plug',
        'support_line' => 'fas fa-clock',
        'extension_data_pending' => 'fas fa-puzzle-piece',
        'extension_assets' => 'fas fa-puzzle-piece',
        'raw_output_scan' => 'fas fa-code',
    ];

    private const FALLBACK_ICONS = [
        AdminAlert::CRITICAL => 'fas fa-exclamation-triangle',
        AdminAlert::WARNING => 'fas fa-exclamation-triangle',
        AdminAlert::INFO => 'fas fa-info-circle',
        AdminAlert::OK => 'fas fa-check-circle',
    ];

    private const TILES = [
        AdminAlert::CRITICAL => 'bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-400',
        AdminAlert::WARNING => 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400',
        AdminAlert::INFO => 'bg-sky-50 text-sky-600 dark:bg-sky-500/10 dark:text-sky-400',
        AdminAlert::OK => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400',
    ];

    private const TEXTS = [
        AdminAlert::CRITICAL => 'text-red-600 dark:text-red-400',
        AdminAlert::WARNING => 'text-amber-600 dark:text-amber-400',
        AdminAlert::INFO => 'text-sky-600 dark:text-sky-400',
        AdminAlert::OK => 'text-emerald-600 dark:text-emerald-400',
    ];

    /**
     * @param AdminAlert $alert
     * @return string Font Awesome 5 classes.
     */
    public static function icon(AdminAlert $alert): string
    {
        return self::ICONS[$alert->key] ?? self::FALLBACK_ICONS[$alert->level] ?? self::FALLBACK_ICONS[AdminAlert::INFO];
    }

    /**
     * @param string $level One of AdminAlert::CRITICAL|WARNING|INFO|OK.
     * @return string Background + text classes of the icon tile.
     */
    public static function tile(string $level): string
    {
        return self::TILES[$level] ?? self::TILES[AdminAlert::INFO];
    }

    /**
     * @param string $level One of AdminAlert::CRITICAL|WARNING|INFO|OK.
     * @return string Text colour classes for labels of that level.
     */
    public static function text(string $level): string
    {
        return self::TEXTS[$level] ?? self::TEXTS[AdminAlert::INFO];
    }
}
