<?php

namespace GP247\Core\AdminShell\Application;

use GP247\Core\AdminShell\Domain\PermissionKey;

/**
 * Resolves a Livewire component identifier to the permission slug that gates it
 * (ADR-001 / ADR-003).
 *
 * Resolution order, most-specific first:
 *   1. An explicit permission declared by the component ("protected ?string $permission").
 *   2. An entry in the explicit component-to-slug map.
 *   3. A naming convention derived from the component identifier.
 *
 * The convention is best-effort: an unmapped or malformed identifier resolves to
 * null, which the authorizer turns into a deny (secure default). Components with
 * compound or irregular resources should declare their permission explicitly.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-RBAC-002
 * @aidlc-adr ADR-001
 */
final class PermissionResolver
{
    /**
     * Action suffixes stripped before deriving a resource name, so that
     * "user-list" / "user-edit" both map to the "user" resource.
     *
     * @var string[]
     */
    private const ACTION_SUFFIXES = [
        'list', 'index', 'create', 'store', 'add', 'edit', 'update',
        'show', 'detail', 'view', 'delete', 'destroy', 'form', 'bulk',
    ];

    /**
     * @param array<string,string> $map              Component identifier => permission slug.
     * @param string               $conventionPrefix Prefix applied to derived resource names.
     */
    public function __construct(
        private array $map = [],
        private string $conventionPrefix = 'admin_',
    ) {
    }

    /**
     * Resolve the permission key for a component, optionally honoring a
     * permission the component declares for itself.
     *
     * @param string      $component          Component identifier (e.g. "gp247-core::user-list").
     * @param string|null $explicitPermission Permission slug declared by the component, if any.
     * @return PermissionKey|null The resolved key, or null when it cannot be determined.
     */
    public function resolve(string $component, ?string $explicitPermission = null): ?PermissionKey
    {
        if ($explicitPermission !== null && trim($explicitPermission) !== '') {
            return new PermissionKey($explicitPermission);
        }

        if (isset($this->map[$component]) && trim($this->map[$component]) !== '') {
            return new PermissionKey($this->map[$component]);
        }

        $slug = $this->deriveByConvention($component);

        return $slug === null ? null : new PermissionKey($slug);
    }

    /**
     * Derive a permission slug from a component identifier by stripping the
     * namespace prefix and any trailing action suffix.
     *
     * @param string $component Component identifier.
     * @return string|null The derived slug, or null when no resource is present.
     */
    private function deriveByConvention(string $component): ?string
    {
        // Drop the "ns::" prefix; the resource lives in the last segment.
        $name = str_contains($component, '::')
            ? substr($component, strrpos($component, '::') + 2)
            : $component;

        $parts = array_values(array_filter(explode('-', $name), static fn ($p) => $p !== ''));

        if ($parts === []) {
            return null;
        }

        // Strip a single trailing action suffix so the remaining parts are the resource.
        if (count($parts) > 1 && in_array(end($parts), self::ACTION_SUFFIXES, true)) {
            array_pop($parts);
        }

        if ($parts === []) {
            return null;
        }

        return $this->conventionPrefix . implode('_', $parts);
    }
}
