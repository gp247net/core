<?php

namespace GP247\Core\Tests\Feature\PersonalData\Retention\Support;

use Carbon\CarbonImmutable;
use GP247\Core\PersonalData\Application\PersonalDataRegistry;
use GP247\Core\PersonalData\Application\Requests\RequestScheduler;
use GP247\Core\PersonalData\Application\Retention\RetentionSweeper;
use GP247\Core\PersonalData\Domain\PersonalDataSource;
use GP247\Core\PersonalData\Domain\Retention\RetentionItem;
use GP247\Core\PersonalData\Domain\SubjectLink;
use GP247\Core\PersonalData\Infrastructure\PersonalDataSchema;
use GP247\Core\PersonalData\Infrastructure\Retention\RetentionStateRepository;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tables owned by the tests for retention (carts, an administrators' log, orders with notes)
 * and their retention items, on top of UsesPersonalDataFixtures (the host class uses both).
 * The tables are created once (DDL) before any write of the test.
 *
 * @aidlc-unit personal-data-retention
 * @aidlc-story US-personal-data-retention-lazy-run
 */
trait UsesRetentionFixtures
{
    /** Closed order statuses of the fixture orders. */
    private const CLOSED = [5, 6];

    /**
     * @return void
     */
    protected function setUpRetention(): void
    {
        $this->createRetentionTables();
        RetentionStateRepository::forgetReady();
        RequestScheduler::forgetReady();
        $this->declareRetentionFixtures();
    }

    /**
     * @return void
     */
    private function createRetentionTables(): void
    {
        $schema = Schema::connection(GP247_DB_CONNECTION);
        $p = GP247_DB_PREFIX;
        $ddl = false;
        if (!$schema->hasTable($p . 'pdt_rt_cart')) {
            $schema->create($p . 'pdt_rt_cart', function (Blueprint $t) {
                $t->string('identifier', 64);
                $t->string('instance', 32);
                $t->text('content')->nullable();
                $t->timestamp('updated_at')->nullable();
            });
            $ddl = true;
        }
        if (!$schema->hasTable($p . 'pdt_rt_log')) {
            $schema->create($p . 'pdt_rt_log', function (Blueprint $t) {
                $t->increments('id');
                $t->string('content', 200);
                $t->timestamp('created_at')->nullable();
            });
            $ddl = true;
        }
        if (!$schema->hasTable($p . 'pdt_rt_order')) {
            $schema->create($p . 'pdt_rt_order', function (Blueprint $t) {
                $t->increments('id');
                $t->string('customer_id', 36)->default('0');
                $t->string('email', 150)->nullable();
                $t->string('first_name', 100)->nullable();
                $t->string('phone', 30)->nullable();
                $t->unsignedTinyInteger('status')->default(1);
                $t->decimal('total', 15, 2)->default(0);
                $t->timestamp('created_at')->nullable();
            });
            $ddl = true;
        }
        if (!$schema->hasTable($p . 'pdt_rt_order_note')) {
            $schema->create($p . 'pdt_rt_order_note', function (Blueprint $t) {
                $t->increments('id');
                $t->unsignedInteger('order_id');
                $t->text('content');
            });
            $ddl = true;
        }
        if ($ddl) {
            $this->reopenTransactionAfterDdl();
        }
    }

    /**
     * @return void
     */
    private function declareRetentionFixtures(): void
    {
        PersonalDataRegistry::declare(
            PersonalDataSource::table('pdt_rt_cart', 'identifier')->link(SubjectLink::customer('identifier'))->deleteRows()->order(60)->describe('test.carts', 'tests')
        );
        PersonalDataRegistry::declare(
            PersonalDataSource::table('pdt_rt_order_note')->link(SubjectLink::parent('pdt_rt_order', 'order_id'))
                ->anonymise(['content' => 'scrubText'])->order(10)->describe('test.notes', 'tests')
        );
        PersonalDataRegistry::declare(
            PersonalDataSource::table('pdt_rt_order')
                ->link(SubjectLink::customer('customer_id'))
                ->anonymise(['first_name' => 'mark', 'email' => 'null', 'phone' => 'null', 'total' => 'keep'])
                ->identity(['first_name', 'phone'], ['email'])
                ->order(40)->describe('test.rt_orders', 'tests')
        );
        PersonalDataRegistry::notCustomerData('pdt_rt_log', 'administrators log of the tests');
        PersonalDataRegistry::retention(
            RetentionItem::rows('test.wishlist', 'pdt_rt_cart')->olderThan('updated_at')->where(['instance' => 'wishlist'])->days(180, 30, 730)->describe('test.wishlist', 'tests', 10)
        );
        PersonalDataRegistry::retention(
            RetentionItem::rows('test.admin_log', 'pdt_rt_log')->days(365, 90, 3650)->describe('test.admin_log', 'tests', 20)
        );
        PersonalDataRegistry::retention(
            RetentionItem::records('test.orders', 'pdt_rt_order')
                ->whereUsing(static function (Builder $q): void {
                    $q->whereIn('status', self::CLOSED)
                        ->where(fn (Builder $m) => $m->whereNull('first_name')->orWhere('first_name', '<>', '[deleted]'));
                })
                ->days(3650, 365, 7300)->disabledByDefault()->risky(['orders'])->describe('test.orders', 'tests', 30)
        );
    }

    /**
     * Every item may clean from now on (as if the 30 days after the update had passed).
     *
     * @param CarbonImmutable $now
     * @return void
     */
    private function allowCleaning(CarbonImmutable $now): void
    {
        app(RetentionSweeper::class)->states($now);
        DB::connection(GP247_DB_CONNECTION)->table(PersonalDataSchema::table(PersonalDataSchema::RETENTION))
            ->update(['first_allowed_at' => $now->subMinute()]);
    }

    /**
     * @param string $key
     * @return object|null
     */
    private function stateOf(string $key): ?object
    {
        return DB::connection(GP247_DB_CONNECTION)->table(PersonalDataSchema::table(PersonalDataSchema::RETENTION))->where('item_key', $key)->first();
    }
}
