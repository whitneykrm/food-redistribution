
function activateTab(name) {
    const tabs = ['browse', 'orders', 'points'];
    if (!tabs.includes(name)) name = 'browse';

    tabs.forEach(t => {
        const panel = document.getElementById('tab-' + t);
        if (panel) panel.style.display = (t === name) ? '' : 'none';
    });
    document.querySelectorAll('.tab-btn').forEach(b => {
        b.classList.toggle('active', b.dataset.tab === name);
    });
}

document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => { location.hash = btn.dataset.tab; });
});
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

// ── Modal buttons (null-safe: a missing modal won't break the page) ──
function onClick(id, handler) {
    const el = $(id);
    if (el) el.addEventListener('click', handler);
}
onClick('reserve-confirm', () => { if (typeof confirmReservation === 'function') confirmReservation(); });
onClick('reserve-cancel', closeModals);
onClick('redeem-confirm', confirmRedemption);
onClick('redeem-cancel', closeModals);
onClick('qr-close', closeModals);

// Clicking the dark backdrop or pressing Escape closes any open modal
document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
    backdrop.addEventListener('click', (e) => { if (e.target === backdrop) closeModals(); });
});
document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeModals(); });

// ── Start ──────────────────────────────────────────────────────
(async () => {
    loadFragment('navbar-placeholder', 'includes/navbar.html');
    loadFragment('footer-placeholder', 'includes/footer.html');

    const allowed = await checkConsumerAccess();
    if (!allowed) return;

    renderPoints();
    activateTab(location.hash.slice(1));   // open the tab from the URL (defaults to browse)
    await Promise.all([loadListings(), loadOrders()]);

    // Keep "expires in" labels fresh and drop listings that expire while the page is open
    setInterval(() => { renderListings(); renderRedeem(); }, 60000);
})();