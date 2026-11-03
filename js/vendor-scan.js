let qrScanner = null;
let scanBusy = false;

async function startScanner() {
    const note = document.getElementById('scan-camera-note');
    document.getElementById('scan-camera').style.display = '';
    if (typeof Html5Qrcode === 'undefined') {
        note.textContent = 'The camera scanner could not load. Type the code below instead.';
        return;
    }
    try {
        qrScanner = new Html5Qrcode('qr-reader');
        await qrScanner.start(
            { facingMode: 'environment' },
            { fps: 10, qrbox: { width: 220, height: 220 } },
            (text) => { if (!scanBusy) verifyCode(text); },
            () => {}
        );
        note.textContent = "Point the camera at the consumer's QR code.";
    } catch (err) {
        console.error('Camera error:', err);
        qrScanner = null;
        note.textContent = 'The camera could not start (allow camera access, or type the code below instead).';
    }
}

async function stopScanner() {
    if (!qrScanner) return;
    try { await qrScanner.stop(); } catch (e) { /* already stopped */ }
    try { qrScanner.clear(); } catch (e) { /* nothing to clear */ }
    qrScanner = null;
}

function showScanResult(result) {
    const box = document.getElementById('scan-result');
    const o = result.order;
    const kind = result.success ? 'ok' : (result.code === 'already_collected' ? 'warn' : 'bad');
    const icon = result.success ? '✔' : (kind === 'warn' ? '!' : '✖');

    let details = '';
    if (o) {
        const amount = o.amount !== null ? 'KES ' + kes(o.amount) : '-';
        const paid = o.payment_paid ? 'Paid' : 'Pending';
        details = `<dl class="scan-details">
            <dt>Order</dt><dd>#ORD-${o.order_no}</dd>
            <dt>Item</dt><dd>${escapeHtml(o.item_name)}</dd>
            <dt>Customer</dt><dd>${escapeHtml(o.customer)}</dd>
            <dt>Amount</dt><dd>${amount} &middot; ${escapeHtml(o.payment_method || '-')} &middot; ${paid}</dd>
        </dl>`;
        if (result.success && !o.payment_paid) {
            details += `<button type="button" class="btn-submit" onclick="markPaid(${o.order_id}, this)">Payment received</button>`;
        }
    }

    box.className = 'scan-result scan-' + kind;
    box.innerHTML = `<div class="scan-message"><span class="scan-icon">${icon}</span>${escapeHtml(result.message)}</div>${details}`;
    box.style.display = 'block';
    document.getElementById('scan-again').style.display = '';
}

async function verifyCode(text) {
    if (scanBusy) return;
    scanBusy = true;
    await stopScanner();
    document.getElementById('scan-camera').style.display = 'none';
    try {
        const res = await fetch('api/verify-qr.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ code: text })
        });
        const result = JSON.parse(await res.text());
        showScanResult(result);
        if (result.success) loadOverview();
    } catch (err) {
        console.error('Verify failed:', err);
        showScanResult({ success: false, code: 'error', message: 'Could not verify the QR code. Please try again.' });
    }
    scanBusy = false;
}

function resetScanModal() {
    document.getElementById('scan-result').style.display = 'none';
    document.getElementById('scan-again').style.display = 'none';
    document.getElementById('scan-code').value = '';
}

function openScanModal() {
    resetScanModal();
    document.getElementById('scan-modal').classList.add('open');
    startScanner();
}

async function closeScanModal() {
    await stopScanner();
    document.getElementById('scan-modal').classList.remove('open');
}

document.getElementById('open-scan').addEventListener('click', openScanModal);
document.getElementById('scan-close').addEventListener('click', closeScanModal);
document.getElementById('scan-again').addEventListener('click', () => { resetScanModal(); startScanner(); });
document.getElementById('scan-manual').addEventListener('submit', (e) => {
    e.preventDefault();
    const code = document.getElementById('scan-code').value.trim();
    if (code) verifyCode(code);
});
document.getElementById('scan-modal').addEventListener('click', (e) => {
    if (e.target.id === 'scan-modal') closeScanModal();
});