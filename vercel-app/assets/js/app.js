import { createClient } from '@supabase/supabase-js';
import { getAccessToken, refreshAccessToken } from '../../src/authSession.js';
import { calculateOrderTotals } from '../../src/orderMath.js';
import { getOrderTrackingState, shouldShowOrderTracking } from '../../src/orderTracking.js';
import { isFarmStaffRole } from '../../src/roles.js';
import { sortFifoLots } from '../../src/fifoInventory.js';
import { isOrderReturnWindowOpen } from '../../src/orderReturns.js';
import { filterAndSortProducts } from '../../src/shopFilters.js';
import { getVegetablePhotoExtension } from '../../src/vegetablePhoto.js';

const supabaseUrl = import.meta.env.VITE_SUPABASE_URL;
const supabaseAnonKey = import.meta.env.VITE_SUPABASE_ANON_KEY;
const configuredContactEmail = import.meta.env.VITE_CONTACT_EMAIL?.trim() || '';
const contactEmail = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(configuredContactEmail)
    ? configuredContactEmail
    : '';
const app = document.querySelector('#app');
const notice = document.querySelector('#app-notice');
const cartStorageKey = 'harvest-cart-v1';
const languageStorageKey = 'harvest-buyer-language-v1';
const LOW_STOCK_THRESHOLD = 5;
const buyerCopy = {
    en: {
        navDashboard: 'Dashboard', navShop: 'Shop', navOrders: 'My orders', navCart: 'Cart',
        switchLanguage: 'Filipino', shopEyebrow: 'Fresh from the farm', shopTitle: 'Good food, grown well.',
        shopIntro: 'Fresh vegetables, carefully grown and harvested for your table.',
        viewCart: 'View cart', farmFresh: 'Farm fresh', harvestedCare: 'Harvested with care',
        seasonalProduce: 'Seasonal produce', availableToday: "See what's available today",
        localDelivery: 'Local delivery', flatDelivery: 'Flat ₱20 delivery fee',
        deliveryNote: 'Flat ₱20 delivery fee per order. The order summary shows the fee and final total.',
        catalogTitle: "Today's harvest", catalogIntro: 'Choose from the current catalog.',
        searchProducts: 'Search produce', searchPlaceholder: 'Name, variety, or description',
        category: 'Category', allCategories: 'All categories', inStockOnly: 'In stock only',
        sortBy: 'Sort by', sortName: 'Name', sortPriceLow: 'Price: low to high',
        sortPriceHigh: 'Price: high to low', sortStock: 'Most available',
        clearFilters: 'Clear filters', productsShown: 'products shown',
        noMatchingProducts: 'No produce matches these filters.',
        quantity: 'Quantity', addToCart: 'Add to cart', viewDetails: 'View details',
        decreaseQuantity: 'Decrease quantity', increaseQuantity: 'Increase quantity',
        available: 'available', lowStock: 'Low stock', outOfStock: 'Out of stock',
        lastUpdated: 'Stock updated', emptyCatalog: 'There are no active products in the catalog.',
        cartTitle: 'Your cart', continueShopping: 'Continue shopping', produce: 'Produce',
        lineTotal: 'Line total', update: 'Update', remove: 'Remove', emptyCart: 'Your cart is empty.',
        browseShop: 'Browse the shop to add produce.', orderEstimate: 'Order estimate',
        finalStockCheck: 'Final stock and totals are checked securely at checkout.',
        checkout: 'Continue to checkout', subtotal: 'Subtotal', bulkDiscount: 'Bulk discount',
        delivery: 'Delivery', estimatedTotal: 'Estimated total',
        bulkNote: 'Orders of 20 or more combined units receive a 10% produce discount.',
        checkoutTitle: 'Checkout', backToCart: 'Back to cart', deliveryDetails: 'Delivery details',
        deliveryAddress: 'Delivery address', addressHint: 'Enter the house number and street.',
        recipient: 'Recipient name', contactNumber: 'Contact number',
        streetAddress: 'House number and street', barangay: 'Barangay',
        cityMunicipality: 'City / Municipality', deliveryInstructions: 'Delivery instructions',
        instructionsHint: 'Optional: landmark or directions for the rider.',
        completeDeliveryDetails: 'Enter the recipient, contact number, and complete delivery address.',
        invalidContactNumber: 'Enter a valid contact number.',
        paymentMethod: 'Payment method', cod: 'Cash on Delivery',
        onlinePayment: 'Online payment (GCash/card)', placeOrder: 'Place order',
        orderSummary: 'Order summary', browseProduce: 'Browse produce',
        onlineUnavailable: 'Online payment is unavailable until Xendit and the public HTTPS address are configured.',
        onlineCheckFailed: 'Online payment could not be verified and is hidden for now. Cash on Delivery is still available.',
        ordersTitle: 'My orders', order: 'Order', total: 'Total', payment: 'Payment',
        trackOrder: 'Track order', latestActiveOrder: 'Your order in progress',
        deliveryStatus: 'Delivery status', paymentStatus: 'Payment status',
        orderTracking: 'Order tracking', orderTrackingIntro: 'Follow your order from the farm to your door.',
        trackingLiveNote: 'Progress updates automatically when the farm updates your order.',
        trackingAwaitingConfirmation: 'Waiting for farm confirmation',
        trackingAwaitingConfirmationNote: 'The farm will confirm your order before harvesting begins.',
        trackingConfirmed: 'Order confirmed', trackingConfirmedNote: 'The farm has accepted your order.',
        trackingPreparing: 'Harvesting in progress', trackingPreparingNote: 'Your fresh produce is being harvested.',
        trackingReady: 'Packed & quality check', trackingReadyNote: 'Your order is packed and being checked for quality.',
        trackingOut: 'Out for delivery', trackingOutNote: 'Your order is on its way to your address.',
        trackingDelivered: 'Delivered', trackingDeliveredNote: 'Your order has arrived.',
        trackingCancelled: 'Order cancelled', trackingCancelledNote: 'Contact the farm if you need help with this order.',
        noOrders: 'You have not placed an order yet.', paymentReturn: 'Payment return received',
        paymentReturnNote: 'Payment status changes only after the verified payment notification arrives.',
        checkoutCancelled: 'Checkout cancelled',
        checkoutCancelledNote: 'Your payment is not marked as paid. Contact the farm for help with this order.',
        backToOrders: 'Back to orders', orderNotFound: 'Order not found',
        address: 'Address', placed: 'Placed', items: 'Items', unitPrice: 'Unit price',
        orderTotal: 'Order total', paymentHistory: 'Payment history',
        noPaymentEvents: 'No payment events recorded.', orderAgain: 'Order these items again',
        returnRequest: 'Request a return', returnRequests: 'Return requests',
        returnWindowOpen: 'You can request a return within 24 hours of delivery for damaged items or quality issues.',
        returnWindowClosed: 'The 24-hour return-request window has ended.',
        returnNotDelivered: 'Return requests are available after delivery.',
        returnReason: 'Reason', damaged: 'Damaged item', qualityIssue: 'Quality issue',
        availableQuantity: 'available',
        returnNotes: 'Additional details (optional)', submitReturnRequest: 'Send return request',
        returnRequestNotice: 'Farm staff will review your request. Refunds and restocking are handled manually.',
        noReturnRequests: 'No return requests for this order.',
        returnRequestSent: 'Return request sent for staff review.',
        status: 'Status',
        addedToCart: 'added to your cart.', openCart: 'View cart',
        reorderAdded: 'Available items from this order were added to your cart.',
        reorderPartial: 'Some items were unavailable or had less stock than before.',
        reorderUnavailable: 'None of the items from this order are currently available.',
        reorderNotComplete: 'This order is still in progress and cannot be ordered again yet.',
        languageChanged: 'Language preference saved.',
        productInformation: 'Product information', variety: 'Variety', unit: 'Unit', stock: 'Stock',
        mostRecentHarvest: 'Most recent harvest', description: 'Description',
        buyerAccount: 'Buyer account', welcome: 'Welcome', buyerIntro: "Browse today's produce and keep track of your orders.",
        activeProduce: 'Active produce', availableInShop: 'Available in the shop',
        recentOrders: 'Your recent orders', latestOrders: 'Latest orders shown below',
        itemsInCart: 'Items in cart', readyToOrder: 'Ready when you are',
        accountRole: 'Account role', quickLinks: 'Quick links', shopAndOrders: 'Shop and manage your orders.',
        browseFresh: 'Browse fresh produce', trackOrders: 'Track your orders',
        logout: 'Log out', actions: 'Actions', orderPlaced: 'Order placed:',
        statusConfirmed: 'confirmed', statusProcessing: 'processing', statusOutForDelivery: 'out for delivery',
        statusPending: 'pending', statusPreparing: 'preparing', statusReadyForDelivery: 'ready for delivery',
        statusDelivered: 'delivered', statusCancelled: 'cancelled', statusPendingPayment: 'pending payment',
        statusPaid: 'paid', statusFailed: 'failed', statusRefunded: 'refunded',
        legacyPayment: 'Legacy', buyerRole: 'Buyer', date: 'Date', event: 'Event',
        amount: 'Amount', reference: 'Reference', details: 'Details',
    },
    fil: {
        navDashboard: 'Dashboard', navShop: 'Mamili', navOrders: 'Mga order ko', navCart: 'Cart',
        switchLanguage: 'English', shopEyebrow: 'Sariwa mula sa bukid', shopTitle: 'Masarap at sariwa, mula sa bukid.',
        shopIntro: 'Mga sariwang gulay na inalagaan at inani para sa inyong hapag.',
        viewCart: 'Tingnan ang cart', farmFresh: 'Sariwa mula sa bukid', harvestedCare: 'Maingat na inani',
        seasonalProduce: 'Pana-panahong ani', availableToday: 'Tingnan ang mga available ngayon',
        localDelivery: 'Lokal na delivery', flatDelivery: '₱20 ang delivery bawat order',
        deliveryNote: 'Flat na ₱20 ang delivery bawat order. Makikita sa buod ang bayad at kabuuang halaga.',
        catalogTitle: 'Ani ngayon', catalogIntro: 'Pumili mula sa kasalukuyang talaan.',
        searchProducts: 'Maghanap ng gulay', searchPlaceholder: 'Pangalan, variety, o paglalarawan',
        category: 'Kategorya', allCategories: 'Lahat ng kategorya', inStockOnly: 'May stock lamang',
        sortBy: 'Ayusin ayon sa', sortName: 'Pangalan', sortPriceLow: 'Presyo: pinakamababa',
        sortPriceHigh: 'Presyo: pinakamataas', sortStock: 'Pinakamaraming stock',
        clearFilters: 'I-reset ang filter', productsShown: 'produkto ang ipinapakita',
        noMatchingProducts: 'Walang produktong tumutugma sa mga filter.',
        quantity: 'Dami', addToCart: 'Idagdag sa cart', viewDetails: 'Tingnan ang detalye',
        decreaseQuantity: 'Bawasan ang dami', increaseQuantity: 'Dagdagan ang dami',
        available: 'available', lowStock: 'Kaunti na lang', outOfStock: 'Ubos na',
        lastUpdated: 'Na-update ang stock', emptyCatalog: 'Walang aktibong produkto sa talaan.',
        cartTitle: 'Ang cart mo', continueShopping: 'Magpatuloy mamili', produce: 'Produkto',
        lineTotal: 'Kabuuan', update: 'I-update', remove: 'Alisin', emptyCart: 'Walang laman ang cart mo.',
        browseShop: 'Mamili ng produkto para maidagdag sa cart.', orderEstimate: 'Tantiyang bayarin',
        finalStockCheck: 'Kukumpirmahin ang stock at kabuuan sa ligtas na pag-checkout.',
        checkout: 'Magpatuloy sa checkout', subtotal: 'Subtotal', bulkDiscount: 'Diskuwento sa maramihang bili',
        delivery: 'Delivery', estimatedTotal: 'Tantiyang kabuuan',
        bulkNote: 'May 10% diskuwento sa gulay kapag umabot sa 20 pinagsamang yunit ang order.',
        checkoutTitle: 'Checkout', backToCart: 'Bumalik sa cart', deliveryDetails: 'Detalye ng delivery',
        deliveryAddress: 'Address ng delivery', addressHint: 'Ilagay ang house number at kalye.',
        recipient: 'Pangalan ng tatanggap', contactNumber: 'Contact number',
        streetAddress: 'House number at kalye', barangay: 'Barangay',
        cityMunicipality: 'Lungsod / Munisipalidad', deliveryInstructions: 'Tagubilin sa delivery',
        instructionsHint: 'Opsyonal: landmark o direksiyon para sa rider.',
        completeDeliveryDetails: 'Ilagay ang pangalan ng tatanggap, contact number, at kumpletong address.',
        invalidContactNumber: 'Maglagay ng wastong contact number.',
        paymentMethod: 'Paraan ng bayad', cod: 'Bayad pagdating (COD)',
        onlinePayment: 'Online na bayad (GCash/card)', placeOrder: 'Ilagay ang order',
        orderSummary: 'Buod ng order', browseProduce: 'Mamili ng gulay',
        onlineUnavailable: 'Hindi available ang online payment hangga’t hindi naka-configure ang Xendit at pampublikong HTTPS address.',
        onlineCheckFailed: 'Hindi makumpirma ang online payment kaya pansamantalang nakatago ito. Available pa rin ang COD.',
        ordersTitle: 'Mga order ko', order: 'Order', total: 'Kabuuan', payment: 'Bayad',
        trackOrder: 'Subaybayan ang order', latestActiveOrder: 'Order mong pinoproseso',
        deliveryStatus: 'Status ng delivery', paymentStatus: 'Status ng bayad',
        orderTracking: 'Pagsubaybay sa order', orderTrackingIntro: 'Subaybayan ang order mula bukid hanggang sa iyo.',
        trackingLiveNote: 'Awtomatikong naa-update ang progreso kapag binago ng bukid ang status ng order.',
        trackingAwaitingConfirmation: 'Naghihintay ng kumpirmasyon ng bukid',
        trackingAwaitingConfirmationNote: 'Kukumpirmahin muna ng bukid ang order bago simulan ang pag-aani.',
        trackingConfirmed: 'Kumpirmado ang order', trackingConfirmedNote: 'Tinanggap na ng bukid ang iyong order.',
        trackingPreparing: 'Isinasagawa ang pag-aani', trackingPreparingNote: 'Inaani na ang iyong sariwang gulay.',
        trackingReady: 'Naka-pack at sinusuri ang kalidad', trackingReadyNote: 'Naka-pack na ang order at sinusuri ang kalidad nito.',
        trackingOut: 'Nasa biyahe na para ihatid', trackingOutNote: 'Papunta na ang order sa iyong address.',
        trackingDelivered: 'Naideliver na', trackingDeliveredNote: 'Nakarating na ang iyong order.',
        trackingCancelled: 'Kinansela ang order', trackingCancelledNote: 'Makipag-ugnayan sa bukid kung kailangan mo ng tulong sa order na ito.',
        noOrders: 'Wala ka pang nailalagay na order.', paymentReturn: 'Natanggap ang pagbabalik mula sa bayad',
        paymentReturnNote: 'Magbabago lang ang status ng bayad kapag natanggap ang beripikadong abiso.',
        checkoutCancelled: 'Kinansela ang checkout',
        checkoutCancelledNote: 'Hindi minarkahang bayad ang order. Makipag-ugnayan sa bukid kung kailangan ng tulong.',
        backToOrders: 'Bumalik sa mga order', orderNotFound: 'Hindi makita ang order',
        address: 'Address', placed: 'Petsa ng order', items: 'Mga produkto', unitPrice: 'Presyo bawat yunit',
        orderTotal: 'Kabuuang bayarin', paymentHistory: 'Kasaysayan ng bayad',
        noPaymentEvents: 'Wala pang naitalang bayad.', orderAgain: 'Bilhin muli ang mga ito',
        returnRequest: 'Humiling ng pagsasauli', returnRequests: 'Mga kahilingan sa pagsasauli',
        returnWindowOpen: 'Maaari kang humiling ng pagsasauli sa loob ng 24 oras mula sa paghahatid kung may sira o problema sa kalidad ang produkto.',
        returnWindowClosed: 'Tapos na ang 24-oras na panahon para humiling ng pagsasauli.',
        returnNotDelivered: 'Pagkatapos maihatid lamang maaaring humiling ng pagsasauli.',
        returnReason: 'Dahilan', damaged: 'Sirang produkto', qualityIssue: 'Problema sa kalidad',
        availableQuantity: 'na natitira',
        returnNotes: 'Karagdagang detalye (opsyonal)', submitReturnRequest: 'Ipadala ang kahilingan',
        returnRequestNotice: 'Susuriin ng farm staff ang kahilingan. Mano-manong aasikasuhin ang refund at pagbalik sa stock.',
        noReturnRequests: 'Wala pang kahilingan sa pagsasauli para sa order na ito.',
        returnRequestSent: 'Naipadala na ang kahilingan para masuri ng staff.',
        status: 'Status',
        addedToCart: 'naidagdag sa cart mo.', openCart: 'Tingnan ang cart',
        reorderAdded: 'Naidagdag sa cart ang mga produktong may stock mula sa order na ito.',
        reorderPartial: 'May produktong ubos na o mas kaunti na ang stock.',
        reorderUnavailable: 'Wala nang available na produkto mula sa order na ito.',
        reorderNotComplete: 'Pinoproseso pa ang order na ito at hindi pa ito maaaring ulitin.',
        languageChanged: 'Nai-save ang piniling wika.',
        productInformation: 'Impormasyon ng produkto', variety: 'Variety', unit: 'Yunit', stock: 'Stock',
        mostRecentHarvest: 'Huling anihan', description: 'Paglalarawan',
        buyerAccount: 'Buyer account', welcome: 'Kumusta', buyerIntro: 'Mamili ng ani ngayon at subaybayan ang mga order mo.',
        activeProduce: 'Aktibong produkto', availableInShop: 'Available sa shop',
        recentOrders: 'Mga kamakailang order', latestOrders: 'Makikita sa ibaba ang mga huling order',
        itemsInCart: 'Mga item sa cart', readyToOrder: 'Handa nang umorder',
        accountRole: 'Uri ng account', quickLinks: 'Mabilisang link', shopAndOrders: 'Mamili at tingnan ang mga order.',
        browseFresh: 'Mamili ng sariwang ani', trackOrders: 'Subaybayan ang mga order',
        logout: 'Mag-log out', actions: 'Mga aksyon', orderPlaced: 'Naipasa ang order:',
        statusConfirmed: 'kumpirmado', statusProcessing: 'pinoproseso', statusOutForDelivery: 'nasa biyahe',
        statusPending: 'nakabinbin', statusPreparing: 'inihahanda', statusReadyForDelivery: 'handa na para ihatid',
        statusDelivered: 'naideliver', statusCancelled: 'kinansela', statusPendingPayment: 'naghihintay ng bayad',
        statusPaid: 'bayad na', statusFailed: 'hindi nagtagumpay', statusRefunded: 'na-refund',
        legacyPayment: 'Lumang tala', buyerRole: 'Mamimili', date: 'Petsa', event: 'Kaganapan',
        amount: 'Halaga', reference: 'Sanggunian', details: 'Detalye',
    },
};
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
        || (new URLSearchParams(location.hash.slice(1)).get('type') === 'recovery' ? 'reset-password' : 'home'),
    language: readBuyerLanguage(),
    products: [],
    records: new Map(),
    orderDetails: null,
    orderStatusChannel: null,
    orderStatusBuyerId: null,
    returnRequestChannel: null,
    returnRequestUserId: null,
    pendingReturnCount: 0,
    cart: readCart(),
};

