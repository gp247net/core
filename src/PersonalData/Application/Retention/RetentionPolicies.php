<?php

namespace GP247\Core\PersonalData\Application\Retention;

use GP247\Core\PersonalData\Application\PersonalDataRegistry;
use GP247\Core\PersonalData\Application\Requests\PrivacySettings;
use GP247\Core\PersonalData\Domain\Retention\RetentionItem;
use Illuminate\Support\Facades\DB;

/**
 * The site owner's choice for each retention item: on or off, and the days kept (within the
 * range the item allows). One policy for the whole site, read from admin_config (group
 * personal_data, store GLOBAL); an item without a row uses its declared default.
 *
 * @aidlc-unit personal-data-retention
 * @aidlc-story US-personal-data-retention-policies
 * @aidlc-adr personal-data-retention_retention-registry
 */
final class RetentionPolicies
{
    /** @var array<string, string>|null Key => value of every retention setting (one query). */
    private ?array $values = null;

    /**
     * @param RetentionItem $item
     * @return bool
     */
    public function enabled(RetentionItem $item): bool
    {
        $value = $this->values()[$item->enabledKey()] ?? null;

        return $value === null ? $item->enabledByDefault() : $value === '1';
    }

    /**
     * @param RetentionItem $item
     * @return int Days kept, within the item's range.
     */
    public function days(RetentionItem $item): int
    {
        return $item->clampDays($this->values()[$item->daysKey()] ?? $item->defaultDays());
    }

    /**
     * @param RetentionItem $item
     * @param bool          $enabled
     * @param int           $days
     * @return void
     */
    public function save(RetentionItem $item, bool $enabled, int $days): void
    {
        foreach (self::rowsOf($item, $enabled ? '1' : '0', (string) $item->clampDays($days)) as $row) {
            // WHY a new builder per row: updateOrInsert() adds its conditions to the builder it is called on.
            DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'admin_config')
                ->updateOrInsert(['key' => $row['key'], 'store_id' => $row['store_id']], $row);
        }
        $this->values = null;
    }

    /**
     * @return list<array<string, mixed>> admin_config rows with every declared item's defaults (inserted only when missing).
     */
    public static function defaultRows(): array
    {
        $rows = [];
        foreach (PersonalDataRegistry::retentionItems() as $item) {
            array_push($rows, ...self::rowsOf($item, $item->enabledByDefault() ? '1' : '0', (string) $item->defaultDays()));
        }

        return $rows;
    }

    /**
     * @param RetentionItem $item
     * @param string        $enabled
     * @param string        $days
     * @return list<array<string, mixed>>
     */
    private static function rowsOf(RetentionItem $item, string $enabled, string $days): array
    {
        $row = fn (string $key, string $value, int $sort) => [
            'group' => PrivacySettings::GROUP, 'code' => PrivacySettings::GROUP, 'key' => $key, 'value' => $value, 'security' => 0,
            'store_id' => (string) GP247_STORE_ID_GLOBAL, 'sort' => $sort, 'detail' => 'admin.personal_data.retention.title',
        ];

        return [$row($item->enabledKey(), $enabled, 20), $row($item->daysKey(), $days, 21)];
    }

    /**
     * @return array<string, string>
     */
    private function values(): array
    {
        if ($this->values === null) {
            try {
                $this->values = DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'admin_config')
                    ->where('store_id', (string) GP247_STORE_ID_GLOBAL)->where('key', 'like', 'pd_retention_%')
                    ->pluck('value', 'key')->map(fn ($v) => (string) $v)->all();
            } catch (\Throwable $e) {
                $this->values = [];
            }
        }

        return $this->values;
    }
}
