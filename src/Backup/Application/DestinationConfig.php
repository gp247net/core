<?php

namespace GP247\Core\Backup\Application;

use GP247\Core\Backup\Domain\Archive;
use GP247\Core\Backup\Domain\ConnectionReport;
use GP247\Core\Backup\Domain\DestinationType;
use GP247\Core\Backup\Domain\OffsiteRefused;
use GP247\Core\Backup\Infrastructure\Store\JobRepository;
use GP247\Core\Models\AdminConfig;
use Illuminate\Support\Facades\DB;

/**
 * The one external destination of the site (Free), in admin_config (code "backup", global store;
 * keys start with "backup_" so a restore never brings an old destination back):
 *  - backup_offsite         plain JSON {type, fields, fingerprint}
 *  - backup_offsite_secret  secret JSON (security = 1 ⇒ encrypted at rest), never shown again
 *  - backup_offsite_report  last connection test (rights found, no secret)
 *  - backup_offsite_trash   archives removed by retention whose off-site copy may be deleted
 *  - backup_offsite_retention  last off-site retention outcome
 *
 * Write-only secrets: a blank secret keeps the stored one — but only while the fields that say
 * where the data goes are unchanged; pointing the destination elsewhere needs the secrets again,
 * so a stored SFTP password is never sent to a new server.
 *
 * @aidlc-unit backup-storage
 * @aidlc-story US-backup-storage-credential-secret, US-backup-storage-s3-compatible, US-backup-storage-sftp
 * @aidlc-adr backup-storage_optional-adapters
 */
final class DestinationConfig
{
    public const CONFIG = 'backup_offsite';
    public const SECRET = 'backup_offsite_secret';
    public const REPORT = 'backup_offsite_report';
    public const TRASH = 'backup_offsite_trash';
    public const RETENTION = 'backup_offsite_retention';
    private const GROUP = 'backup';
    private const TRASH_MAX = 200;

    /**
     * @param DestinationRegistry|null $registry
     */
    public function __construct(private ?DestinationRegistry $registry = null)
    {
        $this->registry ??= new DestinationRegistry;
    }

    /**
     * @return array{type: string, fields: array<string, mixed>, fingerprint: string|null}|null
     */
    public function current(): ?array
    {
        $data = $this->json(self::CONFIG);
        if (!is_string($data['type'] ?? null) || $data['type'] === '') {
            return null;
        }

        return [
            'type' => $data['type'],
            'fields' => is_array($data['fields'] ?? null) ? $data['fields'] : [],
            'fingerprint' => is_string($data['fingerprint'] ?? null) && $data['fingerprint'] !== '' ? $data['fingerprint'] : null,
        ];
    }

    /**
     * @return bool A destination is set.
     */
    public function configured(): bool
    {
        return $this->current() !== null;
    }

    /**
     * @return list<string> Names of the secrets stored (values are never returned to a screen).
     */
    public function secretNames(): array
    {
        return array_keys(array_filter($this->secrets(), fn ($v) => $v !== ''));
    }

    /**
     * @return array<string, string> Decrypted secrets of the stored destination.
     */
    public function secrets(): array
    {
        $raw = (string) AdminConfig::where('store_id', GP247_STORE_ID_GLOBAL)->where('key', self::SECRET)->value('value');
        if ($raw === '') {
            return [];
        }
        $plain = gp247_secret_decrypt($raw, ['key' => self::SECRET, 'store_id' => GP247_STORE_ID_GLOBAL]);
        $data = json_decode($plain, true);

        return is_array($data) ? array_map('strval', array_filter($data, 'is_scalar')) : [];
    }