function readBuyerLanguage() {
    try {
        return localStorage.getItem(languageStorageKey) === 'fil' ? 'fil' : 'en';
    } catch (error) {
        console.error('Buyer language preference could not be read.', error);
        return 'en';
    }
}

function buyerText(key) {
    return buyerCopy[state.language]?.[key] || buyerCopy.en[key] || key;
}

function formatQuantity(value) {
    return Number(value || 0).toLocaleString('en-PH', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 2,
    });
}

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

function dateTimeLabel(value) {
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString('en-PH', {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}

function dateTimeBoundary(day, endOfDay = false) {
    const time = endOfDay ? '23:59:59.999' : '00:00:00';
    return new Date(`${day}T${time}`).toISOString();
}

function notify(message, type = 'success', scrollToTop = true) {
    notice.textContent = message;
    notice.className = `notice ${type}`;
    notice.hidden = false;
    if (scrollToTop) window.scrollTo({ top: 0, behavior: 'smooth' });
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
    const header = document.querySelector('.site-header');
    const navToggle = document.querySelector('[data-nav-toggle]');
    const isOperationsUser = Boolean(
        state.session
        && state.profile
        && state.page !== 'reset-password'
        && isFarmStaffRole(state.profile.role),
    );
    document.body.classList.toggle('operations-layout', isOperationsUser);
    header.classList.toggle('operations-sidebar', isOperationsUser);
    if (!isOperationsUser) document.body.classList.remove('sidebar-collapsed');
    nav.setAttribute('aria-label', isOperationsUser ? 'Farm operations' : 'Main navigation');
    if (!state.session || !state.profile) {
        menu.open = false;
        menu.hidden = true;
        guest.hidden = false;
        nav.innerHTML = state.page === 'home'
            ? '<a href="#home">Home</a><a href="#about">About</a><a href="#featured">Produce</a><a href="#why-harvest">Why Harvest</a><a href="#farming-process">Our process</a><a href="#contact">Contact</a>'
            : '<a href="/" data-page="home">Home</a>';
        navToggle.setAttribute('aria-expanded', 'false');
        navToggle.setAttribute('aria-label', 'Open navigation');
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
    document.querySelector('#logout-button').textContent = `↪ ${state.profile.role === 'buyer' ? buyerText('logout') : 'Log out'}`;

    if (isOperationsUser) {
        const links = [
            ['dashboard', '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>', 'Dashboard'],
            ['vegetables', '<path d="M20 4c-7 0-13 3-13 9a5 5 0 0 0 5 5c6 0 8-7 8-14Z"/><path d="M4 21c3-5 7-8 12-11"/>', 'Vegetables'],
            ['plantings', '<path d="M12 22v-8"/><path d="M12 14c-4 0-7-2-7-6 4 0 7 2 7 6Z"/><path d="M12 11c0-4 3-7 7-7 0 4-3 7-7 7Z"/>', 'Plantings'],
            ['harvests', '<path d="M12 22V8"/><path d="M12 13 7 9"/><path d="m12 17 6-5"/><path d="M7 9c-2-2-2-4-1-6 3 1 4 3 4 6"/><path d="M18 12c2-2 2-4 1-6-3 1-4 3-4 6"/>', 'Harvests'],
            ['inventory', '<path d="m12 3 9 5-9 5-9-5 9-5Z"/><path d="m3 8 9 5 9-5"/><path d="M3 8v9l9 5 9-5V8"/><path d="M12 13v9"/>', 'Inventory'],
            ['sales', '<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 7H6"/>', 'Sales'],
            ['returns', '<path d="M3 12a9 9 0 1 0 2.64-6.36L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l3 2"/>', 'Returns', state.pendingReturnCount],
            ['quality', '<path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11Z"/><path d="m9 12 2 2 4-4"/>', 'Quality'],
            ['reports', '<path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/>', 'Reports'],
            ...(state.profile.role === 'admin' ? [['activity', '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>', 'Activity']] : []),
        ];
        nav.innerHTML = links.map(([page, icon, label, badgeCount]) => `
            <a class="nav-link ${state.page === page ? 'active' : ''}" href="?page=${page}" data-page="${page}" title="${label}" aria-label="${label}${badgeCount ? `, ${badgeCount} pending` : ''}" ${state.page === page ? 'aria-current="page"' : ''}>
                <span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">${icon}</svg></span>
                <span class="nav-link-label">${label}</span>${badgeCount ? `<span class="nav-return-count">${badgeCount}</span>` : ''}
            </a>
        `).join('');
    } else {
        const links = [
            ['dashboard', '▦', buyerText('navDashboard')],
            ['shop', '⌂', buyerText('navShop')],
            ['orders', '▤', buyerText('navOrders')],
            ['cart', '▱', `${buyerText('navCart')} <span class="app-cart-count">${cartCount()}</span>`],
        ];
        nav.innerHTML = links.map(([page, icon, label]) => `
            <a class="${state.page === page ? 'active' : ''}" href="?page=${page}" data-page="${page}" ${state.page === page ? 'aria-current="page"' : ''}>
                <span class="nav-icon" aria-hidden="true">${icon}</span>${label}
            </a>
        `).join('') + `<button class="buyer-language-toggle" type="button" data-action="toggle-language">${buyerText('switchLanguage')}</button>`;
    }

    document.documentElement.lang = state.language === 'fil' ? 'fil' : 'en';
    const isMobile = window.matchMedia(isOperationsUser ? '(max-width: 900px)' : '(max-width: 1180px)').matches;
    const isCollapsed = isOperationsUser && document.body.classList.contains('sidebar-collapsed');
    navToggle.setAttribute('aria-expanded', String(isOperationsUser && !isMobile && !isCollapsed));
    navToggle.setAttribute('aria-label', isMobile
        ? 'Open navigation'
        : isOperationsUser
            ? (isCollapsed ? 'Expand navigation' : 'Collapse navigation')
            : 'Open navigation');
}

function go(page, replace = false) {
    document.querySelector('#account-menu').open = false;
    state.page = page;
    const url = new URL(location.href);
    url.search = '';
    if (page && page !== 'home') url.searchParams.set('page', page);
    if (replace) history.replaceState({}, '', url);
    else history.pushState({}, '', url);
    updateNavigation();
    clearNotice();
    void renderPage();
}

function isStaff() {
    return isFarmStaffRole(state.profile?.role);
}

function vegetablePhotoUrl(path) {
    if (!path) return '';
    return state.supabase.storage.from('vegetable-images').getPublicUrl(path).data.publicUrl;
}

function productPicture(name, imagePath = null) {
    const key = String(name || '').trim().toLowerCase();
    const image = imageByName[key];
    const icon = iconByName[key] || '🥬';
    const photoUrl = vegetablePhotoUrl(imagePath);
    if (photoUrl) {
        return `<div class="product-image"><img src="${escapeHtml(photoUrl)}" alt="${escapeHtml(name)}" loading="lazy"><span class="product-image-fallback" hidden>${icon}</span></div>`;
    }
    return image
        ? `<div class="product-image"><img src="/assets/images/${encodeURIComponent(image)}" alt="${escapeHtml(name)}" loading="lazy"><span class="product-image-fallback" hidden>${icon}</span></div>`
        : `<div class="product-image product-image-fallback" role="img" aria-label="${escapeHtml(name)}">${icon}</div>`;
}

function statusBadge(value) {
    const label = String(value || '').replaceAll('_', ' ');
    return `<span class="app-status ${escapeHtml(value)}">${escapeHtml(label)}</span>`;
}

function buyerStatusBadge(value) {
    const keys = {
        pending: 'statusPending',
        confirmed: 'statusConfirmed',
        preparing: 'statusPreparing',
        ready_for_delivery: 'statusReadyForDelivery',
        out_for_delivery: 'statusOutForDelivery',
        delivered: 'statusDelivered',
        cancelled: 'statusCancelled',
        pending_payment: 'statusPendingPayment',
        paid: 'statusPaid',
        failed: 'statusFailed',
        refunded: 'statusRefunded',
    };
    const key = keys[value];
    if (!key) return statusBadge(value);
    return `<span class="app-status ${escapeHtml(value)}">${escapeHtml(buyerText(key))}</span>`;
}

function buyerOrderStatusBadge(orderCode, value) {
    return `<span data-order-status-for="${escapeHtml(orderCode)}">${buyerStatusBadge(value)}</span>`;
}

function buyerPaymentStatusBadge(orderCode, value) {
    return `<span data-payment-status-for="${escapeHtml(orderCode)}">${buyerStatusBadge(value)}</span>`;
}

function buyerActiveOrderCard(order) {
    if (!order) return '';
    const encodedOrderCode = encodeURIComponent(order.order_code);
    return `<section class="panel app-active-order" aria-label="${buyerText('orderTracking')}">
        <div><h2>${buyerText('latestActiveOrder')}</h2><p>${buyerText('order')} ${escapeHtml(order.order_code)} · ${buyerText('trackingLiveNote')}</p></div>
        <div class="app-active-order-actions">${buyerOrderStatusBadge(order.order_code, order.order_status)}
            <a class="button secondary" href="?page=order&order=${encodedOrderCode}" data-page="order" data-order="${escapeHtml(order.order_code)}">${buyerText('trackOrder')}</a>
        </div>
    </section>`;
}

async function getLatestActiveBuyerOrder() {
    const orders = await queryOrThrow(state.supabase.from('orders')
        .select('order_code, order_status, buyer_id')
        .eq('buyer_id', state.profile.id)
        .not('order_status', 'in', '(delivered,cancelled)')
        .order('created_at', { ascending: false })
        .limit(1));
    return orders[0] || null;
}

function buyerOrderTracker(order) {
    if (!shouldShowOrderTracking(order.order_status)) return '';
    const tracking = getOrderTrackingState(order.order_status);
    const orderCode = escapeHtml(order.order_code);
    if (tracking.cancelled) {
        return `<section class="panel section app-order-tracker" data-order-tracker-for="${orderCode}">
            <div class="app-order-tracker-heading"><div><h2>${buyerText('orderTracking')}</h2><p>${buyerText('orderTrackingIntro')}</p></div>
                <span class="app-status cancelled">${buyerText('trackingCancelled')}</span></div>
            <p class="app-order-tracker-cancelled" role="status">${buyerText('trackingCancelledNote')}</p>
        </section>`;
    }

    const copyByStatus = {
        confirmed: ['trackingConfirmed', 'trackingConfirmedNote'],
        preparing: ['trackingPreparing', 'trackingPreparingNote'],
        ready_for_delivery: ['trackingReady', 'trackingReadyNote'],
        out_for_delivery: ['trackingOut', 'trackingOutNote'],
        delivered: ['trackingDelivered', 'trackingDeliveredNote'],
    };
    const steps = tracking.steps.map((step, index) => {
        const [titleKey, descriptionKey] = copyByStatus[step.status];
        return `<li class="app-order-tracker-step is-${step.state}" ${step.state === 'current' ? 'aria-current="step"' : ''}>
            <span class="app-order-tracker-marker" aria-hidden="true">${step.state === 'complete' ? '✓' : index + 1}</span>
            <div><h3>${buyerText(titleKey)}</h3><p>${buyerText(descriptionKey)}</p></div>
        </li>`;
    }).join('');
    const currentStatus = order.order_status === 'pending'
        ? 'trackingAwaitingConfirmation'
        : copyByStatus[order.order_status][0];

    return `<section class="panel section app-order-tracker" data-order-tracker-for="${orderCode}">
        <div class="app-order-tracker-heading"><div><h2>${buyerText('orderTracking')}</h2><p>${buyerText('orderTrackingIntro')}</p></div>
            <span class="app-status ${escapeHtml(order.order_status)}">${buyerText(currentStatus)}</span></div>
        ${order.order_status === 'pending' ? `<p class="app-order-tracker-pending" role="status">${buyerText('trackingAwaitingConfirmationNote')}</p>` : ''}
        <ol class="app-order-tracker-list">${steps}</ol>
        <p class="app-order-tracker-live">${buyerText('trackingLiveNote')}</p>
    </section>`;
}

function quantityStepper(inputId, min, max, value, disabled = false) {
    const minimum = Number(min);
    const maximum = Number(max);
    const quantity = Number(value);
    const noQuantity = disabled || !Number.isFinite(quantity);
    return `<div class="app-quantity-control" data-quantity-control>
        <button type="button" class="app-quantity-stepper" data-quantity-step="-1" aria-label="${buyerText('decreaseQuantity')}" ${noQuantity || quantity <= minimum ? 'disabled' : ''}>−</button>
        <input id="${escapeHtml(inputId)}" class="app-quantity-input" aria-label="${buyerText('quantity')}" type="number" name="quantity" min="${minimum}" max="${maximum}" step="0.01" value="${escapeHtml(String(value))}" ${disabled ? 'disabled' : ''}>
        <button type="button" class="app-quantity-stepper" data-quantity-step="1" aria-label="${buyerText('increaseQuantity')}" ${noQuantity || quantity >= maximum ? 'disabled' : ''}>+</button>
    </div>`;
}

function updateQuantityStepper(input) {
    const control = input.closest('[data-quantity-control]');
    if (!control) return;
    const quantity = Number(input.value);
    const minimum = Number(input.min);
    const maximum = Number(input.max);
    const decreaseButton = control.querySelector('[data-quantity-step="-1"]');
    const increaseButton = control.querySelector('[data-quantity-step="1"]');
    decreaseButton.disabled = !Number.isFinite(quantity) || quantity <= minimum;
    increaseButton.disabled = !Number.isFinite(quantity) || quantity >= maximum;
}

function updateVisibleBuyerOrderStatus(order) {
    if (
        state.profile?.role !== 'buyer'
        || String(order.buyer_id) !== String(state.profile.id)
        || !['dashboard', 'shop', 'orders', 'order'].includes(state.page)
    ) return;
    if (['dashboard', 'shop'].includes(state.page)
        && ['delivered', 'cancelled'].includes(order.order_status)) {
        void renderPage();
        return;
    }

    const orderCode = String(order.order_code);
    app.querySelectorAll('[data-order-status-for]').forEach((element) => {
        if (element.dataset.orderStatusFor === orderCode) {
            element.innerHTML = buyerStatusBadge(order.order_status);
        }
    });
    app.querySelectorAll('[data-payment-status-for]').forEach((element) => {
        if (element.dataset.paymentStatusFor === orderCode) {
            element.innerHTML = buyerStatusBadge(order.payment_status);
        }
    });
    app.querySelectorAll('[data-order-tracker-for]').forEach((element) => {
        if (element.dataset.orderTrackerFor === orderCode) {
            element.outerHTML = buyerOrderTracker(order);
        }
    });
}

function syncBuyerOrderStatusSubscription() {
    const buyerId = state.profile?.role === 'buyer' ? state.profile.id : null;
    const shouldSubscribe = buyerId !== null && ['dashboard', 'shop', 'orders', 'order'].includes(state.page);
    const sameBuyer = String(state.orderStatusBuyerId) === String(buyerId);

    if ((!shouldSubscribe || !sameBuyer) && state.orderStatusChannel) {
        void state.supabase.removeChannel(state.orderStatusChannel);
        state.orderStatusChannel = null;
        state.orderStatusBuyerId = null;
    }
    if (!shouldSubscribe || state.orderStatusChannel) return;

    state.orderStatusBuyerId = buyerId;
    state.orderStatusChannel = state.supabase
        .channel(`buyer-order-status-${buyerId}`)
        .on('postgres_changes', {
            event: 'UPDATE',
            schema: 'public',
            table: 'orders',
            filter: `buyer_id=eq.${buyerId}`,
        }, ({ new: order }) => updateVisibleBuyerOrderStatus(order))
        .subscribe((status) => {
            if (status === 'CHANNEL_ERROR' || status === 'TIMED_OUT') {
                console.error('Live order status updates could not connect.', status);
            }
        });
}

async function refreshPendingReturnCount() {
    const { count, error } = await state.supabase
        .from('return_requests')
        .select('id', { count: 'exact', head: true })
        .eq('status', 'pending');
    if (error) throw error;
    const pendingCount = count || 0;
    if (state.pendingReturnCount !== pendingCount) {
        state.pendingReturnCount = pendingCount;
        updateNavigation();
    }
}

function syncReturnRequestSubscription() {
    const role = state.profile?.role;
    const hasVerifiedSession = Boolean(state.session && state.profile?.email_verified_at);
    const userId = hasVerifiedSession && role === 'buyer' ? state.profile.id : null;
    const isOperationsUser = hasVerifiedSession && isStaff();
    const subscriptionKey = userId ? `buyer-${userId}` : isOperationsUser ? `staff-${state.profile.id}` : null;
    const sameUser = state.returnRequestUserId === subscriptionKey;

    if ((!subscriptionKey || !sameUser) && state.returnRequestChannel) {
        void state.supabase.removeChannel(state.returnRequestChannel);
        state.returnRequestChannel = null;
        state.returnRequestUserId = null;
    }
    if (!subscriptionKey || state.returnRequestChannel) {
        if (!subscriptionKey && state.pendingReturnCount !== 0) {
            state.pendingReturnCount = 0;
            updateNavigation();
        }
        return;
    }

    state.returnRequestUserId = subscriptionKey;
    const channel = state.supabase.channel(`return-requests-${subscriptionKey}`);
    if (userId) {
        channel.on('postgres_changes', {
            event: 'UPDATE',
            schema: 'public',
            table: 'return_requests',
            filter: `buyer_id=eq.${userId}`,
        }, ({ new: request }) => {
            notify(`Your return request for order ${request.order_code} was ${request.status.replaceAll('_', ' ')}.`);
            if (state.page === 'order') void renderPage().catch(showError);
        });
    } else {
        const refreshCount = () => {
            void refreshPendingReturnCount().catch((error) => {
                console.error('Pending return requests could not be refreshed.', error);
                notify('Pending return requests could not be refreshed. Refresh and try again.', 'error', false);
            });
        };
        refreshCount();
        channel.on('postgres_changes', {
            event: 'INSERT',
            schema: 'public',
            table: 'return_requests',
        }, (payload) => {
            notify(`New return request for order ${payload.new.order_code}.`);
            refreshCount();
        });
        channel.on('postgres_changes', {
            event: 'UPDATE',
            schema: 'public',
            table: 'return_requests',
        }, refreshCount);
        channel.on('postgres_changes', {
            event: 'DELETE',
            schema: 'public',
            table: 'return_requests',
        }, refreshCount);
    }
    state.returnRequestChannel = channel.subscribe((status) => {
        if (status === 'CHANNEL_ERROR' || status === 'TIMED_OUT') {
            console.error('Live return request notifications could not connect.', status);
            notify('Live return request notifications could not connect. Refresh and try again.', 'error', false);
        }
    });
}

function buyerPaymentMethod(value) {
    if (value === 'cod') return buyerText('cod');
    if (value === 'online') return buyerText('onlinePayment');
    return buyerText('legacyPayment');
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
    const horizontal = canvas.dataset.chart === 'horizontal';
    const line = canvas.dataset.chart === 'line';
    const left = horizontal ? Math.min(150, width * .4) : 42;
    const right = 12;
    const top = 14;
    const bottom = horizontal ? 12 : 34;
    const plotWidth = width - left - right;
    const plotHeight = height - top - bottom;
    const maximum = Math.max(1, ...values) * 1.12;
    context.clearRect(0, 0, width, height);
    context.font = '11px system-ui, sans-serif';
    context.fillStyle = '#718078';
    if (horizontal) {
        for (let tick = 0; tick <= 4; tick += 1) {
            const x = left + plotWidth * tick / 4;
            context.beginPath();
            context.strokeStyle = '#e7eee7';
            context.moveTo(x, top);
            context.lineTo(x, top + plotHeight);
            context.stroke();
            context.textAlign = 'center';
            context.textBaseline = 'top';
            context.fillStyle = '#718078';
            context.fillText(String(Math.round(maximum * tick / 4)), x, top + plotHeight + 5);
        }
        const slot = plotHeight / Math.max(1, labels.length);
        values.forEach((value, index) => {
            const y = top + slot * index + slot * .2;
            const barWidth = Math.max(0, value / maximum * plotWidth);
            context.fillStyle = color;
            context.fillRect(left, y, barWidth, slot * .6);
            context.textAlign = 'right';
            context.textBaseline = 'middle';
            context.fillStyle = '#718078';
            const label = labels[index].length > 20 ? `${labels[index].slice(0, 18)}…` : labels[index];
            context.fillText(label, left - 7, y + slot * .3);
        });
        return;
    }
    for (let tick = 0; tick <= 4; tick += 1) {
        const y = top + plotHeight * tick / 4;
        context.beginPath();
        context.strokeStyle = '#e7eee7';
        context.moveTo(left, y);
        context.lineTo(width - right, y);
        context.stroke();
        context.textAlign = 'right';
        context.textBaseline = 'middle';
        const tickValue = maximum * (4 - tick) / 4;
        const tickLabel = canvas.dataset.format === 'currency'
            ? `₱${Math.round(tickValue).toLocaleString('en-PH')}`
            : String(Math.round(tickValue));
        context.fillText(tickLabel, left - 6, y);
    }
    const slot = plotWidth / Math.max(1, labels.length);
    if (line) {
        context.beginPath();
        context.strokeStyle = color;
        context.lineWidth = 2.5;
        values.forEach((value, index) => {
            const x = left + slot * (index + .5);
            const y = top + plotHeight - Math.max(0, value / maximum * plotHeight);
            if (index === 0) context.moveTo(x, y);
            else context.lineTo(x, y);
        });
        context.stroke();
        values.forEach((value, index) => {
            const x = left + slot * (index + .5);
            const y = top + plotHeight - Math.max(0, value / maximum * plotHeight);
            context.beginPath();
            context.fillStyle = color;
            context.arc(x, y, 3, 0, Math.PI * 2);
            context.fill();
        });
    } else {
        values.forEach((value, index) => {
            const barHeight = Math.max(0, value / maximum * plotHeight);
            const x = left + slot * index + slot * .2;
            context.fillStyle = color;
            context.fillRect(x, top + plotHeight - barHeight, slot * .6, barHeight);
        });
    }
    const labelStep = Math.max(1, Math.ceil(labels.length / 7));
    labels.forEach((label, index) => {
        if (index % labelStep !== 0 && index !== labels.length - 1) return;
        const x = left + slot * (index + .5);
        context.textAlign = 'center';
        context.textBaseline = 'top';
        context.fillStyle = '#718078';
        context.fillText(label, x, top + plotHeight + 8);
    });
}

function drawDashboardDoughnut(canvasId, values) {
    const canvas = document.getElementById(canvasId);
    if (!canvas || !values.length) return;
    const bounds = canvas.getBoundingClientRect();
    const ratio = Math.max(1, window.devicePixelRatio || 1);
    canvas.width = Math.round(bounds.width * ratio);
    canvas.height = Math.round(bounds.height * ratio);
    const context = canvas.getContext('2d');
    if (!context) return;
    context.setTransform(ratio, 0, 0, ratio, 0, 0);
    const colors = ['#218653', '#76a85b', '#e0b84c', '#5b9c9b', '#9a82b4', '#d98661', '#5478a5'];
    const total = values.reduce((sum, value) => sum + value, 0);
    if (!total) return;
    const centerX = bounds.width / 2;
    const centerY = bounds.height / 2;
    const radius = Math.max(1, Math.min(bounds.width, bounds.height) * .44);
    let angle = -Math.PI / 2;
    values.forEach((value, index) => {
        const nextAngle = angle + value / total * Math.PI * 2;
        context.beginPath();
        context.moveTo(centerX, centerY);
        context.arc(centerX, centerY, radius, angle, nextAngle);
        context.closePath();
        context.fillStyle = colors[index % colors.length];
        context.fill();
        angle = nextAngle;
    });
    context.beginPath();
    context.arc(centerX, centerY, radius * .57, 0, Math.PI * 2);
    context.fillStyle = '#fff';
    context.fill();
    context.fillStyle = '#31513a';
    context.font = '600 13px system-ui, sans-serif';
    context.textAlign = 'center';
    context.textBaseline = 'middle';
    context.fillText(total.toFixed(2), centerX, centerY);
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

async function queryAllRows(createQuery) {
    const rows = [];
    for (let offset = 0; ; offset += 500) {
        const page = await queryOrThrow(createQuery().range(offset, offset + 499));
        rows.push(...page);
        if (page.length < 500) return rows;
    }
}

async function getProducts(activeOnly = false) {
    let query = state.supabase.from('vegetables').select('*').order('name');
    if (activeOnly) query = query.eq('active', true);
    const [vegetables, inventory] = await Promise.all([
        queryOrThrow(query),
        queryOrThrow(state.supabase.from('inventory').select('vegetable_id, quantity, updated_at')),
    ]);
    const inventoryByVegetable = new Map(inventory.map((row) => [row.vegetable_id, row]));
    return vegetables.map((vegetable) => ({
        ...vegetable,
        quantity: Number(inventoryByVegetable.get(vegetable.id)?.quantity || 0),
        inventoryUpdatedAt: inventoryByVegetable.get(vegetable.id)?.updated_at || null,
    }));
}

async function getPublicProducts() {
    return queryOrThrow(state.supabase.from('vegetables')
        .select('id, name, category, variety, description, unit, price, image_path')
        .eq('active', true)
        .order('name')
        .limit(6));
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
    const pages = ['home', 'login', 'register', 'forgot', 'reset-password'];
    if (!pages.includes(page)) return 'login';
    return page;
}

function renderAuth(page) {
    document.body.classList.add('auth-page');
    document.body.classList.toggle('password-reset-page', page === 'reset-password');
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
        const [orders, products, activeOrder] = await Promise.all([
            queryOrThrow(state.supabase.from('orders')
                .select('order_code, final_total, order_status, payment_status, created_at')
                .eq('buyer_id', state.profile.id).order('created_at', { ascending: false }).limit(5)),
            queryOrThrow(state.supabase.from('vegetables').select('id').eq('active', true)),
            getLatestActiveBuyerOrder(),
        ]);
        const rows = orders.map((order) => `<tr>
            <td><a href="?page=order&order=${encodeURIComponent(order.order_code)}" data-page="order" data-order="${escapeHtml(order.order_code)}">${escapeHtml(order.order_code)}</a></td>
            <td>${money(order.final_total)}</td><td>${buyerOrderStatusBadge(order.order_code, order.order_status)}</td><td>${buyerPaymentStatusBadge(order.order_code, order.payment_status)}</td>
        </tr>`);
        app.innerHTML = `
            <section class="dashboard-welcome"><div><p class="dashboard-eyebrow">${buyerText('buyerAccount')}</p><h1>${buyerText('welcome')}, ${escapeHtml(state.profile.name)}</h1><p>${buyerText('buyerIntro')}</p></div></section>
            ${buyerActiveOrderCard(activeOrder)}
            <section class="dashboard-stat-grid">
                <article class="dashboard-stat-card"><span class="dashboard-stat-label">${buyerText('activeProduce')}</span><strong>${products.length}</strong><small>${buyerText('availableInShop')}</small></article>
                <article class="dashboard-stat-card"><span class="dashboard-stat-label">${buyerText('recentOrders')}</span><strong>${orders.length}</strong><small>${buyerText('latestOrders')}</small></article>
                <article class="dashboard-stat-card"><span class="dashboard-stat-label">${buyerText('itemsInCart')}</span><strong>${cartCount()}</strong><small>${buyerText('readyToOrder')}</small></article>
                <article class="dashboard-stat-card"><span class="dashboard-stat-label">${buyerText('accountRole')}</span><strong>${buyerText('buyerRole')}</strong><small>Email verified</small></article>
            </section>
            <section class="panel section"><div class="app-toolbar"><div><h2>${buyerText('quickLinks')}</h2><p class="muted">${buyerText('shopAndOrders')}</p></div></div>
                <div class="app-dashboard-links"><a href="?page=shop" data-page="shop">${buyerText('browseFresh')} →</a><a href="?page=cart" data-page="cart">${buyerText('viewCart')} (${cartCount()}) →</a><a href="?page=orders" data-page="orders">${buyerText('trackOrders')} →</a></div>
            </section>
            <section class="panel section"><h2>${buyerText('recentOrders')}</h2>${table([buyerText('order'), buyerText('total'), buyerText('deliveryStatus'), buyerText('paymentStatus')], rows, buyerText('noOrders'))}</section>`;
        return;
    }

    const isAdmin = state.profile.role === 'admin';
    const requestedPeriod = Number(new URLSearchParams(location.search).get('period'));
    const chartPeriod = isAdmin && [7, 30, 90].includes(requestedPeriod) ? requestedPeriod : 7;
    const today = dateInputValue();
    const chartStart = dateInputValue(new Date(Date.now() - (chartPeriod - 1) * 86400000));
    const comparisonStart = dateInputValue(new Date(Date.now() - 13 * 86400000));
    const salesStart = chartStart < comparisonStart ? chartStart : comparisonStart;
    const weekStart = dateInputValue(new Date(Date.now() - 6 * 86400000));
    const topSellingStart = dateInputValue(new Date(Date.now() - 29 * 86400000));
    const [vegetables, inventory, paidOrders, dailyHarvests, recentOrders, recentHarvests,
        recentInventory, harvestCount, openOrderCount, topSellingRows, activityRows] = await Promise.all([
        queryOrThrow(state.supabase.from('vegetables').select('id, name, unit, active')),
        queryOrThrow(state.supabase.from('inventory').select('vegetable_id, quantity, updated_at')),
        queryAllRows(() => state.supabase.from('orders').select('final_total, created_at')
            .eq('payment_status', 'paid')
            .gte('created_at', dateTimeBoundary(salesStart))
            .lte('created_at', dateTimeBoundary(today, true))
            .order('created_at')),
        queryAllRows(() => state.supabase.from('harvests').select('quantity, harvested_on')
            .is('archived_at', null)
            .gte('harvested_on', chartStart)
            .lte('harvested_on', today)
            .order('harvested_on')),
        queryOrThrow(state.supabase.from('orders')
            .select('order_code, buyer_id, eligible_quantity, final_total, order_status, payment_status, created_at')
            .order('created_at', { ascending: false }).limit(8)),
        queryOrThrow(state.supabase.from('harvests').select('id, harvested_on, quantity, planting_id').is('archived_at', null).order('harvested_on', { ascending: false }).limit(6)),
        queryOrThrow(state.supabase.from('inventory').select('vegetable_id, quantity, updated_at')
            .order('updated_at', { ascending: false }).limit(6)),
        (async () => {
            const { count, error } = await state.supabase.from('harvests').select('id', { count: 'exact', head: true }).is('archived_at', null);
            if (error) throw error;
            return count || 0;
        })(),
        (async () => {
            const { count, error } = await state.supabase.from('orders').select('id', { count: 'exact', head: true })
                .not('order_status', 'in', '(delivered,cancelled)');
            if (error) throw error;
            return count || 0;
        })(),
        isAdmin
            ? queryAllRows(() => state.supabase.from('sales')
                .select('vegetable_id, quantity, orders!inner(payment_status, created_at)')
                .eq('orders.payment_status', 'paid')
                .gte('orders.created_at', dateTimeBoundary(topSellingStart))
                .lte('orders.created_at', dateTimeBoundary(today, true)))
            : Promise.resolve([]),
        isAdmin
            ? queryOrThrow(state.supabase.from('activity_logs')
                .select('user_id, action, entity_type, details, created_at')
                .order('created_at', { ascending: false }).limit(8))
            : Promise.resolve([]),
    ]);

    const profileIds = [...new Set([
        ...recentOrders.map((row) => row.buyer_id),
        ...activityRows.map((row) => row.user_id).filter(Boolean),
    ])];
    const profiles = profileIds.length
        ? await queryOrThrow(state.supabase.from('profiles').select('id, name').in('id', profileIds))
        : [];
    const names = new Map(profiles.map((profile) => [profile.id, profile.name]));
    const totalStock = inventory.reduce((sum, row) => sum + Number(row.quantity), 0);
    const inventoryByVegetable = new Map(inventory.map((row) => [row.vegetable_id, row]));
    const activeVegetables = vegetables.filter((vegetable) => vegetable.active);
    const inventoryRows = activeVegetables.map((vegetable) => ({
        ...vegetable,
        quantity: Number(inventoryByVegetable.get(vegetable.id)?.quantity || 0),
    })).sort((a, b) => b.quantity - a.quantity || a.name.localeCompare(b.name));
    const lowStockItems = inventoryRows.filter((item) => item.quantity <= 5);
    const salesByDay = new Map();
    paidOrders.forEach((row) => {
        const day = dateInputValue(new Date(row.created_at));
        salesByDay.set(day, (salesByDay.get(day) || 0) + Number(row.final_total));
    });
    const harvestsByDay = new Map();
    dailyHarvests.forEach((row) => {
        harvestsByDay.set(row.harvested_on, (harvestsByDay.get(row.harvested_on) || 0) + Number(row.quantity));
    });
    const chartDays = Array.from({ length: chartPeriod }, (_, index) => {
        const day = dateInputValue(new Date(Date.now() - (chartPeriod - index - 1) * 86400000));
        return {
            day,
            label: new Date(`${day}T00:00:00`).toLocaleDateString('en-PH', { month: 'short', day: 'numeric' }),
        };
    });
    const salesValues = chartDays.map((item) => salesByDay.get(item.day) || 0);
    const harvestValues = chartDays.map((item) => harvestsByDay.get(item.day) || 0);
    const todayRevenue = salesByDay.get(today) || 0;
    const currentPeriodSales = [...salesByDay.entries()]
        .filter(([day]) => day >= weekStart && day <= today)
        .reduce((sum, [, value]) => sum + value, 0);
    const previousPeriodSales = [...salesByDay.entries()]
        .filter(([day]) => day >= comparisonStart && day < weekStart)
        .reduce((sum, [, value]) => sum + value, 0);
    const salesTrend = previousPeriodSales > 0
        ? ((currentPeriodSales - previousPeriodSales) / previousPeriodSales) * 100
        : null;

    const orderCodes = recentOrders.map((row) => row.order_code);
    const orderLines = orderCodes.length
        ? await queryOrThrow(state.supabase.from('sales').select('order_code, quantity').in('order_code', orderCodes))
        : [];
    const itemsByOrder = new Map();
    orderLines.forEach((line) => {
        const items = itemsByOrder.get(line.order_code) || { count: 0, quantity: 0 };
        items.count += 1;
        items.quantity += Number(line.quantity);
        itemsByOrder.set(line.order_code, items);
    });
    const orderRows = recentOrders.map((order) => {
        const items = itemsByOrder.get(order.order_code) || { count: 0, quantity: Number(order.eligible_quantity || 0) };
        return isAdmin
            ? `<tr><td><strong>${escapeHtml(order.order_code)}</strong><span class="table-secondary">${dateTimeLabel(order.created_at)}</span></td><td>${escapeHtml(names.get(order.buyer_id) || 'Buyer')}</td><td>${items.count} product(s)<span class="table-secondary">${items.quantity.toFixed(2)} units</span></td><td><strong>${money(order.final_total)}</strong></td><td>${statusBadge(order.payment_status)}<span class="table-secondary">${statusBadge(order.order_status)}</span></td></tr>`
            : `<tr><td><strong>${escapeHtml(order.order_code)}</strong><span class="table-secondary">${dateTimeLabel(order.created_at)}</span></td><td>${escapeHtml(names.get(order.buyer_id) || 'Buyer')}</td><td>${money(order.final_total)}</td><td>${statusBadge(order.order_status)}</td></tr>`;
    });

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
    const topSellingByVegetable = new Map();
    topSellingRows.forEach((row) => {
        const quantity = (topSellingByVegetable.get(row.vegetable_id) || 0) + Number(row.quantity);
        topSellingByVegetable.set(row.vegetable_id, quantity);
    });
    const vegetableById = new Map(vegetables.map((row) => [row.id, row]));
    const topSelling = [...topSellingByVegetable.entries()]
        .map(([id, quantity]) => ({ ...vegetableById.get(id), quantity }))
        .filter((row) => row.name)
        .sort((a, b) => b.quantity - a.quantity || a.name.localeCompare(b.name))
        .slice(0, 7);
    const recentInventoryRows = recentInventory.map((row) => {
        const details = vegetableById.get(row.vegetable_id);
        return `<tr><td>${escapeHtml(details?.name || 'Vegetable')}</td><td>${Number(row.quantity).toFixed(2)} ${escapeHtml(details?.unit || '')}</td><td>${dateTimeLabel(row.updated_at)}</td></tr>`;
    });
    const activityRowsHtml = activityRows.map((row) => `<li>
        <span class="dashboard-activity-mark" aria-hidden="true">•</span>
        <span class="dashboard-activity-copy"><strong>${escapeHtml(row.action.replaceAll('_', ' '))}</strong>
            <small>${escapeHtml(row.details || row.entity_type.replaceAll('_', ' '))} · ${escapeHtml(names.get(row.user_id) || (row.user_id ? 'Former user' : 'System'))}</small>
        </span><time datetime="${escapeHtml(row.created_at)}">${dateTimeLabel(row.created_at)}</time>
    </li>`);
    const inventoryTotal = inventoryRows.reduce((sum, row) => sum + row.quantity, 0);
    const dashboardDate = new Date(`${today}T00:00:00`).toLocaleDateString('en-PH', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });
    const summaryCards = isAdmin
        ? `
            <article class="dashboard-stat-card"><span class="dashboard-stat-icon">◉</span><span class="dashboard-stat-label">Active vegetables</span><strong>${activeVegetables.length}</strong><small>Available in the catalog</small></article>
            <article class="dashboard-stat-card"><span class="dashboard-stat-icon">▤</span><span class="dashboard-stat-label">Total inventory</span><strong>${totalStock.toFixed(2)}</strong><small>Combined quantity across catalog units</small></article>
            <article class="dashboard-stat-card"><span class="dashboard-stat-icon">↗</span><span class="dashboard-stat-label">Sales today</span><strong>${money(todayRevenue)}</strong><small>Paid orders today</small><small class="dashboard-stat-trend ${salesTrend === null ? '' : salesTrend >= 0 ? 'is-positive' : 'is-negative'}">${salesTrend === null ? (currentPeriodSales > 0 ? 'Sales recorded this week' : 'No sales in the last 7 days') : `${salesTrend >= 0 ? '↑' : '↓'} ${Math.abs(salesTrend).toFixed(1)}% vs previous 7 days`}</small></article>
            <a class="dashboard-stat-card dashboard-stat-card-link" href="?page=sales" data-page="sales" aria-label="View ${openOrderCount} open orders"><span class="dashboard-stat-icon">▱</span><span class="dashboard-stat-label">Open orders</span><strong>${openOrderCount}</strong><small>Need fulfillment</small></a>
            <article class="dashboard-stat-card"><span class="dashboard-stat-icon">⌁</span><span class="dashboard-stat-label">Total harvests</span><strong>${harvestCount}</strong><small>Active harvest records</small></article>
            <a class="dashboard-stat-card dashboard-stat-card-link dashboard-stat-card-warning" href="?page=inventory" data-page="inventory" aria-label="View ${lowStockItems.length} low stock items"><span class="dashboard-stat-icon">!</span><span class="dashboard-stat-label">Low stock items</span><strong>${lowStockItems.length}</strong><small>At or below 5.00 units</small></a>`
        : `
            <article class="dashboard-stat-card"><span class="dashboard-stat-icon">⌁</span><span class="dashboard-stat-label">Today's harvest</span><strong>${(harvestsByDay.get(today) || 0).toFixed(2)}</strong><small>Combined quantity; units vary by crop</small></article>
            <article class="dashboard-stat-card"><span class="dashboard-stat-icon">▤</span><span class="dashboard-stat-label">Available inventory</span><strong>${totalStock.toFixed(2)}</strong><small>Combined quantity across catalog units</small></article>
            <a class="dashboard-stat-card dashboard-stat-card-link" href="?page=sales" data-page="sales" aria-label="Review ${openOrderCount} open orders"><span class="dashboard-stat-icon">▱</span><span class="dashboard-stat-label">Open orders</span><strong>${openOrderCount}</strong><small>Ready for staff review</small></a>`;
    const quickActions = isAdmin
        ? `<section class="dashboard-section dashboard-actions" aria-label="Quick actions">
                <div><h2>Quick actions</h2><p>Shortcuts to your farm management tools.</p></div>
                <div class="dashboard-action-links">
                    <a class="button" href="?page=vegetables" data-page="vegetables"><span aria-hidden="true">＋</span> Add vegetable</a>
                    <a class="button secondary" href="?page=harvests" data-page="harvests"><span aria-hidden="true">⌁</span> Record harvest</a>
                    <a class="button secondary" href="?page=inventory" data-page="inventory"><span aria-hidden="true">▤</span> Manage inventory</a>
                    <a class="button secondary" href="?page=sales" data-page="sales"><span aria-hidden="true">↗</span> Review orders</a>
                    <a class="button secondary" href="?page=reports" data-page="reports"><span aria-hidden="true">▥</span> Reports</a>
                </div>
            </section>`
        : `<section class="dashboard-section dashboard-actions" aria-label="Quick actions">
                <div><h2>Quick actions</h2><p>The tools you need for today's farm work.</p></div>
                <div class="dashboard-action-links">
                    <a class="button" href="?page=harvests" data-page="harvests"><span aria-hidden="true">⌁</span> Record harvest</a>
                    <a class="button secondary" href="?page=inventory" data-page="inventory"><span aria-hidden="true">▤</span> Update inventory</a>
                    <a class="button secondary" href="?page=vegetables" data-page="vegetables"><span aria-hidden="true">◉</span> Vegetables</a>
                    <a class="button secondary" href="?page=plantings" data-page="plantings"><span aria-hidden="true">♧</span> Plantings</a>
                </div>
            </section>`;
    const salesChartCard = isAdmin
        ? `<article class="dashboard-chart-card">
                <div class="dashboard-card-heading"><div><h2>Sales performance</h2><p>Paid revenue · last ${chartPeriod} days</p></div><span class="dashboard-chart-symbol" aria-hidden="true">↗</span></div>
                ${salesValues.some((value) => value > 0)
        ? `<div class="dashboard-canvas-wrap"><canvas id="sales-chart" class="dashboard-chart" data-chart="line" data-format="currency" aria-label="Line chart of paid sales revenue over the last ${chartPeriod} days" role="img"></canvas></div>`
        : `<p class="empty">No paid sales in the last ${chartPeriod} days.</p>`}
            </article>`
        : '';
    const inventoryChartCard = `<article class="dashboard-chart-card">
            <div class="dashboard-card-heading"><div><h2>Inventory overview</h2><p>Available stock by vegetable</p></div><span class="dashboard-chart-symbol" aria-hidden="true">◌</span></div>
            ${inventoryTotal > 0
        ? `<div class="dashboard-inventory-chart"><canvas id="inventory-chart" class="dashboard-chart dashboard-doughnut" aria-label="Doughnut chart of inventory by vegetable" role="img"></canvas><ul class="dashboard-chart-legend">${inventoryRows.map((item, index) => `<li><span class="chart-legend-dot chart-color-${index % 7}" aria-hidden="true"></span>${escapeHtml(item.name)}<strong>${item.quantity.toFixed(2)} ${escapeHtml(item.unit)}</strong></li>`).join('')}</ul></div>`
        : '<p class="empty">No inventory quantities to visualize.</p>'}
        </article>`;
    const harvestChartCard = `<article class="dashboard-chart-card">
            <div class="dashboard-card-heading"><div><h2>Harvest production</h2><p>Recorded quantity · last ${chartPeriod} days</p></div><span class="dashboard-chart-symbol" aria-hidden="true">⌁</span></div>
            ${harvestValues.some((value) => value > 0)
        ? `<div class="dashboard-canvas-wrap"><canvas id="harvest-chart" class="dashboard-chart" aria-label="Bar chart of harvest quantity over the last ${chartPeriod} days" role="img"></canvas></div>`
        : `<p class="empty">No active harvests in the last ${chartPeriod} days.</p>`}
        </article>`;
    const stockChartCard = `<article class="dashboard-chart-card">
            <div class="dashboard-card-heading"><div><h2>Stock comparison</h2><p>Top stocked active vegetables</p></div><span class="dashboard-chart-symbol" aria-hidden="true">▥</span></div>
            ${inventoryRows.length
        ? '<div class="dashboard-canvas-wrap dashboard-canvas-wrap-tall"><canvas id="stock-chart" class="dashboard-chart" data-chart="horizontal" aria-label="Horizontal bar chart comparing stock quantities by vegetable" role="img"></canvas></div>'
        : '<p class="empty">Add vegetables to view stock comparisons.</p>'}
        </article>`;
    const topSellingChartCard = isAdmin
        ? `<article class="dashboard-chart-card">
                <div class="dashboard-card-heading"><div><h2>Top-selling vegetables</h2><p>Paid quantities · last 30 days</p></div><span class="dashboard-chart-symbol" aria-hidden="true">↗</span></div>
                ${topSelling.length
        ? '<div class="dashboard-canvas-wrap dashboard-canvas-wrap-tall"><canvas id="top-selling-chart" class="dashboard-chart" data-chart="horizontal" aria-label="Horizontal bar chart of top-selling vegetables by paid quantity over the last 30 days" role="img"></canvas></div>'
        : '<p class="empty">No paid vegetable sales in the last 30 days.</p>'}
            </article>`
        : '';
    const recentPrimaryCard = isAdmin
        ? `<article class="dashboard-table-card">
                <div class="dashboard-card-heading"><div><h2>Recent orders</h2><p>Latest order activity</p></div><a href="?page=sales" data-page="sales">Review orders</a></div>
                ${table(['Order', 'Buyer', 'Items', 'Total', 'Payment / delivery'], orderRows, 'Sales will appear here after the first order is placed.')}
            </article>`
        : `<article class="dashboard-table-card">
                <div class="dashboard-card-heading"><div><h2>Recently updated inventory</h2><p>Latest stock updates</p></div><a href="?page=inventory" data-page="inventory">View inventory</a></div>
                ${table(['Vegetable', 'Available', 'Last updated'], recentInventoryRows, 'Inventory updates will appear here.')}
            </article>`;

    app.innerHTML = `
        <section class="dashboard-welcome"><div><p class="dashboard-eyebrow">${isAdmin ? 'Farm operations · Admin' : 'Daily farm operations'}</p><h1>Welcome back, ${escapeHtml(state.profile.name)}</h1><p>${isAdmin ? 'Here’s what’s happening across your farm today.' : 'Your farm activity and priorities for today.'}</p></div><div class="dashboard-date"><span class="dashboard-date-icon" aria-hidden="true">▦</span>${escapeHtml(dashboardDate)}</div></section>
        <section class="dashboard-stat-grid ${isAdmin ? 'dashboard-admin-stats' : ''}" aria-label="Farm summary">
            ${summaryCards}
        </section>
        ${quickActions}
        ${lowStockItems.length ? `<section class="dashboard-alert section" aria-labelledby="low-stock-title"><div class="dashboard-alert-heading"><span class="dashboard-alert-icon" aria-hidden="true">!</span><div><h2 id="low-stock-title">Stock needs attention</h2><p>${lowStockItems.length} active ${lowStockItems.length === 1 ? 'item is' : 'items are'} at or below 5.00 units.</p></div></div><div class="dashboard-alert-items">${lowStockItems.slice(0, 5).map((item) => `<a href="?page=inventory" data-page="inventory"><span>${escapeHtml(item.name)}</span><strong>${item.quantity.toFixed(2)} ${escapeHtml(item.unit)}</strong></a>`).join('')}${lowStockItems.length > 5 ? `<a class="dashboard-alert-more" href="?page=inventory" data-page="inventory">+${lowStockItems.length - 5} more</a>` : ''}</div></section>` : ''}
        <section class="dashboard-chart-grid" aria-label="Farm performance charts">
            ${isAdmin ? `<form class="dashboard-chart-period" data-form="dashboard-period"><label for="dashboard-period">Chart period</label><select id="dashboard-period" name="period">${[7, 30, 90].map((period) => `<option value="${period}" ${chartPeriod === period ? 'selected' : ''}>Last ${period} days</option>`).join('')}</select><button class="button secondary" type="submit">Update charts</button></form>` : ''}
            ${salesChartCard}
            ${inventoryChartCard}
            ${harvestChartCard}
            ${stockChartCard}
            ${topSellingChartCard}
        </section>
        <section class="dashboard-bottom-grid">
            ${recentPrimaryCard}
            <article class="dashboard-table-card"><div class="dashboard-card-heading"><div><h2>Recent harvests</h2><p>Latest recorded harvests</p></div><a href="?page=harvests" data-page="harvests">View all</a></div><ul class="recent-harvest-list">${harvestRows.length ? harvestRows.join('') : '<li class="muted">No harvest records yet.</li>'}</ul></article>
        </section>`;
    if (isAdmin) {
        app.insertAdjacentHTML('beforeend', `
            <section class="dashboard-table-card dashboard-inventory-updates">
                <div class="dashboard-card-heading"><div><h2>Recent inventory updates</h2><p>Latest recorded stock quantities</p></div><a href="?page=inventory" data-page="inventory">View inventory</a></div>
                ${table(['Vegetable', 'Available', 'Last updated'], recentInventoryRows, 'Inventory updates will appear here.')}
            </section>
            <section class="dashboard-table-card dashboard-activity-card">
                <div class="dashboard-card-heading"><div><h2>Recent activity</h2><p>Latest changes made across the farm</p></div><a href="?page=activity" data-page="activity">View activity log</a></div>
                <ul class="dashboard-activity-list">${activityRowsHtml.length ? activityRowsHtml.join('') : '<li class="muted">Activity will appear here as the team works.</li>'}</ul>
            </section>`);
    }
    if (salesValues.some((value) => value > 0)) drawDashboardChart('sales-chart', chartDays.map((item) => item.label), salesValues, '#218653');
    if (harvestValues.some((value) => value > 0)) drawDashboardChart('harvest-chart', chartDays.map((item) => item.label), harvestValues, '#76a85b');
    if (inventoryTotal > 0) drawDashboardDoughnut('inventory-chart', inventoryRows.map((item) => item.quantity));
    if (inventoryRows.length) drawDashboardChart('stock-chart', inventoryRows.slice(0, 7).map((item) => `${item.name} (${item.unit})`), inventoryRows.slice(0, 7).map((item) => item.quantity), '#218653');
    if (topSelling.length) drawDashboardChart('top-selling-chart', topSelling.map((item) => `${item.name} (${item.unit})`), topSelling.map((item) => item.quantity), '#76a85b');
}

function renderShop(products, activeOrder = null) {
    state.products = products;
    const categories = [...new Set(products.map((product) => product.category).filter(Boolean))]
        .sort((left, right) => left.localeCompare(right));
    const cards = products.map((product) => `
        <article class="card product-card" data-product-id="${product.id}">
            ${productPicture(product.name, product.image_path)}
            <div class="product-card-content">
                <div class="product-card-heading"><h3>${escapeHtml(product.name)}</h3><span class="product-variety">${escapeHtml(product.variety || '')}</span></div>
                <p class="product-description">${escapeHtml(product.description || 'Fresh produce from the farm.')}</p>
                <div class="product-card-meta">
                    <p class="product-category">${escapeHtml(product.category || '')}</p>
                    <div class="product-price"><strong>${money(product.price)}</strong><span>/ ${escapeHtml(product.unit)}</span></div>
                    <p class="product-stock ${product.quantity <= 0 ? 'out-of-stock' : product.quantity <= LOW_STOCK_THRESHOLD ? 'low-stock' : ''}"><span></span>${product.quantity <= 0
        ? buyerText('outOfStock')
        : `${product.quantity <= LOW_STOCK_THRESHOLD ? `${buyerText('lowStock')} · ` : ''}${formatQuantity(product.quantity)} ${escapeHtml(product.unit)} ${buyerText('available')}`}</p>
                    ${product.inventoryUpdatedAt ? `<small class="product-stock-updated">${buyerText('lastUpdated')} ${dateTimeLabel(product.inventoryUpdatedAt)}</small>` : ''}
                </div>
                <a class="product-details-link" href="?page=product&id=${product.id}" data-page="product" data-product="${product.id}">${buyerText('viewDetails')}</a>
            </div>
            <form class="product-card-form" data-form="add-cart" data-product="${product.id}">
                <label for="product-quantity-${product.id}">${buyerText('quantity')} (${escapeHtml(product.unit)})</label>
                <div class="app-product-actions">${quantityStepper(`product-quantity-${product.id}`, 0.01, product.quantity, product.quantity > 0 ? Math.min(1, product.quantity) : 0, product.quantity <= 0)}<button type="submit" ${product.quantity <= 0 ? 'disabled' : ''}>${buyerText('addToCart')}</button></div>
            </form>
        </article>`).join('');
    const filters = `
        <section class="shop-filters" aria-label="${buyerText('catalogTitle')}">
            <label>${buyerText('searchProducts')}<input type="search" data-shop-search placeholder="${buyerText('searchPlaceholder')}"></label>
            <label>${buyerText('category')}<select data-shop-category><option value="">${buyerText('allCategories')}</option>${categories.map((category) => `<option value="${escapeHtml(category)}">${escapeHtml(category)}</option>`).join('')}</select></label>
            <label class="shop-in-stock-filter"><input type="checkbox" data-shop-in-stock> ${buyerText('inStockOnly')}</label>
            <label>${buyerText('sortBy')}<select data-shop-sort>
                <option value="name">${buyerText('sortName')}</option>
                <option value="price_asc">${buyerText('sortPriceLow')}</option>
                <option value="price_desc">${buyerText('sortPriceHigh')}</option>
                <option value="stock_desc">${buyerText('sortStock')}</option>
            </select></label>
            <button type="button" class="button secondary shop-filter-clear" data-action="clear-shop-filters">${buyerText('clearFilters')}</button>
        </section>
        <p class="shop-results-count" data-shop-results aria-live="polite"></p>`;
    const subtotal = state.products.reduce((sum, product) => sum + product.price * Number(state.cart[product.id] || 0), 0);
    app.innerHTML = `
        ${buyerActiveOrderCard(activeOrder)}
        <section class="shop-hero"><div class="shop-hero-copy"><p class="shop-eyebrow">${buyerText('shopEyebrow')}</p><h1>${buyerText('shopTitle')}</h1><p>${buyerText('shopIntro')}</p></div><a class="button shop-hero-button" href="?page=cart" data-page="cart">${buyerText('viewCart')} <span class="cart-count">${cartCount()}</span></a></section>
        <section class="shop-highlights"><div><span aria-hidden="true">🌱</span><strong>${buyerText('farmFresh')}</strong><small>${buyerText('harvestedCare')}</small></div><div><span aria-hidden="true">🥬</span><strong>${buyerText('seasonalProduce')}</strong><small>${buyerText('availableToday')}</small></div><div><span aria-hidden="true">🚚</span><strong>${buyerText('localDelivery')}</strong><small>${buyerText('flatDelivery')}</small></div></section>
        <p class="shop-delivery-note">${buyerText('deliveryNote')} ${buyerText('bulkNote')}</p>
        <section class="shop-section-heading"><div><h2>${buyerText('catalogTitle')}</h2><p class="muted">${buyerText('catalogIntro')}</p></div></section>
        ${filters}
        <section class="card-grid shop-product-grid" data-shop-grid>${cards || `<div class="empty">${buyerText('emptyCatalog')}</div>`}<p class="empty shop-filter-empty" data-shop-empty hidden>${buyerText('noMatchingProducts')}</p></section>
        ${cartCount() ? `<a class="button shop-mobile-cart" href="?page=cart" data-page="cart">${buyerText('viewCart')} · ${cartCount()} · ${money(subtotal)}</a>` : ''}`;
    applyShopFilters();
}

function updateShopCartUI() {
    const heroCart = app.querySelector('.shop-hero-button');
    const mobileCart = app.querySelector('.shop-mobile-cart');
    const products = state.products;
    const subtotal = products.reduce((sum, product) => sum + product.price * Number(state.cart[product.id] || 0), 0);
    if (heroCart) {
        heroCart.innerHTML = `${buyerText('viewCart')} <span class="cart-count">${formatQuantity(cartCount())}</span>`;
    }
    if (!cartCount()) {
        mobileCart?.remove();
        return;
    }
    const link = `<a class="button shop-mobile-cart" href="?page=cart" data-page="cart">${buyerText('viewCart')} · ${formatQuantity(cartCount())} · ${money(subtotal)}</a>`;
    if (mobileCart) mobileCart.outerHTML = link;
    else app.querySelector('[data-shop-grid]')?.insertAdjacentHTML('afterend', link);
}

function applyShopFilters() {
    const grid = app.querySelector('[data-shop-grid]');
    if (!grid) return;
    const filters = {
        search: app.querySelector('[data-shop-search]')?.value || '',
        category: app.querySelector('[data-shop-category]')?.value || '',
        inStockOnly: Boolean(app.querySelector('[data-shop-in-stock]')?.checked),
        sort: app.querySelector('[data-shop-sort]')?.value || 'name',
    };
    const products = filterAndSortProducts(state.products, filters);
    const visibleIds = new Set(products.map((product) => String(product.id)));
    const cards = [...grid.querySelectorAll('.product-card')];
    cards.forEach((card) => {
        card.hidden = !visibleIds.has(card.dataset.productId);
    });
    products.forEach((product) => {
        const card = cards.find((item) => item.dataset.productId === String(product.id));
        if (card) grid.append(card);
    });
    const result = app.querySelector('[data-shop-results]');
    if (result) result.textContent = `${products.length} ${buyerText('productsShown')}`;
    const empty = app.querySelector('[data-shop-empty]');
    if (empty) empty.hidden = products.length > 0 || state.products.length === 0;
}

function renderLanding(products, catalogUnavailable = false) {
    document.body.classList.remove('auth-page');
    document.body.classList.remove('password-reset-page');
    const cards = products.map((product) => `
        <article class="landing-product">
            <div class="landing-product-image">${productPicture(product.name, product.image_path)}</div>
            <div class="landing-product-copy">
                <p class="landing-product-category">${escapeHtml(product.category || 'From the farm')}</p>
                <h3>${escapeHtml(product.name)}</h3>
                <p>${escapeHtml(product.description || 'Fresh produce, grown and harvested with care.')}</p>
                <strong>${money(product.price)} <small>/ ${escapeHtml(product.unit)}</small></strong>
                <p class="landing-product-availability">Sign in to check current stock.</p>
                <a class="button landing-product-cta" href="?page=register" data-page="register">Create account to order <span aria-hidden="true">→</span></a>
            </div>
        </article>`).join('');

    app.innerHTML = `
        <div class="landing-page">
            <section class="landing-hero" id="home" aria-labelledby="landing-title">
                <div class="landing-hero-copy">
                    <p class="landing-eyebrow"><span></span> From our farm to your table</p>
                    <h1 id="landing-title">A little more <em>goodness</em> in every day.</h1>
                    <p class="landing-intro">Get to know the produce in our live farm catalog and find fresh picks for your table.</p>
                    <div class="landing-actions">
                        <a class="button" href="#featured">Shop the harvest <span aria-hidden="true">→</span></a>
                        <a class="landing-secondary-link" href="#about">Learn more <span aria-hidden="true">↗</span></a>
                    </div>
                    <p class="landing-note">Prices are from the current catalog. Sign in to check stock and order.</p>
                </div>
                <div class="landing-photo-grid" aria-label="Fresh vegetable photography">
                    <figure class="landing-photo landing-photo-main">${productPicture('Carrot')}<figcaption>A fresh look at the harvest</figcaption></figure>
                    <figure class="landing-photo landing-photo-tomato">${productPicture('Tomato')}</figure>
                    <figure class="landing-photo landing-photo-greens">${productPicture('Zucchini')}</figure>
                </div>
                <aside class="landing-highlights" aria-label="Shopping highlights">
                    <p>Good things, made simple</p>
                    <div><strong>See what is available</strong><small>Check produce from the live catalog.</small></div>
                    <div><strong>Know what you are choosing</strong><small>Browse item details, prices, and units.</small></div>
                    <div><strong>Order when you are ready</strong><small>Create an account when you want to shop.</small></div>
                </aside>
            </section>
            <section class="landing-trust" aria-label="Harvest at a glance">
                <div><strong>Live catalog</strong><span>Explore produce listed by the farm.</span></div>
                <div><strong>Clear details</strong><span>Know the price, variety, and unit.</span></div>
                <div><strong>Order tracking</strong><span>Follow delivery progress from your buyer account.</span></div>
            </section>
            <section class="landing-section" id="featured" aria-labelledby="landing-featured-title">
                <div class="landing-section-heading">
                    <div><p class="landing-eyebrow">From the current catalog</p><h2 id="landing-featured-title">Meet the fresh picks.</h2></div>
                    <p>Every item below comes from the farm's active produce catalog.</p>
                </div>
                <div class="landing-product-grid">${cards || `<p class="landing-empty">${catalogUnavailable ? 'The live catalog is temporarily unavailable. Please check back soon.' : 'The next harvest is on its way. Please check back soon.'}</p>`}</div>
                <div class="landing-section-action"><p>Ready to choose your produce?</p><a class="button" href="?page=register" data-page="register">Create an account to shop <span aria-hidden="true">→</span></a></div>
            </section>
            <section class="landing-story" id="about" aria-labelledby="landing-story-title">
                <div class="landing-story-image">${productPicture('Kale')}</div>
                <div class="landing-story-copy">
                    <p class="landing-eyebrow">A closer connection to the farm</p>
                    <h2 id="landing-story-title">Farm to <em>your table.</em></h2>
                    <p>Harvest brings farm operations and produce ordering together. Staff can record plantings and harvests, track inventory, and share current availability with shoppers.</p>
                    <p>Customers can explore real catalog details and manage orders through their own account.</p>
                    <a class="landing-secondary-link" href="#farming-process">See how Harvest works <span aria-hidden="true">→</span></a>
                </div>
            </section>
            <section class="landing-section landing-why" id="why-harvest" aria-labelledby="landing-why-title">
                <div class="landing-section-heading">
                    <div><p class="landing-eyebrow">The Harvest difference</p><h2 id="landing-why-title">Farm details, made easier to find.</h2></div>
                    <p>A connected view of produce, availability, and ordering.</p>
                </div>
                <div class="landing-feature-grid">
                    <article class="landing-feature-card"><span aria-hidden="true">🌱</span><h3>Current catalog</h3><p>Explore active produce and see the prices and units listed by the farm.</p></article>
                    <article class="landing-feature-card"><span aria-hidden="true">📦</span><h3>Clear availability</h3><p>Check current inventory before choosing what to order.</p></article>
                    <article class="landing-feature-card"><span aria-hidden="true">▤</span><h3>Orders in one place</h3><p>Review past orders and track delivery progress from your buyer account.</p></article>
                    <article class="landing-feature-card"><span aria-hidden="true">🧺</span><h3>Order management</h3><p>Place orders through your account and keep track of their status.</p></article>
                </div>
            </section>
            <section class="landing-section landing-process" id="farming-process" aria-labelledby="landing-process-title">
                <div class="landing-section-heading">
                    <div><p class="landing-eyebrow">From farm records to fresh picks</p><h2 id="landing-process-title">Connected at every step.</h2></div>
                    <p>Harvest brings farm records and the customer shop into one place.</p>
                </div>
                <ol class="landing-process-grid">
                    <li><span>01</span><h3>Plan</h3><p>Staff record what is planted and when it is expected to grow.</p></li>
                    <li><span>02</span><h3>Harvest</h3><p>Harvest records keep quantities and dates together.</p></li>
                    <li><span>03</span><h3>Track</h3><p>Inventory shows the produce currently listed as available.</p></li>
                    <li><span>04</span><h3>Choose</h3><p>Customers browse the catalog and place orders from their account.</p></li>
                </ol>
            </section>
            <section class="landing-contact" id="contact" aria-labelledby="landing-contact-title">
                <div>
                    <p class="landing-eyebrow">We're here to help</p>
                    <h2 id="landing-contact-title">Questions about your next order?</h2>
                    <p>Sign in to manage an order, or create a buyer account to start browsing and save produce you love.</p>
                </div>
                <div class="landing-contact-actions">
                    ${contactEmail ? `<a class="landing-contact-email" href="mailto:${escapeHtml(contactEmail)}">${escapeHtml(contactEmail)}</a>` : ''}
                    <a class="button" href="?page=register" data-page="register">Create a buyer account <span aria-hidden="true">→</span></a>
                    <a class="landing-secondary-link" href="?page=login" data-page="login">Already have an account? Log in</a>
                </div>
            </section>
        </div>`;
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
        <nav class="product-breadcrumb"><a href="?page=shop" data-page="shop">${buyerText('navShop')}</a><span>›</span><span>${escapeHtml(product.name)}</span></nav>
        <section class="product-detail">
            <div class="product-detail-image">${productPicture(product.name, product.image_path)}<span class="product-detail-image-tag">${buyerText('farmFresh')}</span></div>
            <div class="product-detail-content"><p class="product-detail-eyebrow">${buyerText('shopEyebrow')}</p><h1>${escapeHtml(product.name)}</h1>
                <p class="product-detail-variety">${escapeHtml(product.variety || '')}</p>
                <p class="product-detail-description">${escapeHtml(product.description || 'Fresh produce grown and harvested with care.')}</p>
                <p class="product-detail-price"><strong>${money(product.price)}</strong> / ${escapeHtml(product.unit)}</p>
                <p class="product-stock ${product.quantity <= 0 ? 'out-of-stock' : product.quantity <= LOW_STOCK_THRESHOLD ? 'low-stock' : ''}">${product.quantity <= 0 ? buyerText('outOfStock') : `${product.quantity <= LOW_STOCK_THRESHOLD ? `${buyerText('lowStock')} · ` : ''}${formatQuantity(product.quantity)} ${escapeHtml(product.unit)} ${buyerText('available')}`}</p>
                ${product.inventoryUpdatedAt ? `<small class="product-stock-updated">${buyerText('lastUpdated')} ${dateTimeLabel(product.inventoryUpdatedAt)}</small>` : ''}
                <form class="product-detail-form" data-form="add-cart" data-product="${product.id}">
                    <label for="detail-quantity">${buyerText('quantity')} (${escapeHtml(product.unit)})</label>
                    <div class="app-product-actions">${quantityStepper('detail-quantity', 0.01, product.quantity, product.quantity > 0 ? Math.min(1, product.quantity) : 0, product.quantity <= 0)}<button type="submit" ${product.quantity <= 0 ? 'disabled' : ''}>${buyerText('addToCart')}</button></div>
                </form>
            </div>
        </section>
        <section class="product-information"><div class="product-information-heading"><h2>${buyerText('productInformation')}</h2></div><dl class="product-information-list">
            <div><dt>${buyerText('variety')}</dt><dd>${escapeHtml(product.variety || '—')}</dd></div><div><dt>${buyerText('unit')}</dt><dd>${escapeHtml(product.unit)}</dd></div><div><dt>${buyerText('stock')}</dt><dd>${formatQuantity(product.quantity)} ${escapeHtml(product.unit)}</dd></div><div><dt>${buyerText('mostRecentHarvest')}</dt><dd>${dateLabel(lastHarvest?.harvested_on)}</dd></div><div class="product-information-description"><dt>${buyerText('description')}</dt><dd>${escapeHtml(product.description || 'No description available.')}</dd></div>
        </dl></section>`;
}

async function renderCart() {
    const products = await getProducts(true);
    state.products = products;
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
            <td><form class="app-inline-form" data-form="update-cart" data-product="${product.id}">${quantityStepper(`cart-quantity-${product.id}`, 0, product.quantity, safeQuantity)}<button type="submit" class="button secondary">${buyerText('update')}</button></form></td>
            <td>${money(product.price * safeQuantity)}</td><td><button class="button danger" type="button" data-action="remove-cart" data-product="${product.id}">${buyerText('remove')}</button></td></tr>`);
    }
    state.cart = validCart;
    saveCart();
    const items = Object.entries(validCart).map(([id, quantity]) => ({
        quantity,
        price: byId.get(id).price,
    }));
    const totals = calculateOrderTotals(items);
    app.innerHTML = `
        <div class="page-heading"><h1>${buyerText('cartTitle')}</h1><a class="button secondary" href="?page=shop" data-page="shop">${buyerText('continueShopping')}</a></div>
        ${table([buyerText('produce'), buyerText('quantity'), buyerText('lineTotal'), ''], rows, `${buyerText('emptyCart')} ${buyerText('browseShop')}`)}
        ${rows.length ? `<section class="panel app-summary section"><h2>${buyerText('orderEstimate')}</h2>${summaryRows(totals)}<p class="app-muted">${buyerText('finalStockCheck')}</p><a class="button" href="?page=checkout" data-page="checkout">${buyerText('checkout')}</a></section>` : `<a class="button" href="?page=shop" data-page="shop">${buyerText('browseProduce')}</a>`}`;
}

