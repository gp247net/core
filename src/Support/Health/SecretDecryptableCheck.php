<?php

namespace GP247\Core\Support\Health;

use GP247\Core\AdminShell\Domain\AdminAlertCheck;
use GP247\Core\AdminShell\Infrastructure\AdminAlert;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Stored secrets (registered encrypted columns) that no longer decrypt.
 *
 * A failing row is the tell-tale of a changed key: mail, payment and similar
 * features silently stop. Each broken row is named (group/key@store or
 * table.column#id) with its key id — never its value. Inlined decryption (no
 * gp247_* helper) because `gp247:doctor` is a bootstrap-tier command.
 * Throws when the database cannot be read; callers treat that as "skipped".
 *
 * Doctor row `secret_decryptable` — detail text kept word for word.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-system-alerts, US-CLI-004, US-CMP-secret-decrypt-diagnosable
 * @aidlc-adr admin-shell_system-alert-registry, compat-foundation_config-secret-at-rest
 */
class SecretDecryptableCheck implements AdminAlertCheck
{
    private const SHOWN = 10;

    /** @return string */
    public function key(): string
    {
        return 'secret_decryptable';
    }

    /** @return int */
    public function ttl(): int
    {
        return 3600;
    }

    /**
     * @return AdminAlert
     */
    public function evaluate(): AdminAlert
    {
        [$total, $broken] = $this->scan();

        if ($broken === []) {
            return AdminAlert::ok($this->key(), HealthText::t('admin.alerts.secret_decryptable_ok'), $total . ' secrets OK');
        }

        $shown = array_slice($broken, 0, self::SHOWN);
        $more = count($broken) - count($shown);

        return AdminAlert::critical(
            $this->key(),
            HealthText::t('admin.alerts.secret_decryptable', ['count' => count($broken)]),
            count($broken) . ' of ' . $total . ' secrets undecryptable: ' . implode(', ', $shown)
                . ($more > 0 ? ' …and ' . $more . ' more' : '')
                . ' — the encryption key may have changed; set GP247_ENCRYPTION_PREVIOUS_KEYS / APP_PREVIOUS_KEYS or re-enter them',
            docUrl: HealthText::doc('security-keys-and-backup'),
            items: $broken,
        );
    }

    /**
     * @return array{0: int, 1: array<int, string>} Total secrets and labels of the broken ones.
     */
    private function scan(): array
    {
        $connection = DB::connection(GP247_DB_CONNECTION);
        $schema = $connection->getSchemaBuilder();
        $total = 0;
        $broken = [];

        foreach ((array) config('gp247-config.security.encrypted_columns', []) as $table => $cols) {
            $fullTable = GP247_DB_PREFIX . $table;
            // WHY: a registered table missing on this site (feature not upgraded) holds no secret.
            if (!$schema->hasTable($fullTable)) {
                continue;
            }
            $identity = $schema->hasColumns($fullTable, ['group', 'key', 'store_id'])
                ? ['group', 'key', 'store_id']
                : ($schema->hasColumn($fullTable, 'id') ? ['id'] : []);
            foreach ((array) $cols as $column) {
                if (!$schema->hasColumn($fullTable, (string) $column)) {
                    continue;
                }
                $rows = $connection->table($fullTable)
                    ->where($column, 'like', 'enc:%')
                    ->get(array_merge($identity, [$column]));
                foreach ($rows as $row) {
                    $value = (string) ($row->{$column} ?? '');
                    $total++;
                    if (!$this->canDecrypt($value)) {
                        $broken[] = $this->rowLabel((string) $table, (string) $column, $identity, $row, $value);
                    }
                }
            }
        }

        return [$total, $broken];
    }

    /**
     * "<group>/<key>@<store_id> kid=<kid>" for config rows, "<table>.<column>#<id> kid=<kid>"
     * otherwise ("v1" = legacy APP_KEY envelope). Never includes the value.
     *
     * @param string             $table
     * @param string             $column
     * @param array<int, string> $identity
     * @param object             $row
     * @param string             $value
     * @return string
     */
    private function rowLabel(string $table, string $column, array $identity, object $row, string $value): string
    {
        if ($identity === ['group', 'key', 'store_id']) {
            $label = $row->group . '/' . $row->key . '@' . $row->store_id;
        } else {
            $label = $table . '.' . $column . ($identity === ['id'] ? '#' . $row->id : '');
        }

        $kid = 'v1';
        if (str_starts_with($value, 'enc:v2:')) {
            $rest = substr($value, strlen('enc:v2:'));
            $kid = substr($rest, 0, (int) strpos($rest, ':'));
        }

        return $label . ' kid=' . $kid;
    }

    /**
     * Whether one enveloped value decrypts (v1 = APP_KEY/Crypt, v2 = dedicated key).
     *
     * @param string $value
     * @return bool
     */
    private function canDecrypt(string $value): bool
    {
        try {
            if (str_starts_with($value, 'enc:v2:')) {
                $rest = substr($value, strlen('enc:v2:'));
                $encrypter = $this->dedicatedEncrypter();
                if ($encrypter === null) {
                    return false;
                }
                $encrypter->decryptString(substr($rest, strpos($rest, ':') + 1));

                return true;
            }
            if (str_starts_with($value, 'enc:v1:')) {
                Crypt::decryptString(substr($value, strlen('enc:v1:')));

                return true;
            }
        } catch (\Throwable $e) {
            return false;
        }

        return false;
    }

    /**
     * Encrypter over the dedicated key set (active + previous), or the APP_KEY set when
     * the dedicated key is unset.
     *
     * @return Encrypter|null
     */
    private function dedicatedEncrypter(): ?Encrypter
    {
        $active = (string) config('gp247-config.security.encryption_key', '');
        if ($active !== '') {
            $previous = (array) config('gp247-config.security.encryption_previous_keys', []);
        } else {
            $active = (string) config('app.key', '');
            $previous = (array) config('app.previous_keys', []);
        }
        $parse = static function (string $k): string {
            $k = trim($k);

            return $k !== '' && str_starts_with($k, 'base64:') ? (string) base64_decode(substr($k, 7)) : $k;
        };
        $activeRaw = $parse($active);
        if ($activeRaw === '') {
            return null;
        }
        $encrypter = new Encrypter($activeRaw, (string) config('app.cipher', 'AES-256-CBC'));
        $prev = array_values(array_filter(array_map($parse, $previous)));
        if ($prev !== []) {
            $encrypter->previousKeys($prev);
        }

        return $encrypter;
    }
}
