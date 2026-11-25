<?php

namespace GP247\Core\Tests\Unit\PersonalData;

use Carbon\CarbonImmutable;
use GP247\Core\PersonalData\Domain\DataSubject;
use GP247\Core\PersonalData\Domain\Retention\RetentionAction;
use GP247\Core\PersonalData\Domain\Retention\RetentionItem;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Rules of a retention item (how long data is kept): the allowed range of days, items that are
 * off by default or risky, the moment rows become due, and the "group of records" subject.
 */
#[Group('personal-data-retention')]
class RetentionRulesTest extends TestCase
{
    public function test_days_outside_the_allowed_range_are_kept_within_it(): void
    {
        $item = RetentionItem::rows('shop.carts', 'shop_shoppingcart')->days(180, 30, 730);

        $this->assertSame(180, $item->defaultDays());
        $this->assertSame(30, $item->clampDays(1));
        $this->assertSame(730, $item->clampDays(99999));
        $this->assertSame(200, $item->clampDays('200'));
        $this->assertSame(180, $item->clampDays('abc'), 'a value that is not a number falls back to the default');
    }

    public function test_an_item_is_on_by_default_unless_it_is_declared_off_and_risky_items_say_so(): void
    {
        $carts = RetentionItem::rows('shop.carts', 'shop_shoppingcart');
        $orders = RetentionItem::records('shop.orders', 'shop_order')->days(3650, 365, 7300)->disabledByDefault()->risky(['orders']);

        $this->assertTrue($carts->enabledByDefault());
        $this->assertFalse($carts->isRisky());
        $this->assertSame(RetentionAction::Delete, $carts->action);
        $this->assertFalse($orders->enabledByDefault());
        $this->assertTrue($orders->isRisky());
        $this->assertSame(['orders'], $orders->backupGroups());
        $this->assertSame(RetentionAction::Anonymise, $orders->action);
    }

    public function test_rows_older_than_the_days_kept_are_due(): void
    {
        $now = CarbonImmutable::parse('2026-11-25 10:00:00');
        $item = RetentionItem::rows('front.contact', 'front_contact')->days(730, 30, 3650);

        $this->assertSame('2024-11-25 10:00:00', $item->cutoff(730, $now)->format('Y-m-d H:i:s'));
    }

    public function test_an_item_with_its_own_expiry_ignores_the_days_and_cannot_be_given_days(): void
    {
        $now = CarbonImmutable::parse('2026-11-25 10:00:00');
        $item = RetentionItem::rows('shop.password_resets', 'shop_password_resets')
            ->expiresAfter(fn () => 60 * 60);

        $this->assertTrue($item->hasFixedExpiry());
        $this->assertSame('2026-11-25 09:00:00', $item->cutoff(999, $now)->format('Y-m-d H:i:s'));
    }

    public function test_a_key_must_be_a_short_plain_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RetentionItem::rows('shop carts; DROP', 'shop_shoppingcart');
    }

    public function test_an_account_item_needs_a_way_to_find_inactive_accounts(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RetentionItem::accounts('shop.inactive_accounts')->assertComplete();
    }

    public function test_the_config_keys_of_an_item_are_stable_plain_names(): void
    {
        $item = RetentionItem::rows('shop.carts', 'shop_shoppingcart');

        $this->assertSame('pd_retention_shop_carts_enabled', $item->enabledKey());
        $this->assertSame('pd_retention_shop_carts_days', $item->daysKey());
    }

    public function test_a_group_of_records_is_a_subject_without_customer_or_email(): void
    {
        $subject = DataSubject::records('shop_order', [3, '7', 3]);

        $this->assertNull($subject->customerId);
        $this->assertSame([], $subject->emails);
        $this->assertFalse($subject->isGuest());
        $this->assertSame(['table' => 'shop_order', 'ids' => ['3', '7']], $subject->records);
    }

    public function test_a_group_of_records_needs_at_least_one_record(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DataSubject::records('shop_order', []);
    }
}