function summaryRows(totals) {
    return `<div class="app-summary-row"><span>${buyerText('subtotal')}</span><strong>${money(totals.subtotal)}</strong></div>
        <div class="app-summary-row"><span>${buyerText('bulkDiscount')} (${totals.discountPercentage}%)</span><strong>−${money(totals.discountAmount)}</strong></div>
        <div class="app-summary-row"><span>${buyerText('delivery')}</span><strong>${money(totals.deliveryFee)}</strong></div>
        <div class="app-summary-row total"><span>${buyerText('estimatedTotal')}</span><strong>${money(totals.finalTotal)}</strong></div>
        <p class="app-muted">${buyerText('bulkNote')}</p>`;
}

async function reorderOrder(orderCode) {
    const { data: order, error: orderError } = await state.supabase.from('orders')
        .select('order_code, order_status')
        .eq('order_code', orderCode)
        .eq('buyer_id', state.profile.id)
        .maybeSingle();
    if (orderError) throw orderError;
    if (!order) throw new Error(buyerText('orderNotFound'));
    if (!['delivered', 'cancelled'].includes(order.order_status)) {
        throw new Error(buyerText('reorderNotComplete'));
    }

    const [sales, products] = await Promise.all([
        queryOrThrow(state.supabase.from('sales')
            .select('vegetable_id, quantity')
            .eq('order_code', orderCode)),
        getProducts(true),
    ]);
    const productById = new Map(products.map((product) => [product.id, product]));
    let addedQuantity = 0;
    let skippedItems = 0;
    for (const sale of sales) {
        const product = productById.get(sale.vegetable_id);
        const requested = Number(sale.quantity);
        const current = Number(state.cart[sale.vegetable_id] || 0);
        const available = Math.max(0, Number(product?.quantity || 0) - current);
        if (!product || !Number.isFinite(requested) || requested <= 0 || available <= 0) {
            skippedItems += 1;
            continue;
        }
        const quantity = Math.min(requested, available);
        state.cart[sale.vegetable_id] = current + quantity;
        addedQuantity += quantity;
        if (quantity < requested) skippedItems += 1;
    }

    if (!addedQuantity) {
        notify(buyerText('reorderUnavailable'), 'error');
        return;
    }
    saveCart();
    notify(buyerText('reorderAdded'));
    notice.insertAdjacentHTML('beforeend', ` <a href="?page=cart" data-page="cart">${buyerText('openCart')}</a>`);
    if (skippedItems) notice.insertAdjacentText('beforeend', ` ${buyerText('reorderPartial')}`);
}

