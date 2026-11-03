import { createClient } from '@supabase/supabase-js';
import { getAccessToken, refreshAccessToken } from '../../src/authSession.js';
import { calculateOrderTotals } from '../../src/orderMath.js';
import { isFarmStaffRole } from '../../src/roles.js';

const supabaseUrl = import.meta.env.VITE_SUPABASE_URL;
const supabaseAnonKey = import.meta.env.VITE_SUPABASE_ANON_KEY;
const app = document.querySelector('#app');
const notice = document.querySelector('#app-notice');
const cartStorageKey = 'harvest-cart-v1';
const imageByName = {
    tomato: 'tomato.webp',
    carrot: 'OIP (2).webp',
    lettuce: 'OIP (3).webp',
    cucumber: 'cart.jpeg',
    spinach: 'spinach.jpg',
    'bell pepper': 'OIP.webp',
    zucchini: 'imrs.webp',
    broccoli: 'OIP (1).webp',
    kale: 'OIP (4).webp',
    'green beans': 'R.jpeg',
};
const iconByName = {
    tomato: '🍅',
    carrot: '🥕',
    lettuce: '🥬',
    cucumber: '🥒',
    spinach: '🥬',
    'bell pepper': '🫑',
    zucchini: '🥒',
    broccoli: '🥦',
    kale: '🥬',
    'green beans': '🫛',
};
const state = {
    supabase: null,
    session: null,
    profile: null,
    page: new URLSearchParams(location.search).get('page')
        || (new URLSearchParams(location.hash.slice(1)).get('type') === 'recovery' ? 'reset-password' : ''),
    products: [],
    records: new Map(),
    orderDetails: null,
    cart: readCart(),
};

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (character) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;',
    })[character]);
}

function money(value) {
    return `₱${Number(value || 0).toLocaleString('en-PH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })}`;
}

