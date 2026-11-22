<?php

namespace GP247\Core\Tests\Feature\AdminShell\Support;

use GP247\Core\AdminShell\Infrastructure\GP247AdminComponent;

/**
 * Minimal concrete admin component used to exercise the Layer-2 trait through
 * the real Livewire 4 lifecycle (mount view-check + per-action check).
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-LW-001
 * @aidlc-adr ADR-001
 */
class TestAdminComponent extends GP247AdminComponent
{
    /**
     * Declared explicitly so resolution is deterministic regardless of the
     * auto-generated component name in tests.
     *
     * @var string|null
     */
    protected ?string $permission = 'admin_test';

    /**
     * Deterministic screen path for authorization under Livewire::test (which does
     * not run through a route, so request()->path() is not the screen path).
     *
     * @var string|null
     */
    protected ?string $screenUri = 'gp247_admin/test';

    public bool $deleted = false;

    /**
     * A mutating action that must pass Layer-2 authorization before running.
     *
     * @return void
     */
    public function delete(): void
    {
        $this->authorizeAction('delete');
        $this->deleted = true;
    }

    /**
     * @return string Inline Blade markup for the component.
     */
    public function render(): string
    {
        return '<div>admin-shell-test</div>';
    }
}
