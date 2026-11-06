<?php

namespace GP247\Core\Backup\Infrastructure\Storage;

use GP247\Core\Backup\Application\RemoteAddressGuard;
use GP247\Core\Backup\Domain\Destination;
use GP247\Core\Backup\Domain\DestinationFactory;
use GP247\Core\Backup\Domain\DestinationFailure;
use GP247\Core\Backup\Domain\DestinationType;

/**
 * The "SFTP" destination type (league/flysystem-sftp-v3 + phpseclib 3 — optional: without them
 * the type shows as unavailable with the install command). The server's host key is pinned: the
 * connection checks it before any password or key is sent.
 *
 * @aidlc-unit backup-storage
 * @aidlc-story US-backup-storage-sftp, US-backup-storage-credential-secret
 * @aidlc-adr backup-storage_optional-adapters
 */
final class SftpFactory implements DestinationFactory
{
    public const KEY = 'sftp';

    /**
     * {@inheritDoc}
     */
    public static function type(): DestinationType
    {
        return new DestinationType(
            self::KEY,
            'admin.backup.offsite.type.sftp',
            [
                'host' => ['kind' => DestinationType::KIND_TEXT, 'required' => true, 'max' => 253],
                'port' => ['kind' => DestinationType::KIND_NUMBER, 'default' => 22],
                'username' => ['kind' => DestinationType::KIND_TEXT, 'required' => true, 'max' => 100],
                'root' => ['kind' => DestinationType::KIND_TEXT, 'max' => 200],
            ],
            [
                'password' => ['kind' => DestinationType::KIND_TEXT, 'max' => 1024],
                'private_key' => ['kind' => DestinationType::KIND_TEXTAREA, 'max' => 16384],
                'passphrase' => ['kind' => DestinationType::KIND_TEXT, 'max' => 1024],
            ],
            ['host', 'port', 'username'],
            'League\\Flysystem\\PhpseclibV3\\SftpAdapter',
            'composer require league/flysystem-sftp-v3',
            self::class,
        );
    }

    /**
     * {@inheritDoc}
     */
    public static function validate(array $fields, #[\SensitiveParameter] array $secrets): array
    {
        $problems = [];
        $host = RemoteAddressGuard::hostProblem((string) $fields['host']);
        if ($host !== null) {
            $problems[] = 'host:' . $host;
        }
        $port = (int) ($fields['port'] ?? 22);
        if ($port < 1 || $port > 65535) {
            $problems[] = 'port:invalid';
        }
        if (preg_match('/^[A-Za-z0-9._@\-]{1,100}$/D', (string) $fields['username']) !== 1) {
            $problems[] = 'username:invalid';
        }
        $root = (string) $fields['root'];
        if ($root !== '' && (strlen($root) > 200 || preg_match('#^/?[A-Za-z0-9_.\-/]+$#D', $root) !== 1 || preg_match('#(^|/)\.\.?(/|$)#', $root) === 1)) {
            $problems[] = 'root:invalid';
        }
        if ((string) ($secrets['password'] ?? '') === '' && (string) ($secrets['private_key'] ?? '') === '') {
            $problems[] = 'password:required';
        }

        return $problems;
    }

    /**
     * {@inheritDoc}
     */
    public static function build(array $fields, #[\SensitiveParameter] array $secrets, bool $learnHostKey = false): Destination
    {
        // WHY again here: checked when saved, but the name may resolve elsewhere since (DNS).
        if (RemoteAddressGuard::hostProblem((string) $fields['host']) !== null) {
            throw new DestinationFailure(DestinationFailure::INVALID_CONFIG, 'host');
        }
        $connection = new PinnedSftpConnection(
            (string) $fields['host'],
            (int) ($fields['port'] ?: 22),
            (string) $fields['username'],
            $secrets,
            isset($fields['host_fingerprint']) && is_string($fields['host_fingerprint']) && $fields['host_fingerprint'] !== '' ? $fields['host_fingerprint'] : null,
            $learnHostKey,
            (int) config('gp247-config.backup.offsite_timeout', 10),
        );
        $root = trim((string) $fields['root']);
        $adapter = new \League\Flysystem\PhpseclibV3\SftpAdapter($connection, $root === '' ? '/' : $root);

        return new SftpDestination(new \League\Flysystem\Filesystem($adapter), $connection);
    }
}
