function numberOrders(orders) {
    [...orders]
        .sort((a, b) => Number(a.order_id) - Number(b.order_id))
        .forEach((o, i) => { o.order_no = i + 1; });
}

async function loadOrders() {
    const list = $('orders-list');
    try {
        const res = await fetch('api/get-my-orders.php');
        if (res.status === 401) { window.location.href = 'login.html'; return; }
        const data = await res.json();

        if (!data.success) {
            list.innerHTML = `<p class="empty">${escapeHtml(data.message || 'Failed to load your orders.')}</p>`;
            return;
        }
        myOrders = data.orders;
        numberOrders(myOrders);
        points = data.points || points;
        rules = data.rules || rules;
        renderPoints();
        renderOrders();
    } catch (err) {
        console.error('Error loading orders:', err);
        list.innerHTML = '<p class="empty">Failed to load your orders.</p>';
    }
}

function pointsBadge(o) {
    if (o.paid_with_points) {
        return o.order_status === 'cancelled'
            ? `<span class="badge-points">${o.points_cost} pts refunded</span>`
            : `<span class="badge-points">${o.points_cost} pts used</span>`;
    }
    if (!o.points) return '';
    if (o.order_status === 'collected') return `<span class="badge-points">+${o.points} pts earned</span>`;
    if (o.order_status === 'reserved') return `<span class="badge-points">${o.points} pts on collection</span>`;
    return '';
}

function renderOrders() {
    const list = $('orders-list');
    const badge = $('orders-count');   // optional: only exists if the page has an orders counter
    const activeCount = myOrders.filter(o => o.order_status === 'reserved').length;
    if (badge) {
        badge.textContent = activeCount;
        badge.style.display = activeCount > 0 ? 'inline-block' : 'none';
    }

    if (myOrders.length === 0) {
        list.innerHTML = '<p class="empty">You have no reservations yet. Browse listings to reserve your first meal.</p>';
        return;
    }

    list.innerHTML = myOrders.map(o => {
        const isReserved = o.order_status === 'reserved';
        const method = o.payment_method === 'cash' ? 'Cash'
                     : o.payment_method === 'points' ? 'Points'
                     : (o.payment_method || '—');
        const amountText = o.paid_with_points
            ? o.points_cost.toLocaleString('en-KE') + ' pts'
            : (o.amount !== null ? 'KES ' + money(o.amount) : '—');

        return `
            <article class="order-card">
                <div class="order-main">
                    <div class="order-ref">ORDER #ORD-${o.order_no}</div>
                    <h3 class="item-name">${escapeHtml(o.item_name)}</h3>
                    <div class="meta">
                        <span>${escapeHtml(o.business_name)}</span>
                        <span>${escapeHtml(o.city)}</span>
                    </div>
                    <div class="address">Pickup: ${escapeHtml(o.address)}</div>
                </div>
                <div class="order-side">
                    <span class="status status-${escapeHtml(o.order_status)}">${escapeHtml(o.order_status.charAt(0).toUpperCase() + o.order_status.slice(1))}</span>
                    <div class="order-amount">${amountText}</div>
                    ${pointsBadge(o)}
                    <div class="meta">${escapeHtml(method)} &middot; payment ${escapeHtml(o.payment_status || 'n/a')}</div>
                    ${isReserved ? `
                        <div class="order-actions">
                            <button type="button" class="btn-secondary" data-action="show-qr" data-id="${o.order_id}">Show QR</button>
                            <button type="button" class="btn-danger-outline" data-action="cancel-order" data-id="${o.order_id}">Cancel</button>
                        </div>` : ''}
                </div>
            </article>`;
    }).join('');
}
function buildQrText(order) {
    return [
        'FOOD CONNECT',
        'Order #ORD-' + order.order_no,
        order.item_name,
        order.business_name + (order.city ? ', ' + order.city : ''),
        'Code: ' + order.qr_code
    ].join('\n');
}

function openQrModal(order) {
    $('qr-details').innerHTML =
        `Order #ORD-${order.order_no} &middot; ${escapeHtml(order.item_name)}<br>` +
        `${escapeHtml(order.business_name)} &middot; ${escapeHtml(order.address)}`;

    const box = $('qr-box');
    box.innerHTML = '';
    if (typeof QRCode !== 'undefined') {
        new QRCode(box, { text: buildQrText(order), width: 240, height: 240, correctLevel: QRCode.CorrectLevel.M });
    }
    $('qr-code-text').textContent = order.qr_code;
    $('qr-modal').classList.add('open');
}

// ── Cancel ─────────────────────────────────────────────────────
async function cancelOrder(orderId) {
    if (!confirm('Cancel this reservation? Any points used on it are refunded.')) return;
    try {
        const res = await fetch('api/cancel-order.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ order_id: orderId })
        });
        const result = await res.json();
        showAlert(result.message || (result.success ? 'Reservation cancelled.' : 'Could not cancel.'), result.success);
        if (result.success) await Promise.all([loadListings(), loadOrders()]);
    } catch (err) {
        console.error('Cancel failed:', err);
        showAlert('An unexpected error occurred. Please try again.', false);
    }
}