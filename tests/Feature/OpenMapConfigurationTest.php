<?php

namespace Tests\Feature;

use Tests\TestCase;

class OpenMapConfigurationTest extends TestCase
{
    public function test_map_endpoints_are_explicit_and_unconfigured_by_default(): void
    {
        $this->assertArrayHasKey('street_tiles_url', config('open_maps'));
        $this->assertArrayHasKey('satellite_tiles_url', config('open_maps'));
        $this->assertIsInt(config('open_maps.satellite_native_zoom'));
        $this->assertFileExists(resource_path('views/partials/open_map_styles.blade.php'));
    }
}
