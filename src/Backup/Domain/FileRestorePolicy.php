<?php

namespace GP247\Core\Backup\Domain;

/**
 * Which file groups of an archive may be written back, and which files of the upload folder are
 * never written (NFR-SEC-backup-no-code-execution).
 *
 *  - "lock" (composer.lock) is never restored: it is rebuilt from package sources;
 *  - "extensions" (app/GP247 code) and "env" (.env) only on an explicit choice, in an interactive
 *    run (the person sees the warning / the key differences and confirms), never in post-incident
 *    mode, never from an archive whose origin could not be verified;
 *  - "uploads": files a web server could execute or that change its configuration are skipped.
 *
 * @aidlc-unit backup-restore
 * @aidlc-story US-backup-restore-cli-restore, US-backup-restore-post-incident-mode, US-backup-restore-import-archive
 * @aidlc-adr backup-engine_tamper-evidence
 */
final class FileRestorePolicy
{
    public const UPLOADS = 'uploads';
    public const EXTENSIONS = 'extensions';
    public const ENV = 'env';
    public const LOCK = 'lock';

    /** @var list<string> Groups a restore may write. */
    public const RESTORABLE = [self::UPLOADS, self::EXTENSIONS, self::ENV];

    /** @var list<string> Groups that carry code or secrets. */
    public const SENSITIVE = [self::EXTENSIONS, self::ENV];

    /** @var list<string> Extensions never written into the upload folder (lower case, no dot). */
    public const DEFAULT_BLOCKED_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phps', 'phar', 'inc',
        'cgi', 'pl', 'py', 'sh', 'bash', 'jsp', 'htaccess', 'htpasswd', 'ini',
        // IIS handlers.
        'asp', 'aspx', 'asa', 'cer', 'ashx', 'asmx', 'axd', 'svc', 'cshtml', 'vbhtml', 'config',
        // Server-side includes, and pages that run script in the site's own origin.
        'shtml', 'shtm', 'stm', 'html', 'htm', 'xhtml', 'js', 'mjs',
    ];

    /** @var list<string> Also blocked when the archive's origin could not be verified. */
    public const STRICT_EXTRA_EXTENSIONS = ['svg', 'svgz', 'xml', 'xsl', 'swf'];

    /** @var list<string> Exact file names never written into the upload folder (lower case). */
    public const BLOCKED_NAMES = ['.htaccess', '.htpasswd', '.user.ini', 'php.ini', 'web.config'];

    /**
     * @param list<string> $groups      Groups asked for.
     * @param bool         $interactive A person answers the confirmation questions.
     * @param bool         $postIncident
     * @param bool         $unverified  Archive origin could not be verified.
     * @return void
     * @throws RestoreRefused file_group_unknown | files_not_allowed | interactive_only
     *
     * @aidlc-story US-backup-restore-cli-restore, US-backup-restore-post-incident-mode
     */
    public static function assertAllowed(array $groups, bool $interactive, bool $postIncident, bool $unverified): void
    {
        foreach ($groups as $group) {
            if (!in_array($group, self::RESTORABLE, true)) {
                throw new RestoreRefused(RestoreRefused::FILE_GROUP_UNKNOWN, (string) $group);
            }
            if (!in_array($group, self::SENSITIVE, true)) {
                continue;
            }
            if ($postIncident || $unverified) {
                throw new RestoreRefused(RestoreRefused::FILES_NOT_ALLOWED, $group . ($postIncident ? ' (post-incident)' : ' (unverified origin)'));
            }
            if (!$interactive) {
                throw new RestoreRefused(RestoreRefused::INTERACTIVE_ONLY, $group);
            }
        }
    }

    /**
     * @param string       $path    Relative path inside the upload folder (already ArchivePath-checked).
     * @param list<string> $blocked Blocked extensions (lower case, no dot).
     * @return bool The file must not be written: every dot-separated part after the first is
     *              checked ("shell.php.jpg" runs as PHP on some Apache set-ups), and a name ending
     *              in a dot or space is refused (Windows drops them: "web.config." is web.config).
     *
     * @aidlc-story US-backup-restore-cli-restore
     */
    public static function isBlockedUpload(string $path, array $blocked = self::DEFAULT_BLOCKED_EXTENSIONS): bool
    {
        $raw = basename(str_replace('\\', '/', $path));
        if ($raw !== rtrim($raw, ". \t")) {
            return true;
        }
        $name = strtolower($raw);
        if (in_array($name, self::BLOCKED_NAMES, true) || str_starts_with($name, '.ht')) {
            return true;
        }
        $parts = explode('.', $name);
        array_shift($parts);
        foreach ($parts as $part) {
            if (in_array(rtrim($part, " \t"), $blocked, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param bool $strict Archive of unverified origin: also image / document types that can carry script.
     * @return list<string> Blocked extensions (built-in list + gp247-config.backup.blocked_upload_extensions).
     *
     * @aidlc-story US-backup-restore-cli-restore, US-backup-restore-import-archive
     */
    public static function blockedExtensions(bool $strict = false): array
    {
        $base = $strict ? [...self::DEFAULT_BLOCKED_EXTENSIONS, ...self::STRICT_EXTRA_EXTENSIONS] : self::DEFAULT_BLOCKED_EXTENSIONS;
        $configured = config('gp247-config.backup.blocked_upload_extensions');
        if (!is_array($configured) || $configured === []) {
            return $base;
        }

        return array_values(array_unique(array_merge($base, array_map(fn ($e) => strtolower(ltrim((string) $e, '.')), $configured))));
    }
}
