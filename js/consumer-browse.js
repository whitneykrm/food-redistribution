let userLocation = null;           // { lat, lng } once the browser shares it
let locationState = 'unknown';     // 'unknown' | 'asking' | 'granted' | 'denied'
let reserveRadiusKm = 5;           // replaced by the value from the server

function distanceKm(lat1, lon1, lat2, lon2) {
    const toRad = d => d * Math.PI / 180;
    const dLat = toRad(lat2 - lat1), dLon = toRad(lon2 - lon1);
    const a = Math.sin(dLat / 2) ** 2 +
              Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLon / 2) ** 2;
    return 6371 * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}
// Distance from the consumer to a listing's vendor, or null when it can't be worked out
function listingDistance(l) {
    if (!userLocation || l.latitude == null || l.longitude == null) return null;
    return distanceKm(userLocation.lat, userLocation.lng, Number(l.latitude), Number(l.longitude));
}

function requestLocation() {
    if (!navigator.geolocation) { locationState = 'denied'; renderListings(); return; }
    locationState = 'asking';
    navigator.geolocation.getCurrentPosition(
        (pos) => {
            userLocation = { lat: pos.coords.latitude, lng: pos.coords.longitude };
            locationState = 'granted';
            renderListings();
        },
        () => { locationState = 'denied'; renderListings(); },
        { enableHighAccuracy: false, timeout: 10000, maximumAge: 60000 }
    );
}

function formatKm(km) {
    return km < 1 ? Math.round(km * 1000) + ' m' : km.toFixed(1) + ' km';
}

// Shown when the listings could not be loaded
function showListingsError(message) {
    allListings = [];
    $('listings-grid').innerHTML = `<p class="empty">${escapeHtml(message)}</p>`;
    $('results-count').textContent = '';
}

async function loadListings() {
    const grid = $('listings-grid');
    try {
        const res = await fetch('api/get-public-listings.php');
        if (res.status === 401) { window.location.href = 'login.html'; return; }
        const data = await res.json();

        if (!data.success) {
            showListingsError(data.message || 'Failed to load listings.');
            return;
        }

        reserveRadiusKm = data.reserve_radius_km || reserveRadiusKm;
        allListings = data.listings || [];
        populateCities();
        renderListings();
        renderRedeem();
    } catch (err) {
        console.error('Error loading listings:', err);
        showListingsError('Failed to load listings.');
    }
}

// The location filter lists the vendors' areas (Lavington, Westlands...)
const placeKey = (s) => String(s || '').trim().replace(/\s+/g, ' ').toLowerCase();
const listingArea = (l) => (l.area && String(l.area).trim()) || l.city || '';

// Pickup text = road + area ("James Gichuru Road, Lavington"), without repeating the area
function pickupText(l) {
    const address = String(l.address || '').trim();
    const area = l.area ? String(l.area).trim() : '';
    if (!area) return address;
    if (placeKey(address).includes(placeKey(area))) return address;
    return address ? address + ', ' + area : area;
}

function populateCities() {
    const select = $('filter-city');
    const current = select.value;
    const places = new Map();
    allListings.forEach(l => {
        const key = placeKey(listingArea(l));
        if (key && !places.has(key)) places.set(key, String(listingArea(l)).trim());
    });
    const sorted = [...places.entries()].sort((a, b) => a[1].localeCompare(b[1]));
    select.innerHTML = '<option value="">All locations</option>' +
        sorted.map(([key, label]) => `<option value="${escapeHtml(key)}">${escapeHtml(label)}</option>`).join('');
    select.value = places.has(current) ? current : '';
}