function dateLabel(value) {
    if (!value) return '—';
    const date = new Date(`${value}`.length === 10 ? `${value}T00:00:00` : value);
    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleDateString('en-PH', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

function dateInputValue(value = new Date()) {
    const date = new Date(value);
    return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
}

function notify(message, type = 'success') {
    notice.textContent = message;
    notice.className = `notice ${type}`;
    notice.hidden = false;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function clearNotice() {
    notice.hidden = true;
    notice.textContent = '';
}

function showError(error) {
    console.error(error);
    notify(error?.message || 'Something went wrong. Please try again.', 'error');
}

function showSupabaseConfigurationError() {
    document.querySelector('#guest-navigation').hidden = true;
    document.querySelector('[data-nav-toggle]').disabled = true;
    app.innerHTML = `
        <section class="panel">
            <h1>Connect Supabase</h1>
            <p>This deployment is missing the Supabase browser configuration.</p>
            <p>In Vercel, add <code>VITE_SUPABASE_URL</code> and <code>VITE_SUPABASE_ANON_KEY</code>
            to the project environment variables for this deployment's environment, then redeploy.</p>
            <p>Make sure the Vercel project root directory is <code>vercel-app</code>.
            The Supabase service-role key must not use the <code>VITE_</code> prefix.</p>
        </section>`;
}

function readCart() {
    try {
        const value = JSON.parse(localStorage.getItem(cartStorageKey) || '{}');
        return value && typeof value === 'object' && !Array.isArray(value) ? value : {};
    } catch (error) {
        console.error('Saved cart could not be read.', error);
        return {};
    }
}

function saveCart() {
    localStorage.setItem(cartStorageKey, JSON.stringify(state.cart));
    updateNavigation();
}

function cartCount() {
    return Object.values(state.cart).reduce((sum, quantity) => sum + Number(quantity || 0), 0);
}

function cartToken() {
    return [...crypto.getRandomValues(new Uint8Array(32))]
        .map((value) => value.toString(16).padStart(2, '0'))
        .join('');
}

function profileInitial() {
    return (state.profile?.name || '?').trim().slice(0, 1).toUpperCase();
}

function updateNavigation() {
    const menu = document.querySelector('#account-menu');
    const guest = document.querySelector('#guest-navigation');
    const nav = document.querySelector('#primary-navigation');
    if (!state.session || !state.profile) {
        menu.hidden = true;
        guest.hidden = false;
        nav.innerHTML = '';
        return;
    }
    menu.hidden = false;
    guest.hidden = true;
    document.querySelector('#account-avatar').textContent = profileInitial();
    document.querySelector('#account-name').textContent = state.profile.name;
    document.querySelector('#account-role').textContent = state.profile.role;
    document.querySelector('#account-dropdown-name').textContent = state.profile.name;
    document.querySelector('#account-email').textContent = state.profile.email;
    document.querySelector('#account-dropdown-role').textContent = state.profile.role;

    const links = state.profile.role === 'buyer'
        ? [
            ['dashboard', '▦', 'Dashboard'],
            ['shop', '⌂', 'Shop'],
            ['orders', '▤', 'My orders'],
            ['cart', '▱', `Cart <span class="app-cart-count">${cartCount()}</span>`],
        ]
        : [
            ['dashboard', '▦', 'Dashboard'],
            ['vegetables', '◉', 'Vegetables'],
            ['plantings', '♧', 'Plantings'],
            ['harvests', '⌁', 'Harvests'],
            ['inventory', '▤', 'Inventory'],
            ['sales', '↗', 'Sales'],
            ['quality', '✓', 'Quality'],
            ['reports', '▥', 'Reports'],
            ...(state.profile.role === 'admin' ? [['activity', '◷', 'Activity']] : []),
        ];
    nav.innerHTML = links.map(([page, icon, label]) => `
        <a class="${state.page === page ? 'active' : ''}" href="?page=${page}" data-page="${page}">
            <span class="nav-icon" aria-hidden="true">${icon}</span>${label}
        </a>
    `).join('');
}

function go(page, replace = false) {
    state.page = page;
    const url = new URL(location.href);
    url.search = '';
    if (page) url.searchParams.set('page', page);
    if (replace) history.replaceState({}, '', url);
    else history.pushState({}, '', url);
    updateNavigation();
    clearNotice();
    void renderPage();
}

function isStaff() {
    return isFarmStaffRole(state.profile?.role);
}

function productPicture(name) {
    const key = String(name || '').trim().toLowerCase();
    const image = imageByName[key];
    const icon = iconByName[key] || '🥬';
    return image
        ? `<div class="product-image"><img src="/assets/images/${encodeURIComponent(image)}" alt="${escapeHtml(name)}" loading="lazy"><span class="product-image-fallback" hidden>${icon}</span></div>`
        : `<div class="product-image product-image-fallback" role="img" aria-label="${escapeHtml(name)}">${icon}</div>`;
}

function statusBadge(value) {
    const label = String(value || '').replaceAll('_', ' ');
    return `<span class="app-status ${escapeHtml(value)}">${escapeHtml(label)}</span>`;
}

function drawDashboardChart(canvasId, labels, values, color) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;
    const bounds = canvas.getBoundingClientRect();
    const ratio = Math.max(1, window.devicePixelRatio || 1);
    canvas.width = Math.round(bounds.width * ratio);
    canvas.height = Math.round(bounds.height * ratio);
    const context = canvas.getContext('2d');
    if (!context) return;
    context.setTransform(ratio, 0, 0, ratio, 0, 0);
    const width = bounds.width;
    const height = bounds.height;
    const left = 42;
    const right = 12;
    const top = 14;
    const bottom = 34;
    const plotWidth = width - left - right;
    const plotHeight = height - top - bottom;
    const maximum = Math.max(1, ...values) * 1.12;
    context.clearRect(0, 0, width, height);
    context.font = '11px system-ui, sans-serif';
    context.fillStyle = '#718078';
    for (let tick = 0; tick <= 4; tick += 1) {
        const y = top + plotHeight * tick / 4;
        context.beginPath();
        context.strokeStyle = '#e7eee7';
        context.moveTo(left, y);
        context.lineTo(width - right, y);
        context.stroke();
        context.textAlign = 'right';
        context.textBaseline = 'middle';
        context.fillText(String(Math.round(maximum * (4 - tick) / 4)), left - 6, y);
    }
    const slot = plotWidth / Math.max(1, labels.length);
    values.forEach((value, index) => {
        const barHeight = Math.max(0, value / maximum * plotHeight);
        const x = left + slot * index + slot * .2;
        context.fillStyle = color;
        context.fillRect(x, top + plotHeight - barHeight, slot * .6, barHeight);
        context.textAlign = 'center';
        context.textBaseline = 'top';
        context.fillStyle = '#718078';
        context.fillText(labels[index], x + slot * .3, top + plotHeight + 8);
    });
}

function table(headers, rows, emptyText = 'No records found.') {
    if (!rows.length) return `<div class="empty">${escapeHtml(emptyText)}</div>`;
    return `<div class="table-wrap"><table><thead><tr>${headers.map((header) => `<th>${escapeHtml(header)}</th>`).join('')}</tr></thead><tbody>${rows.join('')}</tbody></table></div>`;
}

async function queryOrThrow(query) {
    const { data, error } = await query;
    if (error) throw error;
    return data || [];
}

async function getProducts(activeOnly = false) {
    let query = state.supabase.from('vegetables').select('*').order('name');
    if (activeOnly) query = query.eq('active', true);
    const [vegetables, inventory] = await Promise.all([
        queryOrThrow(query),
        queryOrThrow(state.supabase.from('inventory').select('vegetable_id, quantity')),
    ]);
    const quantities = new Map(inventory.map((row) => [row.vegetable_id, Number(row.quantity)]));
    return vegetables.map((vegetable) => ({
        ...vegetable,
        quantity: quantities.get(vegetable.id) || 0,
    }));
}

async function loadProfile(user) {
    if (!user) {
        state.profile = null;
        return;
    }
    const { data, error } = await state.supabase
        .from('profiles')
        .select('id, name, email, role, email_verified_at')
        .eq('auth_user_id', user.id)
        .maybeSingle();
    if (error) throw error;
    state.profile = data;
}

async function loadEmailCallback() {
    const url = new URL(location.href);
    const tokenHash = url.searchParams.get('token_hash');
    const type = url.searchParams.get('type');
    if (!tokenHash || !type) return false;
    const { error } = await state.supabase.auth.verifyOtp({ token_hash: tokenHash, type });
    url.searchParams.delete('token_hash');
    url.searchParams.delete('type');
    url.searchParams.delete('next');
    history.replaceState({}, '', url);
    if (error) throw error;
    notify('Your email has been verified. You can now sign in.');
    return true;
}

function guestPage(page) {
    const pages = ['login', 'register', 'forgot', 'reset-password'];
    if (!pages.includes(page)) return 'login';
    return page;
}

function renderAuth(page) {
    document.body.classList.add('auth-page');
    const title = page === 'register' ? 'Create your account'
        : page === 'forgot' ? 'Reset your password'
            : page === 'reset-password' ? 'Choose a new password'
                : 'Welcome back';
    let form = '';
    if (page === 'register') {
        form = `
            <form class="auth-form-content" data-form="register">
                <h2>${title}</h2><p class="auth-form-intro">Join Harvest to order fresh produce and stay connected to the farm.</p>
                <div class="field"><label for="register-name">Full name</label><input id="register-name" name="name" required maxlength="120" autocomplete="name"></div>
                <div class="field"><label for="register-email">Email address</label><input id="register-email" name="email" type="email" required autocomplete="email"></div>
                <div class="field"><label for="register-password">Password</label><div class="password-field"><input id="register-password" name="password" type="password" required minlength="10" autocomplete="new-password"><button class="password-toggle" type="button" data-action="toggle-password" aria-label="Show password" aria-pressed="false">Show</button></div></div>
                <div class="field"><label for="register-confirm">Confirm password</label><div class="password-field"><input id="register-confirm" name="confirmPassword" type="password" required minlength="10" autocomplete="new-password"><button class="password-toggle" type="button" data-action="toggle-password" aria-label="Show password" aria-pressed="false">Show</button></div></div>
                <button class="auth-submit" type="submit">Create account</button>
                <p class="auth-links">Already registered? <a href="?page=login" data-page="login">Log in</a></p>
            </form>`;
    } else if (page === 'forgot') {
        form = `
            <form class="auth-form-content" data-form="forgot">
                <h2>${title}</h2><p class="auth-form-intro">We will send a password reset link if the account can receive email.</p>
                <div class="field"><label for="forgot-email">Email address</label><input id="forgot-email" name="email" type="email" required autocomplete="email"></div>
                <button class="auth-submit" type="submit">Send reset link</button>
                <p class="auth-links"><a href="?page=login" data-page="login">Back to log in</a></p>
            </form>`;
    } else if (page === 'reset-password') {
        form = `
            <form class="auth-form-content" data-form="reset-password">
                <h2>${title}</h2><p class="auth-form-intro">Use at least 10 characters for your new password.</p>
                <div class="field"><label for="new-password">New password</label><div class="password-field"><input id="new-password" name="password" type="password" required minlength="10" autocomplete="new-password"><button class="password-toggle" type="button" data-action="toggle-password" aria-label="Show password" aria-pressed="false">Show</button></div></div>
                <div class="field"><label for="new-password-confirm">Confirm new password</label><div class="password-field"><input id="new-password-confirm" name="confirmPassword" type="password" required minlength="10" autocomplete="new-password"><button class="password-toggle" type="button" data-action="toggle-password" aria-label="Show password" aria-pressed="false">Show</button></div></div>
                <button class="auth-submit" type="submit">Update password</button>
            </form>`;
    } else {
        form = `
            <form class="auth-form-content" data-form="login">
                <h2>${title}</h2><p class="auth-form-intro">Sign in to manage your farm operations and order fresh produce.</p>
                <div class="field"><label for="login-email">Email address</label><input id="login-email" name="email" type="email" required autocomplete="email"></div>
                <div class="field"><label for="login-password">Password</label><div class="password-field"><input id="login-password" name="password" type="password" required autocomplete="current-password"><button class="password-toggle" type="button" data-action="toggle-password" aria-label="Show password" aria-pressed="false">Show</button></div></div>
                <button class="auth-submit" type="submit">Log in</button>
                <p class="auth-links"><a href="?page=forgot" data-page="forgot">Forgot password?</a></p>
                <p class="auth-links">New here? <a href="?page=register" data-page="register">Create account</a></p>
                <p class="auth-links"><a href="#resend" data-action="resend-verification">Resend verification email</a></p>
            </form>`;
    }
    app.innerHTML = `
        <section class="auth-layout">
            <div class="auth-visual"><div class="auth-visual-content">
                <span class="auth-visual-tag">Fresh from the farm</span>
                <h1>Fresh Harvest,<br>Fresh Choices.</h1>
                <p>Discover fresh vegetables and farm products, grown with care and brought to your table.</p>
            </div></div>
            <div class="auth-form-panel">${form}</div>
        </section>`;
}

async function renderDashboard() {
    if (state.profile.role === 'buyer') {
        const [orders, products] = await Promise.all([
            queryOrThrow(state.supabase.from('orders')
                .select('order_code, final_total, order_status, payment_status, created_at')
                .eq('buyer_id', state.profile.id).order('created_at', { ascending: false }).limit(5)),
            queryOrThrow(state.supabase.from('vegetables').select('id').eq('active', true)),
        ]);
        const rows = orders.map((order) => `<tr>
            <td><a href="?page=order&order=${encodeURIComponent(order.order_code)}" data-page="order" data-order="${escapeHtml(order.order_code)}">${escapeHtml(order.order_code)}</a></td>
            <td>${money(order.final_total)}</td><td>${statusBadge(order.order_status)}</td><td>${statusBadge(order.payment_status)}</td>
        </tr>`);
        app.innerHTML = `
            <section class="dashboard-welcome"><div><p class="dashboard-eyebrow">Buyer account</p><h1>Welcome, ${escapeHtml(state.profile.name)}</h1><p>Browse today's produce and keep track of your orders.</p></div></section>
            <section class="dashboard-stat-grid">
                <article class="dashboard-stat-card"><span class="dashboard-stat-label">Active produce</span><strong>${products.length}</strong><small>Available in the shop</small></article>
                <article class="dashboard-stat-card"><span class="dashboard-stat-label">Your recent orders</span><strong>${orders.length}</strong><small>Latest orders shown below</small></article>
                <article class="dashboard-stat-card"><span class="dashboard-stat-label">Items in cart</span><strong>${cartCount()}</strong><small>Ready when you are</small></article>
                <article class="dashboard-stat-card"><span class="dashboard-stat-label">Account role</span><strong>Buyer</strong><small>Email verified</small></article>
            </section>
            <section class="panel section"><div class="app-toolbar"><div><h2>Quick links</h2><p class="muted">Shop and manage your orders.</p></div></div>
                <div class="app-dashboard-links"><a href="?page=shop" data-page="shop">Browse fresh produce →</a><a href="?page=cart" data-page="cart">View your cart (${cartCount()}) →</a><a href="?page=orders" data-page="orders">Track your orders →</a></div>
            </section>
            <section class="panel section"><h2>Recent orders</h2>${table(['Order', 'Total', 'Delivery', 'Payment'], rows, 'You have not placed any orders yet.')}</section>`;
        return;
    }

    const today = dateInputValue();
    const weekStart = dateInputValue(new Date(Date.now() - 6 * 86400000));
    const [vegetables, inventory, paidOrders, harvests, recentOrders, recentHarvests, dailyHarvests] = await Promise.all([
        queryOrThrow(state.supabase.from('vegetables').select('id, name, unit').eq('active', true)),
        queryOrThrow(state.supabase.from('inventory').select('quantity')),
        queryOrThrow(state.supabase.from('orders').select('final_total, created_at').eq('payment_status', 'paid').gte('created_at', `${weekStart}T00:00:00`)),
        queryOrThrow(state.supabase.from('harvests').select('id').is('archived_at', null)),
        queryOrThrow(state.supabase.from('orders').select('order_code, buyer_id, final_total, order_status, payment_status, created_at').order('created_at', { ascending: false }).limit(8)),
        queryOrThrow(state.supabase.from('harvests').select('id, harvested_on, quantity, planting_id').is('archived_at', null).order('harvested_on', { ascending: false }).limit(6)),
        queryOrThrow(state.supabase.from('harvests').select('quantity, harvested_on').is('archived_at', null).gte('harvested_on', weekStart).lte('harvested_on', today)),
    ]);
    const profiles = recentOrders.length
        ? await queryOrThrow(state.supabase.from('profiles').select('id, name').in('id', [...new Set(recentOrders.map((row) => row.buyer_id))]))
        : [];
    const names = new Map(profiles.map((profile) => [profile.id, profile.name]));
    const totalStock = inventory.reduce((sum, row) => sum + Number(row.quantity), 0);
    const salesByDay = new Map();
    paidOrders.forEach((row) => {
        const day = row.created_at.slice(0, 10);
        salesByDay.set(day, (salesByDay.get(day) || 0) + Number(row.final_total));
    });
    const harvestsByDay = new Map();
    dailyHarvests.forEach((row) => {
        harvestsByDay.set(row.harvested_on, (harvestsByDay.get(row.harvested_on) || 0) + Number(row.quantity));
    });
    const chartDays = Array.from({ length: 7 }, (_, index) => {
        const day = dateInputValue(new Date(Date.now() - (6 - index) * 86400000));
        return {
            day,
            label: new Date(`${day}T00:00:00`).toLocaleDateString('en-PH', { month: 'short', day: 'numeric' }),
        };
    });
    const salesValues = chartDays.map((item) => salesByDay.get(item.day) || 0);
    const harvestValues = chartDays.map((item) => harvestsByDay.get(item.day) || 0);
    const todayRevenue = salesByDay.get(today) || 0;
    const activeVegetableIds = new Set(vegetables.map((item) => item.id));
    const activeInventory = activeVegetableIds.size
        ? await queryOrThrow(state.supabase.from('inventory').select('vegetable_id, quantity').in('vegetable_id', [...activeVegetableIds]))
        : [];
    const vegetableById = new Map(vegetables.map((item) => [item.id, item]));
    const lowStockItems = activeInventory
        .filter((item) => Number(item.quantity) <= 5)
        .map((item) => ({ ...vegetableById.get(item.vegetable_id), quantity: Number(item.quantity) }));
    const orderRows = recentOrders.map((order) => `<tr><td><strong>${escapeHtml(order.order_code)}</strong><span class="table-secondary">${dateLabel(order.created_at)}</span></td><td>${escapeHtml(names.get(order.buyer_id) || 'Buyer')}</td><td>${money(order.final_total)}</td><td>${statusBadge(order.order_status)}</td></tr>`);
    const plantingIds = [...new Set(recentHarvests.map((row) => row.planting_id))];
    const plantings = plantingIds.length
        ? await queryOrThrow(state.supabase.from('plantings').select('id, vegetable_id').in('id', plantingIds))
        : [];
    const vegIds = [...new Set(plantings.map((row) => row.vegetable_id))];
    const harvestVegetables = vegIds.length
        ? await queryOrThrow(state.supabase.from('vegetables').select('id, name, unit').in('id', vegIds))
        : [];
    const plantingVegetable = new Map(plantings.map((row) => [row.id, row.vegetable_id]));
    const vegetableDetails = new Map(harvestVegetables.map((row) => [row.id, row]));
    const harvestRows = recentHarvests.map((row) => {
        const vegetable = vegetableDetails.get(plantingVegetable.get(row.planting_id));
        return `<li><span class="recent-harvest-icon" aria-hidden="true">⌁</span><span class="recent-harvest-copy"><strong>${escapeHtml(vegetable?.name || 'Produce')}</strong><small>${dateLabel(row.harvested_on)}</small></span><strong>${Number(row.quantity).toFixed(2)} <small>${escapeHtml(vegetable?.unit || '')}</small></strong></li>`;
    });

    app.innerHTML = `
        <section class="dashboard-welcome"><div><p class="dashboard-eyebrow">Farm operations</p><h1>Welcome back, ${escapeHtml(state.profile.name)}</h1><p>Here’s what’s happening across your farm today.</p></div><div class="dashboard-date"><span aria-hidden="true">▦</span>${dateLabel(today)}</div></section>
        <section class="dashboard-stat-grid" aria-label="Farm summary">
            <article class="dashboard-stat-card"><span class="dashboard-stat-icon">◉</span><span class="dashboard-stat-label">Active vegetables</span><strong>${vegetables.length}</strong><small>Available in the catalog</small></article>
            <article class="dashboard-stat-card"><span class="dashboard-stat-icon">▤</span><span class="dashboard-stat-label">Total inventory</span><strong>${totalStock.toFixed(2)}</strong><small>Across all recorded stock</small></article>
            <article class="dashboard-stat-card"><span class="dashboard-stat-icon">↗</span><span class="dashboard-stat-label">Sales today</span><strong>${money(todayRevenue)}</strong><small>Paid orders today</small></article>
            <article class="dashboard-stat-card"><span class="dashboard-stat-icon">⌁</span><span class="dashboard-stat-label">Total harvests</span><strong>${harvests.length}</strong><small>Active harvest records</small></article>
        </section>
        ${lowStockItems.length ? `<section class="dashboard-alert section"><div class="dashboard-alert-heading"><span class="dashboard-alert-icon">!</span><div><h2>Stock needs attention</h2><p>${lowStockItems.length} active item(s) are at or below 5 units.</p></div></div><div class="dashboard-alert-items">${lowStockItems.slice(0, 5).map((item) => `<a href="?page=inventory" data-page="inventory"><span>${escapeHtml(item.name)}</span><strong>${item.quantity.toFixed(2)} ${escapeHtml(item.unit)}</strong></a>`).join('')}</div></section>` : ''}
        <section class="dashboard-chart-grid" aria-label="Farm performance charts">
            <article class="dashboard-chart-card"><div class="dashboard-card-heading"><div><h2>Sales performance</h2><p>Paid revenue · last 7 days</p></div></div><div class="dashboard-canvas-wrap"><canvas id="sales-chart" class="dashboard-chart" aria-label="Daily paid sales for the last seven days" role="img"></canvas></div></article>
            <article class="dashboard-chart-card"><div class="dashboard-card-heading"><div><h2>Harvest activity</h2><p>Harvest quantity · last 7 days</p></div></div><div class="dashboard-canvas-wrap"><canvas id="harvest-chart" class="dashboard-chart" aria-label="Daily harvest quantity for the last seven days" role="img"></canvas></div></article>
        </section>
        <section class="panel section"><h2>Quick actions</h2><p class="muted">Jump into your day-to-day farm tasks.</p><div class="app-dashboard-links">
            <a href="?page=vegetables" data-page="vegetables">Manage vegetables →</a><a href="?page=harvests" data-page="harvests">Record harvest →</a><a href="?page=sales" data-page="sales">Review sales →</a><a href="?page=reports" data-page="reports">View reports →</a>
        </div></section>
        <section class="dashboard-bottom-grid">
            <article class="dashboard-table-card"><div class="dashboard-card-heading"><div><h2>Recent sales</h2><p>Latest customer orders</p></div><a href="?page=sales" data-page="sales">View all</a></div>${table(['Order', 'Buyer', 'Total', 'Status'], orderRows, 'No orders have been placed.')}</article>
            <article class="dashboard-table-card"><div class="dashboard-card-heading"><div><h2>Recent harvests</h2><p>Latest recorded harvests</p></div><a href="?page=harvests" data-page="harvests">View all</a></div><ul class="recent-harvest-list">${harvestRows.length ? harvestRows.join('') : '<li class="muted">No harvest records yet.</li>'}</ul></article>
        </section>`;
    drawDashboardChart('sales-chart', chartDays.map((item) => item.label), salesValues, '#218653');
    drawDashboardChart('harvest-chart', chartDays.map((item) => item.label), harvestValues, '#76a85b');
}

function renderShop(products) {
    state.products = products;
    const cards = products.map((product) => `
        <article class="card product-card">
            ${productPicture(product.name)}
            <div class="product-card-content">
                <div class="product-card-heading"><h3>${escapeHtml(product.name)}</h3><span class="product-variety">${escapeHtml(product.variety || '')}</span></div>
                <p class="product-description">${escapeHtml(product.description || 'Fresh produce from the farm.')}</p>
                <div class="product-card-meta"><div class="product-price"><strong>${money(product.price)}</strong><span>/ ${escapeHtml(product.unit)}</span></div>
                    <p class="product-stock ${product.quantity <= 0 ? 'out-of-stock' : ''}"><span></span>${product.quantity > 0 ? `${Number(product.quantity).toFixed(2)} ${escapeHtml(product.unit)} available` : 'Out of stock'}</p>
                </div>
                <a class="product-details-link" href="?page=product&id=${product.id}" data-page="product" data-product="${product.id}">View details</a>
            </div>
            <form class="product-card-form" data-form="add-cart" data-product="${product.id}">
                <label for="product-quantity-${product.id}">Quantity (${escapeHtml(product.unit)})</label>
                <div class="app-product-actions"><input id="product-quantity-${product.id}" type="number" name="quantity" min="0.01" max="${Math.max(0, product.quantity)}" step="0.01" value="${product.quantity > 0 ? '1' : '0'}" ${product.quantity <= 0 ? 'disabled' : ''}><button type="submit" ${product.quantity <= 0 ? 'disabled' : ''}>Add to cart</button></div>
            </form>
        </article>`).join('');
    app.innerHTML = `
        <section class="shop-hero"><div class="shop-hero-copy"><p class="shop-eyebrow">Fresh from the farm</p><h1>Good food, grown well.</h1><p>Pick up fresh vegetables, carefully grown and harvested for your table.</p></div><a class="button shop-hero-button" href="?page=cart" data-page="cart">View cart <span class="cart-count">${cartCount()}</span></a></section>
        <section class="shop-highlights"><div><span>🌱</span><strong>Farm fresh</strong><small>Harvested with care</small></div><div><span>🥬</span><strong>Seasonal produce</strong><small>See what's available today</small></div><div><span>🚚</span><strong>Local delivery</strong><small>Flat ₱20 delivery fee</small></div></section>
        <section class="shop-section-heading"><div><h2>Today's harvest</h2><p class="muted">Choose from the current catalog.</p></div></section>
        <section class="card-grid shop-product-grid">${cards || '<div class="empty">There are no active products in the catalog.</div>'}</section>`;
}

async function renderProduct(product) {
    state.products = [product];
    const plantings = await queryOrThrow(state.supabase.from('plantings')
        .select('id').eq('vegetable_id', product.id));
    const plantingIds = plantings.map((row) => row.id);
    const lastHarvest = plantingIds.length
        ? (await queryOrThrow(state.supabase.from('harvests')
            .select('harvested_on').in('planting_id', plantingIds)
            .is('archived_at', null).order('harvested_on', { ascending: false }).limit(1)))[0]
        : null;
    app.innerHTML = `
        <nav class="product-breadcrumb"><a href="?page=shop" data-page="shop">Shop</a><span>›</span><span>${escapeHtml(product.name)}</span></nav>
        <section class="product-detail">
            <div class="product-detail-image">${productPicture(product.name)}<span class="product-detail-image-tag">Farm fresh</span></div>
            <div class="product-detail-content"><p class="product-detail-eyebrow">Fresh from the farm</p><h1>${escapeHtml(product.name)}</h1>
                <p class="product-detail-variety">${escapeHtml(product.variety || '')}</p>
                <p class="product-detail-description">${escapeHtml(product.description || 'Fresh produce grown and harvested with care.')}</p>
                <p class="product-detail-price"><strong>${money(product.price)}</strong> / ${escapeHtml(product.unit)}</p>
                <p class="product-stock ${product.quantity <= 0 ? 'out-of-stock' : ''}">${product.quantity > 0 ? `${Number(product.quantity).toFixed(2)} ${escapeHtml(product.unit)} available` : 'Out of stock'}</p>
                <form class="product-detail-form" data-form="add-cart" data-product="${product.id}">
                    <label for="detail-quantity">Quantity (${escapeHtml(product.unit)})</label>
                    <div class="app-product-actions"><input id="detail-quantity" type="number" name="quantity" min="0.01" max="${Math.max(0, product.quantity)}" step="0.01" value="1" ${product.quantity <= 0 ? 'disabled' : ''}><button type="submit" ${product.quantity <= 0 ? 'disabled' : ''}>Add to cart</button></div>
                </form>
            </div>
        </section>
        <section class="product-information"><div class="product-information-heading"><h2>Product information</h2></div><dl class="product-information-list">
            <div><dt>Variety</dt><dd>${escapeHtml(product.variety || '—')}</dd></div><div><dt>Unit</dt><dd>${escapeHtml(product.unit)}</dd></div><div><dt>Stock</dt><dd>${Number(product.quantity).toFixed(2)} ${escapeHtml(product.unit)}</dd></div><div><dt>Most recent harvest</dt><dd>${dateLabel(lastHarvest?.harvested_on)}</dd></div><div class="product-information-description"><dt>Description</dt><dd>${escapeHtml(product.description || 'No description available.')}</dd></div>
        </dl></section>`;
}

async function renderCart() {
    const products = await getProducts(true);
    const byId = new Map(products.map((product) => [String(product.id), product]));
    const rows = [];
    const validCart = {};
    for (const [id, quantityValue] of Object.entries(state.cart)) {
        const product = byId.get(id);
        const quantity = Number(quantityValue);
        if (!product || !Number.isFinite(quantity) || quantity <= 0) continue;
        const safeQuantity = Math.min(quantity, product.quantity);
        if (safeQuantity <= 0) continue;
        validCart[id] = safeQuantity;
        rows.push(`<tr><td><strong>${escapeHtml(product.name)}</strong><span class="table-secondary">${money(product.price)} / ${escapeHtml(product.unit)}</span></td>
            <td><form class="app-inline-form" data-form="update-cart" data-product="${product.id}"><input type="number" name="quantity" min="0" max="${product.quantity}" step="0.01" value="${safeQuantity}"><button type="submit" class="button secondary">Update</button></form></td>
            <td>${money(product.price * safeQuantity)}</td><td><button class="button danger" type="button" data-action="remove-cart" data-product="${product.id}">Remove</button></td></tr>`);
    }
    state.cart = validCart;
    saveCart();
    const items = Object.entries(validCart).map(([id, quantity]) => ({
        quantity,
        price: byId.get(id).price,
    }));
    const totals = calculateOrderTotals(items);
    app.innerHTML = `
        <div class="page-heading"><h1>Your cart</h1><a class="button secondary" href="?page=shop" data-page="shop">Continue shopping</a></div>
        ${table(['Produce', 'Quantity', 'Line total', ''], rows, 'Your cart is empty. Browse the shop to add produce.')}
        ${rows.length ? `<section class="panel app-summary section"><h2>Order estimate</h2>${summaryRows(totals)}<p class="app-muted">Final stock and totals are checked securely at checkout.</p><a class="button" href="?page=checkout" data-page="checkout">Continue to checkout</a></section>` : ''}`;
}

function summaryRows(totals) {
    return `<div class="app-summary-row"><span>Subtotal</span><strong>${money(totals.subtotal)}</strong></div>
        <div class="app-summary-row"><span>Bulk discount (${totals.discountPercentage}%)</span><strong>−${money(totals.discountAmount)}</strong></div>
        <div class="app-summary-row"><span>Delivery</span><strong>${money(totals.deliveryFee)}</strong></div>
        <div class="app-summary-row total"><span>Estimated total</span><strong>${money(totals.finalTotal)}</strong></div>
        <p class="app-muted">Orders of 20 or more combined units receive a 10% produce discount.</p>`;
}

async function renderCheckout() {
    const products = await getProducts(true);
    const productMap = new Map(products.map((product) => [String(product.id), product]));
    const items = Object.entries(state.cart).flatMap(([id, quantity]) => {
        const product = productMap.get(id);
        return product && Number(quantity) > 0 ? [{ id, quantity: Number(quantity), product }] : [];
    });
    if (!items.length) {
        app.innerHTML = '<section class="panel"><h1>Checkout</h1><p>Your cart is empty.</p><a href="?page=shop" class="button" data-page="shop">Browse produce</a></section>';
        return;
    }
    let onlineAvailable = false;
    let availabilityMessage = '';
    try {
        const response = await fetch('/api/payment-availability');
        if (!response.ok) throw new Error('Online payment availability could not be checked.');
        onlineAvailable = Boolean((await response.json()).onlinePaymentEnabled);
    } catch (error) {
        console.error('Could not check online payment configuration:', error);
        availabilityMessage = 'Online payment could not be verified and is hidden for now. Cash on Delivery is still available.';
    }
    const totals = calculateOrderTotals(items.map(({ quantity, product }) => ({ quantity, price: product.price })));
    const lines = items.map(({ quantity, product }) => `<div class="app-summary-row"><span>${escapeHtml(product.name)} × ${quantity} ${escapeHtml(product.unit)}</span><strong>${money(product.price * quantity)}</strong></div>`).join('');
    app.innerHTML = `
        <div class="page-heading"><h1>Checkout</h1><a class="button secondary" href="?page=cart" data-page="cart">Back to cart</a></div>
        <div class="card-grid">
            <section class="panel"><h2>Delivery details</h2>
                <form class="app-form" data-form="checkout">
                    <div class="field field-wide"><label for="delivery-address">Delivery address</label><textarea id="delivery-address" name="deliveryAddress" required minlength="10" maxlength="1000"></textarea></div>
                    <div class="field field-wide"><label>Payment method</label><div class="app-checkout-methods">
                        <label><input type="radio" name="paymentMethod" value="cod" checked> Cash on Delivery</label>
                        ${onlineAvailable ? '<label><input type="radio" name="paymentMethod" value="online"> Online payment (GCash/card)</label>' : ''}
                    </div></div>
                    ${availabilityMessage ? `<p class="app-alert field-wide">${escapeHtml(availabilityMessage)}</p>` : ''}
                    ${!onlineAvailable && !availabilityMessage ? '<p class="app-muted field-wide">Online payment is unavailable until PayMongo and the public HTTPS address are configured.</p>' : ''}
                    <button type="submit">Place order</button>
                </form>
            </section>
            <section class="panel"><h2>Order summary</h2>${lines}${summaryRows(totals)}</section>
        </div>`;
}

async function renderBuyerOrders() {
    const orderCode = new URLSearchParams(location.search).get('order');
    if (state.page === 'order' && orderCode) {
        await renderOrderDetails(orderCode);
        return;
    }
    const payment = new URLSearchParams(location.search).get('payment');
    if (payment === 'success') {
        app.innerHTML = `<section class="panel app-alert"><h1>Payment return received</h1><p>This page does not mark your order as paid. Payment status changes only after the verified PayMongo notification arrives.</p></section>`;
    } else if (payment === 'cancel') {
        app.innerHTML = `<section class="panel app-alert"><h1>Checkout cancelled</h1><p>Your payment is not marked as paid. Reserved stock remains until farm staff confirms the order can be cancelled.</p></section>`;
    }
    const orders = await queryOrThrow(state.supabase.from('orders')
        .select('order_code, final_total, order_status, payment_status, payment_method, created_at')
        .eq('buyer_id', state.profile.id)
        .order('created_at', { ascending: false }));
    const rows = orders.map((order) => `<tr><td><a href="?page=order&order=${encodeURIComponent(order.order_code)}" data-page="order" data-order="${escapeHtml(order.order_code)}">${escapeHtml(order.order_code)}</a><span class="table-secondary">${dateLabel(order.created_at)}</span></td>
        <td>${money(order.final_total)}</td><td>${escapeHtml(order.payment_method.toUpperCase())}</td><td>${statusBadge(order.order_status)}</td><td>${statusBadge(order.payment_status)}</td></tr>`);
    app.insertAdjacentHTML('beforeend', `<section class="panel section"><div class="page-heading"><h1>My orders</h1></div>${table(['Order', 'Total', 'Payment method', 'Delivery status', 'Payment status'], rows, 'You have not placed an order yet.')}</section>`);
}

async function renderOrderDetails(orderCode) {
    const { data: order, error } = await state.supabase.from('orders')
        .select('*').eq('order_code', orderCode).maybeSingle();
    if (error) throw error;
    if (!order) {
        app.innerHTML = '<section class="panel"><h1>Order not found</h1><a href="?page=orders" data-page="orders">Back to orders</a></section>';
        return;
    }
    const [sales, events] = await Promise.all([
        queryOrThrow(state.supabase.from('sales').select('*').eq('order_code', orderCode)),
        queryOrThrow(state.supabase.from('payment_history').select('*').eq('order_code', orderCode).order('created_at')),
    ]);
    const productIds = [...new Set(sales.map((line) => line.vegetable_id))];
    const vegetables = productIds.length
        ? await queryOrThrow(state.supabase.from('vegetables').select('id, name, unit').in('id', productIds))
        : [];
    const nameById = new Map(vegetables.map((vegetable) => [vegetable.id, vegetable]));
    const lineRows = sales.map((line) => {
        const product = nameById.get(line.vegetable_id);
        return `<tr><td>${escapeHtml(product?.name || 'Produce')}</td><td>${Number(line.quantity).toFixed(2)} ${escapeHtml(product?.unit || '')}</td><td>${money(line.unit_price)}</td><td>${money(line.total)}</td></tr>`;
    });
    const eventRows = events.map((event) => `<tr><td>${dateLabel(event.created_at)}</td><td>${escapeHtml(event.event_type.replaceAll('_', ' '))}</td><td>${statusBadge(event.payment_status)}</td><td>${money(event.amount)}</td><td>${escapeHtml(event.payment_reference || '—')}</td><td>${escapeHtml(event.details || '—')}</td></tr>`);
    app.innerHTML = `
        <div class="page-heading"><h1>Order ${escapeHtml(order.order_code)}</h1><a class="button secondary" href="?page=orders" data-page="orders">Back to orders</a></div>
        <section class="stats"><article class="card"><span class="muted">Delivery status</span><strong class="stat-value">${statusBadge(order.order_status)}</strong></article><article class="card"><span class="muted">Payment status</span><strong class="stat-value">${statusBadge(order.payment_status)}</strong></article><article class="card"><span class="muted">Total</span><strong class="stat-value">${money(order.final_total)}</strong></article></section>
        <section class="panel section"><h2>Delivery details</h2><p><strong>Address:</strong> ${escapeHtml(order.delivery_address)}</p><p><strong>Payment:</strong> ${escapeHtml(order.payment_method.toUpperCase())}</p><p><strong>Placed:</strong> ${dateLabel(order.created_at)}</p></section>
        <section class="panel section"><h2>Items</h2>${table(['Produce', 'Quantity', 'Unit price', 'Line total'], lineRows)}</section>
        <section class="panel section app-summary"><h2>Order total</h2>${summaryRows({ subtotal: order.subtotal, discountPercentage: order.discount_percentage, discountAmount: order.discount_amount, deliveryFee: order.delivery_fee, finalTotal: order.final_total })}</section>
        <section class="panel section"><h2>Payment history</h2>${table(['Date', 'Event', 'Status', 'Amount', 'Reference', 'Details'], eventRows, 'No payment events recorded.')}</section>`;
}

function renderVegetables(products) {
    state.products = products;
    state.records = new Map(products.map((row) => [String(row.id), row]));
    const rows = products.map((item) => `<tr><td><strong>${escapeHtml(item.name)}</strong></td><td>${escapeHtml(item.variety || '—')}</td><td>${money(item.price)} / ${escapeHtml(item.unit)}</td><td>${Number(item.quantity).toFixed(2)}</td><td>${statusBadge(item.active ? 'active' : 'inactive')}</td>
        <td><div class="app-table-actions"><button type="button" class="button secondary" data-action="edit-vegetable" data-id="${item.id}">Edit</button><button type="button" class="button ${item.active ? 'danger' : 'secondary'}" data-action="toggle-vegetable" data-id="${item.id}">${item.active ? 'Deactivate' : 'Activate'}</button></div></td></tr>`);
    app.innerHTML = `
        <section class="module-heading"><div><p class="module-eyebrow">Catalog management</p><h1>Vegetables</h1><p>Create produce listings and control what buyers can see.</p></div></section>
        <section class="panel section"><h2 id="vegetable-form-title">Add a vegetable</h2>
            <form class="app-form" data-form="vegetable">
                <input type="hidden" name="id">
                <div class="field"><label>Name</label><input name="name" maxlength="120" required></div>
                <div class="field"><label>Variety</label><input name="variety" maxlength="120"></div>
                <div class="field"><label>Unit</label><input name="unit" maxlength="30" value="kg" required></div>
                <div class="field"><label>Price (₱)</label><input name="price" type="number" min="0" step="0.01" required></div>
                <div class="field field-wide"><label>Description</label><textarea name="description"></textarea></div>
                <div class="app-actions"><button type="submit">Save vegetable</button><button type="button" class="button secondary" data-action="clear-vegetable">Clear</button></div>
            </form>
        </section>
        <section class="panel section"><h2>Catalog</h2>${table(['Name', 'Variety', 'Price', 'Stock', 'Status', 'Actions'], rows, 'No vegetables in the catalog.')}</section>`;
}

async function renderInventory(products) {
    state.products = products;
    const rows = products.map((item) => `<tr><td>${escapeHtml(item.name)}</td><td>${Number(item.quantity).toFixed(2)} ${escapeHtml(item.unit)}</td><td>${item.quantity <= 5 ? '<span class="app-status pending_payment">Low stock</span>' : '<span class="app-status paid">In stock</span>'}</td></tr>`);
    app.innerHTML = `
        <section class="module-heading"><div><p class="module-eyebrow">Farm operations</p><h1>Inventory</h1><p>Adjust available stock. Harvests and orders update quantities automatically.</p></div></section>
        <section class="panel section"><h2>Set stock quantity</h2><form class="app-inline-form" data-form="inventory">
            <div class="field"><label for="inventory-vegetable">Vegetable</label><select id="inventory-vegetable" name="vegetableId" required>${products.map((item) => `<option value="${item.id}">${escapeHtml(item.name)}</option>`).join('')}</select></div>
            <div class="field"><label for="inventory-quantity">Quantity</label><input id="inventory-quantity" name="quantity" type="number" min="0" step="0.01" required></div>
            <button type="submit">Save inventory</button>
        </form></section>
        <section class="panel section"><h2>Current stock</h2>${table(['Vegetable', 'Available quantity', 'Status'], rows, 'No vegetables are available.')}</section>`;
}

async function renderPlantings() {
    const rows = await queryOrThrow(state.supabase.from('plantings').select('*').order('planted_on', { ascending: false }));
    const vegIds = [...new Set(rows.map((row) => row.vegetable_id))];
    const products = vegIds.length
        ? await queryOrThrow(state.supabase.from('vegetables').select('id, name').in('id', vegIds))
        : [];
    const names = new Map(products.map((row) => [row.id, row.name]));
    state.records = new Map(rows.map((row) => [String(row.id), row]));
    app.innerHTML = `
        <section class="module-heading"><div><p class="module-eyebrow">Growing cycle</p><h1>Plantings</h1><p>Track what is planted and when it is expected to be ready.</p></div></section>
        <section class="panel section"><h2 id="planting-form-title">Add a planting</h2><form class="app-form" data-form="planting"><input type="hidden" name="id">
            <div class="field"><label>Vegetable</label><select name="vegetableId" required>${state.products.filter((item) => item.active).map((item) => `<option value="${item.id}">${escapeHtml(item.name)}</option>`).join('')}</select></div>
            <div class="field"><label>Quantity planted</label><input name="quantity" type="number" min="0.01" step="0.01" required></div>
            <div class="field"><label>Planted on</label><input name="plantedOn" type="date" value="${dateInputValue()}" required></div>
            <div class="field"><label>Expected harvest date</label><input name="expectedHarvestOn" type="date"></div>
            <div class="app-actions"><button type="submit">Save planting</button><button type="button" class="button secondary" data-action="clear-planting">Clear</button></div>
        </form></section>
        <section class="panel section"><h2>Planting records</h2>${table(['Vegetable', 'Quantity', 'Planted', 'Expected harvest', 'Status', 'Actions'], rows.map((row) => `<tr><td>${escapeHtml(names.get(row.vegetable_id) || 'Produce')}</td><td>${Number(row.quantity_planted).toFixed(2)}</td><td>${dateLabel(row.planted_on)}</td><td>${dateLabel(row.expected_harvest_on)}</td><td>${statusBadge(row.status)}</td><td>${row.status === 'growing' ? `<div class="app-table-actions"><button type="button" class="button secondary" data-action="edit-planting" data-id="${row.id}">Edit</button><button type="button" class="button danger" data-action="archive-planting" data-id="${row.id}">Archive</button></div>` : '—'}</td></tr>`), 'No planting records.')}</section>`;
}

async function renderHarvests() {
    const rows = await queryOrThrow(state.supabase.from('harvests').select('*').order('harvested_on', { ascending: false }));
    const plantingIds = [...new Set(rows.map((row) => row.planting_id))];
    const plantings = plantingIds.length
        ? await queryOrThrow(state.supabase.from('plantings').select('id, vegetable_id').in('id', plantingIds))
        : [];
    const vegIds = [...new Set(plantings.map((row) => row.vegetable_id))];
    const products = vegIds.length
        ? await queryOrThrow(state.supabase.from('vegetables').select('id, name, unit').in('id', vegIds))
        : [];
    const vegetableIdByPlanting = new Map(plantings.map((row) => [row.id, row.vegetable_id]));
    const productById = new Map(products.map((row) => [row.id, row]));
    const growing = await queryOrThrow(state.supabase.from('plantings').select('id, vegetable_id').eq('status', 'growing'));
    const growingIds = [...new Set(growing.map((row) => row.vegetable_id))];
    const growingProducts = growingIds.length
        ? await queryOrThrow(state.supabase.from('vegetables').select('id, name').in('id', growingIds))
        : [];
    const growingNameById = new Map(growingProducts.map((row) => [row.id, row.name]));
    state.records = new Map(rows.map((row) => [String(row.id), row]));
    app.innerHTML = `
        <section class="module-heading"><div><p class="module-eyebrow">Farm operations</p><h1>Harvests</h1><p>Record harvest quantities; stock is updated automatically.</p></div></section>
        <section class="panel section"><h2 id="harvest-form-title">Record a harvest</h2><form class="app-form" data-form="harvest"><input type="hidden" name="id">
            <div class="field"><label>Growing planting</label><select name="plantingId" required>${growing.map((row) => `<option value="${row.id}">${escapeHtml(growingNameById.get(row.vegetable_id) || 'Produce')} · #${row.id}</option>`).join('')}</select></div>
            <div class="field"><label>Harvest quantity</label><input name="quantity" type="number" min="0.01" step="0.01" required></div>
            <div class="field"><label>Harvested on</label><input name="harvestedOn" type="date" value="${dateInputValue()}" required></div>
            <div class="field field-wide"><label>Quality notes</label><textarea name="qualityNotes" maxlength="4000"></textarea></div>
            <div class="app-actions"><button type="submit">Save harvest</button><button type="button" class="button secondary" data-action="clear-harvest">Clear</button></div>
        </form></section>
        <section class="panel section"><h2>Harvest records</h2>${table(['Vegetable', 'Quantity', 'Harvested', 'Notes', 'Status', 'Actions'], rows.map((row) => {
            const product = productById.get(vegetableIdByPlanting.get(row.planting_id));
            const active = !row.archived_at;
            return `<tr><td>${escapeHtml(product?.name || 'Produce')}</td><td>${Number(row.quantity).toFixed(2)} ${escapeHtml(product?.unit || '')}</td><td>${dateLabel(row.harvested_on)}</td><td>${escapeHtml(row.quality_notes || '—')}</td><td>${statusBadge(active ? 'active' : 'archived')}</td><td>${active ? `<div class="app-table-actions"><button type="button" class="button secondary" data-action="edit-harvest" data-id="${row.id}">Edit</button><button type="button" class="button danger" data-action="archive-harvest" data-id="${row.id}">Archive</button></div>` : '—'}</td></tr>`;
        }), 'No harvest records.')}</section>`;
}

async function renderQuality() {
    const params = new URLSearchParams(location.search);
    const from = params.get('from') || '';
    const to = params.get('to') || '';
    const grade = params.get('grade') || '';
    const search = (params.get('search') || '').toLowerCase();
    const [harvests, inspections] = await Promise.all([
        queryOrThrow(state.supabase.from('harvests').select('id, planting_id, harvested_on').is('archived_at', null).order('harvested_on', { ascending: false })),
        queryOrThrow(state.supabase.from('quality_inspections').select('*').order('inspected_on', { ascending: false }).limit(300)),
    ]);
    const plantingIds = [...new Set(harvests.map((row) => row.planting_id))];
    const plantings = plantingIds.length
        ? await queryOrThrow(state.supabase.from('plantings').select('id, vegetable_id').in('id', plantingIds))
        : [];
    const vegetableIds = [...new Set(plantings.map((row) => row.vegetable_id))];
    const vegetables = vegetableIds.length
        ? await queryOrThrow(state.supabase.from('vegetables').select('id, name').in('id', vegetableIds))
        : [];
    let filteredInspections = inspections;
    if (from) filteredInspections = filteredInspections.filter((row) => row.inspected_on >= from);
    if (to) filteredInspections = filteredInspections.filter((row) => row.inspected_on <= to);
    if (grade) filteredInspections = filteredInspections.filter((row) => row.grade === grade);
    const profiles = filteredInspections.length
        ? await queryOrThrow(state.supabase.from('profiles').select('id, name').in('id', [...new Set(filteredInspections.map((row) => row.inspector_id))]))
        : [];
    const plantingVegetable = new Map(plantings.map((row) => [row.id, row.vegetable_id]));
    const vegetableName = new Map(vegetables.map((row) => [row.id, row.name]));
    const harvestName = new Map(harvests.map((row) => [row.id, `${vegetableName.get(plantingVegetable.get(row.planting_id)) || 'Produce'} · ${dateLabel(row.harvested_on)} · #${row.id}`]));
    const profileName = new Map(profiles.map((row) => [row.id, row.name]));
    const matchingInspections = filteredInspections.filter((row) => {
        const harvestLabel = harvestName.get(row.harvest_id) || '';
        const inspector = profileName.get(row.inspector_id) || '';
        return !search || `${harvestLabel} ${inspector} ${row.notes || ''}`.toLowerCase().includes(search);
    });
    app.innerHTML = `
        <section class="module-heading"><div><p class="module-eyebrow">Farm operations</p><h1>Quality inspections</h1><p>Record a grade for an active harvest and review inspection history.</p></div></section>
        <section class="panel section"><h2>Record inspection</h2><form class="app-form" data-form="quality">
            <div class="field"><label>Active harvest</label><select name="harvestId" required>${harvests.map((row) => `<option value="${row.id}">${escapeHtml(harvestName.get(row.id))}</option>`).join('')}</select></div>
            <div class="field"><label>Grade</label><select name="grade" required><option>A</option><option>B</option><option>C</option><option>Rejected</option></select></div>
            <div class="field"><label>Inspection date</label><input name="inspectedOn" type="date" value="${dateInputValue()}" required></div>
            <div class="field field-wide"><label>Notes</label><textarea name="notes" maxlength="4000"></textarea></div>
            <button type="submit">Save inspection</button>
        </form></section>
        <section class="panel section"><h2>Filter inspections</h2><form class="app-inline-form" data-form="quality-filter">
            <div class="field"><label>Search</label><input name="search" value="${escapeHtml(params.get('search') || '')}" placeholder="Produce, inspector, notes"></div>
            <div class="field"><label>Grade</label><select name="grade"><option value="">All grades</option>${['A', 'B', 'C', 'Rejected'].map((value) => `<option value="${value}" ${grade === value ? 'selected' : ''}>${value}</option>`).join('')}</select></div>
            <div class="field"><label>From</label><input name="from" type="date" value="${escapeHtml(from)}"></div><div class="field"><label>To</label><input name="to" type="date" value="${escapeHtml(to)}"></div><button type="submit">Filter</button>
        </form></section>
        <section class="panel section"><h2>Inspection records</h2>${table(['Produce / harvest', 'Grade', 'Inspected', 'Inspector', 'Notes'], matchingInspections.map((row) => `<tr><td>${escapeHtml(harvestName.get(row.harvest_id) || `Harvest #${row.harvest_id}`)}</td><td>${statusBadge(row.grade.toLowerCase())}</td><td>${dateLabel(row.inspected_on)}</td><td>${escapeHtml(profileName.get(row.inspector_id) || 'Staff')}</td><td>${escapeHtml(row.notes || '—')}</td></tr>`), 'No quality inspections match these filters.')}</section>`;
}

async function renderSales() {
    const params = new URLSearchParams(location.search);
    const from = params.get('from') || '';
    const to = params.get('to') || '';
    const status = params.get('status') || '';
    const search = (params.get('search') || '').toLowerCase();
    const [orders, sales, profiles, products] = await Promise.all([
        queryOrThrow(state.supabase.from('orders').select('*').order('created_at', { ascending: false }).limit(500)),
        queryOrThrow(state.supabase.from('sales').select('*').order('created_at', { ascending: false })),
        queryOrThrow(state.supabase.from('profiles').select('id, name, email')),
        getProducts(false),
    ]);
    const profileMap = new Map(profiles.map((row) => [row.id, row]));
    const productMap = new Map(products.map((row) => [row.id, row]));
    const linesByOrder = new Map();
    sales.forEach((line) => {
        if (!linesByOrder.has(line.order_code)) linesByOrder.set(line.order_code, []);
        linesByOrder.get(line.order_code).push(line);
    });
    const filteredOrders = orders.filter((order) => {
        if (from && order.created_at.slice(0, 10) < from) return false;
        if (to && order.created_at.slice(0, 10) > to) return false;
        if (status && order.order_status !== status && order.payment_status !== status) return false;
        if (!search) return true;
        const buyer = profileMap.get(order.buyer_id);
        const lines = linesByOrder.get(order.order_code) || [];
        const productNames = lines.map((line) => productMap.get(line.vegetable_id)?.name || '').join(' ');
        return `${order.order_code} ${buyer?.name || ''} ${buyer?.email || ''} ${productNames}`.toLowerCase().includes(search);
    });
    state.records = new Map(filteredOrders.map((order) => [String(order.order_code), order]));
    const rows = filteredOrders.map((order) => {
        const buyer = profileMap.get(order.buyer_id);
        const lines = linesByOrder.get(order.order_code) || [];
        const summary = lines.map((line) => {
            const product = productMap.get(line.vegetable_id);
            return `${product?.name || 'Produce'} × ${Number(line.quantity).toFixed(2)}`;
        }).join(', ');
        const canCollect = order.payment_method === 'cod'
            && order.payment_status === 'pending_payment'
            && ['out_for_delivery', 'delivered'].includes(order.order_status);
        const canCancel = order.payment_status !== 'paid' && order.payment_status !== 'refunded'
            && order.order_status !== 'delivered' && order.order_status !== 'cancelled';
        const allowed = {
            pending: ['confirmed'],
            confirmed: ['preparing'],
            preparing: ['ready_for_delivery'],
            ready_for_delivery: ['out_for_delivery'],
            out_for_delivery: ['delivered'],
        }[order.order_status] || [];
        return `<tr><td><strong>${escapeHtml(order.order_code)}</strong><span class="table-secondary">${dateLabel(order.created_at)}</span></td>
            <td>${escapeHtml(buyer?.name || 'Buyer')}<span class="table-secondary">${escapeHtml(buyer?.email || '')}</span></td>
            <td>${escapeHtml(summary || '—')}</td><td>${money(order.final_total)}</td>
            <td>${statusBadge(order.order_status)}<span class="table-secondary">${statusBadge(order.payment_status)}</span></td>
            <td><div class="app-table-actions">${allowed.length ? `<select aria-label="New delivery status" data-status-for="${escapeHtml(order.order_code)}"><option value="">Update status</option>${allowed.map((status) => `<option value="${status}">${status.replaceAll('_', ' ')}</option>`).join('')}</select>` : ''}
                ${canCollect ? `<button type="button" data-action="collect-cod" data-id="${escapeHtml(order.order_code)}">Confirm cash</button>` : ''}
                ${canCancel ? `<button type="button" class="button danger" data-action="cancel-order" data-id="${escapeHtml(order.order_code)}">Cancel</button>` : ''}
            </div></td></tr>`;
    });
    app.innerHTML = `
        <section class="module-heading"><div><p class="module-eyebrow">Order management</p><h1>Sales</h1><p>Review orders, delivery progress, and payment status.</p></div></section>
        <section class="panel report-filters section"><form class="app-inline-form" data-form="sales-filter">
            <div class="field"><label>Search order / buyer / product</label><input name="search" value="${escapeHtml(params.get('search') || '')}"></div>
            <div class="field"><label>Status</label><select name="status"><option value="">All statuses</option>${['pending', 'confirmed', 'preparing', 'ready_for_delivery', 'out_for_delivery', 'delivered', 'cancelled', 'pending_payment', 'paid', 'failed', 'refunded'].map((value) => `<option value="${value}" ${status === value ? 'selected' : ''}>${value.replaceAll('_', ' ')}</option>`).join('')}</select></div>
            <div class="field"><label>From</label><input name="from" type="date" value="${escapeHtml(from)}"></div><div class="field"><label>To</label><input name="to" type="date" value="${escapeHtml(to)}"></div><button type="submit">Filter</button>
        </form></section>
        <section class="panel section"><h2>Orders</h2>${table(['Order', 'Buyer', 'Items', 'Total', 'Status', 'Actions'], rows, 'No orders match these filters.')}</section>`;
}

async function renderReports() {
    const params = new URLSearchParams(location.search);
    const from = params.get('from') || '';
    const to = params.get('to') || '';
    const search = (params.get('search') || '').toLowerCase();
    let query = state.supabase.from('orders').select('*').order('created_at', { ascending: false });
    if (from) query = query.gte('created_at', `${from}T00:00:00`);
    if (to) query = query.lte('created_at', `${to}T23:59:59.999`);
    let harvestQuery = state.supabase.from('harvests')
        .select('id, planting_id, quantity, harvested_on')
        .is('archived_at', null);
    if (from) harvestQuery = harvestQuery.gte('harvested_on', from);
    if (to) harvestQuery = harvestQuery.lte('harvested_on', to);
    const [orders, harvests, stock] = await Promise.all([
        queryOrThrow(query),
        queryOrThrow(harvestQuery),
        queryOrThrow(state.supabase.from('inventory').select('vegetable_id, quantity')),
    ]);
    const orderCodes = orders.map((row) => row.order_code);
    const saleLines = orderCodes.length
        ? await queryOrThrow(state.supabase.from('sales').select('order_code, vegetable_id').in('order_code', orderCodes))
        : [];
    const profiles = orders.length
        ? await queryOrThrow(state.supabase.from('profiles').select('id, name, email').in('id', [...new Set(orders.map((row) => row.buyer_id))]))
        : [];
    const buyerById = new Map(profiles.map((row) => [row.id, row]));
    const plantings = harvests.length
        ? await queryOrThrow(state.supabase.from('plantings').select('id, vegetable_id').in('id', [...new Set(harvests.map((row) => row.planting_id))]))
        : [];
    const vegetableIds = [...new Set([
        ...plantings.map((row) => row.vegetable_id),
        ...stock.map((row) => row.vegetable_id),
        ...saleLines.map((row) => row.vegetable_id),
    ])];
    const vegetables = vegetableIds.length
        ? await queryOrThrow(state.supabase.from('vegetables').select('id, name, unit').in('id', vegetableIds))
        : [];
    const productById = new Map(vegetables.map((row) => [row.id, row]));
    const linesByOrder = new Map();
    saleLines.forEach((line) => {
        if (!linesByOrder.has(line.order_code)) linesByOrder.set(line.order_code, []);
        linesByOrder.get(line.order_code).push(line);
    });
    const filteredOrders = orders.filter((order) => {
        if (!search) return true;
        const buyer = buyerById.get(order.buyer_id);
        const productNames = (linesByOrder.get(order.order_code) || [])
            .map((line) => productById.get(line.vegetable_id)?.name || '')
            .join(' ');
        return `${order.order_code} ${buyer?.name || ''} ${buyer?.email || ''} ${productNames}`
            .toLowerCase().includes(search);
    });
    const paidOrders = filteredOrders.filter((row) => row.payment_status === 'paid');
    const revenue = paidOrders.reduce((sum, row) => sum + Number(row.final_total), 0);
    const discountTotal = filteredOrders.reduce((sum, row) => sum + Number(row.discount_amount), 0);
    const ordersByPayment = new Map();
    filteredOrders.forEach((row) => {
        const key = `${row.payment_method} · ${row.payment_status}`;
        ordersByPayment.set(key, (ordersByPayment.get(key) || 0) + 1);
    });
    const salesRows = filteredOrders.map((row) => `<tr><td>${escapeHtml(row.order_code)}</td><td>${dateLabel(row.created_at)}</td><td>${escapeHtml(row.payment_method.toUpperCase())}</td><td>${statusBadge(row.payment_status)}</td><td>${money(row.subtotal)}</td><td>${money(row.discount_amount)}</td><td>${money(row.final_total)}</td></tr>`);
    const paymentRows = [...ordersByPayment].map(([key, count]) => `<tr><td>${escapeHtml(key)}</td><td>${count}</td></tr>`);
    const vegetableIdByPlanting = new Map(plantings.map((row) => [row.id, row.vegetable_id]));
    const harvestSums = new Map();
    harvests.forEach((row) => {
        const id = vegetableIdByPlanting.get(row.planting_id);
        harvestSums.set(id, (harvestSums.get(id) || 0) + Number(row.quantity));
    });
    const harvestRows = [...harvestSums].map(([id, quantity]) => `<tr><td>${escapeHtml(productById.get(id)?.name || 'Produce')}</td><td>${quantity.toFixed(2)} ${escapeHtml(productById.get(id)?.unit || '')}</td></tr>`);
    const stockRows = stock.map((row) => `<tr><td>${escapeHtml(productById.get(row.vegetable_id)?.name || 'Produce')}</td><td>${Number(row.quantity).toFixed(2)} ${escapeHtml(productById.get(row.vegetable_id)?.unit || '')}</td></tr>`);
    app.innerHTML = `
        <section class="module-heading"><div><p class="module-eyebrow">Business overview</p><h1>Reports</h1><p>Review paid sales, discounts, payment mix, harvests, and current stock.</p></div><button class="button secondary" type="button" data-action="print">Print report</button></section>
        <section class="panel report-filters section"><form class="app-inline-form" data-form="report-filter"><div class="field"><label>Search order / buyer / product</label><input name="search" value="${escapeHtml(params.get('search') || '')}"></div><div class="field"><label>From</label><input name="from" type="date" value="${escapeHtml(from)}"></div><div class="field"><label>To</label><input name="to" type="date" value="${escapeHtml(to)}"></div><button type="submit">Filter</button><a class="button secondary" href="?page=reports" data-page="reports">Clear</a></form></section>
        <section class="module-stat-grid">
            <article class="module-stat-card"><span>Paid revenue</span><strong>${money(revenue)}</strong><small>From ${paidOrders.length} paid orders</small></article>
            <article class="module-stat-card"><span>Orders</span><strong>${filteredOrders.length}</strong><small>Matching the selected filters</small></article>
            <article class="module-stat-card"><span>Discounts</span><strong>${money(discountTotal)}</strong><small>Recorded discount total</small></article>
            <article class="module-stat-card"><span>Harvest records</span><strong>${harvests.length}</strong><small>Active harvest records</small></article>
        </section>
        <section class="panel section"><h2>Sales details</h2>${table(['Order', 'Date', 'Method', 'Payment status', 'Subtotal', 'Discount', 'Final total'], salesRows, 'No orders in this period.')}</section>
        <section class="card-grid section"><article class="panel"><h2>Payment method and status</h2>${table(['Method / status', 'Orders'], paymentRows, 'No payments in this period.')}</article><article class="panel"><h2>Current inventory</h2>${table(['Vegetable', 'Stock'], stockRows, 'No inventory records.')}</article></section>
        <section class="panel section"><h2>Harvest quantities</h2>${table(['Vegetable', 'Total harvested'], harvestRows, 'No active harvest records.')}</section>`;
}

async function renderActivity() {
    if (state.profile.role !== 'admin') {
        app.innerHTML = '<section class="panel"><h1>Access denied</h1><p>Only administrators can view activity logs.</p></section>';
        return;
    }
    const params = new URLSearchParams(location.search);
    const search = params.get('search') || '';
    const from = params.get('from') || '';
    const to = params.get('to') || '';
    const page = Math.max(1, Number(params.get('p') || 1));
    let query = state.supabase.from('activity_logs').select('*', { count: 'exact' }).order('created_at', { ascending: false });
    if (from) query = query.gte('created_at', `${from}T00:00:00`);
    if (to) query = query.lte('created_at', `${to}T23:59:59.999`);
    if (search) query = query.ilike('details', `%${search.replace(/[(),.]/g, '')}%`);
    const start = (page - 1) * 25;
    const { data: logs, error, count } = await query.range(start, start + 24);
    if (error) throw error;
    const profiles = logs?.length
        ? await queryOrThrow(state.supabase.from('profiles').select('id, name, email').in('id', [...new Set(logs.map((row) => row.user_id).filter(Boolean))]))
        : [];
    const profileMap = new Map(profiles.map((row) => [row.id, row]));
    const rows = (logs || []).map((row) => {
        const actor = profileMap.get(row.user_id);
        return `<tr><td>${dateLabel(row.created_at)}</td><td>${escapeHtml(actor?.name || 'System')}<span class="table-secondary">${escapeHtml(actor?.email || '')}</span></td><td>${escapeHtml(row.action)}</td><td>${escapeHtml(row.entity_type)} ${row.entity_id ? `#${row.entity_id}` : ''}</td><td>${escapeHtml(row.details || '—')}</td></tr>`;
    });
    app.innerHTML = `
        <section class="module-heading"><div><p class="module-eyebrow">Administrator</p><h1>Activity log</h1><p>Review account and farm operations activity.</p></div></section>
        <section class="panel report-filters section"><form class="app-inline-form" data-form="activity-filter">
            <div class="field"><label>Search</label><input name="search" value="${escapeHtml(search)}" placeholder="Action, record, details"></div>
            <div class="field"><label>From</label><input name="from" type="date" value="${escapeHtml(from)}"></div><div class="field"><label>To</label><input name="to" type="date" value="${escapeHtml(to)}"></div><button type="submit">Filter</button>
        </form></section>
        <section class="panel section"><h2>Activity records</h2>${table(['Date', 'User', 'Action', 'Record', 'Details'], rows, 'No matching activity records.')}
            <div class="app-pagination"><span class="muted">Showing ${count ? start + 1 : 0}–${Math.min(start + 25, count || 0)} of ${count || 0}</span><div class="app-actions">
                ${page > 1 ? `<button type="button" class="button secondary" data-action="activity-page" data-page-number="${page - 1}">Previous</button>` : ''}
                ${count > start + 25 ? `<button type="button" class="button secondary" data-action="activity-page" data-page-number="${page + 1}">Next</button>` : ''}
            </div></div>
        </section>`;
}

async function renderPage() {
    if (!state.supabase) {
        showSupabaseConfigurationError();
        return;
    }
    if (!state.session || !state.profile) {
        const page = guestPage(state.page);
        state.page = page;
        renderAuth(page);
        return;
    }
    if (!state.profile.email_verified_at) {
        await state.supabase.auth.signOut();
        state.session = null;
        state.profile = null;
        renderAuth('login');
        notify('Verify your email address before signing in.', 'error');
        updateNavigation();
        return;
    }

    if (state.page === 'reset-password') {
        updateNavigation();
        renderAuth('reset-password');
        return;
    }

    let page = state.page || 'dashboard';
    const buyerPages = ['shop', 'product', 'cart', 'checkout', 'orders', 'order'];
    const staffPages = ['vegetables', 'plantings', 'harvests', 'inventory', 'sales', 'quality', 'reports', 'activity'];
    if (state.profile.role === 'buyer' && staffPages.includes(page)) page = 'dashboard';
    if (isStaff() && buyerPages.includes(page)) page = 'dashboard';
    if (page === 'activity' && state.profile.role !== 'admin') page = 'dashboard';
    state.page = page;
    document.body.classList.remove('auth-page');
    updateNavigation();

    try {
        if (page === 'dashboard') return await renderDashboard();
        if (page === 'shop') return renderShop(await getProducts(true));
        if (page === 'product') {
            const id = new URLSearchParams(location.search).get('id');
            const product = (await getProducts(true)).find((item) => String(item.id) === id);
            return product ? await renderProduct(product) : (app.innerHTML = '<section class="panel"><h1>Product not found</h1><a href="?page=shop" data-page="shop">Back to shop</a></section>');
        }
        if (page === 'cart') return await renderCart();
        if (page === 'checkout') return await renderCheckout();
        if (page === 'orders' || page === 'order') return await renderBuyerOrders();
        if (page === 'vegetables') return renderVegetables(await getProducts(false));
        if (page === 'inventory') return await renderInventory(await getProducts(false));
        if (page === 'plantings') {
            state.products = await getProducts(false);
            return await renderPlantings();
        }
        if (page === 'harvests') return await renderHarvests();
        if (page === 'quality') return await renderQuality();
        if (page === 'sales') return await renderSales();
        if (page === 'reports') return await renderReports();
        if (page === 'activity') return await renderActivity();
        return await renderDashboard();
    } catch (error) {
        showError(error);
        app.innerHTML = `<section class="panel"><h1>Could not load this page</h1><p>${escapeHtml(error.message || 'Check your connection and try again.')}</p><button type="button" data-action="retry">Try again</button></section>`;
    }
}

async function apiRequest(path, payload) {
    const auth = state.supabase.auth;
    let accessToken;
    try {
        accessToken = await getAccessToken(auth);
    } catch (error) {
        if (error?.status === 400 || error?.status === 401) {
            console.error('Supabase rejected the stored refresh token.', error);
            await expireClientSession();
            throw new Error('Your session expired. Sign in again.');
        }
        throw error;
    }
    if (!accessToken) {
        await expireClientSession();
        throw new Error('Your session expired. Sign in again.');
    }

    const sendRequest = (token) => fetch(path, {
        method: 'POST',
        headers: {
            Authorization: `Bearer ${token}`,
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(payload),
    });

    let response = await sendRequest(accessToken);
    if (response.status === 401) {
        try {
            const refreshedToken = await refreshAccessToken(auth);
            if (refreshedToken && refreshedToken !== accessToken) {
                accessToken = refreshedToken;
                response = await sendRequest(accessToken);
            }
        } catch (error) {
            console.error('Could not refresh the Supabase session after an API authorization failure.', error);
        }
    }

    const result = await response.json().catch(() => ({}));
    if (response.status === 401) {
        await expireClientSession();
        throw new Error(result.error || 'Your session expired. Sign in again.');
    }
    if (!response.ok) throw new Error(result.error || 'The server could not complete this request.');
    return result;
}

async function expireClientSession() {
    const { error } = await state.supabase.auth.signOut({ scope: 'local' });
    if (error) {
        console.error('Could not clear the expired local session.', error);
    }
    state.session = null;
    state.profile = null;
    go('login');
    notify('Your session expired. Sign in again.', 'error');
}

async function logAccountAction(action) {
    const { error } = await state.supabase.rpc('log_auth_activity', { p_action: action });
    if (error) {
        console.error(`Could not record ${action} activity:`, error.message);
        return false;
    }
    return true;
}

function readForm(form) {
    return Object.fromEntries(new FormData(form).entries());
}

async function handleSubmit(form) {
    if (!state.supabase) {
        showSupabaseConfigurationError();
        return;
    }
    const values = readForm(form);
    const formName = form.dataset.form;
    if (formName === 'login') {
        const { error } = await state.supabase.auth.signInWithPassword({
            email: values.email.trim(),
            password: values.password,
        });
        if (error) throw error;
        const { data } = await state.supabase.auth.getSession();
        state.session = data.session;
        await loadProfile(data.session.user);
        const logged = await logAccountAction('login');
        go('dashboard');
        if (!logged) notify('Signed in, but the activity log could not be updated.', 'error');
        return;
    }
    if (formName === 'register') {
        if (values.password !== values.confirmPassword) throw new Error('The passwords do not match.');
        const { error } = await state.supabase.auth.signUp({
            email: values.email.trim(),
            password: values.password,
            options: {
                data: { name: values.name.trim() },
                emailRedirectTo: location.origin,
            },
        });
        if (error) throw error;
        go('login');
        notify('Account created. Check your email to verify the address before signing in.');
        return;
    }
    if (formName === 'forgot') {
        const { error } = await state.supabase.auth.resetPasswordForEmail(values.email.trim(), {
            redirectTo: location.origin,
        });
        if (error) throw error;
        notify('If the address belongs to an account, a password reset link will be sent.');
        return;
    }
    if (formName === 'reset-password') {
        if (values.password !== values.confirmPassword) throw new Error('The passwords do not match.');
        const { error } = await state.supabase.auth.updateUser({ password: values.password });
        if (error) throw error;
        const logged = await logAccountAction('password_reset');
        await state.supabase.auth.signOut();
        state.session = null;
        state.profile = null;
        const message = logged
            ? 'Password updated. Sign in using your new password.'
            : 'Password updated, but the activity log could not be updated.';
        go('login');
        notify(message, logged ? 'success' : 'error');
        return;
    }
    if (formName === 'add-cart') {
        const product = state.products.find((item) => String(item.id) === form.dataset.product);
        const quantity = Number(values.quantity);
        if (!product || !Number.isFinite(quantity) || quantity <= 0 || quantity > product.quantity) {
            throw new Error('Choose a quantity that is currently in stock.');
        }
        state.cart[product.id] = Math.min(Number(state.cart[product.id] || 0) + quantity, product.quantity);
        saveCart();
        notify(`${product.name} added to your cart.`);
        return;
    }
    if (formName === 'update-cart') {
        const product = state.products.find((item) => String(item.id) === form.dataset.product);
        const quantity = Number(values.quantity);
        if (!product || !Number.isFinite(quantity) || quantity < 0 || quantity > product.quantity) {
            throw new Error('Choose a quantity between zero and available stock.');
        }
        if (!quantity) delete state.cart[product.id];
        else state.cart[product.id] = quantity;
        saveCart();
        await renderCart();
        return;
    }
    if (formName === 'checkout') {
        const checkoutToken = sessionStorage.getItem('harvest-checkout-token') || cartToken();
        sessionStorage.setItem('harvest-checkout-token', checkoutToken);
        const items = Object.entries(state.cart).map(([id, quantity]) => ({ id: Number(id), quantity: Number(quantity) }));
        let result;
        try {
            result = await apiRequest('/api/orders', {
                action: 'checkout',
                items,
                deliveryAddress: values.deliveryAddress.trim(),
                paymentMethod: values.paymentMethod,
                checkoutToken,
            });
        } catch (error) {
            if (error.message.includes('already used for a different order')) {
                sessionStorage.removeItem('harvest-checkout-token');
            }
            throw error;
        }
        sessionStorage.removeItem('harvest-checkout-token');
        localStorage.removeItem(cartStorageKey);
        state.cart = {};
        saveCart();
        if (result.checkoutUrl) {
            location.assign(result.checkoutUrl);
            return;
        }
        notify(`Order ${result.order.order_code} was placed.`);
        const orderUrl = new URL(location.href);
        orderUrl.searchParams.set('page', 'order');
        orderUrl.searchParams.set('order', result.order.order_code);
        history.pushState({}, '', orderUrl);
        state.page = 'order';
        await renderPage();
        return;
    }
    if (formName === 'vegetable') {
        const id = values.id;
        const record = {
            name: values.name.trim(),
            variety: values.variety.trim() || null,
            unit: values.unit.trim(),
            price: Number(values.price),
            description: values.description.trim() || null,
        };
        if (!record.name || !record.unit || !Number.isFinite(record.price) || record.price < 0) {
            throw new Error('Enter a name, unit, and valid non-negative price.');
        }
        const result = id
            ? await state.supabase.from('vegetables').update(record).eq('id', id)
            : await state.supabase.from('vegetables').insert(record);
        if (result.error) throw result.error;
        notify(id ? 'Vegetable updated.' : 'Vegetable added.');
        return await renderPage();
    }
    if (formName === 'inventory') {
        const { error } = await state.supabase.rpc('set_inventory', {
            p_vegetable_id: Number(values.vegetableId),
            p_quantity: Number(values.quantity),
        });
        if (error) throw error;
        notify('Inventory updated.');
        return await renderPage();
    }
    if (formName === 'planting') {
        const createArgs = {
            p_vegetable_id: Number(values.vegetableId),
            p_quantity: Number(values.quantity),
            p_planted_on: values.plantedOn,
            p_expected_harvest_on: values.expectedHarvestOn || null,
        };
        const result = values.id
            ? await state.supabase.rpc('update_planting', { p_id: Number(values.id), ...createArgs })
            : await state.supabase.rpc('create_planting', createArgs);
        if (result.error) throw result.error;
        notify(values.id ? 'Planting updated.' : 'Planting recorded.');
        return await renderPage();
    }
    if (formName === 'harvest') {
        const args = {
            p_planting_id: Number(values.plantingId),
            p_quantity: Number(values.quantity),
            p_harvested_on: values.harvestedOn,
            p_quality_notes: values.qualityNotes.trim(),
        };
        let result;
        if (values.id) {
            const existing = state.records.get(String(values.id));
            if (!existing) throw new Error('The harvest record is no longer available.');
            result = await state.supabase.rpc('update_harvest', {
                p_id: Number(values.id),
                p_quantity: Number(values.quantity),
                p_harvested_on: values.harvestedOn,
                p_quality_notes: values.qualityNotes.trim(),
            });
        } else {
            result = await state.supabase.rpc('create_harvest', args);
        }
        if (result.error) throw result.error;
        notify(values.id ? 'Harvest updated.' : 'Harvest recorded and inventory updated.');
        return await renderPage();
    }
    if (formName === 'quality') {
        const { error } = await state.supabase.rpc('add_quality_inspection', {
            p_harvest_id: Number(values.harvestId),
            p_grade: values.grade,
            p_notes: values.notes.trim(),
            p_inspected_on: values.inspectedOn,
        });
        if (error) throw error;
        notify('Quality inspection recorded.');
        return await renderPage();
    }
    if (formName === 'report-filter' || formName === 'activity-filter'
        || formName === 'quality-filter' || formName === 'sales-filter') {
        const url = new URL(location.href);
        for (const [key, value] of Object.entries(values)) {
            if (value) url.searchParams.set(key, value);
            else url.searchParams.delete(key);
        }
        if (formName === 'activity-filter') url.searchParams.delete('p');
        history.pushState({}, '', url);
        return await renderPage();
    }
}

async function handleClick(target) {
    const pageLink = target.closest('[data-page]');
    if (pageLink) {
        const page = pageLink.dataset.page;
        if (page === 'order') {
            const url = new URL(location.href);
            url.searchParams.set('page', 'order');
            url.searchParams.set('order', pageLink.dataset.order);
            history.pushState({}, '', url);
            state.page = 'order';
            return await renderPage();
        }
        if (page === 'product') {
            const url = new URL(location.href);
            url.searchParams.set('page', 'product');
            url.searchParams.set('id', pageLink.dataset.product);
            history.pushState({}, '', url);
            state.page = 'product';
            return await renderPage();
        }
        go(page);
        return;
    }

    const action = target.closest('[data-action]');
    if (!action) return;
    if (action.dataset.action === 'toggle-password') {
        const input = action.parentElement.querySelector('input');
        if (!(input instanceof HTMLInputElement)) return;
        const visible = input.type === 'password';
        input.type = visible ? 'text' : 'password';
        action.textContent = visible ? 'Hide' : 'Show';
        action.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
        action.setAttribute('aria-pressed', String(visible));
        return;
    }
    if (action.dataset.action === 'remove-cart') {
        delete state.cart[action.dataset.product];
        saveCart();
        return await renderCart();
    }
    if (action.dataset.action === 'toggle-vegetable') {
        const item = state.records.get(action.dataset.id);
        if (!item) return;
        const { error } = await state.supabase.from('vegetables').update({ active: !item.active }).eq('id', item.id);
        if (error) throw error;
        notify(item.active ? 'Vegetable deactivated.' : 'Vegetable activated.');
        return await renderPage();
    }
    if (action.dataset.action === 'edit-vegetable') {
        const row = state.records.get(action.dataset.id);
        const form = app.querySelector('[data-form="vegetable"]');
        if (!row || !form) return;
        form.elements.id.value = row.id;
        form.elements.name.value = row.name;
        form.elements.variety.value = row.variety || '';
        form.elements.unit.value = row.unit;
        form.elements.price.value = row.price;
        form.elements.description.value = row.description || '';
        app.querySelector('#vegetable-form-title').textContent = `Edit ${row.name}`;
        form.scrollIntoView({ behavior: 'smooth' });
        return;
    }
    if (action.dataset.action === 'clear-vegetable') {
        app.querySelector('[data-form="vegetable"]').reset();
        app.querySelector('#vegetable-form-title').textContent = 'Add a vegetable';
        return;
    }
    if (action.dataset.action === 'edit-planting') {
        const row = state.records.get(action.dataset.id);
        const form = app.querySelector('[data-form="planting"]');
        if (!row || !form) return;
        form.elements.id.value = row.id;
        form.elements.vegetableId.value = row.vegetable_id;
        form.elements.quantity.value = row.quantity_planted;
        form.elements.plantedOn.value = row.planted_on;
        form.elements.expectedHarvestOn.value = row.expected_harvest_on || '';
        app.querySelector('#planting-form-title').textContent = `Edit planting #${row.id}`;
        form.scrollIntoView({ behavior: 'smooth' });
        return;
    }
    if (action.dataset.action === 'clear-planting') {
        app.querySelector('[data-form="planting"]').reset();
        app.querySelector('#planting-form-title').textContent = 'Add a planting';
        return;
    }
    if (action.dataset.action === 'archive-planting') {
        if (!window.confirm('Archive this growing planting?')) return;
        const { error } = await state.supabase.rpc('archive_planting', { p_id: Number(action.dataset.id) });
        if (error) throw error;
        notify('Planting archived.');
        return await renderPage();
    }
    if (action.dataset.action === 'edit-harvest') {
        const row = state.records.get(action.dataset.id);
        const form = app.querySelector('[data-form="harvest"]');
        if (!row || !form) return;
        form.elements.id.value = row.id;
        form.elements.plantingId.value = row.planting_id;
        form.elements.quantity.value = row.quantity;
        form.elements.harvestedOn.value = row.harvested_on;
        form.elements.qualityNotes.value = row.quality_notes || '';
        app.querySelector('#harvest-form-title').textContent = `Edit harvest #${row.id}`;
        form.scrollIntoView({ behavior: 'smooth' });
        return;
    }
    if (action.dataset.action === 'clear-harvest') {
        app.querySelector('[data-form="harvest"]').reset();
        app.querySelector('#harvest-form-title').textContent = 'Record a harvest';
        return;
    }
    if (action.dataset.action === 'archive-harvest') {
        if (!window.confirm('Archive this harvest? Inventory will be reduced by its quantity.')) return;
        const { error } = await state.supabase.rpc('archive_harvest', { p_id: Number(action.dataset.id) });
        if (error) throw error;
        notify('Harvest archived and inventory adjusted.');
        return await renderPage();
    }
    if (action.dataset.action === 'collect-cod') {
        if (!window.confirm('Confirm that cash was collected for this order?')) return;
        await apiRequest('/api/orders', { action: 'collect-cod', orderCode: action.dataset.id });
        notify('COD payment confirmed.');
        return await renderPage();
    }
    if (action.dataset.action === 'cancel-order') {
        if (!window.confirm('Cancel this order? Reserved inventory will be returned only when cancellation is safe.')) return;
        await apiRequest('/api/orders', { action: 'cancel', orderCode: action.dataset.id });
        notify('Order cancelled and stock restored.');
        return await renderPage();
    }
    if (action.dataset.action === 'print') return window.print();
    if (action.dataset.action === 'retry') return await renderPage();
    if (action.dataset.action === 'activity-page') {
        const url = new URL(location.href);
        url.searchParams.set('p', action.dataset.pageNumber);
        history.pushState({}, '', url);
        return await renderPage();
    }
    if (action.dataset.action === 'resend-verification') {
        const email = window.prompt('Enter the email address used for your account.');
        if (!email) return;
        const { error } = await state.supabase.auth.resend({
            type: 'signup',
            email: email.trim(),
            options: { emailRedirectTo: location.origin },
        });
        if (error) throw error;
        notify('If the account needs verification, a new link will be sent.');
    }
}

async function handleOrderStatusChange(select) {
    const status = select.value;
    if (!status) return;
    await apiRequest('/api/orders', {
        action: 'status',
        orderCode: select.dataset.statusFor,
        status,
    });
    notify('Order status updated.');
    await renderPage();
}

document.addEventListener('submit', async (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches('[data-form]')) return;
    event.preventDefault();
    clearNotice();
    const button = form.querySelector('button[type="submit"]');
    if (button) button.disabled = true;
    try {
        await handleSubmit(form);
    } catch (error) {
        showError(error);
    } finally {
        if (button?.isConnected) button.disabled = false;
    }
});

document.addEventListener('click', async (event) => {
    const target = event.target;
    if (!(target instanceof Element)) return;
    try {
        await handleClick(target);
    } catch (error) {
        showError(error);
    }
});

document.addEventListener('error', (event) => {
    const image = event.target;
    if (!(image instanceof HTMLImageElement)
        || !image.matches('.product-image img, .product-detail-image img')) {
        return;
    }
    image.hidden = true;
    const fallback = image.parentElement.querySelector('.product-image-fallback');
    if (fallback) fallback.hidden = false;
}, true);

document.addEventListener('change', async (event) => {
    if (!(event.target instanceof HTMLSelectElement) || !event.target.matches('[data-status-for]')) return;
    try {
        await handleOrderStatusChange(event.target);
    } catch (error) {
        showError(error);
        await renderPage();
    }
});

document.querySelector('[data-nav-toggle]').addEventListener('click', (event) => {
    const button = event.currentTarget;
    const nav = document.querySelector('#primary-navigation');
    const open = nav.classList.toggle('is-open');
    button.setAttribute('aria-expanded', String(open));
    button.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
});

document.querySelector('#logout-button').addEventListener('click', async () => {
    try {
        const logged = await logAccountAction('logout');
        const { error } = await state.supabase.auth.signOut();
        if (error) throw error;
        state.session = null;
        state.profile = null;
        go('login');
        if (!logged) notify('Signed out, but the activity log could not be updated.', 'error');
    } catch (error) {
        showError(error);
    }
});

window.addEventListener('popstate', () => {
    state.page = new URLSearchParams(location.search).get('page') || '';
    void renderPage();
});

async function start() {
    document.querySelector('#current-year').textContent = new Date().getFullYear();
    if (!supabaseUrl || !supabaseAnonKey) {
        showSupabaseConfigurationError();
        return;
    }
    state.supabase = createClient(supabaseUrl, supabaseAnonKey, {
        auth: { autoRefreshToken: true, persistSession: true, detectSessionInUrl: true },
    });

    try {
        await loadEmailCallback();
        const { data, error } = await state.supabase.auth.getSession();
        if (error) throw error;
        state.session = data.session;
        if (state.session) await loadProfile(state.session.user);
        updateNavigation();
        await renderPage();
    } catch (error) {
        showError(error);
        renderAuth('login');
    }

    state.supabase.auth.onAuthStateChange((event, session) => {
        state.session = session;
        if (event === 'PASSWORD_RECOVERY') {
            state.page = 'reset-password';
            history.replaceState({}, '', '?page=reset-password');
        }
        if (!session) state.profile = null;
        setTimeout(async () => {
            try {
                if (session) await loadProfile(session.user);
                updateNavigation();
                await renderPage();
            } catch (error) {
                showError(error);
            }
        }, 0);
    });
}

void start();
