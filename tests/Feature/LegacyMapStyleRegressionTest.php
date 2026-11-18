<?php

namespace Tests\Feature;

use Tests\TestCase;

class LegacyMapStyleRegressionTest extends TestCase
{
    public function test_previous_satellite_and_street_styles_are_restored_without_removing_evidence_security(): void
    {
        foreach ([
            'locations/create.blade.php',
            'locations/edit.blade.php',
            'locations/show.blade.php',
            'assignments/show.blade.php',
        ] as $view) {
            $contents = file_get_contents(resource_path('views/' . $view));
            $this->assertStringContainsString('google-hybrid', $contents);
            $this->assertStringNotContainsString('createOpenMapStyle', $contents);
        }

        $create = file_get_contents(resource_path('views/locations/create.blade.php'));
        $this->assertStringContainsString('tile.openstreetmap.org', $create);
        $assignment = file_get_contents(resource_path('views/assignments/show.blade.php'));
        $this->assertStringContainsString('EvidenceUrl::forPhoto', $assignment);
        $this->assertFileDoesNotExist(base_path('config/open_maps.php'));
    }
}
