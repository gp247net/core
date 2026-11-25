<?php

namespace GP247\Core\PersonalData\Application\Retention;

use Carbon\CarbonImmutable;
use GP247\Core\PersonalData\Application\PersonalDataRegistry;
use GP247\Core\PersonalData\Application\Requests\CustomerErasures;
use GP247\Core\PersonalData\Domain\Retention\RetentionAction;
use GP247\Core\PersonalData\Domain\Retention\RetentionItem;
use GP247\Core\PersonalData\Infrastructure\Requests\RequestRepository;

/**
 * Accounts unused for the retention period: each one gets an erasure request that waits
 * NOTICE_DAYS (the customer is told and keeps the account by signing in), then runs like the
 * customer's own erasure — held while an order is still open.
 *
 * "Unused since" counts from the latest of the customer's activity (found by the package that
 * owns accounts) and the day the owner turned the item on: accounts are never judged on activity
 * the site did not record before. A customer who called off a notice is not asked again before a
 * whole period has passed, and activity during the notice is checked again before the erasure runs.
 *
 * @aidlc-unit personal-data-retention
 * @aidlc-story US-personal-data-retention-inactive-accounts
 * @aidlc-adr personal-data-retention_inactive-accounts
 */
final class InactiveAccounts
{
    public const NOTICE_DAYS = 30;
    /** WHY 10: each account may get an email, sent while the visitor's request ends — a slice stays short. */
    public const LIMIT = 10;
    /** Most accounts counted for a preview. */
    private const COUNT_LIMIT = 10000;

    /**
     * @param CustomerErasures  $erasures
     * @param RequestRepository $requests
     */
    public function __construct(private readonly CustomerErasures $erasures, private readonly RequestRepository $requests)
    {
    }

    /**
     * One slice: requests for at most LIMIT accounts after the cursor.
     *
     * @param RetentionItem        $item
     * @param int                  $days         Days without activity before an account is erased.
     * @param CarbonImmutable|null $enabledSince Since when the item is on.
     * @param string|null          $after        Last account id of the previous slice.
     * @param CarbonImmutable      $now
     * @return array{0: int, 1: bool, 2: string|null} Requests created, more to look at, cursor.
     */
    public function sweep(RetentionItem $item, int $days, ?CarbonImmutable $enabledSince, ?string $after, CarbonImmutable $now): array
    {
        $since = self::noticeFrom($days, $now);
        // WHY: activity before the item was turned on was not all recorded (last sign-in is new).
        if ($enabledSince === null || $enabledSince->greaterThan($since)) {
            return [0, false, null];
        }
        $found = ($item->accountFinder())($since, $after, self::LIMIT);
        $created = 0;
        $last = null;
        foreach ($found as $account) {
            $last = (string) $account['id'];
            // WHY: a customer who called off the previous notice chose to keep the account — not asked again before a whole period.
            if ($this->requests->retentionKeptSince($last, $since)) {
                continue;
            }
            if ($this->erasures->scheduleForRetention($last, (string) ($account['store_id'] ?? (defined('GP247_STORE_ID_ROOT') ? GP247_STORE_ID_ROOT : 1)), $now->addDays(self::NOTICE_DAYS), $days)) {
                $created++;
            }
        }
        $more = count($found) >= self::LIMIT;

        return [$created, $more, $more ? $last : null];
    }

    /**
     * @param RetentionItem   $item
     * @param int             $days
     * @param CarbonImmutable $now
     * @return int Accounts without activity long enough to be told now (preview; ignores since when the item is on).
     */
    public function count(RetentionItem $item, int $days, CarbonImmutable $now): int
    {
        $since = self::noticeFrom($days, $now);
        if ($item->accountCounter() !== null) {
            return (int) ($item->accountCounter())($since);
        }

        return count(($item->accountFinder())($since, null, self::COUNT_LIMIT));
    }

    /**
     * Checked again before an unused account is erased: activity during the notice keeps it.
     *
     * @param string             $customerId
     * @param \DateTimeInterface $since
     * @return bool Whether any package that owns accounts saw the customer active since the date.
     */
    public static function activeSince(string $customerId, \DateTimeInterface $since): bool
    {
        foreach (PersonalDataRegistry::retentionItems() as $item) {
            if ($item->action === RetentionAction::EraseAccounts && $item->activityCheck() !== null && ($item->activityCheck())($customerId, $since) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param int             $days
     * @param CarbonImmutable $now
     * @return CarbonImmutable Accounts with no activity since this moment are told now (erased NOTICE_DAYS later).
     */
    public static function noticeFrom(int $days, CarbonImmutable $now): CarbonImmutable
    {
        return $now->subDays(max(self::NOTICE_DAYS, $days) - self::NOTICE_DAYS);
    }
}
