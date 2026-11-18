<?php

return [
    // HTTPS XYZ tile URLs generated on infrastructure under your control.
    // No fallback to public OSM, Google, or Mapbox tile endpoints.
    'street_tiles_url' => env('MAP_STREET_TILES_URL', ''),
    'satellite_tiles_url' => env('MAP_SATELLITE_TILES_URL', ''),
    'street_native_zoom' => (int) env('MAP_STREET_NATIVE_ZOOM', 18),
    'satellite_native_zoom' => (int) env('MAP_SATELLITE_NATIVE_ZOOM', 14),
];
