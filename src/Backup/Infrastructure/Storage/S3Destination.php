<?php

namespace GP247\Core\Backup\Infrastructure\Storage;

use GP247\Core\Backup\Domain\DestinationFailure;
use GP247\Core\Backup\Domain\NotPermitted;

/**
 * S3-compatible destination (AWS S3, Cloudflare R2, Backblaze B2, Wasabi, MinIO, DigitalOcean
 * Spaces…) through league/flysystem-aws-s3-v3, built by S3Factory. Provider error codes are mapped
 * to fixed reasons — AccessDenied (a write-only credential asked to delete) becomes NotPermitted,
 * never an error of the site. With Object Lock on a versioned bucket, a delete without a version
 * only adds a delete marker (the locked version stays): the test then honestly reports "can delete".
 *
 * @aidlc-unit backup-storage
 * @aidlc-story US-backup-storage-s3-compatible, US-backup-storage-immutable-guide
 * @aidlc-adr backup-storage_optional-adapters
 */
final class S3Destination extends FlysystemDestination
{
    /** @var list<string> */
    private const NOT_PERMITTED = ['AccessDenied', 'AllAccessDisabled', 'AccountProblem', 'InvalidObjectState', 'ObjectLocked', 'unauthorized', 'Unauthorized'];
    /** @var list<string> */
    private const AUTH = ['InvalidAccessKeyId', 'SignatureDoesNotMatch', 'InvalidToken', 'ExpiredToken', 'InvalidSecurity', 'InvalidClientTokenId', 'bad_auth_token', 'expired_auth_token'];
    /** @var list<string> */
    private const CONFIG = ['NoSuchBucket', 'PermanentRedirect', 'AuthorizationHeaderMalformed', 'InvalidBucketName', 'IllegalLocationConstraintException', 'InvalidRequest'];
    /** @var list<string> */
    private const QUOTA = ['QuotaExceeded', 'EntityTooLarge', 'StorageQuotaExceeded', 'cap_exceeded', 'storage_cap_exceeded', 'XMinioStorageFull'];

    /**
     * {@inheritDoc}
     */
    public function type(): string
    {
        return S3Factory::KEY;
    }

    /**
     * Map an error of the AWS SDK (or of Flysystem around it) to a fixed reason.
     *
     * @param \Throwable $error
     * @param string     $operation
     * @return DestinationFailure
     */
    public static function reasonFor(\Throwable $error, string $operation): DestinationFailure
    {
        $aws = self::find($error, \Aws\Exception\AwsException::class);
        if ($aws instanceof \Aws\Exception\AwsException) {
            $code = (string) $aws->getAwsErrorCode();
            $status = (int) $aws->getStatusCode();

            return match (true) {
                in_array($code, self::NOT_PERMITTED, true) || ($code === '' && $status === 403) => new NotPermitted($operation . ' ' . $code, $error),
                in_array($code, self::AUTH, true) || $status === 401 => new DestinationFailure(DestinationFailure::AUTH, $code, $error),
                in_array($code, self::CONFIG, true) || $status === 301 => new DestinationFailure(DestinationFailure::INVALID_CONFIG, $code, $error),
                in_array($code, ['NoSuchKey', 'NotFound'], true) || $status === 404 => new DestinationFailure(DestinationFailure::NOT_FOUND, $operation, $error),
                in_array($code, self::QUOTA, true) || $status === 507 => new DestinationFailure(DestinationFailure::QUOTA, $code, $error),
                $aws->isConnectionError() || $status === 0 || $status >= 500 => new DestinationFailure(DestinationFailure::NETWORK, $code, $error),
                default => new DestinationFailure(DestinationFailure::ERROR, $operation . ' ' . $code, $error),
            };
        }

        return new DestinationFailure(DestinationFailure::ERROR, $operation . ' ' . class_basename($error), $error);
    }

    /**
     * {@inheritDoc}
     */
    protected function classify(\Throwable $error, string $operation): DestinationFailure
    {
        return self::reasonFor($error, $operation);
    }
}
