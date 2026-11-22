<?php

namespace GP247\Core\Testing;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Test-case trait: fail a DatabaseTransactions test whose transaction was closed
 * by a DDL statement.
 *
 * WHY: on MySQL / MariaDB, CREATE / ALTER / DROP TABLE (also when run by a plugin
 * install) commit the open transaction implicitly. Laravel still counts one level,
 * so every write after the DDL lands in the database for good and the final
 * rollback undoes nothing — silently. This trait turns that into a failing test
 * that names the fix (ReopensTransactionAfterDdl).
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-public-test-suite
 * @aidlc-adr compat-foundation_public-test-placement
 */
trait GuardsDdlInTransaction
{
    /**
     * Fail the test when DDL closed its DatabaseTransactions transaction.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        // WHY read before parent::tearDown(): it rolls back and disconnects, which hides the state.
        $closed = $this->connectionsClosedByDdl();

        parent::tearDown();

        if ($closed !== []) {
            $this->fail(sprintf(
                'DDL committed the test transaction on connection(s) %s, so the writes after it were not rolled back. '
                . 'Create the schema in setUp before any write and call reopenTransactionAfterDdl() '
                . '(%s) right after the DDL.',
                implode(', ', $closed),
                ReopensTransactionAfterDdl::class
            ));
        }
    }

    /**
     * Names of the connections whose test transaction was closed by DDL.
     *
     * Laravel still counts an open level while the PDO connection is no longer inside a
     * transaction — the trace an implicit MySQL commit leaves.
     *
     * @return string[] Empty for a test without a transaction trait or with every transaction open.
     */
    protected function connectionsClosedByDdl(): array
    {
        // WHY both traits: RefreshDatabase also wraps each test in a transaction that DDL can close.
        $uses = class_uses_recursive(static::class);
        if ($this->app === null || (!isset($uses[DatabaseTransactions::class]) && !isset($uses[RefreshDatabase::class]))) {
            return [];
        }

        $closed = [];
        foreach ($this->connectionsToTransact() as $name) {
            $connection = $this->app['db']->connection($name);
            if ($connection->transactionLevel() > 0 && !$connection->getPdo()->inTransaction()) {
                $closed[] = $connection->getName();
            }
        }

        return $closed;
    }
}
