<?php

namespace GP247\Core\Tests\Feature\SecurityHeaders;

use GP247\Core\Tests\TestCase;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use PHPUnit\Framework\Attributes\Group;

/**
 * Livewire serves the source map of its script on every site; outside debug mode
 * the map is not served, so a live store does not hand out unminified source.
 *
 * @aidlc-unit security-headers
 * @aidlc-story US-security-headers-no-source-maps
 */
#[Group('security-headers')]
class SourceMapExposureTest extends TestCase
{
    public function test_the_livewire_source_map_is_not_served_on_a_live_site(): void
    {
        config(['app.debug' => false]);

        $this->get(EndpointResolver::mapPath(csp: false))->assertNotFound();
    }

    public function test_the_csp_livewire_source_map_is_not_served_on_a_live_site(): void
    {
        config(['app.debug' => false]);

        $this->get(EndpointResolver::mapPath(csp: true))->assertNotFound();
    }

    public function test_the_livewire_source_map_is_still_served_while_debugging(): void
    {
        config(['app.debug' => true]);

        $this->get(EndpointResolver::mapPath(csp: false))->assertOk();
    }

    public function test_the_livewire_script_itself_is_still_served_on_a_live_site(): void
    {
        config(['app.debug' => false]);

        // WHY: the script route is chosen when the app boots (minified unless debug), so ask the
        // router for the path it actually registered instead of guessing from the test's config.
        $script = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($route) => str_ends_with($route->uri(), 'livewire.min.js')
                || str_ends_with($route->uri(), 'livewire.js'));

        $this->assertNotNull($script, 'Livewire script route is registered');
        $this->get('/'.$script->uri())->assertOk();
    }
}