async function renderCheckout() {
    const products = await getProducts(true);
    const productMap = new Map(products.map((product) => [String(product.id), product]));
    const items = Object.entries(state.cart).flatMap(([id, quantity]) => {
        const product = productMap.get(id);
        return product && Number(quantity) > 0 ? [{ id, quantity: Number(quantity), product }] : [];
    });
    if (!items.length) {
        app.innerHTML = `<section class="panel"><h1>${buyerText('checkoutTitle')}</h1><p>${buyerText('emptyCart')}</p><a href="?page=shop" class="button" data-page="shop">${buyerText('browseProduce')}</a></section>`;
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
        availabilityMessage = buyerText('onlineCheckFailed');
    }
    const totals = calculateOrderTotals(items.map(({ quantity, product }) => ({ quantity, price: product.price })));
    const lines = items.map(({ quantity, product }) => `<div class="app-summary-row"><span>${escapeHtml(product.name)} × ${quantity} ${escapeHtml(product.unit)}</span><strong>${money(product.price * quantity)}</strong></div>`).join('');
    app.innerHTML = `
        <div class="page-heading"><h1>${buyerText('checkoutTitle')}</h1><a class="button secondary" href="?page=cart" data-page="cart">${buyerText('backToCart')}</a></div>
        <div class="card-grid">
            <section class="panel"><h2>${buyerText('deliveryDetails')}</h2>
                <form class="app-form" data-form="checkout">
                    <div class="field"><label for="recipient-name">${buyerText('recipient')}</label><input id="recipient-name" name="recipientName" type="text" autocomplete="name" required minlength="2" maxlength="100"></div>
                    <div class="field"><label for="contact-number">${buyerText('contactNumber')}</label><input id="contact-number" name="contactNumber" type="tel" inputmode="tel" autocomplete="tel" required minlength="7" maxlength="20" pattern="(?=.*[0-9])[+0-9() .-]{7,20}"></div>
                    <div class="field field-wide"><label for="street-address">${buyerText('streetAddress')}</label><input id="street-address" name="streetAddress" type="text" autocomplete="address-line1" required minlength="3" maxlength="200" placeholder="${buyerText('addressHint')}"></div>
                    <div class="field"><label for="delivery-barangay">${buyerText('barangay')}</label><input id="delivery-barangay" name="barangay" type="text" required minlength="2" maxlength="100"></div>
                    <div class="field"><label for="delivery-city">${buyerText('cityMunicipality')}</label><input id="delivery-city" name="cityMunicipality" type="text" autocomplete="address-level2" required minlength="2" maxlength="100"></div>
                    <div class="field field-wide"><label for="delivery-instructions">${buyerText('deliveryInstructions')}</label><textarea id="delivery-instructions" name="deliveryInstructions" maxlength="300" placeholder="${buyerText('instructionsHint')}"></textarea></div>
                    <p class="app-muted field-wide">${buyerText('deliveryNote')}</p>
                    <div class="field field-wide"><label>${buyerText('paymentMethod')}</label><div class="app-checkout-methods">
                        <label><input type="radio" name="paymentMethod" value="cod" checked> ${buyerText('cod')}</label>
                        ${onlineAvailable ? `<label><input type="radio" name="paymentMethod" value="online"> ${buyerText('onlinePayment')}</label>` : ''}
                    </div></div>
                    ${availabilityMessage ? `<p class="app-alert field-wide">${escapeHtml(availabilityMessage)}</p>` : ''}
                    ${!onlineAvailable && !availabilityMessage ? `<p class="app-muted field-wide">${buyerText('onlineUnavailable')}</p>` : ''}
                    <button type="submit">${buyerText('placeOrder')}</button>
                </form>
            </section>
            <section class="panel"><h2>${buyerText('orderSummary')}</h2>${lines}${summaryRows(totals)}</section>
        </div>`;
}

