<?php

namespace GP247\Core\PersonalData\Domain\Requests;

/**
 * The emails of personal data requests. The core decides when a message goes out; the package
 * that owns the storefront (gp247/shop) writes and sends it — its own email views, links on the
 * configured domain. Without such a package nothing is sent (NullPrivacyMailer).
 *
 * Customer messages carry no personal data beyond what the recipient already knows; messages
 * to the shop carry the request id and a link to the screen only.
 *
 * @aidlc-unit personal-data-requests
 * @aidlc-story US-customer-privacy-customer-emails, US-personal-data-requests-admin-notify
 * @aidlc-adr customer-privacy_self-service-surfaces
 */
interface PrivacyMailer
{
    /**
     * An erasure was asked for and waits: when it runs, and how to call it off.
     *
     * @param string             $email
     * @param string             $requestId
     * @param \DateTimeInterface $runsAt
     * @return void
     */
    public function erasureScheduled(string $email, string $requestId, \DateTimeInterface $runsAt): void;

    /**
     * The person's data is erased (sent to an address known only during the erasure).
     *
     * @param string $email
     * @return void
     */
    public function erased(string $email): void;

    /**
     * @param string $email
     * @param string $reason Written by the administrator.
     * @return void
     */
    public function rejected(string $email, string $reason): void;

    /**
     * A shopper without an account asked on the public form: the link that proves the address.
     *
     * @param string $email
     * @param string $requestId
     * @param string $kind      export or erase.
     * @return void
     */
    public function verifyGuest(string $email, string $requestId, string $kind): void;

    /**
     * The address of the public form belongs to an account: sign in to do it there.
     *
     * @param string $email
     * @return void
     */
    public function signInInstead(string $email): void;

    /**
     * A data package is ready.
     *
     * @param string      $email
     * @param string      $requestId
     * @param string      $exportId
     * @param bool        $guest     A shopper without an account (signed download link) or an account holder (download after signing in).
     * @return void
     */
    public function exportReady(string $email, string $requestId, string $exportId, bool $guest): void;

    /**
     * To the shop: a guest erasure waits for approval.
     *
     * @param string $requestId
     * @return void
     */
    public function adminApprovalNeeded(string $requestId): void;

    /**
     * To the shop: a request is close to its due date.
     *
     * @param string             $requestId
     * @param \DateTimeInterface $dueAt
     * @return void
     */
    public function adminDueSoon(string $requestId, \DateTimeInterface $dueAt): void;
}