function renderListings() {
    const grid = $('listings-grid');
    const search = $('filter-search').value.trim().toLowerCase();
    const city = $('filter-city').value;
    const sort = $('filter-sort').value;
    const now = new Date();

    const items = allListings.filter(l => {
        if (parseDate(l.expiry_time) <= now) return false;
        if (city && placeKey(listingArea(l)) !== city) return false;
        if (search && !(l.item_name.toLowerCase().includes(search) || l.business_name.toLowerCase().includes(search))) return false;
        return true;
    });

    items.sort((a, b) => {
        if (sort === 'price') return a.discounted_price - b.discounted_price;
        if (sort === 'discount') return discountPercent(b) - discountPercent(a);
        if (sort === 'nearest') {
            const da = listingDistance(a), db = listingDistance(b);
            if (da === null && db === null) return parseDate(a.expiry_time) - parseDate(b.expiry_time);
            if (da === null) return 1;
            if (db === null) return -1;
            return da - db;
        }
        return parseDate(a.expiry_time) - parseDate(b.expiry_time);
    });

    $('results-count').textContent = items.length + (items.length === 1 ? ' listing available' : ' listings available');

    if (items.length === 0) {
        grid.innerHTML = '<p class="empty">No surplus food matches right now. Try another location or check back soon.</p>';
        return;
    }

    grid.innerHTML = items.map(l => {
        const pct = discountPercent(l);
        const dist = listingDistance(l);
        const needsFence = !l.is_sample && l.latitude != null && l.longitude != null;

        // Reserve button depends on the geofence (samples and vendors without a pin are not restricted)
        let action;
        if (!needsFence) {
            action = `<a href="checkout.html?listing=${encodeURIComponent(l.listings_id)}" class="btn-primary btn-link">Reserve &amp; Generate QR</a>`;
        } else if (locationState !== 'granted') {
            action = `<button type="button" class="btn-primary btn-locate-me" data-action="locate">${locationState === 'asking' ? 'Getting your location...' : 'Share location to reserve'}</button>`;
        } else if (dist > reserveRadiusKm) {
            action = `<button type="button" class="btn-primary btn-too-far" disabled>Too far to reserve</button>`;
        } else {
            action = `<a href="checkout.html?listing=${encodeURIComponent(l.listings_id)}" class="btn-primary btn-link">Reserve &amp; Generate QR</a>`;
        }

        return `
            <article class="food-card">
                ${l.image_path
                    ? `<img class="food-img" src="${escapeHtml(l.image_path)}" alt="${escapeHtml(l.item_name)}" loading="lazy" onerror="this.outerHTML='<div class=&quot;food-img food-img-empty&quot; aria-hidden=&quot;true&quot;>🍽️</div>'">`
                    : `<div class="food-img food-img-empty" aria-hidden="true">🍽️</div>`}
                <div class="vendor-line">
                    <strong>${escapeHtml(l.business_name)}</strong>
                    <span>${escapeHtml(listingArea(l))}</span>
                </div>
                <h3 class="item-name">${escapeHtml(l.item_name)}</h3>
                <div class="price-row">
                    <span class="price-now">KES ${money(l.discounted_price)}</span>
                    <span class="price-was">KES ${money(l.original_price)}</span>
                    ${pct > 0 ? `<span class="badge-off">-${pct}%</span>` : ''}
                    ${l.points > 0 ? `<span class="badge-points">+${l.points} pts</span>` : ''}
                    ${l.is_sample ? '<span class="badge-sample">Sample</span>' : ''}
                </div>
                <div class="meta">
                    <span>${l.quantity} left</span>
                    <span>Expires in ${timeLeft(l.expiry_time)}</span>
                </div>
                <div class="address">Pickup: ${escapeHtml(pickupText(l))}
                </div>
                ${dist !== null ? `<div class="distance ${needsFence && dist > reserveRadiusKm ? 'far' : 'near'}">${formatKm(dist)} away${needsFence && dist > reserveRadiusKm ? ` (reserve within ${reserveRadiusKm} km)` : ''}</div>` : ''}
                ${l.is_sample ? '' : `<div class="redeem-hint">Or redeem for ${l.points_cost} pts</div>`}
                ${action}
            </article>`;
    }).join('');
}
// "Share location to reserve" button on a card
document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-action="locate"]');
    if (btn) requestLocation();
});

// Ask for the location once the listings are on screen
window.addEventListener('load', () => {
    if (navigator.geolocation && navigator.permissions && navigator.permissions.query) {
        // Only prompt automatically if the person already allowed it; otherwise wait for the button
        navigator.permissions.query({ name: 'geolocation' }).then(p => {
            if (p.state === 'granted') requestLocation();
            else if (p.state === 'denied') { locationState = 'denied'; }
        }).catch(() => {});
    }
});