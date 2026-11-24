<?php

namespace GP247\Core\Tests\Unit\PersonalData;

use GP247\Core\PersonalData\Application\PersonalDataColumns;
use GP247\Core\PersonalData\Application\Requests\PrivacySettings;
use GP247\Core\PersonalData\Domain\Requests\RequestStatus;
use GP247\Core\PersonalData\Domain\Requests\RequestTransitions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Rules of personal data requests: which status moves are allowed, which statuses are open,
 * the limits of the settings, the column names that look like personal data.
 */
#[Group('personal-data-requests')]
class RequestRulesTest extends TestCase
{
    public function test_a_waiting_erasure_can_run_be_held_or_be_cancelled(): void
    {
        foreach ([RequestStatus::Processing, RequestStatus::OnHold, RequestStatus::Cancelled] as $to) {
            $this->assertTrue(RequestTransitions::allows(RequestStatus::Waiting, $to), $to->value);
        }
        $this->assertFalse(RequestTransitions::allows(RequestStatus::Waiting, RequestStatus::Completed));
    }

    public function test_a_closed_request_never_moves_again(): void
    {
        foreach ([RequestStatus::Completed, RequestStatus::Rejected, RequestStatus::Cancelled] as $from) {
            foreach (RequestStatus::cases() as $to) {
                $this->assertFalse(RequestTransitions::allows($from, $to), $from->value . ' -> ' . $to->value);
            }
        }
    }

    public function test_a_request_that_cannot_start_can_fail_and_one_starting_can_be_held(): void
    {
        $this->assertTrue(RequestTransitions::allows(RequestStatus::Waiting, RequestStatus::Failed));
        $this->assertTrue(RequestTransitions::allows(RequestStatus::OnHold, RequestStatus::Failed));
        $this->assertTrue(RequestTransitions::allows(RequestStatus::Processing, RequestStatus::OnHold));
    }

    public function test_a_processing_request_cannot_be_cancelled(): void
    {
        $this->expectException(\DomainException::class);

        RequestTransitions::assertAllowed(RequestStatus::Processing, RequestStatus::Cancelled);
    }

    public function test_open_statuses_are_every_status_that_is_not_closed(): void
    {
        $this->assertNotContains('completed', RequestStatus::openValues());
        $this->assertContains('failed', RequestStatus::openValues());
        $this->assertContains('on_hold', RequestStatus::openValues());
    }

    public function test_the_wait_and_the_overdue_days_stay_within_their_limits(): void
    {
        $this->assertSame(30, PrivacySettings::clampWait(45));
        $this->assertSame(0, PrivacySettings::clampWait(-2));
        $this->assertSame(3, PrivacySettings::clampWait('abc'));
        $this->assertSame(1, PrivacySettings::clampOverdue(0));
        $this->assertSame(20, PrivacySettings::clampOverdue(null));
    }

    public function test_column_names_that_look_like_personal_data(): void
    {
        $this->assertSame(['email', 'ship_phone', 'ip'], PersonalDataColumns::matching(['id', 'email', 'ship_phone', 'ip', 'email_hash', 'title']));
    }
}
