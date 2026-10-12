document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-action]');
    if (!btn || btn.disabled) return;
    const id = Number(btn.dataset.id);

    if (btn.dataset.action === 'reserve') {
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

document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => switchTab(btn.dataset.tab));
});

$('filter-search').addEventListener('input', renderListings);
$('filter-city').addEventListener('change', renderListings);
$('filter-sort').addEventListener('change', renderListings);

$('reserve-confirm').addEventListener('click', confirmReservation);
$('reserve-cancel').addEventListener('click', closeModals);
$('redeem-confirm').addEventListener('click', confirmRedemption);
$('redeem-cancel').addEventListener('click', closeModals);
$('qr-close').addEventListener('click', closeModals);

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
    await Promise.all([loadListings(), loadOrders()]);

    // Keep "expires in" labels fresh and drop listings that expire while the page is open
    setInterval(() => { renderListings(); renderRedeem(); }, 60000);
})();