<?php

namespace GP247\Core\Tests\Feature\PersonalData\Support;

use GP247\Core\PersonalData\Application\Erasure\Anonymiser;
use GP247\Core\PersonalData\Application\ErasureService;
use GP247\Core\PersonalData\Application\Pepper;
use GP247\Core\PersonalData\Application\PersonalDataRegistry;
use GP247\Core\PersonalData\Domain\PersonalDataSource;
use GP247\Core\PersonalData\Domain\SubjectLink;
use GP247\Core\Testing\ReopensTransactionAfterDdl;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A small shop-like schema owned by the tests (customers, orders, order history, contact
 * messages) declared in the personal data registry, so the core can be tested on its own,
 * without gp247/shop. The tables are created once (DDL) before any write of the test.
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-personal-data-registry-erase-anonymise
 */
trait UsesPersonalDataFixtures
{
    use ReopensTransactionAfterDdl;

    /**
     * @return void
     */
    protected function setUpPersonalData(): void
    {
        $this->createFixtureTables();
        PersonalDataRegistry::reset();
        PersonalDataRegistry::useManifestSources([]);
        Anonymiser::forgetColumns();
        ErasureService::forgetReady();
        Pepper::forget();
        $this->declareFixtureSources();
    }

    /**
     * @return void
     */
    protected function tearDownPersonalData(): void
    {
        PersonalDataRegistry::reset();
        Pepper::forget();
    }

