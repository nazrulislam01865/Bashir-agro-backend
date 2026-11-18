{{-- Public HTTPS XYZ URLs on our own tile host; Laravel never proxies tile requests. --}}
const openStreetTiles = @json(config('open_maps.street_tiles_url'));
const openSatelliteTiles = @json(config('open_maps.satellite_tiles_url'));
const streetNativeZoom = @json(config('open_maps.street_native_zoom'));
const satelliteNativeZoom = @json(config('open_maps.satellite_native_zoom'));
const validOpenTileUrl = (value) => {
    if (typeof value !== 'string' || !value.includes('{z}') || !value.includes('{x}') || !value.includes('{y}')) return false;
    try {
        const url = new URL(value);
        return url.protocol === 'https:' && !!url.hostname &&
            !url.hostname.endsWith('mapbox.com') &&
            !url.hostname.endsWith('google.com') &&
            url.hostname !== 'tile.openstreetmap.org';
    } catch (_) { return false; }
};
const openMapStreetReady = validOpenTileUrl(openStreetTiles);
const openMapSatelliteReady = validOpenTileUrl(openSatelliteTiles);
const createOpenMapStyle = (mode) => {
    const satellite = mode === 'satellite';
    const url = satellite ? openSatelliteTiles : openStreetTiles;
    const ready = satellite ? openMapSatelliteReady : openMapStreetReady;
    const style = {version: 8, sources: {}, layers: []};
    if (!ready) return style; // Preserve boundaries and markers; no unlicensed fallback.
    const maxzoom = satellite ? satelliteNativeZoom : streetNativeZoom;
    style.sources['self-hosted-tiles'] = {
        type: 'raster', tiles: [url], tileSize: 256,
        minzoom: 0, maxzoom: Math.max(0, Math.min(21, Number(maxzoom) || 14)),
        attribution: satellite
            ? '<a href="https://dataspace.copernicus.eu/" target="_blank" rel="noopener">Contains modified Copernicus Sentinel data</a>'
            : '<a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">&copy; OpenStreetMap contributors</a> &middot; <a href="https://openmaptiles.org/" target="_blank" rel="noopener">OpenMapTiles</a>'
    };
    style.layers.push({id:'self-hosted-raster', type:'raster', source:'self-hosted-tiles',
        minzoom:0, maxzoom:22, paint:{'raster-fade-duration': 100}});
    return style;
};
const addOpenMapStatus = (containerId, mode = 'satellite') => {
    const container = document.getElementById(containerId);
    if (!container) return;
    const notice = document.createElement('div');
    notice.style.cssText = 'position:absolute;bottom:7px;left:8px;z-index:15;background:#ffffffeb;padding:4px 8px;border-radius:5px;font-size:11px';
    const configured = mode === 'satellite' ? openMapSatelliteReady : openMapStreetReady;
    if (configured) {
        // Set DOM contents as static text instead of constructing user-supplied markup.
        notice.textContent = mode === 'satellite'
            ? 'Contains modified Copernicus Sentinel data' : '© OpenStreetMap contributors · OpenMapTiles';
    } else {
        notice.textContent = 'এই ম্যাপ লেয়ারটি এখন উপলব্ধ নয় (নিজস্ব টাইল সার্ভার সেট করুন)';
    }
    container.appendChild(notice);
    return (nextMode) => {
        const ready = nextMode === 'satellite' ? openMapSatelliteReady : openMapStreetReady;
        notice.textContent = !ready ? 'এই ম্যাপ লেয়ারটি এখন উপলব্ধ নয় (নিজস্ব টাইল সার্ভার সেট করুন)' :
            nextMode === 'satellite' ? 'Contains modified Copernicus Sentinel data' :
            '© OpenStreetMap contributors · OpenMapTiles';
    };
};
