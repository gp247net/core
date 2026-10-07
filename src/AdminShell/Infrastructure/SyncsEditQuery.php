<?php

namespace GP247\Core\AdminShell\Infrastructure;

/**
 * Mirrors the "record being edited" of an in-place two-panel screen into the
 * URL query (?edit=<id>) without a Livewire remount, so the screen state (list
 * page, open record) survives a refresh while the component instance — and its
 * list state — stays alive (PA-A, ADR two-panel-state-preservation).
 *
 * Shared by ResourcePanel and the tree-based MenuManager.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-AUI-two-panel-state-preservation
 * @aidlc-adr ADR-admin-shell-rbac-two-panel-state-preservation
 */
trait SyncsEditQuery
{
    /**
     * Reflect the edit state in the URL query (?edit=<id>) without remounting,
     * preserving any existing ?page= so a refresh restores both the open record
     * and the list position. Pass null to clear the edit param (back to create
     * mode).
     *
     * @param string|null $id
     * @return void
     */
    protected function syncEditUrl(?string $id): void
    {
        // WHY: a history-only update (not Livewire navigate) so the single
        // component instance — and its list state — survives; a refresh then
        // re-enters through mount(), which reads ?edit=.
        $mutate = $id !== null && $id !== ''
            ? 'u.searchParams.set("edit", ' . json_encode($id) . ');'
            : 'u.searchParams.delete("edit");';

        $this->js('const u = new URL(window.location.href); ' . $mutate . ' window.history.replaceState({}, "", u);');
    }
}
