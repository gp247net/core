<?php

namespace GP247\Core\Testing;

use Illuminate\Support\Facades\DB;

/**
 * Keep a DatabaseTransactions test isolated after it had to run DDL.
 *
 * WHY: on MySQL / MariaDB, CREATE / DROP TABLE commit the open transaction implicitly.
 * Laravel still counts one level, but the PDO connection is no longer in a transaction,
 * so every write after the DDL is committed for good and the final rollback undoes
 * nothing. Re-opening the transaction right after the DDL puts the rest of the test
 * back under the rollback.
 *
 * Writes made BEFORE the DDL are already committed; create the schema a test needs
 * first (setUp), then write.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-public-test-suite
 * @aidlc-adr compat-foundation_public-test-placement
 */
trait ReopensTransactionAfterDdl
{
    /**
     * Re-open the test transaction if a DDL statement closed it.
     *
     * @return void
     */
    protected function reopenTransactionAfterDdl(): void
    {
        // WHY the same list as GuardsDdlInTransaction: reopen every connection the test
        // transacts, not only the GP247 one, so the two never disagree.
        $names = method_exists($this, 'connectionsToTransact') ? $this->connectionsToTransact() : [];
        $names[] = GP247_DB_CONNECTION;
        foreach (array_unique(array_map(fn ($name) => $name ?? config('database.default'), $names)) as $name) {
            $connection = DB::connection($name);
            if ($connection->transactionLevel() > 0 && !$connection->getPdo()->inTransaction()) {
                $connection->getPdo()->beginTransaction();
            }
        }
    }
}
