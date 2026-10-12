function renderPoints() {
    $('points-available').textContent = points.available.toLocaleString('en-KE');
    $('points-pending').textContent = points.pending.toLocaleString('en-KE');
    $('points-spent').textContent = points.spent.toLocaleString('en-KE');
    $('points-rule').textContent =
        `Earn 1 point for every KES ${rules.kes_per_point} you spend, credited when you collect. ` +
        `Redeem once you have ${rules.min_redeem} points: 1 point = KES ${rules.point_value_kes}.`;

    $('points-how').innerHTML = `
        <div class="how-step"><strong>1. Earn</strong>Get 1 point for every KES ${rules.kes_per_point} you spend. Points are credited when you collect your order.</div>
        <div class="how-step"><strong>2. Unlock</strong>Redemption opens once you have at least ${rules.min_redeem} points.</div>
        <div class="how-step"><strong>3. Redeem</strong>Pay for surplus food with points instead of money. 1 point = KES ${rules.point_value_kes}.</div>`;

    renderRedeem();
    renderActivity();
}

function renderRedeem() {
    const grid = $('redeem-grid');
    const status = $('redeem-status');
    const available = points.available;
    const unlocked = available >= rules.min_redeem;
    const now = new Date();

    status.textContent = unlocked
        ? `You have ${available.toLocaleString('en-KE')} points to spend.`
        : `You have ${available.toLocaleString('en-KE')} points. Redemption unlocks at ${rules.min_redeem}, so ${rules.min_redeem - available} more to go.`;

    const items = allListings
        .filter(l => parseDate(l.expiry_time) > now)
        .sort((a, b) => a.points_cost - b.points_cost);

    if (items.length === 0) {
        grid.innerHTML = '<p class="empty">No surplus food is available to redeem right now.</p>';
        return;
    }

    grid.innerHTML = items.map(l => {
        const canRedeem = unlocked && available >= l.points_cost;
        let buttonText = `Redeem ${l.points_cost.toLocaleString('en-KE')} pts`;
        if (!unlocked) buttonText = `Unlocks at ${rules.min_redeem} pts`;
        else if (!canRedeem) buttonText = `Need ${(l.points_cost - available).toLocaleString('en-KE')} more pts`;

        return `
            <article class="food-card ${canRedeem ? '' : 'locked'}">
                <div class="vendor-line">
                    <strong>${escapeHtml(l.business_name)}</strong>
                    <span>${escapeHtml(l.city)}</span>
                </div>
                <h3 class="item-name">${escapeHtml(l.item_name)}</h3>
                <div class="price-row">
                    <span class="price-now">${l.points_cost.toLocaleString('en-KE')} pts</span>
                    <span class="price-was">KES ${money(l.discounted_price)}</span>
                </div>
                <div class="meta">
                    <span>${l.quantity} left</span>
                    <span>Expires in ${timeLeft(l.expiry_time)}</span>
                </div>
                <div class="address">Pickup: ${escapeHtml(l.address)}</div>
                <button type="button" class="btn-primary" data-action="redeem" data-id="${l.listings_id}" ${canRedeem ? '' : 'disabled'}>${buttonText}</button>
            </article>`;
    }).join('');
}

function renderActivity() {
    const box = $('points-activity');
    const rows = [];

    myOrders.forEach(o => {
        const ref = `Order #ORD-${o.order_id}`;
        if (o.paid_with_points) {
            if (o.order_status !== 'cancelled') {
                rows.push({ title: `Redeemed for ${o.item_name}`, note: ref, value: `-${o.points_cost}`, cls: 'pts-neg' });
            }
        } else if (o.points > 0) {
            if (o.order_status === 'collected') {
                rows.push({ title: `Earned from ${o.item_name}`, note: ref, value: `+${o.points}`, cls: 'pts-pos' });
            } else if (o.order_status === 'reserved') {
                rows.push({ title: `Pending from ${o.item_name}`, note: `${ref} · credited when collected`, value: `+${o.points}`, cls: 'pts-pending' });
            }
        }
    });

    if (rows.length === 0) {
        box.innerHTML = '<p class="empty" style="border: none;">No points activity yet. Reserve a meal to start earning.</p>';
        return;
    }

    box.innerHTML = rows.map(r => `
        <div class="activity-row">
            <div>
                <div>${escapeHtml(r.title)}</div>
                <div class="activity-note">${escapeHtml(r.note)}</div>
            </div>
            <div class="${r.cls}">${r.value} pts</div>
        </div>`).join('');
}

// ── Redeem flow ────────────────────────────────────────────────
function openRedeemModal(listingId) {
    const listing = allListings.find(l => l.listings_id === listingId);
    if (!listing) return;
    redeemTarget = listing;

    $('redeem-summary').innerHTML = `
        <strong>${escapeHtml(listing.item_name)}</strong><br>
        ${escapeHtml(listing.business_name)}, ${escapeHtml(listing.city)}<br>
        Pickup: ${escapeHtml(listing.address)}<br>
        Cost: <strong>${listing.points_cost.toLocaleString('en-KE')} points</strong><br>
        Your balance: ${points.available.toLocaleString('en-KE')} &rarr; ${(points.available - listing.points_cost).toLocaleString('en-KE')} points`;
    $('redeem-modal').classList.add('open');
}

async function confirmRedemption() {
    if (!redeemTarget) return;
    const btn = $('redeem-confirm');
    btn.disabled = true;
    btn.textContent = 'Redeeming...';

    try {
        const res = await fetch('api/redeem-points.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ listing_id: redeemTarget.listings_id })
        });
        if (res.status === 401) { window.location.href = 'login.html'; return; }
        const result = await res.json();
        closeModals();

        if (result.success) {
            await Promise.all([loadListings(), loadOrders()]);
            switchTab('orders');
            showAlert(`Redeemed ${result.points_spent} points! You have ${result.points_left} left. Show your QR code when you collect.`, true);
            const order = myOrders.find(o => o.order_id === result.order_id);
            if (order) openQrModal(order);
        } else {
            showAlert(result.message || 'Could not redeem your points.', false);
            await Promise.all([loadListings(), loadOrders()]);
        }
    } catch (err) {
        console.error('Redemption failed:', err);
        closeModals();
        showAlert('An unexpected error occurred. Please try again.', false);
    } finally {
        btn.disabled = false;
        btn.textContent = 'Confirm Redemption';
    }
}