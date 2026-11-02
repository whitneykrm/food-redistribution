// A consumer gets an SMS when a favourite eatery posts a new listing.
let favouriteVendorIds = new Set();
let favouriteList = [];
let favouritePhone = null;
let smsEnabled = true;

async function loadFavourites() {
    try {
        const res = await fetch('api/favourites.php');
        if (res.status === 401) { window.location.href = 'login.html'; return; }
        const data = await res.json();
        if (!data.success) return;
        favouriteList = data.favourites || [];
        favouriteVendorIds = new Set(favouriteList.map(f => f.vendor_id));
        favouritePhone = data.phone || null;
        smsEnabled = data.sms_enabled !== false;
        renderFavourites();
        if (typeof renderListings === 'function' && allListings.length) renderListings();
    } catch (err) {
        console.error('Error loading favourites:', err);
    }
}

// The heart shown on each listing card
function favouriteButton(l) {
    if (!l.vendor_id) return '';
    const fav = favouriteVendorIds.has(Number(l.vendor_id));
    return `<button type="button" class="fav-btn ${fav ? 'is-fav' : ''}" data-fav-vendor="${l.vendor_id}"
                aria-pressed="${fav}" title="${fav ? 'Remove from favourites' : 'Add to favourites (get SMS alerts)'}">${fav ? '♥' : '♡'}</button>`;
}

async function setFavourite(vendorId, makeFavourite) {
    try {
        const res = await fetch('api/favourites.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'toggle', vendor_id: vendorId, favourite: makeFavourite })
        });
        const data = JSON.parse(await res.text());
        if (!data.success) { showAlert(data.message || 'Could not update your favourites.', false); return; }

        if (makeFavourite) {
            showAlert(data.has_phone
                ? `${data.business} added to your favourites. You will get an SMS when they post new food.`
                : `${data.business} added. Add your phone number in My Favourites so we can text you.`, true);
        } else {
            showAlert(`${data.business} removed from your favourites.`, true);
        }
        await loadFavourites();
    } catch (err) {
        console.error('Favourite update failed:', err);
        showAlert('An unexpected error occurred. Please try again.', false);
    }
}

document.addEventListener('click', (e) => {
    const heart = e.target.closest('[data-fav-vendor]');
    if (heart) {
        const id = Number(heart.dataset.favVendor);
        setFavourite(id, !favouriteVendorIds.has(id));
        return;
    }
    const remove = e.target.closest('[data-fav-remove]');
    if (remove) setFavourite(Number(remove.dataset.favRemove), false);
});

// ── My Favourites section ──────────────────────────────────────
function renderFavourites() {
    const box = $('favourites-list');
    if (!box) return;

    const phoneInput = $('fav-phone');
    const phoneNote = $('fav-phone-note');
    if (phoneInput && document.activeElement !== phoneInput) phoneInput.value = favouritePhone || '';
    if (phoneNote) {
        phoneNote.textContent = !smsEnabled
            ? 'SMS alerts are switched off on this system right now.'
            : favouritePhone
                ? `Alerts are sent to ${favouritePhone}.`
                : 'Add a phone number to receive SMS alerts.';
    }

    if (favouriteList.length === 0) {
        box.innerHTML = '<p class="empty">You have no favourite eateries yet. Tap the heart on a listing in Browse Listings to add one.</p>';
        return;
    }

    box.innerHTML = favouriteList.map(f => `
        <article class="order-card fav-card">
            <div class="order-main">
                <h3 class="item-name">${escapeHtml(f.business_name)}</h3>
                <div class="meta"><span>${escapeHtml(f.area || f.city || '')}</span></div>
                <div class="address">${escapeHtml(f.address || '')}</div>
            </div>
            <div class="order-side">
                <span class="status ${f.active_listings > 0 ? 'status-collected' : 'status-cancelled'}">
                    ${f.active_listings > 0 ? f.active_listings + ' listing' + (f.active_listings === 1 ? '' : 's') + ' available' : 'No listings right now'}
                </span>
                <div class="order-actions">
                    <button type="button" class="btn-danger-outline" data-fav-remove="${f.vendor_id}">Remove</button>
                </div>
            </div>
        </article>`).join('');
}

const favPhoneForm = document.getElementById('fav-phone-form');
if (favPhoneForm) {
    favPhoneForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        try {
            const res = await fetch('api/favourites.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'save_phone', phone: $('fav-phone').value })
            });
            const data = JSON.parse(await res.text());
            showAlert(data.message || (data.success ? 'Phone number saved.' : 'Could not save the number.'), !!data.success);
            if (data.success) { favouritePhone = data.phone; renderFavourites(); }
        } catch (err) {
            console.error('Save phone failed:', err);
            showAlert('An unexpected error occurred. Please try again.', false);
        }
    });
}