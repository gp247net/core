<?php

namespace GP247\Core\Tests\Feature\PersonalData;

use GP247\Core\Support\StepUp\StepUpContext;
use GP247\Core\Support\StepUp\StepUpGate;
use GP247\Core\Support\StepUp\StepUpRefused;
use GP247\Core\Tests\TestCase;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Group;

/**
 * Before a dangerous action the signed-in person types their password again; the proof lasts
 * a few minutes in this session only, wrong answers are limited, and two features never share
 * one proof.
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-customer-privacy-admin-delete-complete
 * @aidlc-adr personal-data-registry_shared-step-up
 */
#[Group('personal-data-registry')]
class SharedStepUpTest extends TestCase
{
    private GenericUser $user;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->user = new GenericUser(['id' => 'admin-7', 'password' => Hash::make('correct horse')]);
        RateLimiter::clear('test-step-up:admin-7');
        session()->start();
    }

    /**
     * @param string $sessionKey
     * @return StepUpGate
     */
    private function gate(string $sessionKey = 'gp247_test_step_up'): StepUpGate
    {
        return new StepUpGate(new StepUpContext(fn () => $this->user, 'admin', $sessionKey, 300, 'test-step-up:'));
    }

    public function test_the_right_password_gives_a_proof_valid_in_this_session(): void
    {
        $this->assertFalse($this->gate()->isFresh());

        $this->gate()->confirm('correct horse');

        $this->assertTrue($this->gate()->isFresh());
    }

    public function test_a_wrong_password_is_refused_and_gives_no_proof(): void
    {
        try {
            $this->gate()->confirm('wrong');
            $this->fail('wrong password accepted');
        } catch (StepUpRefused $e) {
            $this->assertSame(StepUpRefused::WRONG_PASSWORD, $e->reason);
        }
        $this->assertFalse($this->gate()->isFresh());
    }

    public function test_after_five_wrong_tries_even_the_right_password_waits(): void
    {
        for ($i = 0; $i < StepUpGate::MAX_ATTEMPTS; $i++) {
            try {
                $this->gate()->confirm('wrong');
            } catch (StepUpRefused $e) {
            }
        }

        try {
            $this->gate()->confirm('correct horse');
            $this->fail('throttle skipped');
        } catch (StepUpRefused $e) {
            $this->assertSame(StepUpRefused::THROTTLED, $e->reason);
            $this->assertGreaterThan(0, $e->retryAfter);
        }
    }

    public function test_a_proof_given_to_one_feature_does_not_open_another(): void
    {
        $this->gate('gp247_feature_a')->confirm('correct horse');

        $this->assertTrue($this->gate('gp247_feature_a')->isFresh());
        $this->assertFalse($this->gate('gp247_feature_b')->isFresh());
    }

    public function test_a_proof_from_another_session_is_not_accepted(): void
    {
        $this->gate()->confirm('correct horse');
        session()->setId(str_repeat('a', 40));

        $this->assertFalse($this->gate()->isFresh());
    }

    public function test_nobody_signed_in_cannot_confirm(): void
    {
        $gate = new StepUpGate(new StepUpContext(fn () => null, 'admin', 'gp247_test_step_up', 300, 'test-step-up:'));

        $this->expectException(StepUpRefused::class);

        $gate->confirm('anything');
    }
}
