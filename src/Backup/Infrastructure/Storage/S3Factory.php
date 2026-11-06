<?php

namespace GP247\Core\Backup\Infrastructure\Storage;

use GP247\Core\Backup\Application\RemoteAddressGuard;
use GP247\Core\Backup\Domain\Destination;
use GP247\Core\Backup\Domain\DestinationFactory;
use GP247\Core\Backup\Domain\DestinationFailure;
use GP247\Core\Backup\Domain\DestinationType;
use Illuminate\Support\Facades\Storage;

/**
 * The "S3-compatible" destination type: fields, checks, and the Flysystem disk built at run time
 * with Storage::build() from the decrypted configuration.
 *
 * @aidlc-unit backup-storage
 * @aidlc-story US-backup-storage-s3-compatible, US-backup-storage-credential-secret
 * @aidlc-adr backup-storage_optional-adapters
 */
final class S3Factory implements DestinationFactory
{
    public const KEY = 's3';

    /**
     * {@inheritDoc}
     */
    public static function type(): DestinationType
    {
        return new DestinationType(
            self::KEY,
            'admin.backup.offsite.type.s3',
            [
                'endpoint' => ['kind' => DestinationType::KIND_TEXT, 'max' => 255],
                'region' => ['kind' => DestinationType::KIND_TEXT, 'default' => 'us-east-1', 'max' => 40],
                'bucket' => ['kind' => DestinationType::KIND_TEXT, 'required' => true, 'max' => 63],
                'prefix' => ['kind' => DestinationType::KIND_TEXT, 'max' => 200],
                'path_style' => ['kind' => DestinationType::KIND_BOOL, 'default' => false],
            ],
            [
                'key' => ['kind' => DestinationType::KIND_TEXT, 'required' => true, 'max' => 200],
                'secret' => ['kind' => DestinationType::KIND_TEXT, 'required' => true, 'max' => 200],
            ],
            ['endpoint', 'bucket'],
            'League\\Flysystem\\AwsS3V3\\AwsS3V3Adapter',
            'composer require league/flysystem-aws-s3-v3',
            self::class,
        );
    }

    /**
     * {@inheritDoc}
     */
    public static function validate(array $fields, #[\SensitiveParameter] array $secrets): array
    {
        $problems = [];
        $endpoint = self::addressProblem($fields);
        if ($endpoint !== null) {
            $problems[] = 'endpoint:' . $endpoint;
        }
        if (preg_match('/^[a-z0-9-]{1,40}$/D', (string) $fields['region']) !== 1) {
            $problems[] = 'region:invalid';
        }
        if (preg_match('/^[a-z0-9][a-z0-9.\-]{1,61}[a-z0-9]$/D', (string) $fields['bucket']) !== 1) {
            $problems[] = 'bucket:invalid';
        }
        if (!self::validFolder((string) $fields['prefix'])) {
            $problems[] = 'prefix:invalid';
        }
        foreach (['key', 'secret'] as $name) {
            $value = (string) ($secrets[$name] ?? '');
            if ($value === '' || strlen($value) > 200 || preg_match('/[\x00-\x1f]/', $value) === 1) {
                $problems[] = $name . ':required';
            }
        }

        return $problems;
    }

    /**
     * {@inheritDoc}
     */
    public static function build(array $fields, #[\SensitiveParameter] array $secrets, bool $learnHostKey = false): Destination
    {
        // WHY again here: the address was checked when saved, but a name may point elsewhere
        // since (DNS); a stored destination never reaches a blocked address.
        if (self::addressProblem($fields) !== null) {
            throw new DestinationFailure(DestinationFailure::INVALID_CONFIG, 'endpoint');
        }
        $config = [
            'driver' => 's3',
            'key' => (string) ($secrets['key'] ?? ''),
            'secret' => (string) ($secrets['secret'] ?? ''),
            'region' => (string) ($fields['region'] ?: 'us-east-1'),
            'bucket' => (string) $fields['bucket'],
            'use_path_style_endpoint' => (bool) $fields['path_style'],
            'throw' => true,
            'http' => ['connect_timeout' => (int) config('gp247-config.backup.offsite_timeout', 10), 'timeout' => 300],
            // WHY: several S3-compatible services reject the SDK's newer default checksums.
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',
        ];
        if ((string) $fields['endpoint'] !== '') {
            $config['endpoint'] = (string) $fields['endpoint'];
        }
        $prefix = trim((string) $fields['prefix'], '/');
        if ($prefix !== '') {
            $config['root'] = $prefix;
        }
        try {
            $disk = Storage::build($config);
        } catch (\Throwable $e) {
            // No previous exception: its trace holds the configuration with the keys.
            throw new DestinationFailure(DestinationFailure::INVALID_CONFIG, class_basename($e));
        }

        return new S3Destination($disk->getDriver());
    }

    /**
     * The address the SDK will call: the endpoint, and with a custom endpoint in virtual-host
     * style also "<bucket>.<endpoint host>" (a wildcard DNS name could point that one elsewhere).
     *
     * @param array<string, mixed> $fields
     * @return string|null Problem code (RemoteAddressGuard), null when allowed.
     */
    public static function addressProblem(array $fields): ?string
    {
        $endpoint = (string) ($fields['endpoint'] ?? '');
        $problem = RemoteAddressGuard::endpointProblem($endpoint);
        if ($problem !== null || $endpoint === '' || !empty($fields['path_style'])) {
            return $problem;
        }
        $host = (string) parse_url($endpoint, PHP_URL_HOST);
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            return null;
        }
        $virtual = (string) preg_replace('#^(https?://)#i', '$1' . (string) ($fields['bucket'] ?? '') . '.', $endpoint, 1);

        return RemoteAddressGuard::endpointProblem($virtual);
    }

    /**
     * @param string $folder
     * @return bool A relative folder without "..", made of safe characters.
     */
    public static function validFolder(string $folder): bool
    {
        return $folder === '' || (strlen($folder) <= 200 && preg_match('#^[A-Za-z0-9_.\-/]+$#D', $folder) === 1
            && preg_match('#(^|/)\.\.?(/|$)#', $folder) !== 1);
    }
}
