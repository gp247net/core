<?php

namespace GP247\Core\PersonalData\Domain\Requests;

/**
 * Who sent the request: the customer on the website, through the API, a guest through the
 * public form, an administrator on the customer's behalf, or the site itself for an account
 * unused for the retention period.
 *
 * @aidlc-unit personal-data-requests
 * @aidlc-story US-personal-data-requests-screen, US-personal-data-requests-admin-create, US-personal-data-retention-inactive-accounts
 * @aidlc-adr personal-data-requests_request-model
 */
enum RequestSource: string
{
    case Customer = 'customer';
    case Api = 'api';
    case Guest = 'guest';
    case Admin = 'admin';
    case Retention = 'retention';

    /** Channels an administrator records when creating a request for a customer. */
    public const ADMIN_CHANNELS = ['email', 'phone', 'store', 'other'];
}
