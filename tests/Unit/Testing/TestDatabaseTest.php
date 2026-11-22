<?php

namespace GP247\Core\Tests\Unit\Testing;

use GP247\Core\Testing\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Which databases the test suites agree to run on.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-public-test-suite
 */
class TestDatabaseTest extends TestCase
{
    public function test_a_database_named_as_a_test_database_is_accepted(): void
    {
        $this->assertTrue(TestDatabase::isAllowed('gp247_test'));
        $this->assertTrue(TestDatabase::isAllowed('shop_testing'));
    }

    public function test_a_database_without_a_test_suffix_is_refused(): void
    {
        $this->assertFalse(TestDatabase::isAllowed('shop'));
        $this->assertFalse(TestDatabase::isAllowed('shop_test_copy'));
        $this->assertFalse(TestDatabase::isAllowed('latest'));
        $this->assertFalse(TestDatabase::isAllowed(''));
    }

    public function test_a_parallel_worker_database_is_accepted_only_when_its_base_is_a_test_database(): void
    {
        $this->assertTrue(TestDatabase::isAllowed('gp247_test_test_3'));
        $this->assertFalse(TestDatabase::isAllowed('shop_test_3'));
    }

    public function test_a_database_confirmed_by_name_is_accepted_and_only_that_one(): void
    {
        $this->assertTrue(TestDatabase::isAllowed('shop_copy', 'shop_copy'));
        $this->assertFalse(TestDatabase::isAllowed('shop', 'shop_copy'));
        $this->assertFalse(TestDatabase::isAllowed('shop', ''));
    }

    public function test_a_confirmed_database_is_also_accepted_for_its_parallel_workers(): void
    {
        $this->assertTrue(TestDatabase::isAllowed('shop_copy_test_2', 'shop_copy'));
        $this->assertFalse(TestDatabase::isAllowed('shop_copy_live', 'shop_copy'));
    }

    public function test_a_database_url_wins_over_the_database_key(): void
    {
        $names = TestDatabase::databasesOf([
            'driver' => 'mysql',
            'url' => 'mysql://root@127.0.0.1:3306/realshop',
            'database' => 'gp247_test',
        ]);

        $this->assertSame(['realshop'], $names);
        $this->assertFalse(TestDatabase::isAllowed($names[0]));
    }

    public function test_the_read_and_write_databases_of_a_split_connection_are_all_checked(): void
    {
        $names = TestDatabase::databasesOf([
            'driver' => 'mysql',
            'database' => 'gp247_test',
            'write' => ['database' => 'shop'],
        ]);

        $this->assertSame(['gp247_test', 'shop'], $names);
    }

    public function test_the_refusal_names_the_database_and_how_to_confirm_it(): void
    {
        $message = TestDatabase::refusal('mysql', 'shop');

        $this->assertStringContainsString('"shop"', $message);
        $this->assertStringContainsString('_test', $message);
        $this->assertStringContainsString(TestDatabase::CONFIRM_ENV, $message);
    }
}