async function renderBuyerOrders() {
    const orderCode = new URLSearchParams(location.search).get('order');
    if (state.page === 'order' && orderCode) {
        await renderOrderDetails(orderCode);
        return;
    }
    app.innerHTML = '';
    const payment = new URLSearchParams(location.search).get('payment');
    if (payment === 'success') {
        app.innerHTML = `<section class="panel app-alert"><h1>${buyerText('paymentReturn')}</h1><p>${buyerText('paymentReturnNote')}</p></section>`;
    } else if (payment === 'cancel') {
        app.innerHTML = `<section class="panel app-alert"><h1>${buyerText('checkoutCancelled')}</h1><p>${buyerText('checkoutCancelledNote')}</p></section>`;
    }
    const orders = await queryOrThrow(state.supabase.from('orders')
        .select('order_code, final_total, order_status, payment_status, payment_method, created_at')
        .eq('buyer_id', state.profile.id)
        .order('created_at', { ascending: false }));
    const rows = orders.map((order) => `<tr><td><a href="?page=order&order=${encodeURIComponent(order.order_code)}" data-page="order" data-order="${escapeHtml(order.order_code)}">${escapeHtml(order.order_code)}</a><span class="table-secondary">${dateLabel(order.created_at)}</span></td>
        <td>${money(order.final_total)}</td><td>${escapeHtml(buyerPaymentMethod(order.payment_method))}</td><td>${buyerOrderStatusBadge(order.order_code, order.order_status)}</td><td>${buyerPaymentStatusBadge(order.order_code, order.payment_status)}</td><td><div class="app-table-actions">${shouldShowOrderTracking(order.order_status) ? `<a class="button secondary" href="?page=order&order=${encodeURIComponent(order.order_code)}" data-page="order" data-order="${escapeHtml(order.order_code)}">${buyerText('trackOrder')}</a>` : ''}${['delivered', 'cancelled'].includes(order.order_status) ? `<button type="button" class="button secondary" data-action="reorder" data-order="${escapeHtml(order.order_code)}">${buyerText('orderAgain')}</button>` : ''}</div></td></tr>`);
    app.insertAdjacentHTML('beforeend', `<section class="panel section"><div class="page-heading"><h1>${buyerText('ordersTitle')}</h1></div>${table([buyerText('order'), buyerText('total'), buyerText('payment'), buyerText('deliveryStatus'), buyerText('paymentStatus'), buyerText('actions')], rows, buyerText('noOrders'))}</section>`);
}

