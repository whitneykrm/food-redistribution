let allListings = [];
let myOrders = [];
let points = { earned: 0, pending: 0, spent: 0, available: 0 };
let rules = { kes_per_point: 10, point_value_kes: 1, min_redeem: 100 };
let reserveTarget = null;
let redeemTarget = null;
let alertTimer;

const $ = (id) => document.getElementById(id);

function escapeHtml(str) {
    return String(str ?? '')
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
function money(n) {
    return Number(n).toLocaleString('en-KE', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
}
function parseDate(s) {
    return new Date(String(s).replace(' ', 'T'));
}

function timeLeft(expiry) {
    const mins = Math.floor((parseDate(expiry) - new Date()) / 60000);
    if (mins <= 0) return 'now';
    const d = Math.floor(mins / 1440);
    const h = Math.floor((mins % 1440) / 60);
    const m = mins % 60;
    if (d > 0) return `${d}d ${h}h`;
    if (h > 0) return `${h}h ${m}m`;
    return `${m}m`;
}

function discountPercent(l) {
    return l.original_price > 0 ? Math.round((1 - l.discounted_price / l.original_price) * 100) : 0;
}

function showAlert(message, isSuccess = true) {
    const box = $('alert-msg');
    box.className = 'flash ' + (isSuccess ? 'flash-success' : 'flash-error');
    box.textContent = message;
    box.style.display = 'block';
    clearTimeout(alertTimer);
    if (isSuccess) alertTimer = setTimeout(() => { box.style.display = 'none'; }, 6000);
}
async function checkConsumerAccess() {
    try {
        const res = await fetch('api/session-check.php');
        const data = await res.json();
        const role = data.role || (data.user && data.user.role);
        if (!data.loggedIn || role !== 'consumer') {
            window.location.href = 'login.html';
            return false;
        }
        return true;
    } catch (err) {
        console.error('Session check failed:', err);
        window.location.href = 'login.html';
        return false;
    }
}
function switchTab(name) {
    ['browse', 'orders', 'points'].forEach(tab => {
        const panel = $('tab-' + tab);
        // '' removes the inline style so the CSS layout applies
        if (panel) panel.style.display = tab === name ? '' : 'none';
    });
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.tab === name);
    });
}
function closeModals() {
    document.querySelectorAll('.modal-backdrop').forEach(m => m.classList.remove('open'));
    reserveTarget = null;
    redeemTarget = null;
}