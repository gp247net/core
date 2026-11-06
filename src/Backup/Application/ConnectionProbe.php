<?php

namespace GP247\Core\Backup\Application;

use GP247\Core\Backup\Domain\ConnectionReport;
use GP247\Core\Backup\Domain\Destination;
use GP247\Core\Backup\Domain\DestinationCapabilities;
use GP247\Core\Backup\Domain\DestinationFailure;
use GP247\Core\Backup\Domain\HostKeyAware;
use GP247\Core\Backup\Domain\NotPermitted;
use GP247\Core\Backup\Domain\RemoteLayout;

/**
 * "Test the connection": find out what the credential really allows by trying, on one small file
 * of fixed name in this site's folder — write it, read it back, see it in a listing, try to delete
 * it. A credential that cannot delete leaves that one file behind (re-written by the next test).
 *
 * @aidlc-unit backup-storage
 * @aidlc-story US-backup-storage-s3-compatible, US-backup-storage-immutable-guide, US-backup-storage-sftp
 * @aidlc-adr backup-storage_optional-adapters
 */
final class ConnectionProbe
{
    /**
     * @param Destination $destination
     * @param string      $siteKey
     * @return ConnectionReport
     */
    public function run(Destination $destination, string $siteKey): ConnectionReport
    {
        $path = $siteKey . '/' . RemoteLayout::PROBE;
        $content = 'GP247 backup connection check ' . bin2hex(random_bytes(16)) . "\n";
        $write = $read = $list = $delete = false;
        $error = null;
        try {
            $write = $this->attempt(fn () => $destination->put($path, self::stream($content)));
            if ($write) {
                $read = $this->attempt(function () use ($destination, $path, $content) {
                    $stream = $destination->read($path);
                    $back = (string) stream_get_contents($stream, strlen($content) + 1);
                    fclose($stream);
                    if (!hash_equals($content, $back)) {
                        throw new DestinationFailure(DestinationFailure::MISMATCH, 'probe');
                    }
                });
            }
            $list = $this->attempt(function () use ($destination, $siteKey, $path, $write) {
                $seen = false;
                foreach ($destination->files($siteKey) as $file) {
                    $seen = $seen || $file['path'] === $path;
                }
                if ($write && !$seen) {
                    throw new NotPermitted('probe not listed');
                }
            });
            if ($write) {
                $delete = $this->attempt(fn () => $destination->delete($path));
            }
        } catch (DestinationFailure $e) {
            $error = $e->reason;
        }

        return new ConnectionReport(
            new DestinationCapabilities($write, $read, $list, $delete),
            $error,
            $destination instanceof HostKeyAware ? $destination->fingerprint() : null,
            time(),
        );
    }

    /**
     * @param callable $operation
     * @return bool True when it worked, false when the credential lacks that right.
     * @throws DestinationFailure Any other failure (network, authentication…): the test stops.
     */
    private function attempt(callable $operation): bool
    {
        try {
            $operation();

            return true;
        } catch (NotPermitted $e) {
            return false;
        }
    }

    /**
     * @param string $content
     * @return resource
     */
    private static function stream(string $content)
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    }
}