async function renderOrderDetails(orderCode) {
    const { data: order, error } = await state.supabase.from('orders')
        .select('*').eq('order_code', orderCode).maybeSingle();
    if (error) throw error;
    if (!order) {
        app.innerHTML = `<section class="panel"><h1>${buyerText('orderNotFound')}</h1><a href="?page=orders" data-page="orders">${buyerText('backToOrders')}</a></section>`;
        return;
    }
    const [sales, events, returnRequests] = await Promise.all([
        queryOrThrow(state.supabase.from('sales').select('*').eq('order_code', orderCode)),
        queryOrThrow(state.supabase.from('payment_history').select('*').eq('order_code', orderCode).order('created_at')),
        queryOrThrow(state.supabase.from('return_requests').select('*').eq('order_code', orderCode).order('requested_at', { ascending: false })),
    ]);
    const returnRequestIds = returnRequests.map((request) => request.id);
    const returnRequestItems = returnRequestIds.length
        ? await queryOrThrow(state.supabase.from('return_request_items').select('*').in('return_request_id', returnRequestIds))
        : [];
    const productIds = [...new Set(sales.map((line) => line.vegetable_id))];
    const vegetables = productIds.length
        ? await queryOrThrow(state.supabase.from('vegetables').select('id, name, unit').in('id', productIds))
        : [];
    const nameById = new Map(vegetables.map((vegetable) => [vegetable.id, vegetable]));
    const salesById = new Map(sales.map((line) => [line.id, line]));
    const returnRequestById = new Map(returnRequests.map((request) => [request.id, request]));
    const returnableQuantityBySale = new Map(sales.map((line) => [line.id, Number(line.quantity)]));
    returnRequestItems.forEach((item) => {
        const request = returnRequestById.get(item.return_request_id);
        if (!request || request.status === 'rejected') return;
        const remaining = returnableQuantityBySale.get(item.sales_id) || 0;
        returnableQuantityBySale.set(item.sales_id, remaining - Number(item.quantity));
    });
    const lineRows = sales.map((line) => {
        const product = nameById.get(line.vegetable_id);
        return `<tr><td>${escapeHtml(product?.name || buyerText('produce'))}</td><td>${formatQuantity(line.quantity)} ${escapeHtml(product?.unit || '')}</td><td>${money(line.unit_price)}</td><td>${money(line.total)}</td></tr>`;
    });
    const eventRows = events.map((event) => `<tr><td>${dateLabel(event.created_at)}</td><td>${escapeHtml(event.event_type.replaceAll('_', ' '))}</td><td>${buyerStatusBadge(event.payment_status)}</td><td>${money(event.amount)}</td><td>${escapeHtml(event.payment_reference || '—')}</td><td>${escapeHtml(event.details || '—')}</td></tr>`);
    const returnRows = returnRequests.map((request) => {
        const items = returnRequestItems
            .filter((item) => item.return_request_id === request.id)
            .map((item) => {
                const sale = salesById.get(item.sales_id);
                const product = sale ? nameById.get(sale.vegetable_id) : null;
                return `${product?.name || buyerText('produce')} × ${formatQuantity(item.quantity)} ${product?.unit || ''}`;
            })
            .join(', ');
        return `<tr><td>${dateTimeLabel(request.requested_at)}</td><td>${escapeHtml(items || '—')}</td><td>${escapeHtml(buyerText(request.reason === 'damaged' ? 'damaged' : 'qualityIssue'))}</td><td>${statusBadge(request.status)}</td><td>${escapeHtml(request.staff_notes || request.notes || '—')}</td></tr>`;
    });
    const returnableLines = isOrderReturnWindowOpen(order)
        ? sales.filter((line) => (returnableQuantityBySale.get(line.id) || 0) > 0)
        : [];
    const returnWindowMessage = order.order_status !== 'delivered'
        ? 'returnNotDelivered'
        : isOrderReturnWindowOpen(order) ? 'returnWindowOpen' : 'returnWindowClosed';
    const returnSection = `
        <section class="panel section">
            <h2>${buyerText('returnRequests')}</h2>
            <p>${buyerText(returnWindowMessage)}</p>
            ${returnableLines.length ? `
                <form class="app-inline-form" data-form="return-request">
                    <input type="hidden" name="orderCode" value="${escapeHtml(order.order_code)}">
                    <div class="field"><label for="return-sales-id">${buyerText('items')}</label><select id="return-sales-id" name="salesId" data-return-sale-select required>${returnableLines.map((line) => {
                        const product = nameById.get(line.vegetable_id);
                        const remaining = returnableQuantityBySale.get(line.id);
                        return `<option value="${line.id}" data-max="${remaining.toFixed(2)}">${escapeHtml(product?.name || buyerText('produce'))} — ${formatQuantity(remaining)} ${escapeHtml(product?.unit || '')} ${buyerText('availableQuantity')}</option>`;
                    }).join('')}</select></div>
                    <div class="field"><label for="return-quantity">${buyerText('quantity')}</label><input id="return-quantity" name="quantity" type="number" min="0.01" max="${returnableQuantityBySale.get(returnableLines[0].id).toFixed(2)}" step="0.01" value="0.01" required></div>
                    <div class="field"><label for="return-reason">${buyerText('returnReason')}</label><select id="return-reason" name="reason" required><option value="damaged">${buyerText('damaged')}</option><option value="quality_issue">${buyerText('qualityIssue')}</option></select></div>
                    <div class="field"><label for="return-notes">${buyerText('returnNotes')}</label><textarea id="return-notes" name="notes" maxlength="2000"></textarea></div>
                    <p class="muted">${buyerText('returnRequestNotice')}</p>
                    <button type="submit">${buyerText('submitReturnRequest')}</button>
                </form>` : ''}
            ${table([buyerText('date'), buyerText('items'), buyerText('returnReason'), buyerText('status'), buyerText('details')], returnRows, buyerText('noReturnRequests'))}
        </section>`;
    app.innerHTML = `
        <div class="page-heading"><h1>${buyerText('order')} ${escapeHtml(order.order_code)}</h1><div class="app-actions">${['delivered', 'cancelled'].includes(order.order_status) ? `<button type="button" class="button" data-action="reorder" data-order="${escapeHtml(order.order_code)}">${buyerText('orderAgain')}</button>` : ''}<a class="button secondary" href="?page=orders" data-page="orders">${buyerText('backToOrders')}</a></div></div>
        <section class="stats"><article class="card"><span class="muted">${buyerText('deliveryStatus')}</span><strong class="stat-value">${buyerOrderStatusBadge(order.order_code, order.order_status)}</strong></article><article class="card"><span class="muted">${buyerText('paymentStatus')}</span><strong class="stat-value">${buyerPaymentStatusBadge(order.order_code, order.payment_status)}</strong></article><article class="card"><span class="muted">${buyerText('total')}</span><strong class="stat-value">${money(order.final_total)}</strong></article></section>
        ${buyerOrderTracker(order)}
        <section class="panel section"><h2>${buyerText('deliveryDetails')}</h2><p class="app-delivery-address">${escapeHtml(order.delivery_address)}</p><p><strong>${buyerText('payment')}:</strong> ${escapeHtml(buyerPaymentMethod(order.payment_method))}</p><p><strong>${buyerText('placed')}:</strong> ${dateLabel(order.created_at)}</p></section>
        <section class="panel section"><h2>${buyerText('items')}</h2>${table([buyerText('produce'), buyerText('quantity'), buyerText('unitPrice'), buyerText('lineTotal')], lineRows)}</section>
        ${returnSection}
        <section class="panel section app-summary"><h2>${buyerText('orderTotal')}</h2>${summaryRows({ subtotal: order.subtotal, discountPercentage: order.discount_percentage, discountAmount: order.discount_amount, deliveryFee: order.delivery_fee, finalTotal: order.final_total })}</section>
        <section class="panel section"><h2>${buyerText('paymentHistory')}</h2>${table([buyerText('date'), buyerText('event'), buyerText('paymentStatus'), buyerText('amount'), buyerText('reference'), buyerText('details')], eventRows, buyerText('noPaymentEvents'))}</section>`;
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
                <div class="field"><label for="vegetable-name">Name</label><input id="vegetable-name" name="name" maxlength="120" required></div>
                <div class="field"><label for="vegetable-variety">Variety</label><input id="vegetable-variety" name="variety" maxlength="120"></div>
                <div class="field"><label for="vegetable-unit">Unit</label><input id="vegetable-unit" name="unit" maxlength="30" value="kg" required></div>
                <div class="field"><label for="vegetable-price">Price (₱)</label><input id="vegetable-price" name="price" type="number" min="0" step="0.01" required></div>
                <div class="field field-wide"><label for="vegetable-description">Description</label><textarea id="vegetable-description" name="description"></textarea></div>
                <div class="field field-wide"><label for="vegetable-photo">Vegetable photo</label><input id="vegetable-photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp" required><small>Required for new vegetables. JPEG, PNG, or WebP; maximum 5 MB. When editing, leave empty to keep the current photo.</small><div class="vegetable-photo-preview" data-vegetable-photo-preview aria-live="polite"></div></div>
                <div class="app-actions"><button type="submit">Save vegetable</button><button type="button" class="button secondary" data-action="clear-vegetable">Clear</button></div>
            </form>
        </section>
        <section class="panel section"><h2>Catalog</h2>${table(['Name', 'Variety', 'Price', 'Stock', 'Status', 'Actions'], rows, 'No vegetables in the catalog.')}</section>`;
}

async function renderInventory(products) {
    state.products = products;
    const lots = await queryOrThrow(state.supabase.from('inventory_lots')
        .select('id, vegetable_id, harvest_id, source_type, received_on, quantity_remaining')
        .gt('quantity_remaining', 0));
    const productById = new Map(products.map((item) => [item.id, item]));
    const fifoLots = sortFifoLots(lots, (vegetableId) => (
        productById.get(vegetableId)?.name || ''
    ));
    const fifoPositionByVegetable = new Map();
    const lotRows = fifoLots.map((lot) => {
        const product = productById.get(lot.vegetable_id);
        const fifoPosition = (fifoPositionByVegetable.get(lot.vegetable_id) || 0) + 1;
        fifoPositionByVegetable.set(lot.vegetable_id, fifoPosition);
        const source = lot.source_type === 'opening_balance'
            ? 'Opening balance'
            : lot.source_type === 'harvest'
                ? `Harvest #${lot.harvest_id}`
                : 'Manual addition';
        const received = lot.source_type === 'opening_balance'
            ? 'Before FIFO tracking'
            : dateLabel(lot.received_on);
        return `<tr><td>${fifoPosition}</td><td>${escapeHtml(product?.name || 'Produce')}</td>
            <td>${escapeHtml(source)}</td><td>${escapeHtml(received)}</td>
            <td>${Number(lot.quantity_remaining).toFixed(2)} ${escapeHtml(product?.unit || '')}</td></tr>`;
    });
    const rows = products.map((item) => `<tr><td>${escapeHtml(item.name)}</td><td>${Number(item.quantity).toFixed(2)} ${escapeHtml(item.unit)}</td><td>${item.quantity <= 5 ? '<span class="app-status pending_payment">Low stock</span>' : '<span class="app-status paid">In stock</span>'}</td></tr>`);
    app.innerHTML = `
        <section class="module-heading"><div><p class="module-eyebrow">Farm operations</p><h1>Inventory</h1><p>Adjust available stock. FIFO uses the oldest stock batch first when orders are placed.</p></div></section>
        <section class="panel section"><h2>Set stock quantity</h2><form class="app-inline-form" data-form="inventory">
            <div class="field"><label for="inventory-vegetable">Vegetable</label><select id="inventory-vegetable" name="vegetableId" required>${products.map((item) => `<option value="${item.id}">${escapeHtml(item.name)}</option>`).join('')}</select></div>
            <div class="field"><label for="inventory-quantity">Quantity</label><input id="inventory-quantity" name="quantity" type="number" min="0" step="0.01" required></div>
            <button type="submit">Save inventory</button>
        </form></section>
        <section class="panel section"><h2>Current stock</h2>${table(['Vegetable', 'Available quantity', 'Status'], rows, 'No vegetables are available.')}</section>
        <section class="panel section"><h2>FIFO stock batches</h2><p class="app-muted">Each vegetable's batches are ordered oldest first; orders draw from position 1 first.</p>${table(['FIFO position', 'Vegetable', 'Batch source', 'Received', 'Remaining'], lotRows, 'No FIFO stock batches are available.')}</section>`;
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
            <div class="field"><label for="planting-vegetable">Vegetable</label><select id="planting-vegetable" name="vegetableId" required>${state.products.filter((item) => item.active).map((item) => `<option value="${item.id}">${escapeHtml(item.name)}</option>`).join('')}</select></div>
            <div class="field"><label for="planting-quantity">Quantity planted</label><input id="planting-quantity" name="quantity" type="number" min="0.01" step="0.01" required></div>
            <div class="field"><label for="planting-date">Planted on</label><input id="planting-date" name="plantedOn" type="date" value="${dateInputValue()}" required></div>
            <div class="field"><label for="planting-expected-date">Expected harvest date</label><input id="planting-expected-date" name="expectedHarvestOn" type="date"></div>
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
            <div class="field"><label for="harvest-planting">Growing planting</label><select id="harvest-planting" name="plantingId" required>${growing.map((row) => `<option value="${row.id}">${escapeHtml(growingNameById.get(row.vegetable_id) || 'Produce')} · #${row.id}</option>`).join('')}</select></div>
            <div class="field"><label for="harvest-quantity">Harvest quantity</label><input id="harvest-quantity" name="quantity" type="number" min="0.01" step="0.01" required></div>
            <div class="field"><label for="harvest-date">Harvested on</label><input id="harvest-date" name="harvestedOn" type="date" value="${dateInputValue()}" required></div>
            <div class="field field-wide"><label for="harvest-notes">Quality notes</label><textarea id="harvest-notes" name="qualityNotes" maxlength="4000"></textarea></div>
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
            <div class="field"><label for="quality-harvest">Active harvest</label><select id="quality-harvest" name="harvestId" required>${harvests.map((row) => `<option value="${row.id}">${escapeHtml(harvestName.get(row.id))}</option>`).join('')}</select></div>
            <div class="field"><label for="quality-grade">Grade</label><select id="quality-grade" name="grade" required><option>A</option><option>B</option><option>C</option><option>Rejected</option></select></div>
            <div class="field"><label for="quality-date">Inspection date</label><input id="quality-date" name="inspectedOn" type="date" value="${dateInputValue()}" required></div>
            <div class="field field-wide"><label for="quality-notes">Notes</label><textarea id="quality-notes" name="notes" maxlength="4000"></textarea></div>
            <button type="submit">Save inspection</button>
        </form></section>
        <section class="panel section"><h2>Filter inspections</h2><form class="app-inline-form" data-form="quality-filter">
            <div class="field"><label for="quality-search">Search</label><input id="quality-search" name="search" value="${escapeHtml(params.get('search') || '')}" placeholder="Produce, inspector, notes"></div>
            <div class="field"><label for="quality-filter-grade">Grade</label><select id="quality-filter-grade" name="grade"><option value="">All grades</option>${['A', 'B', 'C', 'Rejected'].map((value) => `<option value="${value}" ${grade === value ? 'selected' : ''}>${value}</option>`).join('')}</select></div>
            <div class="field"><label for="quality-from">From</label><input id="quality-from" name="from" type="date" value="${escapeHtml(from)}"></div><div class="field"><label for="quality-to">To</label><input id="quality-to" name="to" type="date" value="${escapeHtml(to)}"></div><button type="submit">Filter</button>
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
        queryOrThrow(state.supabase.from('orders').select('*')
            .order('created_at', { ascending: false }).limit(500)),
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
        const orderDay = dateInputValue(new Date(order.created_at));
        if (from && orderDay < from) return false;
        if (to && orderDay > to) return false;
        if (status && order.order_status !== status && order.payment_status !== status) return false;
        if (!search) return true;
        const buyer = profileMap.get(order.buyer_id);
        const lines = linesByOrder.get(order.order_code) || [];
        const productNames = lines.map((line) => productMap.get(line.vegetable_id)?.name || '').join(' ');
        return `${order.order_code} ${buyer?.name || ''} ${buyer?.email || ''} ${productNames}`.toLowerCase().includes(search);
    });
    const allocationRows = filteredOrders.length
        ? await queryAllRows(() => state.supabase.from('inventory_lot_allocations')
            .select('order_code, vegetable_id, inventory_lot_id, quantity, released_at')
            .in('order_code', filteredOrders.map((order) => order.order_code))
            .order('id'))
        : [];
    const lotIds = [...new Set(allocationRows.map((allocation) => allocation.inventory_lot_id))];
    const lots = lotIds.length
        ? await queryAllRows(() => state.supabase.from('inventory_lots')
            .select('id, source_type, harvest_id, received_on')
            .in('id', lotIds)
            .order('id'))
        : [];
    const lotById = new Map(lots.map((lot) => [lot.id, lot]));
    const allocationsByOrderProduct = new Map();
    allocationRows.forEach((allocation) => {
        const key = `${allocation.order_code}:${allocation.vegetable_id}`;
        const allocations = allocationsByOrderProduct.get(key) || [];
        allocations.push(allocation);
        allocationsByOrderProduct.set(key, allocations);
    });
    state.records = new Map(filteredOrders.map((order) => [String(order.order_code), order]));
    const rows = filteredOrders.map((order) => {
        const buyer = profileMap.get(order.buyer_id);
        const lines = linesByOrder.get(order.order_code) || [];
        const summary = lines.map((line) => {
            const product = productMap.get(line.vegetable_id);
            return `${product?.name || 'Produce'} × ${Number(line.quantity).toFixed(2)}`;
        }).join(', ');
        const fifoLines = lines.map((line) => {
            const product = productMap.get(line.vegetable_id);
            const allocations = allocationsByOrderProduct.get(`${order.order_code}:${line.vegetable_id}`) || [];
            if (!allocations.length) {
                return `<li><strong>${escapeHtml(product?.name || 'Produce')}</strong>: No FIFO allocation recorded</li>`;
            }
            const lotRows = allocations.map((allocation) => {
                const lot = lotById.get(allocation.inventory_lot_id);
                const source = lot?.source_type === 'harvest'
                    ? `Harvest #${lot.harvest_id}`
                    : lot?.source_type === 'manual_addition'
                        ? 'Manual stock addition'
                        : lot?.source_type === 'opening_balance'
                            ? 'Opening balance'
                            : 'Unknown batch';
                const received = lot?.source_type === 'opening_balance'
                    ? 'Before FIFO tracking'
                    : lot?.received_on ? dateLabel(lot.received_on) : '—';
                const restored = allocation.released_at ? ' · Restored' : '';
                return `<li>${escapeHtml(source)} — ${formatQuantity(allocation.quantity)} ${escapeHtml(product?.unit || '')} <span class="muted">(${escapeHtml(received)}${restored})</span></li>`;
            }).join('');
            return `<li><strong>${escapeHtml(product?.name || 'Produce')} × ${formatQuantity(line.quantity)}</strong><ul>${lotRows}</ul></li>`;
        }).join('');
        const fifoCell = `<details class="app-fifo-details"><summary>FIFO lots</summary>${fifoLines ? `<ul>${fifoLines}</ul>` : '<p>No order items to allocate.</p>'}</details>`;
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
            <td>${escapeHtml(summary || '—')}</td><td>${money(order.final_total)}</td><td>${fifoCell}</td>
            <td>${statusBadge(order.order_status)}<span class="table-secondary">${statusBadge(order.payment_status)}</span></td>
            <td><div class="app-table-actions">${allowed.length ? `<select aria-label="New delivery status" data-status-for="${escapeHtml(order.order_code)}"><option value="">Update status</option>${allowed.map((status) => `<option value="${status}">${status.replaceAll('_', ' ')}</option>`).join('')}</select>` : ''}
                ${canCollect ? `<button type="button" data-action="collect-cod" data-id="${escapeHtml(order.order_code)}">Confirm cash</button>` : ''}
                ${canCancel ? `<button type="button" class="button danger" data-action="cancel-order" data-id="${escapeHtml(order.order_code)}">Cancel</button>` : ''}
            </div></td></tr>`;
    });
    app.innerHTML = `
        <section class="module-heading"><div><p class="module-eyebrow">Order management</p><h1>Sales</h1><p>Review orders, delivery progress, payment status, and FIFO stock allocations.</p></div><a class="button secondary" href="?page=returns" data-page="returns">Return requests${state.pendingReturnCount ? ` (${state.pendingReturnCount})` : ''}</a></section>
        <section class="panel report-filters section"><form class="app-inline-form" data-form="sales-filter">
            <div class="field"><label for="sales-search">Search order / buyer / product</label><input id="sales-search" name="search" value="${escapeHtml(params.get('search') || '')}"></div>
            <div class="field"><label for="sales-status">Status</label><select id="sales-status" name="status"><option value="">All statuses</option>${['pending', 'confirmed', 'preparing', 'ready_for_delivery', 'out_for_delivery', 'delivered', 'cancelled', 'pending_payment', 'paid', 'failed', 'refunded'].map((value) => `<option value="${value}" ${status === value ? 'selected' : ''}>${value.replaceAll('_', ' ')}</option>`).join('')}</select></div>
            <div class="field"><label for="sales-from">From</label><input id="sales-from" name="from" type="date" value="${escapeHtml(from)}"></div><div class="field"><label for="sales-to">To</label><input id="sales-to" name="to" type="date" value="${escapeHtml(to)}"></div><button type="submit">Filter</button>
        </form></section>
        <section class="panel section"><h2>Orders</h2>${table(['Order', 'Buyer', 'Items', 'Total', 'FIFO allocation', 'Status', 'Actions'], rows, 'No orders match these filters.')}</section>`;
}

async function renderReturns() {
    const [returnRequests, sales, profiles, products] = await Promise.all([
        queryOrThrow(state.supabase.from('return_requests').select('*').order('requested_at', { ascending: false }).limit(500)),
        queryOrThrow(state.supabase.from('sales').select('id, vegetable_id')),
        queryOrThrow(state.supabase.from('profiles').select('id, name, email')),
        getProducts(false),
    ]);
    const returnRequestItems = returnRequests.length
        ? await queryOrThrow(state.supabase.from('return_request_items')
            .select('*').in('return_request_id', returnRequests.map((request) => request.id)))
        : [];
    const profileMap = new Map(profiles.map((profile) => [profile.id, profile]));
    const productMap = new Map(products.map((product) => [product.id, product]));
    const salesMap = new Map(sales.map((sale) => [sale.id, sale]));
    const itemsByRequest = new Map();
    returnRequestItems.forEach((item) => {
        const items = itemsByRequest.get(item.return_request_id) || [];
        items.push(item);
        itemsByRequest.set(item.return_request_id, items);
    });
    const rows = returnRequests.map((request) => {
        const buyer = profileMap.get(request.buyer_id);
        const itemSummary = (itemsByRequest.get(request.id) || [])
            .map((item) => {
                const sale = salesMap.get(item.sales_id);
                const product = sale ? productMap.get(sale.vegetable_id) : null;
                return `${product?.name || 'Produce'} × ${formatQuantity(item.quantity)} ${product?.unit || ''}`;
            })
            .join(', ');
        const actions = request.status === 'pending'
            ? `<button type="button" data-action="update-return-request" data-id="${request.id}" data-status="approved">Approve</button><button type="button" class="button danger" data-action="update-return-request" data-id="${request.id}" data-status="rejected">Reject</button>`
            : request.status === 'approved'
                ? `<button type="button" data-action="update-return-request" data-id="${request.id}" data-status="completed">Mark complete</button>`
                : '—';
        return `<tr><td><strong>${escapeHtml(request.order_code)}</strong><span class="table-secondary">${dateTimeLabel(request.requested_at)}</span></td>
            <td>${escapeHtml(buyer?.name || 'Buyer')}<span class="table-secondary">${escapeHtml(buyer?.email || '')}</span></td>
            <td>${escapeHtml(itemSummary || '—')}</td><td>${escapeHtml(request.reason === 'damaged' ? 'Damaged item' : 'Quality issue')}<span class="table-secondary">${escapeHtml(request.notes || '')}</span></td>
            <td>${statusBadge(request.status)}${request.staff_notes ? `<span class="table-secondary">${escapeHtml(request.staff_notes)}</span>` : ''}</td>
            <td><div class="app-table-actions">${actions}</div></td></tr>`;
    });
    app.innerHTML = `
        <section class="module-heading"><div><p class="module-eyebrow">Order management</p><h1>Return requests</h1><p>Review and update product-return requests from buyers.</p></div><a class="button secondary" href="?page=sales" data-page="sales">Back to Sales</a></section>
        <section class="panel section"><p>Approving a request does not issue a refund or restock inventory. Handle those steps manually before marking it complete.</p>${table(['Order / requested', 'Buyer', 'Items', 'Reason', 'Status / staff notes', 'Actions'], rows, 'No return requests.')}</section>`;
}

async function renderReports() {
    const params = new URLSearchParams(location.search);
    const from = params.get('from') || '';
    const to = params.get('to') || '';
    const search = (params.get('search') || '').toLowerCase();
    let query = state.supabase.from('orders').select('*').order('created_at', { ascending: false });
    if (from) query = query.gte('created_at', dateTimeBoundary(from));
    if (to) query = query.lte('created_at', dateTimeBoundary(to, true));
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
        <section class="panel report-filters section"><form class="app-inline-form" data-form="report-filter"><div class="field"><label for="report-search">Search order / buyer / product</label><input id="report-search" name="search" value="${escapeHtml(params.get('search') || '')}"></div><div class="field"><label for="report-from">From</label><input id="report-from" name="from" type="date" value="${escapeHtml(from)}"></div><div class="field"><label for="report-to">To</label><input id="report-to" name="to" type="date" value="${escapeHtml(to)}"></div><button type="submit">Filter</button><a class="button secondary" href="?page=reports" data-page="reports">Clear</a></form></section>
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
    if (from) query = query.gte('created_at', dateTimeBoundary(from));
    if (to) query = query.lte('created_at', dateTimeBoundary(to, true));
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
            <div class="field"><label for="activity-search">Search</label><input id="activity-search" name="search" value="${escapeHtml(search)}" placeholder="Action, record, details"></div>
            <div class="field"><label for="activity-from">From</label><input id="activity-from" name="from" type="date" value="${escapeHtml(from)}"></div><div class="field"><label for="activity-to">To</label><input id="activity-to" name="to" type="date" value="${escapeHtml(to)}"></div><button type="submit">Filter</button>
        </form></section>
        <section class="panel section"><h2>Activity records</h2>${table(['Date', 'User', 'Action', 'Record', 'Details'], rows, 'No matching activity records.')}
            <div class="app-pagination"><span class="muted">Showing ${count ? start + 1 : 0}–${Math.min(start + 25, count || 0)} of ${count || 0}</span><div class="app-actions">
                ${page > 1 ? `<button type="button" class="button secondary" data-action="activity-page" data-page-number="${page - 1}">Previous</button>` : ''}
                ${count > start + 25 ? `<button type="button" class="button secondary" data-action="activity-page" data-page-number="${page + 1}">Next</button>` : ''}
            </div></div>
        </section>`;
}

async function renderPage() {
    syncBuyerOrderStatusSubscription();
    syncReturnRequestSubscription();
    if (!state.supabase) {
        const page = guestPage(state.page);
        state.page = page;
        updateNavigation();
        if (page === 'home') renderLanding([], true);
        else renderAuth(page);
        return;
    }
    if (!state.session || !state.profile) {
        const page = guestPage(state.page);
        state.page = page;
        updateNavigation();
        if (page === 'home') {
            try {
                return renderLanding(await getPublicProducts());
            } catch (error) {
                showError(error);
                return renderLanding([]);
            }
        }
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
    if (page === 'home') {
        page = 'dashboard';
        state.page = page;
        const url = new URL(location.href);
        url.search = '?page=dashboard';
        history.replaceState({}, '', url);
    }
    const buyerPages = ['shop', 'product', 'cart', 'checkout', 'orders', 'order'];
    const staffPages = ['vegetables', 'plantings', 'harvests', 'inventory', 'sales', 'returns', 'quality', 'reports', 'activity'];
    if (state.profile.role === 'buyer' && staffPages.includes(page)) page = 'dashboard';
    if (isStaff() && buyerPages.includes(page)) page = 'dashboard';
    if (page === 'activity' && state.profile.role !== 'admin') page = 'dashboard';
    state.page = page;
    syncBuyerOrderStatusSubscription();
    document.body.classList.remove('auth-page');
    document.body.classList.remove('password-reset-page');
    updateNavigation();

    try {
        if (page === 'dashboard') return await renderDashboard();
        if (page === 'shop') {
            const [products, activeOrder] = await Promise.all([
                getProducts(true),
                getLatestActiveBuyerOrder(),
            ]);
            return renderShop(products, activeOrder);
        }
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
        if (page === 'returns') return await renderReturns();
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
    if (formName === 'dashboard-period') {
        const period = Number(values.period);
        if (![7, 30, 90].includes(period)) throw new Error('Choose a chart period of 7, 30, or 90 days.');
        const url = new URL(location.href);
        url.searchParams.set('page', 'dashboard');
        url.searchParams.set('period', String(period));
        history.pushState({}, '', url);
        state.page = 'dashboard';
        return await renderPage();
    }
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
        const redirectUrl = new URL('/', location.origin);
        redirectUrl.searchParams.set('page', 'reset-password');
        const { error } = await state.supabase.auth.resetPasswordForEmail(values.email.trim(), {
            redirectTo: redirectUrl.toString(),
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
        const alreadyInCart = Number(state.cart[product?.id] || 0);
        if (!product || !Number.isFinite(quantity) || quantity <= 0 || quantity + alreadyInCart > product.quantity) {
            throw new Error('Choose a quantity that is currently in stock.');
        }
        state.cart[product.id] = alreadyInCart + quantity;
        saveCart();
        updateShopCartUI();
        notify(`${product.name} ${buyerText('addedToCart')}`, 'success', false);
        notice.insertAdjacentHTML('beforeend', ` <a href="?page=cart" data-page="cart">${buyerText('openCart')} (${cartCount()})</a>`);
        return;
    }
    if (formName === 'update-cart') {
        const product = state.products.find((item) => String(item.id) === form.dataset.product);
        const quantity = Number(values.quantity);
        if (!product || !Number.isFinite(quantity) || quantity < 0 || quantity > product.quantity) {
            throw new Error(`${buyerText('quantity')}: ${buyerText('available')} stock only.`);
        }
        if (!quantity) delete state.cart[product.id];
        else state.cart[product.id] = quantity;
        saveCart();
        await renderCart();
        return;
    }
    if (formName === 'checkout') {
        const recipientName = values.recipientName.trim();
        const contactNumber = values.contactNumber.trim();
        const streetAddress = values.streetAddress.trim();
        const barangay = values.barangay.trim();
        const cityMunicipality = values.cityMunicipality.trim();
        const deliveryInstructions = values.deliveryInstructions.trim();
        if (!recipientName || !contactNumber || !streetAddress || !barangay || !cityMunicipality) {
            throw new Error(buyerText('completeDeliveryDetails'));
        }
        if (!/^(?=.*\d)[+0-9() .-]{7,20}$/.test(contactNumber)) {
            throw new Error(buyerText('invalidContactNumber'));
        }
        const checkoutToken = sessionStorage.getItem('harvest-checkout-token') || cartToken();
        sessionStorage.setItem('harvest-checkout-token', checkoutToken);
        const items = Object.entries(state.cart).map(([id, quantity]) => ({ id: Number(id), quantity: Number(quantity) }));
        const deliveryAddress = [
            `${buyerText('recipient')}: ${recipientName}`,
            `${buyerText('contactNumber')}: ${contactNumber}`,
            `${buyerText('address')}: ${[streetAddress, barangay, cityMunicipality].join(', ')}`,
            deliveryInstructions
                ? `${buyerText('deliveryInstructions')}: ${deliveryInstructions}`
                : '',
        ].filter(Boolean).join('\n');
        let result;
        try {
            result = await apiRequest('/api/orders', {
                action: 'checkout',
                items,
                deliveryAddress,
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
        notify(`${buyerText('orderPlaced')} ${result.order.order_code}`);
        const orderUrl = new URL(location.href);
        orderUrl.searchParams.set('page', 'order');
        orderUrl.searchParams.set('order', result.order.order_code);
        history.pushState({}, '', orderUrl);
        state.page = 'order';
        await renderPage();
        return;
    }
    if (formName === 'return-request') {
        const salesId = Number(values.salesId);
        const quantity = Number(values.quantity);
        if (!Number.isSafeInteger(salesId) || !Number.isFinite(quantity) || quantity <= 0) {
            throw new Error('Choose a valid order item and return quantity.');
        }
        const { error } = await state.supabase.rpc('create_return_request', {
            p_order_code: values.orderCode,
            p_items: [{ sales_id: salesId, quantity }],
            p_reason: values.reason,
            p_notes: values.notes.trim() || null,
        });
        if (error) throw error;
        notify(buyerText('returnRequestSent'));
        return await renderPage();
    }
    if (formName === 'vegetable') {
        const id = values.id;
        const selectedPhoto = form.elements.photo.files[0] || null;
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
        const photoExtension = getVegetablePhotoExtension(selectedPhoto);
        const previous = id ? state.records.get(String(id)) : null;
        const previousPhotoPath = previous?.image_path || null;
        let uploadedPhotoPath = null;
        if (selectedPhoto) {
            uploadedPhotoPath = `${state.profile.id}/${crypto.randomUUID()}.${photoExtension}`;
            const { error: uploadError } = await state.supabase.storage
                .from('vegetable-images')
                .upload(uploadedPhotoPath, selectedPhoto, {
                    contentType: selectedPhoto.type,
                    upsert: false,
                });
            if (uploadError) throw uploadError;
            record.image_path = uploadedPhotoPath;
        } else if (id) {
            record.image_path = previousPhotoPath;
        }
        const result = id
            ? await state.supabase.from('vegetables').update(record).eq('id', id)
            : await state.supabase.from('vegetables').insert(record);
        if (result.error) {
            if (uploadedPhotoPath) {
                const { error: cleanupError } = await state.supabase.storage
                    .from('vegetable-images').remove([uploadedPhotoPath]);
                if (cleanupError) {
                    throw new Error(`${result.error.message} The uploaded photo could not be cleaned up: ${cleanupError.message}`);
                }
            }
            throw result.error;
        }
        if (uploadedPhotoPath && previousPhotoPath) {
            const { error: cleanupError } = await state.supabase.storage
                .from('vegetable-images').remove([previousPhotoPath]);
            if (cleanupError) {
                console.error('The previous vegetable photo could not be removed:', cleanupError);
                notify('Vegetable saved, but the previous photo could not be removed.', 'error');
                return await renderPage();
            }
        }
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
    const quantityButton = target.closest('[data-quantity-step]');
    if (quantityButton instanceof HTMLButtonElement) {
        const control = quantityButton.closest('[data-quantity-control]');
        const input = control?.querySelector('.app-quantity-input');
        if (!(input instanceof HTMLInputElement)) return;
        const minimum = Number(input.min);
        const maximum = Number(input.max);
        const current = Number(input.value);
        const increment = Number(quantityButton.dataset.quantityStep);
        const startingQuantity = Number.isFinite(current) ? current : minimum;
        const nextQuantity = Math.max(minimum, Math.min(maximum, startingQuantity + increment));
        input.value = String(Math.round(nextQuantity * 100) / 100);
        updateQuantityStepper(input);
        return;
    }

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
    if (action.dataset.action === 'toggle-language') {
        state.language = state.language === 'en' ? 'fil' : 'en';
        localStorage.setItem(languageStorageKey, state.language);
        document.documentElement.lang = state.language === 'fil' ? 'fil' : 'en';
        updateNavigation();
        return await renderPage();
    }
    if (action.dataset.action === 'clear-shop-filters') {
        app.querySelector('[data-shop-search]').value = '';
        app.querySelector('[data-shop-category]').value = '';
        app.querySelector('[data-shop-in-stock]').checked = false;
        app.querySelector('[data-shop-sort]').value = 'name';
        return applyShopFilters();
    }
    if (action.dataset.action === 'reorder') {
        action.disabled = true;
        try {
            await reorderOrder(action.dataset.order);
        } finally {
            if (action.isConnected) action.disabled = false;
        }
        return;
    }
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
        notify(item.active ? 'Vegetable deactivated.' : 'Vegetable activated.', 'success', false);
        return await renderPage();
    }
    if (action.dataset.action === 'edit-vegetable') {
        const row = state.records.get(action.dataset.id);
        const form = app.querySelector('[data-form="vegetable"]');
        if (!row || !form) return;
        form.reset();
        form.elements.id.value = row.id;
        form.elements.name.value = row.name;
        form.elements.variety.value = row.variety || '';
        form.elements.unit.value = row.unit;
        form.elements.price.value = row.price;
        form.elements.description.value = row.description || '';
        form.elements.photo.required = false;
        const photoPreview = app.querySelector('[data-vegetable-photo-preview]');
        photoPreview.textContent = '';
        if (row.image_path && photoPreview) {
            photoPreview.innerHTML = `<img src="${escapeHtml(vegetablePhotoUrl(row.image_path))}" alt="Current photo of ${escapeHtml(row.name)}">`;
        }
        app.querySelector('#vegetable-form-title').textContent = `Edit ${row.name}`;
        form.scrollIntoView({ behavior: 'smooth' });
        return;
    }
    if (action.dataset.action === 'clear-vegetable') {
        const form = app.querySelector('[data-form="vegetable"]');
        form.reset();
        form.elements.photo.required = true;
        app.querySelector('[data-vegetable-photo-preview]').textContent = '';
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
    if (action.dataset.action === 'update-return-request') {
        const status = action.dataset.status;
        let staffNotes = null;
        if (status === 'rejected') {
            const reason = window.prompt('Add a reason for rejecting this return request:');
            if (reason === null) return;
            staffNotes = reason.trim();
            if (!staffNotes) throw new Error('A rejection reason is required.');
        } else if (status === 'approved'
            && !window.confirm('Approve this request for manual handling? No refund or inventory changes will happen automatically.')) {
            return;
        } else if (status === 'completed'
            && !window.confirm('Confirm that any refund or payment adjustment and appropriate inventory update were handled manually.')) {
            return;
        }
        const { error } = await state.supabase.rpc('update_return_request', {
            p_request_id: Number(action.dataset.id),
            p_status: status,
            p_staff_notes: staffNotes,
        });
        if (error) throw error;
        notify(`Return request ${status}.`);
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
    const pageLink = target.closest('a[data-page]');
    if (pageLink && (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey)) return;
    if (pageLink) event.preventDefault();
    const accountMenu = document.querySelector('#account-menu');
    if (!accountMenu.contains(target)) accountMenu.open = false;
    try {
        await handleClick(target);
    } catch (error) {
        showError(error);
    }
});

document.addEventListener('input', (event) => {
    if (event.target instanceof HTMLInputElement
        && event.target.matches('[data-quantity-control] .app-quantity-input')) {
        updateQuantityStepper(event.target);
    }
});

document.addEventListener('input', (event) => {
    if (event.target instanceof HTMLInputElement && event.target.matches('[data-shop-search]')) {
        applyShopFilters();
    }
});

document.addEventListener('change', (event) => {
    const target = event.target;
    if ((target instanceof HTMLInputElement || target instanceof HTMLSelectElement)
        && target.matches('[data-shop-category], [data-shop-in-stock], [data-shop-sort]')) {
        applyShopFilters();
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
    const target = event.target;
    if (target instanceof HTMLSelectElement && target.matches('[data-return-sale-select]')) {
        const quantityInput = app.querySelector('#return-quantity');
        const maximum = Number(target.selectedOptions[0]?.dataset.max);
        if (quantityInput instanceof HTMLInputElement && Number.isFinite(maximum) && maximum > 0) {
            quantityInput.max = String(maximum);
            if (Number(quantityInput.value) > maximum) quantityInput.value = String(maximum);
        }
        return;
    }
    if (target instanceof HTMLInputElement && target.matches('[data-form="vegetable"] input[name="photo"]')) {
        const preview = app.querySelector('[data-vegetable-photo-preview]');
        const file = target.files[0];
        if (!preview) return;
        if (!file) {
            preview.textContent = '';
            return;
        }
        try {
            getVegetablePhotoExtension(file);
            preview.innerHTML = `<img src="${escapeHtml(URL.createObjectURL(file))}" alt="Selected vegetable photo">`;
        } catch (error) {
            target.value = '';
            preview.textContent = '';
            showError(error);
        }
        return;
    }
    if (!(target instanceof HTMLSelectElement) || !target.matches('[data-status-for]')) return;
    try {
        await handleOrderStatusChange(target);
    } catch (error) {
        showError(error);
        await renderPage();
    }
});

document.querySelector('[data-nav-toggle]').addEventListener('click', (event) => {
    const button = event.currentTarget;
    const nav = document.querySelector('#primary-navigation');
    const isOperationsUser = document.body.classList.contains('operations-layout');
    if (isOperationsUser && window.matchMedia('(min-width: 901px)').matches) {
        const isCollapsed = document.body.classList.toggle('sidebar-collapsed');
        button.setAttribute('aria-expanded', String(!isCollapsed));
        button.setAttribute('aria-label', isCollapsed ? 'Expand navigation' : 'Collapse navigation');
        return;
    }

    const isMobile = window.matchMedia(isOperationsUser ? '(max-width: 900px)' : '(max-width: 1180px)').matches;
    if (!isMobile) return;
    const open = nav.classList.toggle('is-open');
    button.setAttribute('aria-expanded', String(open));
    button.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
});

window.matchMedia('(max-width: 900px)').addEventListener('change', (event) => {
    const nav = document.querySelector('#primary-navigation');
    const button = document.querySelector('[data-nav-toggle]');
    nav.classList.remove('is-open');
    if (document.body.classList.contains('operations-layout')) {
        document.body.classList.remove('sidebar-collapsed');
    }
    const expanded = document.body.classList.contains('operations-layout') && !event.matches;
    button.setAttribute('aria-expanded', String(expanded));
    button.setAttribute('aria-label', event.matches
        ? 'Open navigation'
        : expanded
            ? 'Collapse navigation'
            : 'Open navigation');
});

document.querySelector('#primary-navigation').addEventListener('click', (event) => {
    if (!(event.target instanceof Element) || !event.target.closest('a')) return;
    const isOperationsUser = document.body.classList.contains('operations-layout');
    const isMobile = window.matchMedia(isOperationsUser ? '(max-width: 900px)' : '(max-width: 1180px)').matches;
    if (!isMobile) return;
    document.querySelector('#primary-navigation').classList.remove('is-open');
    const button = document.querySelector('[data-nav-toggle]');
    button.setAttribute('aria-expanded', 'false');
    button.setAttribute('aria-label', 'Open navigation');
});

document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    const accountMenu = document.querySelector('#account-menu');
    if (accountMenu.open) {
        accountMenu.open = false;
        accountMenu.querySelector('summary').focus();
        return;
    }
    const nav = document.querySelector('#primary-navigation');
    if (!nav.classList.contains('is-open')) return;
    nav.classList.remove('is-open');
    const button = document.querySelector('[data-nav-toggle]');
    button.setAttribute('aria-expanded', 'false');
    button.setAttribute('aria-label', 'Open navigation');
    button.focus();
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
    document.querySelector('#account-menu').open = false;
    state.page = new URLSearchParams(location.search).get('page') || 'home';
    void renderPage();
});

window.addEventListener('scroll', () => {
    const accountMenu = document.querySelector('#account-menu');
    if (accountMenu.open) accountMenu.open = false;
}, { passive: true });

async function start() {
    document.querySelector('#current-year').textContent = new Date().getFullYear();
    if (!supabaseUrl || !supabaseAnonKey) {
        updateNavigation();
        await renderPage();
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
