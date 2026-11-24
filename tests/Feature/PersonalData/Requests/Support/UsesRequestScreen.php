<?php

namespace GP247\Core\Tests\Feature\PersonalData\Requests\Support;

use GP247\Core\Models\AdminRole;
use GP247\Core\Models\AdminUser;
use GP247\Core\PersonalData\Application\PersonalDataRegistry;
use Illuminate\Support\Facades\Hash;

/**
 * A signed-in Administrator with a known password (for the "confirm it is you" box) and a
 * customer resolver over the test customers table, as gp247/shop gives one on a real site.
 *
 * @aidlc-unit personal-data-requests
 */
trait UsesRequestScreen
{
    protected const ADMIN_PASSWORD = 'correct horse battery';

    /**
     * @return AdminUser
     */
    protected function signInAdministrator(): AdminUser
    {
        $user = new AdminUser();
        $user->username = 'pdr-' . bin2hex(random_bytes(4));
        $user->password = Hash::make(self::ADMIN_PASSWORD);
        $user->name = 'Requests tester';
        $user->email = $user->username . '@example.com';
        $user->status = 1;
        $user->save();
        $user->roles()->attach(AdminRole::where('slug', 'administrator')->pluck('id')->all());
        foreach (['allPermissions', 'allViewPermissions', 'canChangeConfig', 'listStoreId', 'listStore'] as $property) {
            (new \ReflectionProperty(AdminUser::class, $property))->setValue(null, null);
        }
        $this->actingAs($user->fresh(), 'admin');

        return $user;
    }

    /**
     * @param array<string, string> $stores Customer id => store id.
     * @return void
     */
    protected function resolveTestCustomers(array $stores): void
    {
        PersonalDataRegistry::customerResolver(function (string $idOrEmail) use ($stores): ?array {
            $row = $this->rows('pdt_customer')->where(str_contains($idOrEmail, '@') ? 'email' : 'id', $idOrEmail)->first();
            if ($row === null) {
                return null;
            }

            return ['id' => (string) $row->id, 'email' => $row->email, 'name' => $row->first_name, 'store_id' => $stores[(string) $row->id] ?? '1'];
        });
    }
}