    /**
     * @return void
     */
    private function createFixtureTables(): void
    {
        $schema = Schema::connection(GP247_DB_CONNECTION);
        $p = GP247_DB_PREFIX;
        $ddl = false;
        if (!$schema->hasTable($p . 'pdt_customer')) {
            $schema->create($p . 'pdt_customer', function (Blueprint $t) {
                $t->string('id', 36)->primary();
                $t->string('email', 150)->nullable();
                $t->string('first_name', 100);
                $t->string('last_name', 100)->nullable();
                $t->string('phone', 30)->nullable();
                $t->string('address1', 150)->nullable();
            });
            $ddl = true;
        }
        if (!$schema->hasTable($p . 'pdt_order')) {
            $schema->create($p . 'pdt_order', function (Blueprint $t) {
                $t->increments('id');
                $t->string('customer_id', 36)->default('0');
                $t->string('email', 150)->nullable();
                $t->string('first_name', 100);
                $t->string('phone', 30)->nullable();
                $t->string('address1', 150)->nullable();
                $t->string('comment', 300)->nullable();
                $t->text('request')->nullable();
                $t->decimal('total', 15, 2)->default(0);
            });
            $ddl = true;
        }
        if (!$schema->hasTable($p . 'pdt_order_history')) {
            $schema->create($p . 'pdt_order_history', function (Blueprint $t) {
                $t->increments('id');
                $t->unsignedInteger('order_id');
                $t->text('content');
            });
            $ddl = true;
        }
        if (!$schema->hasTable($p . 'pdt_contact')) {
            $schema->create($p . 'pdt_contact', function (Blueprint $t) {
                $t->increments('id');
                $t->string('email', 150);
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
    private function declareFixtureSources(): void
    {
        PersonalDataRegistry::declare(
            PersonalDataSource::table('pdt_order_history')
                ->link(SubjectLink::parent('pdt_order', 'order_id'))
                ->anonymise(['content' => 'scrubText'])
                ->export(['order_id', 'content'])
                ->order(10)
                ->describe('test.history', 'tests')
        );
        PersonalDataRegistry::declare(
            PersonalDataSource::table('pdt_order')
                ->link(SubjectLink::customer('customer_id'))
                ->link(SubjectLink::email('email', onlyWhenEmpty: 'customer_id'))
                ->anonymise([
                    'first_name' => 'mark', 'email' => 'null', 'phone' => 'null', 'address1' => 'null', 'comment' => 'null',
                    'request' => ['scrubJson', ['destination.name', 'destination.phone', 'destination.line1', 'note']],
                    'total' => 'keep',
                ])
                ->identity(['first_name', 'phone', 'address1'], ['email'])
                ->order(40)
                ->describe('test.orders', 'tests')
        );
        PersonalDataRegistry::declare(
            PersonalDataSource::table('pdt_contact')->link(SubjectLink::email('email'))->deleteRows()->order(60)->describe('test.contact', 'tests')
        );
        PersonalDataRegistry::declare(
            PersonalDataSource::table('pdt_customer')
                ->link(SubjectLink::customer('id'))
                ->deleteRows()
                ->identity(['first_name', 'last_name', 'phone', 'address1'], ['email'])
                ->order(100)
                ->describe('test.customer', 'tests')
        );
    }

    /**
     * @param string               $table Logical name.
     * @param array<string, mixed> $row
     * @return int|string Inserted id.
     */
    private function insertRow(string $table, array $row): int|string
    {
        $query = DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . $table);
        if (isset($row['id'])) {
            $query->insert($row);

            return $row['id'];
        }

        return $query->insertGetId($row);
    }

    /**
     * @param string $table
     * @return \Illuminate\Database\Query\Builder
     */
    private function rows(string $table)
    {
        return DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . $table);
    }

    /**
     * Customer "Nguyen Van An" with two orders (one with history and a booked shipment), a guest
     * order placed with the same email, a contact message, and an unrelated customer's order.
     *
     * @return array{customer: string, order: int, guestOrder: int, otherOrder: int}
     */
    private function seedCustomerWithOrders(): array
    {
        $customer = 'c-0001';
        $this->insertRow('pdt_customer', ['id' => $customer, 'email' => 'An.Nguyen@Example.com', 'first_name' => 'Nguyen Van An', 'phone' => '+84 912 345 678', 'address1' => '12 Nguyen Trai Street']);
        $order = $this->insertRow('pdt_order', [
            'customer_id' => $customer, 'email' => 'an.nguyen@example.com', 'first_name' => 'Nguyen Van An', 'phone' => '+84 912 345 678',
            'address1' => '12 Nguyen Trai Street', 'comment' => 'Call before delivery', 'total' => 1700000,
            'request' => json_encode(['destination' => ['name' => 'Nguyen Van An', 'phone' => '+84 912 345 678', 'line1' => '12 Nguyen Trai Street', 'city' => 'Hanoi'], 'note' => 'Leave at gate', 'weight' => 1200]),
        ]);
        $this->insertRow('pdt_order_history', ['order_id' => $order, 'content' => "Change <b>address1</b> from '12 Nguyen Trai Street' to 'Old &amp; Co'. Total 1700000. Phone 84912345678."]);
        $this->insertRow('pdt_order_history', ['order_id' => $order, 'content' => 'Status changed to Shipped']);
        $this->insertRow('pdt_order', ['customer_id' => $customer, 'email' => 'an.nguyen@example.com', 'first_name' => 'Nguyen Van An', 'total' => 250000]);
        $guestOrder = $this->insertRow('pdt_order', ['customer_id' => '0', 'email' => 'an.nguyen@example.com', 'first_name' => 'An N.', 'phone' => '0912345678', 'total' => 99000]);
        $this->insertRow('pdt_contact', ['email' => 'an.nguyen@example.com', 'content' => 'Where is my order?']);
        $this->insertRow('pdt_customer', ['id' => 'c-0002', 'email' => 'binh@example.com', 'first_name' => 'Tran Thi Binh']);
        $otherOrder = $this->insertRow('pdt_order', ['customer_id' => 'c-0002', 'email' => 'an.nguyen@example.com', 'first_name' => 'Tran Thi Binh', 'total' => 500000]);

        return ['customer' => $customer, 'order' => (int) $order, 'guestOrder' => (int) $guestOrder, 'otherOrder' => (int) $otherOrder];
    }
}
