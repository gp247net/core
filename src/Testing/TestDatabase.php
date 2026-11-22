<?php

namespace GP247\Core\Testing;

use Illuminate\Support\ConfigurationUrlParser;

/**
 * Decides whether a database may be used by an automated test run.
 *
 * WHY: GP247 tests write rows, run DDL (which MySQL commits implicitly, so a test
 * transaction cannot always undo it) and some swap whole tables. Anyone can run the
 * public test suites, including on a copy of a live shop; a database qualifies only
 * when its name marks it as a test database, or when the person running the tests
 * names it explicitly.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-public-test-suite
 * @aidlc-adr compat-foundation_public-test-placement
 */
final class TestDatabase
{
    /** Environment variable that confirms a database without a test suffix. */
    public const CONFIRM_ENV = 'GP247_TEST_DATABASE';

    /**
     * Whether a database name may be used by the tests.
     *
     * @param string $database Name of the database the connection points to.
     * @param string|null $confirmed Value of GP247_TEST_DATABASE, when set.
     * @return bool True for a name ending in `_test` / `_testing`, or exactly the confirmed name —
     *              each optionally followed by the `_test_<n>` suffix Laravel gives a parallel worker.
     */
    public static function isAllowed(string $database, ?string $confirmed = null): bool
    {
        if ($database === '') {
            return false;
        }
        // WHY the optional worker suffix: a parallel run (paratest) switches each worker to
        // "<base>_test_<n>"; it is a test database only when <base> itself is one.
        if (preg_match('/_(test|testing)(_test_\d+)?$/', $database) === 1) {
            return true;
        }
        if ($confirmed === null || $confirmed === '') {
            return false;
        }

        return $database === $confirmed || preg_match('/^' . preg_quote($confirmed, '/') . '_test_\d+$/', $database) === 1;
    }

    /**
     * Every database name a connection configuration can write to.
     *
     * Resolves a `url` (DB_URL) the way Laravel does — it wins over the plain keys — and
     * includes the `read` / `write` overrides of a split connection.
     *
     * @param array $config One entry of `database.connections`.
     * @return string[] Database names ('' when a part names none).
     */
    public static function databasesOf(array $config): array
    {
        $config = (new ConfigurationUrlParser())->parseConfiguration($config);
        $names = [(string) ($config['database'] ?? '')];
        foreach (['read', 'write'] as $side) {
            if (is_array($config[$side] ?? null) && array_key_exists('database', $config[$side])) {
                $names[] = (string) $config[$side]['database'];
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Message shown when a test run is refused.
     *
     * @param string $connection Connection name.
     * @param string $database Database name ('' when none is configured).
     * @return string
     */
    public static function refusal(string $connection, string $database): string
    {
        return sprintf(
            'Refusing to run tests on database "%s" (connection "%s"): it is not marked as a test database. '
            . 'Point DB_DATABASE to a database whose name ends with _test or _testing, or set %s=<database name> '
            . 'to confirm that this database may be written and wiped.',
            $database,
            $connection,
            self::CONFIRM_ENV
        );
    }
}
