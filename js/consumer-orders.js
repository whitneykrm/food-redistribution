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
                    ${canDownloadReceipt(o) ? `
                        <div class="order-actions">
                            <button type="button" class="btn-secondary" data-receipt="${o.order_id}">Download receipt</button>
                        </div>` : ''}
                    ${isReserved ? `
                        <div class="order-actions">
                            <button type="button" class="btn-secondary" data-action="show-qr" data-id="${o.order_id}">Show QR</button>
                            <button type="button" class="btn-danger-outline" data-action="cancel-order" data-id="${o.order_id}">Cancel</button>
                        </div>` : ''}
                </div>
            </article>`;
    }).join('');
}

// ── QR code ────────────────────────────────────────────────────
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
// ── Receipts and order history ─────────────────────────────────
const PAID_STATUSES = ['paid', 'completed', 'complete', 'successful', 'success', 'received', 'confirmed'];

function isPaid(o) {
    return o.paid_with_points || PAID_STATUSES.includes(String(o.payment_status || '').toLowerCase());
}

// A receipt is for an order that was not cancelled and has been collected or paid for
function canDownloadReceipt(o) {
    return o.order_status !== 'cancelled' && (o.order_status === 'collected' || isPaid(o));
}

function methodLabel(o) {
    return o.payment_method === 'cash' ? 'Cash'
         : o.payment_method === 'points' ? 'Points'
         : (o.payment_method || '-');
}

function amountLabel(o) {
    return o.paid_with_points
        ? Number(o.points_cost).toLocaleString('en-KE') + ' pts'
        : (o.amount !== null ? 'KES ' + money(o.amount) : '-');
}

function cap(s) { s = String(s || ''); return s.charAt(0).toUpperCase() + s.slice(1); }

// Opens a clean receipt in a new tab and the print dialog, so the consumer can
function openReceipt(order) {
    const w = window.open('', '_blank');
    if (!w) { showAlert('Please allow pop-ups to download your receipt.', false); return; }

    const issued = new Date().toLocaleString('en-KE', { dateStyle: 'long', timeStyle: 'short' });
    const earned = order.paid_with_points ? '-' :
        (order.points && order.order_status === 'collected' ? '+' + order.points + ' pts earned' : '-');

    const row = (label, value) => `<tr><th>${label}</th><td>${escapeHtml(value)}</td></tr>`;

    w.document.write(`<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8">
<title>Receipt ORD-${order.order_no}</title>
<style>
    * { box-sizing: border-box; font-family: 'Segoe UI', Tahoma, Arial, sans-serif; }
    body { margin: 0; padding: 40px 16px; background: #f4f6f8; color: #222; }
    .receipt { max-width: 520px; margin: 0 auto; background: #fff; border-radius: 10px;
               padding: 32px; box-shadow: 0 4px 15px rgba(0,0,0,.08); }
    .brand { text-align: center; font-size: 1.6rem; font-weight: 800; color: #28a745; }
    .brand span { color: #e67e22; }
    .tag { text-align: center; color: #6c757d; font-size: .75rem; letter-spacing: .5px;
           text-transform: uppercase; margin-bottom: 18px; }
    h1 { text-align: center; font-size: 1.1rem; margin: 0 0 4px; }
    .ref { text-align: center; color: #6c757d; font-size: .9rem; margin-bottom: 18px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { text-align: left; padding: 9px 0; border-bottom: 1px solid #e5e7eb; font-size: .92rem; vertical-align: top; }
    th { width: 42%; color: #6c757d; font-weight: 600; }
    .total td, .total th { font-size: 1.05rem; font-weight: 700; color: #222; border-bottom: 2px solid #222; }
    .code { word-break: break-all; font-family: monospace; font-size: .78rem; }
    .foot { text-align: center; color: #6c757d; font-size: .8rem; margin-top: 20px; }
    .actions { text-align: center; margin-top: 18px; }
    button { background: #285da7; color: #fff; border: 0; border-radius: 6px; padding: 10px 20px;
             font-size: .95rem; font-weight: 600; cursor: pointer; }
    @media print { body { background: #fff; padding: 0; } .receipt { box-shadow: none; } .actions { display: none; } }
</style></head><body>
<div class="receipt">
    <div class="brand">Food <span>Connect</span></div>
    <div class="tag">Good food. Greater impact.</div>
    <h1>Order Receipt</h1>
    <div class="ref">Order #ORD-${order.order_no}</div>
    <table>
        ${row('Item', order.item_name)}
        ${row('Business', order.business_name)}
        ${row('Pickup location', order.address + (order.city ? ', ' + order.city : ''))}
        ${row('Order status', cap(order.order_status))}
        ${row('Payment method', methodLabel(order))}
        ${row('Payment status', cap(order.payment_status || 'n/a'))}
        ${row('Points', earned)}
        <tr class="total"><th>Total</th><td>${escapeHtml(amountLabel(order))}</td></tr>
        <tr><th>Verification code</th><td class="code">${escapeHtml(order.qr_code)}</td></tr>
    </table>
    <div class="foot">Issued on ${escapeHtml(issued)}<br>Thank you for helping reduce food waste.</div>
    <div class="actions"><button onclick="window.print()">Print / Save as PDF</button></div>
</div>
<script>window.onload = function () { setTimeout(function () { window.print(); }, 300); };<\/script>
</body></html>`);
    w.document.close();
}

// Click on "Download receipt"
document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-receipt]');
    if (!btn) return;
    const order = myOrders.find(o => o.order_id === Number(btn.dataset.receipt));
    if (order) openReceipt(order);
});

// "Download my orders" - the whole order history as a CSV file
function downloadOrdersCsv() {
    if (!myOrders.length) { showAlert('You have no orders to download yet.', false); return; }
    const cell = (v) => '"' + String(v ?? '').replace(/"/g, '""') + '"';
    const lines = [['Order', 'Item', 'Business', 'Pickup', 'Amount', 'Payment method', 'Payment status', 'Order status', 'Points']
        .map(cell).join(',')];

    [...myOrders].sort((a, b) => a.order_no - b.order_no).forEach(o => {
        const pts = o.paid_with_points ? '-' + o.points_cost
                  : (o.order_status === 'collected' ? '+' + (o.points || 0) : 0);
        lines.push([
            'ORD-' + o.order_no, o.item_name, o.business_name,
            o.address + (o.city ? ', ' + o.city : ''),
            amountLabel(o), methodLabel(o), cap(o.payment_status || 'n/a'), cap(o.order_status), pts
        ].map(cell).join(','));
    });

    const blob = new Blob(['\ufeff' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'my-food-connect-orders.csv';
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(a.href);
}

const downloadOrdersBtn = document.getElementById('download-orders');
if (downloadOrdersBtn) downloadOrdersBtn.addEventListener('click', downloadOrdersCsv);