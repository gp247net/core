<?php

namespace GP247\Core\PersonalData\Infrastructure;

use GP247\Core\PersonalData\Application\PersonalDataRegistry;
use GP247\Core\PersonalData\Domain\PersonalDataSource;
use GP247\Core\PersonalData\Domain\SubjectLink;

/**
 * Personal data the core itself keeps about customers: failed sign-ins of the customer group
 * (the email as typed). Kept until its own retention (login_failure_keep_days) removes it —
 * it is a security record, not erased on request.
 *
 * Customer API tokens and sessions are declared by gp247/shop, which knows the customer model.
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-customer-privacy-declare-shop-data
 * @aidlc-adr personal-data-registry_source-declaration
 */
final class CoreSources
{
    /**
     * @return void
     */
    public static function register(): void
    {
        PersonalDataRegistry::declare(
            PersonalDataSource::table('admin_login_failure')
                ->link(SubjectLink::email('identity'))
                ->where(['audience' => 'customer'])
                ->retain()
                ->order(90)
                ->describe('admin.personal_data.source.login_failures', 'gp247/core')
        );
    }
}
