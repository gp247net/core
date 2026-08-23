<?php

namespace GP247\Core\Commands;

use GP247\Core\Console\GP247Command;
use Illuminate\Support\Facades\Storage;

/**
 * Machine-readable system status backbone: installed package versions
 * (core/front/shop), install marker, and plugin/template counts. Read-only.
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-004
 * @aidlc-adr system-cli_output-contract
 */
class Info extends GP247Command
{
    /** @var string */
    protected $signature = 'gp247:info';

    /** @var string */
    protected $description = 'Show GP247 status: versions, install marker, extension counts';

    /**
     * @return int
     */
    protected function handleGp247(): int
    {
        $packages = gp247_composer_get_package_installed();
        $data = [
            'core'      => config('gp247.core'),
            'versions'  => [
                'gp247/core'  => $packages['gp247/core'] ?? null,
                'gp247/front' => $packages['gp247/front'] ?? null,
                'gp247/shop'  => $packages['gp247/shop'] ?? null,
            ],
            'installed' => Storage::disk('local')->exists('gp247-installed.txt'),
            'plugins'   => $this->countExtensions('Plugins'),
            'templates' => $this->countExtensions('Templates'),
            'api'       => config('gp247-config.env.GP247_LIBRARY_API'),
        ];

        if (!$this->isJson()) {
            $this->info('GP247 core: '.$data['core']);
            foreach ($data['versions'] as $pkg => $ver) {
                $this->line('  '.$pkg.': '.($ver ?? '—'));
            }
            $this->line('Installed: '.($data['installed'] ? 'yes' : 'no'));
            $this->line('Plugins: '.$data['plugins']['local'].' local / '.$data['plugins']['active'].' active');
            $this->line('Templates: '.$data['templates']['local'].' local / '.$data['templates']['active'].' active');
            $this->line('API: '.$data['api']);
        }

        return $this->respondSuccess($data);
    }

    /**
     * Count local vs active extensions of a group (degrades to zero on error).
     *
     * @param string $type Plugins|Templates.
     * @return array{local: int, active: int}
     */
    protected function countExtensions(string $type): array
    {
        try {
            $local = count(gp247_extension_get_all_local(type: $type));
            $active = 0;
            foreach (array_keys(gp247_extension_get_all_local(type: $type)) as $key) {
                if (gp247_extension_check_active($type, $key)) {
                    $active++;
                }
            }
            return ['local' => $local, 'active' => $active];
        } catch (\Throwable $e) {
            return ['local' => 0, 'active' => 0];
        }
    }
}
