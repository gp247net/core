<?php

namespace GP247\Core\Backup\Application;

use GP247\Core\Backup\Domain\Archive;
use GP247\Core\Backup\Domain\RestorePlan;

/**
 * Turns the cursor of a running job into what the screen shows: the steps of that kind of job,
 * the step under way, an overall percentage and, when the step counts something, "done of total".
 *
 * Only counters are read from the cursor — never the data key, the plan's secrets or table data.
 * Steps without a counter (checking every part, swapping tables) count as half done while they
 * run, so the bar keeps moving between steps instead of sitting still.
 *
 * @aidlc-unit backup-admin
 * @aidlc-story US-backup-admin-dashboard
 */
final class JobProgress
{
    /** Unit of the "done of total" counter. */
    public const UNIT_TABLES = 'tables';
    public const UNIT_FILES = 'files';
    public const UNIT_PARTS = 'parts';
    public const UNIT_BYTES = 'bytes';

    /**
     * @param string               $type        Job type (Archive::JOB_*).
     * @param array<string, mixed> $cursor      The job's cursor.
     * @param int|null             $sourceBytes Size of the file being unpacked (import), when known.
     * @return array{steps: list<array{key: string, state: string}>, current: string, percent: int, done: int|null, total: int|null, unit: string|null}
     */
    public static function describe(string $type, #[\SensitiveParameter] array $cursor, ?int $sourceBytes = null): array
    {
        $plan = self::plan($type, $cursor);
        $current = self::step($type, $cursor, array_keys($plan));
        [$done, $total, $unit] = self::counter($type, $cursor, $current, $sourceBytes);
        $fraction = $total !== null && $total > 0 ? min(1.0, max(0.0, $done / $total)) : 0.5;

        $percent = 0.0;
        $steps = [];
        $passed = true;
        foreach ($plan as $key => $weight) {
            if ($key === $current) {
                $passed = false;
                $percent += $weight * $fraction;
                $steps[] = ['key' => $key, 'state' => 'current'];
                continue;
            }
            if ($passed) {
                $percent += $weight;
            }
            $steps[] = ['key' => $key, 'state' => $passed ? 'done' : 'todo'];
        }

        return [
            'steps' => $steps,
            'current' => $current,
            'percent' => (int) min(99, max(0, floor($percent))),
            'done' => $total === null ? null : (int) $done,
            'total' => $total,
            'unit' => $total === null ? null : $unit,
        ];
    }

    /**
     * Steps of a kind of job, with their share of the bar (sums to 100).
     *
     * @param string               $type
     * @param array<string, mixed> $cursor
     * @return array<string, int>
     */
    private static function plan(string $type, #[\SensitiveParameter] array $cursor): array
    {
        return match ($type) {
            Archive::JOB_BACKUP => ['tables' => 70, 'files' => 25, 'finish' => 5],
            Archive::JOB_VERIFY => ['check' => 100],
            Archive::JOB_RESTORE => match (true) {
                ($cursor['phase'] ?? null) === RestoreStep::UNDO_PHASE => ['undo' => 100],
                ($cursor['plan']['level'] ?? null) === RestorePlan::UNDELETE => ['verify' => 40, 'apply' => 55, 'finish' => 5],
                default => ['verify' => 25, 'load' => 55, 'swap' => 5, 'refresh' => 10, 'finish' => 5],
            },
            Archive::JOB_IMPORT => array_key_exists('fetch', $cursor) || ($cursor['phase'] ?? null) === 'fetch'
                ? ['fetch' => 45, 'verify' => 50, 'adopt' => 5]
                : ['unpack' => 45, 'verify' => 50, 'adopt' => 5],
            Archive::JOB_UPLOAD => ['upload' => 100],
            default => ['check' => 100],
        };
    }

    /**
     * The step a cursor phase belongs to (several internal phases share one visible step).
     *
     * @param string               $type
     * @param array<string, mixed> $cursor
     * @param list<string>         $steps
     * @return string
     */
    private static function step(string $type, #[\SensitiveParameter] array $cursor, array $steps): string
    {
        $phase = is_string($cursor['phase'] ?? null) ? $cursor['phase'] : '';
        $step = match ($type) {
            Archive::JOB_BACKUP => match ($phase) {
                'files' => 'files',
                'finish', 'done' => 'finish',
                default => 'tables',
            },
            Archive::JOB_RESTORE => match ($phase) {
                'maintenance', 'load' => 'load',
                'update' => 'refresh',
                'done' => 'finish',
                default => $phase,
            },
            Archive::JOB_IMPORT => match ($phase) {
                'open', 'verify' => 'verify',
                'adopt', 'done' => 'adopt',
                default => $phase,
            },
            default => $steps[0] ?? 'check',
        };

        return in_array($step, $steps, true) ? $step : ($steps[0] ?? 'check');
    }

    /**
     * "done of total" for the step under way, when that step counts something.
     *
     * @param string               $type
     * @param array<string, mixed> $cursor
     * @param string               $step
     * @param int|null             $sourceBytes
     * @return array{0: int|float, 1: int|null, 2: string|null}
     */
    private static function counter(string $type, #[\SensitiveParameter] array $cursor, string $step, ?int $sourceBytes): array
    {
        $count = static fn ($list): ?int => is_array($list) && $list !== [] ? count($list) : null;

        return match (true) {
            $type === Archive::JOB_BACKUP && $step === 'tables' => [(int) ($cursor['ti'] ?? 0), $count($cursor['plan']['tables'] ?? null), self::UNIT_TABLES],
            $type === Archive::JOB_BACKUP && $step === 'files' => [(int) ($cursor['fi'] ?? 0), $count($cursor['plan']['files'] ?? null), self::UNIT_FILES],
            $type === Archive::JOB_RESTORE && $step === 'load' => [(int) ($cursor['ti'] ?? 0), $count($cursor['tables'] ?? null), self::UNIT_TABLES],
            $type === Archive::JOB_IMPORT && $step === 'fetch' => [(int) ($cursor['fetch']['i'] ?? 0), $count($cursor['fetch']['order'] ?? null), self::UNIT_PARTS],
            $type === Archive::JOB_IMPORT && $step === 'unpack' && $sourceBytes !== null && $sourceBytes > 0 => [(int) ($cursor['unpack']['pos'] ?? 0), $sourceBytes, self::UNIT_BYTES],
            $type === Archive::JOB_UPLOAD => [(int) ($cursor['i'] ?? 0), $count($cursor['files'] ?? null), self::UNIT_PARTS],
            default => [0, null, null],
        };
    }
}
