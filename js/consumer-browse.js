function useSampleListings(message) {
    if (typeof SAMPLE_LISTINGS === 'undefined' || SAMPLE_LISTINGS.length === 0) {
        $('listings-grid').innerHTML = `<p class="empty">${escapeHtml(message)}</p>`;
        return;
    }
    allListings = [...SAMPLE_LISTINGS];
    populateCities();
    renderListings();
    renderRedeem();
}

async function loadListings() {
    const grid = $('listings-grid');
    try {
        const res = await fetch('api/get-public-listings.php');
        if (res.status === 401) { window.location.href = 'login.html'; return; }
        const data = await res.json();

        if (!data.success) {
            useSampleListings(data.message || 'Failed to load listings.');
            return;
        }

        // Real listings first, then the samples
        const samples = (typeof SAMPLE_LISTINGS !== 'undefined') ? SAMPLE_LISTINGS : [];
        allListings = [...(data.listings || []), ...samples];
        populateCities();
        renderListings();
        renderRedeem();
    } catch (err) {
        console.error('Error loading listings:', err);
        useSampleListings('Failed to load listings.');
    }
}

function populateCities() {
    const select = $('filter-city');
    const current = select.value;
    const cities = [...new Set(allListings.map(l => l.city).filter(Boolean))].sort();
    select.innerHTML = '<option value="">All locations</option>' +
        cities.map(c => `<option value="${escapeHtml(c)}">${escapeHtml(c)}</option>`).join('');
    select.value = cities.includes(current) ? current : '';
}

function renderListings() {
    const grid = $('listings-grid');
    const search = $('filter-search').value.trim().toLowerCase();
    const city = $('filter-city').value;
    const sort = $('filter-sort').value;
    const now = new Date();

    const items = allListings.filter(l => {
        if (parseDate(l.expiry_time) <= now) return false;
        if (city && l.city !== city) return false;
        if (search && !(l.item_name.toLowerCase().includes(search) || l.business_name.toLowerCase().includes(search))) return false;
        return true;
    });

    items.sort((a, b) => {
        if (sort === 'price') return a.discounted_price - b.discounted_price;
        if (sort === 'discount') return discountPercent(b) - discountPercent(a);
        return parseDate(a.expiry_time) - parseDate(b.expiry_time);
    });

    $('results-count').textContent = items.length + (items.length === 1 ? ' listing available' : ' listings available');

    if (items.length === 0) {
        grid.innerHTML = '<p class="empty">No surplus food matches right now. Try another location or check back soon.</p>';
        return;
    }

    grid.innerHTML = items.map(l => {
        const pct = discountPercent(l);
        return `
            <article class="food-card">
                <div class="vendor-line">
                    <strong>${escapeHtml(l.business_name)}</strong>
                    <span>${escapeHtml(l.city)}</span>
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
                <div class="address">Pickup: ${escapeHtml(l.address)}</div>
                ${l.is_sample ? '' : `<div class="redeem-hint">Or redeem for ${l.points_cost} pts</div>`}
                <a href="checkout.html?listing=${encodeURIComponent(l.listings_id)}" class="btn-primary btn-link">Reserve &amp; Generate QR</a>
            </article>`;
    }).join('');
}