    /**
     * Fields + secrets for a type, with the write-only rule applied.
     *
     * @param string                $type
     * @param array<string, mixed>  $fields       Typed on the screen.
     * @param array<string, string> $typedSecrets Typed on the screen (blank = keep).
     * @return array{type: DestinationType, fields: array<string, mixed>, secrets: array<string, string>}
     * @throws OffsiteRefused unknown_type
     */
    public function resolve(string $type, array $fields, #[\SensitiveParameter] array $typedSecrets): array
    {
        $definition = $this->registry->type($type);
        if ($definition === null) {
            throw new OffsiteRefused(OffsiteRefused::UNKNOWN_TYPE);
        }
        $clean = $definition->cleanFields($fields);
        $current = $this->current();
        $reuse = $current !== null && $current['type'] === $type && $definition->sameIdentity($current['fields'], $clean);
        $stored = $reuse ? $this->secrets() : [];
        $secrets = [];
        foreach (array_keys($definition->secrets) as $name) {
            $typed = (string) ($typedSecrets[$name] ?? '');
            $secrets[$name] = $typed !== '' ? $typed : (string) ($stored[$name] ?? '');
        }

        return ['type' => $definition, 'fields' => $clean, 'secrets' => $secrets];
    }

    /**
     * Store a destination (the caller has the Administrator right and a fresh re-confirmation).
     *
     * @param string                $type
     * @param array<string, mixed>  $fields
     * @param array<string, string> $typedSecrets
     * @param string|null           $fingerprint Host key accepted by the administrator (SFTP).
     * @return void
     * @throws OffsiteRefused unknown_type | unavailable | invalid
     */
    public function save(string $type, array $fields, #[\SensitiveParameter] array $typedSecrets, ?string $fingerprint = null): void
    {
        $resolved = $this->resolve($type, $fields, $typedSecrets);
        if (!$resolved['type']->available()) {
            throw new OffsiteRefused(OffsiteRefused::UNAVAILABLE);
        }
        $problems = $resolved['type']->factory::validate($resolved['fields'], $resolved['secrets']);
        if ($problems !== []) {
            throw new OffsiteRefused(OffsiteRefused::INVALID, $problems);
        }
        $current = $this->current();
        $moved = $current === null || $current['type'] !== $type || !$resolved['type']->sameIdentity($current['fields'], $resolved['fields']);
        $fingerprint = $fingerprint !== null && preg_match('/^SHA256:[A-Za-z0-9+\/]{43}$/D', $fingerprint) === 1
            ? $fingerprint
            : ($moved ? null : ($current['fingerprint'] ?? null));
        DB::connection(GP247_DB_CONNECTION)->transaction(function () use ($type, $resolved, $fingerprint, $moved) {
            $this->write(self::CONFIG, json_encode(['type' => $type, 'fields' => $resolved['fields'], 'fingerprint' => $fingerprint], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 0);
            $this->write(self::SECRET, json_encode(array_filter($resolved['secrets'], fn ($v) => $v !== ''), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 1);
            if ($moved) {
                $this->forgetCopies();
            }
        });
    }

    /**
     * The destination now points to another place: rights found, copies recorded and copies
     * waiting for removal all belong to the old place. A copy job half-way through stops (its
     * first files went to the old place), and every archive counts as not copied, so the
     * screen never says "copied" for a place that holds nothing and "Copy again" is offered.
     *
     * @return void
     */
    private function forgetCopies(): void
    {
        $jobs = new JobRepository;
        foreach ($jobs->activeOfType(Archive::JOB_UPLOAD) as $job) {
            $jobs->finish((int) $job->id, null, Archive::JOB_CANCELLED, 'upload: destination changed');
        }
        DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'backup_archive')
            ->where('offsite', '!=', Archive::OFFSITE_NONE)->update(['offsite' => Archive::OFFSITE_NONE]);
        foreach ([self::REPORT, self::TRASH, self::RETENTION] as $key) {
            $this->write($key, '', 0);
        }
    }

    /**
     * @param string $fingerprint
     * @return void
     */
    public function acceptFingerprint(string $fingerprint): void
    {
        $current = $this->current();
        if ($current === null || preg_match('/^SHA256:[A-Za-z0-9+\/]{43}$/D', $fingerprint) !== 1) {
            return;
        }
        $current['fingerprint'] = $fingerprint;
        $this->write(self::CONFIG, json_encode($current, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 0);
    }

    /**
     * Forget the destination (nothing is deleted at the destination).
     *
     * @return void
     */
    public function remove(): void
    {
        foreach ([self::CONFIG, self::SECRET, self::REPORT, self::TRASH, self::RETENTION] as $key) {
            $this->write($key, '', $key === self::SECRET ? 1 : 0);
        }
    }

    /**
     * @return ConnectionReport|null Last connection test of the current destination.
     */
    public function report(): ?ConnectionReport
    {
        $data = $this->json(self::REPORT);

        return $data === [] ? null : ConnectionReport::fromArray($data);
    }

    /**
     * @param ConnectionReport $report
     * @return void
     */
    public function saveReport(ConnectionReport $report): void
    {
        $this->write(self::REPORT, json_encode($report->toArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 0);
    }

    /**
     * @return list<string> Archive ids whose off-site copy may be removed.
     */
    public function trash(): array
    {
        return array_values(array_filter(array_map('strval', $this->json(self::TRASH)), fn ($id) => preg_match('/^[0-9A-Z]{26}$/D', $id) === 1));
    }

    /**
     * Add archives whose off-site copy may now be removed.
     *
     * @param list<string> $ids
     * @return void
     */
    public function addTrash(array $ids): void
    {
        $this->changeTrash(fn (array $current) => array_merge($current, $ids));
    }

    /**
     * Take handled archives off the list.
     *
     * @param list<string> $ids
     * @return void
     */
    public function dropTrash(array $ids): void
    {
        $this->changeTrash(fn (array $current) => array_diff($current, $ids));
    }

    /**
     * WHY locked: local retention (after a backup) and off-site retention (after a copy job,
     * which may run beside it) both change the list; read-change-write under a row lock so
     * neither loses the other's ids.
     *
     * @param callable(list<string>): array<string> $change
     * @return void
     */
    private function changeTrash(callable $change): void
    {
        DB::connection(GP247_DB_CONNECTION)->transaction(function () use ($change) {
            $raw = (string) AdminConfig::where('store_id', GP247_STORE_ID_GLOBAL)->where('key', self::TRASH)->lockForUpdate()->value('value');
            $current = $raw === '' ? [] : (array) json_decode($raw, true);
            $current = array_values(array_filter(array_map('strval', $current), fn ($id) => preg_match('/^[0-9A-Z]{26}$/D', $id) === 1));
            $ids = array_slice(array_values(array_unique($change($current))), -self::TRASH_MAX);
            $this->write(self::TRASH, $ids === [] ? '' : json_encode($ids, JSON_THROW_ON_ERROR), 0);
        });
    }

    /**
     * @return array{deleted: int, skipped: int, at: int}|null Last off-site retention outcome.
     */
    public function retentionOutcome(): ?array
    {
        $data = $this->json(self::RETENTION);

        return $data === [] ? null : ['deleted' => (int) ($data['deleted'] ?? 0), 'skipped' => (int) ($data['skipped'] ?? 0), 'at' => (int) ($data['at'] ?? 0)];
    }

    /**
     * @param int $deleted
     * @param int $skipped
     * @return void
     */
    public function recordRetention(int $deleted, int $skipped): void
    {
        $this->write(self::RETENTION, json_encode(['deleted' => $deleted, 'skipped' => $skipped, 'at' => time()], JSON_THROW_ON_ERROR), 0);
    }

    /**
     * @param string $key
     * @return array<mixed>
     */
    private function json(string $key): array
    {
        $raw = (string) AdminConfig::where('store_id', GP247_STORE_ID_GLOBAL)->where('key', $key)->value('value');
        $data = $raw === '' ? null : json_decode($raw, true);

        return is_array($data) ? $data : [];
    }

    /**
     * @param string $key
     * @param string $value
     * @param int    $security 1 = secret (encrypted at rest).
     * @return void
     */
    private function write(string $key, #[\SensitiveParameter] string $value, int $security): void
    {
        AdminConfig::setConfigValue(self::GROUP, $key, GP247_STORE_ID_GLOBAL, $value, $security);
    }
}
