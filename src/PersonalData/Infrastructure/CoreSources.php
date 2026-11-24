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
    /** @var array<string, string> Core tables that look personal by column names but hold no customer data. */
    public const NOT_CUSTOMER_DATA = [
        'admin_user' => 'administrator accounts, not customers',
        'admin_password_resets' => 'reset links of administrator accounts',
        'admin_store' => "the shop's own contact details",
        'admin_log' => 'security record of administrator actions; kept for its retention period, never edited',
        'api_session' => "API sessions; the package owning the customer model (gp247/shop) declares its customers' sessions",
        'personal_access_tokens' => "API tokens; the package owning the customer model (gp247/shop) declares its customers' tokens",
        'personal_data_erasure' => 'the erasure ledger itself: customer id and salted email hashes only',
        'personal_data_erasure_email' => 'the erasure ledger itself: salted email hashes only',
        'personal_data_job' => 'erasure jobs; their cursor is encrypted and emptied when the job ends',
        'personal_data_request' => 'requests: customer id and salted email hash; the email of an open guest request is encrypted and removed when it closes',
        'personal_data_request_log' => 'request history: ids, events and administrators only',
        'personal_data_export' => 'data packages: customer id and a hash of the download link; the file is removed after 24 hours',
    ];

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
        foreach (self::NOT_CUSTOMER_DATA as $table => $reason) {
            PersonalDataRegistry::notCustomerData($table, $reason);
        }
    }
}
