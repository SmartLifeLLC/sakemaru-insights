<div>
    @if(count($mapData) > 0)
    <div
        x-data="storeSalesMap({{ json_encode($mapData) }})"
        x-init="init()"
        class="w-full overflow-hidden"
        style="height: 500px; border-radius: 8px; border: 1px solid var(--sm-border, #e6ddd0);"
        wire:ignore
    >
        <div id="store-sales-map" class="w-full h-full"></div>
    </div>

    <script>
        function storeSalesMap(mapData) {
            return {
                map: null,
                markers: [],

                init() {
                    if (typeof L !== 'undefined') {
                        this.renderMap();
                        return;
                    }

                    // Leaflet CSS
                    if (!document.querySelector('link[href*="leaflet"]')) {
                        const css = document.createElement('link');
                        css.rel = 'stylesheet';
                        css.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
                        document.head.appendChild(css);
                    }

                    // Leaflet JS
                    const script = document.createElement('script');
                    script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
                    script.onload = () => this.renderMap();
                    document.head.appendChild(script);
                },

                renderMap() {
                    const container = document.getElementById('store-sales-map');
                    if (!container || !mapData.length) return;

                    this.map = L.map(container).setView([36.06, 136.22], 10);

                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        attribution: '&copy; OpenStreetMap contributors',
                        maxZoom: 18,
                    }).addTo(this.map);

                    const maxSales = Math.max(...mapData.map(d => d.sales));

                    mapData.forEach(store => {
                        const radius = this.calcRadius(store.sales, maxSales);

                        const marker = L.circleMarker([store.lat, store.lng], {
                            radius: radius,
                            fillColor: '#8b3a2f',
                            color: '#6b2a1f',
                            weight: 1.5,
                            opacity: 0.9,
                            fillOpacity: 0.55,
                        }).addTo(this.map);

                        marker.bindTooltip(
                            `<div style="font-family: 'Zen Kaku Gothic New', sans-serif; font-size: 12px;">` +
                            `<strong style="color: #1c1917;">${store.name}</strong><br>` +
                            `<span style="color: #b8860b;">売上: ¥${store.sales.toLocaleString('ja-JP')}</span><br>` +
                            `<span style="color: #9c9487;">客数: ${store.customers.toLocaleString('ja-JP')}人</span>` +
                            `</div>`,
                            { direction: 'top', offset: [0, -radius] }
                        );
                    });
                },

                calcRadius(amount, maxAmount) {
                    if (maxAmount === 0) return 5;
                    return Math.max(5, Math.sqrt(amount / maxAmount) * 30);
                }
            };
        }
    </script>
    @else
    <div class="w-full flex items-center justify-center" style="height: 200px; border-radius: 8px; border: 1px solid var(--sm-border, #e6ddd0); background-color: var(--sm-bg-card-alt, #faf8f2);">
        <p style="font-family: var(--sm-font-body); font-size: 0.8rem; color: var(--sm-text-muted, #9c9487);">地図データがありません</p>
    </div>
    @endif
</div>
