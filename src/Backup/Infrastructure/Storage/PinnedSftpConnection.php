<?php

namespace GP247\Core\Backup\Infrastructure\Storage;

use GP247\Core\Backup\Domain\DestinationFailure;
use League\Flysystem\PhpseclibV3\ConnectionProvider;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;

/**
 * SFTP connection that checks the server's host key BEFORE sending the password or key: the
 * fingerprint pinned at set-up must match, otherwise the copy stops (host_key_changed) and
 * nothing secret leaves the site. On "Test the connection" an unknown key is learnt (reported for
 * the administrator to compare) instead of refused. Loaded only when the SFTP library is
 * installed (this class implements its interface).
 *
 * @aidlc-unit backup-storage
 * @aidlc-story US-backup-storage-sftp
 * @aidlc-adr backup-storage_optional-adapters
 */
final class PinnedSftpConnection implements ConnectionProvider
{
    private ?SFTP $connection = null;
    private ?string $seen = null;

    /**
     * @param string                $host
     * @param int                   $port
     * @param string                $username
     * @param array<string, string> $secrets     password | private_key + passphrase.
     * @param string|null           $pinned      SHA256:… accepted by the administrator.
     * @param bool                  $learn       Accept an unknown key (connection test).
     * @param int                   $timeout     Seconds.
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        #[\SensitiveParameter] private readonly array $secrets,
        private readonly ?string $pinned,
        private readonly bool $learn,
        private readonly int $timeout,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function provideConnection(): SFTP
    {
        if ($this->connection !== null && $this->connection->isConnected()) {
            return $this->connection;
        }
        $sftp = new SFTP($this->host, $this->port, $this->timeout);
        try {
            $key = $sftp->getServerPublicHostKey();
        } catch (\Throwable $e) {
            throw new DestinationFailure(DestinationFailure::NETWORK, 'connect', $e);
        }
        if (!is_string($key) || $key === '') {
            throw new DestinationFailure(DestinationFailure::NETWORK, 'connect');
        }
        $this->seen = self::fingerprintOf($key);
        if ($this->pinned === null && !$this->learn) {
            throw new DestinationFailure(DestinationFailure::HOST_KEY_UNKNOWN, null, null, $this->seen);
        }
        if ($this->pinned !== null && !hash_equals($this->pinned, $this->seen)) {
            throw new DestinationFailure(DestinationFailure::HOST_KEY_CHANGED, null, null, $this->seen);
        }
        $privateKey = (string) ($this->secrets['private_key'] ?? '');
        try {
            $credential = $privateKey !== ''
                ? PublicKeyLoader::load($privateKey, ($this->secrets['passphrase'] ?? '') === '' ? false : (string) $this->secrets['passphrase'])
                : (string) ($this->secrets['password'] ?? '');
            $ok = $sftp->login($this->username, $credential);
        } catch (\Throwable $e) {
            throw new DestinationFailure(DestinationFailure::AUTH, class_basename($e), $e, $this->seen);
        }
        if (!$ok) {
            throw new DestinationFailure(DestinationFailure::AUTH, null, null, $this->seen);
        }

        return $this->connection = $sftp;
    }

    /**
     * @return string|null Fingerprint of the host key seen on the last connection.
     */
    public function fingerprint(): ?string
    {
        return $this->seen;
    }

    /**
     * @return string Last SFTP status message of the open connection (no secret in it).
     */
    public function lastError(): string
    {
        return $this->connection === null ? '' : (string) $this->connection->getLastSFTPError();
    }

    /**
     * @param string $publicKey "<type> <base64>" as the server sends it.
     * @return string "SHA256:<base64 without padding>", as `ssh-keygen -l` prints it.
     */
    public static function fingerprintOf(string $publicKey): string
    {
        $parts = explode(' ', trim($publicKey));
        $blob = base64_decode($parts[1] ?? $parts[0], true);

        return 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob === false ? $publicKey : $blob, true)), '=');
    }
}
