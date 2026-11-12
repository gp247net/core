<?php

namespace GP247\Core\AdminShell\Http\Livewire;

use GP247\Core\AdminShell\Infrastructure\GP247AdminComponent;
use GP247\Core\Support\Gp247PackageVersions;
use Illuminate\Contracts\View\View;

/**
 * Read-only server information screen (ADR-005): GP247 package versions first
 * (as the release tags print them), then PHP runtime settings, loaded extensions
 * and the other installed Composer packages. Mirrors the legacy AdminServerInfoController.
 * Gated by `admin_server_info`; no mutating actions.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-UI-002, US-admin-shell-server-info-gp247-versions
 * @aidlc-adr ADR-001, ADR-005, admin-shell_gp247-version-display
 */
class ServerInfo extends GP247AdminComponent
{
    protected ?string $permission = 'admin_server_info';

    /**
     * Collect runtime PHP settings shown in the summary table.
     *
     * @return array<string, string>
     */
    private function phpInfo(): array
    {
        return [
            'version' => PHP_VERSION,
            'os' => PHP_OS,
            'server' => $_SERVER['SERVER_SOFTWARE'] ?? '',
            'memory_limit' => (string) ini_get('memory_limit'),
            'max_execution_time' => (string) ini_get('max_execution_time'),
            'post_max_size' => (string) ini_get('post_max_size'),
            'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
        ];
    }

    /**
     * Render the read-only server-info screen wrapped in the admin layout.
     *
     * @return View
     */
    public function render(): View
    {
        $extensions = get_loaded_extensions();
        sort($extensions);

        $gp247Packages = Gp247PackageVersions::gp247();

        return view('gp247-admin::livewire.server-info', [
            'gp247Packages' => $gp247Packages,
            'phpInfo' => $this->phpInfo(),
            'extensions' => $extensions,
            // WHY: the GP247 block already lists these; the project root package stays here.
            'packages' => array_diff_key(Gp247PackageVersions::all(), $gp247Packages),
        ])->layout('gp247-admin::layouts.admin', [
            'title' => gp247_language_render('admin.server_info'),
        ]);
    }
}
