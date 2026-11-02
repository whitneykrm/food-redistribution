// #browse, #favourites, #orders, #points
const SECTION_HEADERS = {
    browse: ['Find Surplus Food', 'Reserve discounted food from verified local vendors, then collect it with your QR code.'],
    favourites: ['My Favourite Eateries', 'Get an SMS when your favourite eateries post new surplus food.'],
    orders: ['My Orders', 'Track your reservations and show your QR code when you collect.'],
    points: ['My Points', 'Earn points on every meal you collect, then redeem them for surplus food.']
};

function activateTab(name) {
    if (!SECTION_HEADERS[name]) name = 'browse';

    Object.keys(SECTION_HEADERS).forEach(t => {
        const panel = document.getElementById('tab-' + t);
        if (panel) panel.style.display = (t === name) ? '' : 'none';
    });

    document.getElementById('page-title').textContent = SECTION_HEADERS[name][0];
    document.getElementById('page-subtitle').textContent = SECTION_HEADERS[name][1];
}

window.addEventListener('hashchange', () => activateTab(location.hash.slice(1)));

// ── Click actions on cards ─────────────────────────────────────
document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-action]');
    if (!btn || btn.disabled) return;
    const id = Number(btn.dataset.id);

    if (btn.dataset.action === 'reserve' && typeof openReserveModal === 'function') {
        openReserveModal(id);
    } else if (btn.dataset.action === 'redeem') {
        openRedeemModal(id);
    } else if (btn.dataset.action === 'show-qr') {
        const order = myOrders.find(o => o.order_id === id);
        if (order) openQrModal(order);
    } else if (btn.dataset.action === 'cancel-order') {
        cancelOrder(id);
    }
});

// ── Filters ────────────────────────────────────────────────────
$('filter-search').addEventListener('input', renderListings);
$('filter-city').addEventListener('change', renderListings);
$('filter-sort').addEventListener('change', renderListings);

// ── Modal buttons (null-safe: a missing modal won't break the page) 
function closeAllModals() {
    document.querySelectorAll('.modal-backdrop').forEach(m => m.classList.remove('open'));
}

function onClick(id, handler) {
    const el = $(id);
    if (el) el.addEventListener('click', handler);
}
onClick('reserve-confirm', () => { if (typeof confirmReservation === 'function') confirmReservation(); });
onClick('reserve-cancel', closeAllModals);
onClick('redeem-confirm', confirmRedemption);
onClick('redeem-cancel', closeAllModals);
onClick('qr-close', closeAllModals);

// Clicking the dark backdrop or pressing Escape closes any open modal
document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
    backdrop.addEventListener('click', (e) => { if (e.target === backdrop) closeAllModals(); });
});
document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeAllModals(); });

// ── Start ──────────────────────────────────────────────────────
(async () => {
    loadFragment('navbar-placeholder', 'includes/navbar.html');
    loadFragment('footer-placeholder', 'includes/footer.html');

    const allowed = await checkConsumerAccess();
    if (!allowed) return;

    renderPoints();
    activateTab(location.hash.slice(1));   // open the tab from the URL (defaults to browse)
    await Promise.all([loadListings(), loadOrders(), loadFavourites()]);

    // Keep "expires in" labels fresh and drop listings that expire while the page is open
    setInterval(() => { renderListings(); renderRedeem(); }, 60000);
})();