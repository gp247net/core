<?php

namespace GP247\Core\Tests\Feature\AdminShell\Support;

use GP247\Core\AdminShell\Domain\AdminUserContract;
use GP247\Core\AdminShell\Domain\AuthorizationException;
use GP247\Core\Tests\Unit\AdminShell\Support\FakeAdminUser;

/**
 * Shared helpers for admin-shell Livewire feature tests: bind a fake admin user
 * into the container and assert that an interaction is denied (unwrapping the
 * exception chain Livewire may wrap around AuthorizationException).
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-RBAC-001
 * @aidlc-adr ADR-001
 */
trait InteractsWithAdminUser
{
    /**
     * Map a legacy permission-slug grant to the admin path prefix(es) that gate the
     * screen(s) under the v1 URI model (ADR-001 Layer-2). Kept in the test layer so
     * the many `asUser(slugs: [...])` call sites stay unchanged while authorization
     * is now decided by http_uri, not slug. A slug not listed falls back to
     * "gp247_admin/<slug-without-admin_>".
     *
     * @param string $slug Legacy permission slug (e.g. "admin_product", "admin_config").
     * @return string[] Granted admin path prefixes.
     */
    private function slugToScreenUris(string $slug): array
    {
        $p = defined('GP247_ADMIN_PREFIX') ? GP247_ADMIN_PREFIX : 'gp247_admin';

        $map = [
            // Core resources whose path differs from the slug, or that span screens.
            'admin_home_layout' => ["$p/admin_home_layout", "$p/home_layout"],
            'admin_config'      => ["$p/store_config", "$p/cache_config", "$p/password_policy", "$p/config"],
            'store.full'        => ["$p/store_info", "$p/store_config", "$p/store_maintain"],
            // Screens sharing a slug across two route groups.
            'admin_banner'      => ["$p/banner", "$p/banner_type"],
            'admin_link'        => ["$p/link", "$p/link_group"],
            'admin_language'    => ["$p/language", "$p/language_manager"],
        ];

        if (isset($map[$slug])) {
            return $map[$slug];
        }

        // Convention: "admin_<resource>" gates "gp247_admin/<resource>".
        $resource = str_starts_with($slug, 'admin_') ? substr($slug, strlen('admin_')) : $slug;

        return ["$p/$resource"];
    }

    /**
     * Bind the current admin user contract to an in-memory fake.
     *
     * @param bool     $administrator Whether the fake holds the administrator role.
     * @param bool     $viewAll       Whether the fake holds the view.all role.
     * @param string[] $slugs         Legacy permission slugs, translated to screen URIs.
     * @return void
     */
    private function asUser(bool $administrator = false, bool $viewAll = false, array $slugs = []): void
    {
        $uris = [];
        foreach ($slugs as $slug) {
            foreach ($this->slugToScreenUris($slug) as $uri) {
                $uris[] = $uri;
            }
        }

        $this->app->bind(
            AdminUserContract::class,
            static fn (): AdminUserContract => new FakeAdminUser($administrator, $viewAll, $uris),
        );
    }

    /**
     * Assert that running the interaction is denied by the authorization core.
     *
     * @param callable $action The Livewire interaction expected to be denied.
     * @return void
     */
    private function assertDenied(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected authorization to deny the action.');
        } catch (\Throwable $e) {
            $current = $e;
            while ($current !== null && !$current instanceof AuthorizationException) {
                $current = $current->getPrevious();
            }
            $this->assertInstanceOf(
                AuthorizationException::class,
                $current,
                'Expected AuthorizationException in chain; got ' . $e::class . ': ' . $e->getMessage(),
            );
        }
    }
}
