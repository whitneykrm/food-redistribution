const SECTION_HEADERS = {
    browse: ['Find Surplus Food', 'Reserve discounted food from verified local vendors, then collect it with your QR code.'],
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
$('filter-search').addEventListener('input', renderListings);
$('filter-city').addEventListener('change', renderListings);
$('filter-sort').addEventListener('change', renderListings);
function onClick(id, handler) {
    const el = $(id);
    if (el) el.addEventListener('click', handler);
}
onClick('reserve-confirm', () => { if (typeof confirmReservation === 'function') confirmReservation(); });
onClick('reserve-cancel', closeModals);
onClick('redeem-confirm', confirmRedemption);
onClick('redeem-cancel', closeModals);
onClick('qr-close', closeModals);
document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
    backdrop.addEventListener('click', (e) => { if (e.target === backdrop) closeModals(); });
});
document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeModals(); });
(async () => {
    loadFragment('navbar-placeholder', 'includes/navbar.html');
    loadFragment('footer-placeholder', 'includes/footer.html');

    const allowed = await checkConsumerAccess();
    if (!allowed) return;

    renderPoints();
    activateTab(location.hash.slice(1));   
    await Promise.all([loadListings(), loadOrders()]);
    setInterval(() => { renderListings(); renderRedeem(); }, 60000);
})();