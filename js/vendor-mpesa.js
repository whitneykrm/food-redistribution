// Vendor dashboard: send an M-Pesa payment prompt (STK Push) to a customer and wait for the result.

let mpesaOrderId = null;
let mpesaPollTimer = null;
let mpesaBusy = false;

function mpesaShow(kind, text) {
    const box = document.getElementById('mpesa-state');
    box.className = 'mpesa-state ' + kind;
    box.textContent = text;
    box.style.display = '';
}

function mpesaStopPolling() {
    if (mpesaPollTimer) { clearTimeout(mpesaPollTimer); mpesaPollTimer = null; }
}

function openMpesaModal(orderId, orderNo, amount) {
    mpesaOrderId = orderId;
    mpesaBusy = false;
    mpesaStopPolling();
    document.getElementById('mpesa-summary').textContent = `Order #ORD-${orderNo} - KES ${kes(amount)}`;
    document.getElementById('mpesa-phone').value = '';
    document.getElementById('mpesa-form').style.display = '';
    document.getElementById('mpesa-state').style.display = 'none';
    const send = document.getElementById('mpesa-send');
    send.style.display = '';
    send.disabled = false;
    send.textContent = 'Send prompt';
    document.getElementById('mpesa-modal').classList.add('open');
}

function closeMpesaModal() {
    mpesaStopPolling();
    document.getElementById('mpesa-modal').classList.remove('open');
}

async function sendMpesaPrompt() {
    if (mpesaBusy || !mpesaOrderId) return;
    mpesaBusy = true;
    const send = document.getElementById('mpesa-send');
    send.disabled = true;
    mpesaShow('wait', 'Sending the prompt...');

    try {
        const res = await fetch('api/mpesa-stk-push.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                order_id: mpesaOrderId,
                phone: document.getElementById('mpesa-phone').value.trim()
            })
        });
        const data = JSON.parse(await res.text());
        if (!data.success) {
            mpesaShow('bad', data.message || 'Could not send the prompt.');
            send.textContent = 'Try again';
            send.disabled = false;
            mpesaBusy = false;
            return;
        }
        document.getElementById('mpesa-form').style.display = 'none';
        send.style.display = 'none';
        mpesaShow('wait', 'Prompt sent. Ask the customer to enter their M-Pesa PIN...');
        pollMpesa(data.mpesa_id, Date.now());
    } catch (err) {
        console.error('M-Pesa error:', err);
        mpesaShow('bad', 'Could not reach the server. Try again.');
        send.disabled = false;
        mpesaBusy = false;
    }
}

function pollMpesa(mpesaId, startedAt) {
    mpesaPollTimer = setTimeout(async () => {
        try {
            const res = await fetch('api/mpesa-status.php?id=' + encodeURIComponent(mpesaId));
            const data = JSON.parse(await res.text());

            if (data.success && data.status === 'success') {
                mpesaShow('ok', 'Payment received' + (data.receipt ? ' - M-Pesa receipt ' + data.receipt : '') + '.');
                mpesaBusy = false;
                loadOverview();
                return;
            }
            if (data.success && data.status === 'failed') {
                mpesaShow('bad', data.message || 'The payment was not completed.');
                resetMpesaForRetry();
                return;
            }
        } catch (err) {
            console.error('M-Pesa status error:', err);
        }

        if (Date.now() - startedAt > 120000) {
            mpesaShow('bad', 'No answer yet. Ask the customer to check their phone, then request again, or use "Payment received" if they paid.');
            resetMpesaForRetry();
            return;
        }
        pollMpesa(mpesaId, startedAt);
    }, 4000);
}

function resetMpesaForRetry() {
    mpesaBusy = false;
    document.getElementById('mpesa-form').style.display = '';
    const send = document.getElementById('mpesa-send');
    send.style.display = '';
    send.disabled = false;
    send.textContent = 'Send again';
}

document.getElementById('mpesa-send').addEventListener('click', sendMpesaPrompt);
document.getElementById('mpesa-close').addEventListener('click', closeMpesaModal);
document.getElementById('mpesa-modal').addEventListener('click', (e) => {
    if (e.target.id === 'mpesa-modal') closeMpesaModal();
});