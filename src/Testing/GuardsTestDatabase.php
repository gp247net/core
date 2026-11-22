<?php

namespace GP247\Core\Testing;

use Illuminate\Support\Env;

/**
 * Test-case trait: fail before any test work when a connection the test may write to
 * points to a database that is not marked as a test database.
 *
 * Use it on the base TestCase of a GP247 package or plugin test suite. The check runs
 * twice: right after the application is created (before Laravel's parallel-testing
 * callbacks create or migrate a per-worker database), and again in setUpTraits() on the
 * final configuration (before DatabaseTransactions / RefreshDatabase open a transaction
 * or migrate anything).
 *
 * Limit: service providers run while the application boots, i.e. before the first
 * check; a provider that writes to the database at boot is not covered.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-public-test-suite
 * @aidlc-adr compat-foundation_public-test-placement
 */
trait GuardsTestDatabase
{
    /**
     * Create the application, then refuse a non-test database before anything else runs.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        $app = parent::createApplication();
        $this->refuseNonTestDatabases($app['config']);

        return $app;
    }

    /**
     * Refuse a non-test database on the final configuration, then boot the testing traits.
     *
     * @return array
     */
    protected function setUpTraits()
    {
        $this->refuseNonTestDatabases($this->app['config']);

        return parent::setUpTraits();
    }

    /**
     * Fail the test when one of the guarded connections names a non-test database.
     *
     * @param \Illuminate\Contracts\Config\Repository $config
     * @return void
     */
    protected function refuseNonTestDatabases($config): void
    {
        $confirmed = Env::get(TestDatabase::CONFIRM_ENV);
        $confirmed = $confirmed === null ? null : (string) $confirmed;
        foreach ($this->guardedConnectionNames($config) as $connection) {
            foreach (TestDatabase::databasesOf((array) $config->get("database.connections.$connection", [])) as $database) {
                if (!TestDatabase::isAllowed($database, $confirmed)) {
                    $this->fail(TestDatabase::refusal($connection, $database));
                }
            }
        }
    }

    /**
     * Connections a test may write to: the default one, the GP247 one, and every
     * connection the database testing traits of this test transact or truncate.
     *
     * @param \Illuminate\Contracts\Config\Repository $config
     * @return string[]
     */
    protected function guardedConnectionNames($config): array
    {
        $default = (string) $config->get('database.default');
        $names = [$default, (string) $config->get('gp247-config.env.GP247_DB_CONNECTION', '')];
        foreach (['connectionsToTransact', 'connectionsToTruncate'] as $method) {
            if (method_exists($this, $method)) {
                foreach ((array) $this->{$method}() as $name) {
                    $names[] = $name === null ? $default : (string) $name;
                }
            }
        }

        return array_values(array_unique(array_filter($names)));
    }
}
