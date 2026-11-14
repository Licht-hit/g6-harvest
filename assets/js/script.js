document.addEventListener('submit', (event) => {
    const form = event.target;

    if (form instanceof HTMLFormElement && form.matches('[data-cart-add]')) {
        try {
            sessionStorage.setItem('harvest-cart-scroll', JSON.stringify({
                path: window.location.pathname + window.location.search,
                x: window.scrollX,
                y: window.scrollY,
            }));
        } catch (error) {
            console.error('Unable to preserve the page position after adding to cart.', error);
        }
    }

    if (form instanceof HTMLFormElement) {
        if (form.dataset.returnReviewPrompt === 'reject') {
            const staffNotes = form.querySelector('[name="staff_notes"]');
            const reason = window.prompt('Add a reason for rejecting this return request:');
            if (reason === null) {
                event.preventDefault();
                return;
            }
            if (!reason.trim()) {
                window.alert('A rejection reason is required.');
                event.preventDefault();
                return;
            }
            if (!(staffNotes instanceof HTMLInputElement)) {
                console.error('The return rejection form is missing its staff-notes field.');
                event.preventDefault();
                return;
            }
            staffNotes.value = reason.trim();
        }

        const orderStatus = form.querySelector('[name="order_status"]');
        const confirmMessage = form.dataset.confirm
            || (form.hasAttribute('data-confirm-cancel') && orderStatus?.value === 'cancelled'
                ? 'Cancel this order? Reserved inventory will be returned only when cancellation is safe.'
                : '');
        if (confirmMessage && !window.confirm(confirmMessage)) {
            event.preventDefault();
        }
    }
});

const cartAddedUrl = new URL(window.location.href);
if (cartAddedUrl.searchParams.get('cart_added') === '1') {
    let scrollState = null;
    try {
        scrollState = sessionStorage.getItem('harvest-cart-scroll');
        sessionStorage.removeItem('harvest-cart-scroll');
    } catch (error) {
        console.error('Unable to read the saved page position after adding to cart.', error);
    }
    cartAddedUrl.searchParams.delete('cart_added');
    window.history.replaceState({}, '', cartAddedUrl);

    if (scrollState) {
        try {
            const position = JSON.parse(scrollState);
            if (
                position.path === window.location.pathname + window.location.search
                && Number.isFinite(position.x)
                && Number.isFinite(position.y)
            ) {
                document.body.classList.add('cart-add-confirmed');
                window.requestAnimationFrame(() => {
                    window.requestAnimationFrame(() => window.scrollTo(position.x, position.y));
                });
            }
        } catch (error) {
            console.error('Unable to restore the page position after adding to cart.', error);
        }
    }
}

document.addEventListener('error', (event) => {
    const image = event.target;
    if (
        !(image instanceof HTMLImageElement)
        || !image.matches('.product-image img, .product-detail-image img')
    ) {
        return;
    }

    image.hidden = true;
    const fallback = image.parentElement.querySelector(
        '.product-image-fallback, .product-detail-image-fallback'
    );
    if (fallback) {
        fallback.hidden = false;
    }
}, true);

function updateQuantityControls(input) {
    const control = input.closest('[data-quantity-control]');
    if (!control) {
        return;
    }

    const quantity = Number(input.value);
    const minimum = Number(input.min);
    const maximum = Number(input.max);
    const decreaseButton = control.querySelector('[data-quantity-step="-1"]');
    const increaseButton = control.querySelector('[data-quantity-step="1"]');

    decreaseButton.disabled = !Number.isFinite(quantity) || quantity <= minimum;
    increaseButton.disabled = !Number.isFinite(quantity) || quantity >= maximum;
}

document.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof Element)) {
        return;
    }

    const printButton = target.closest('[data-print]');
    if (printButton instanceof HTMLButtonElement) {
        window.print();
        return;
    }

    const button = target.closest('[data-quantity-step]');
    if (!(button instanceof HTMLButtonElement)) {
        return;
    }

    const control = button.closest('[data-quantity-control]');
    const input = control && control.querySelector('.quantity-input');
    if (!(input instanceof HTMLInputElement)) {
        return;
    }

    const minimum = Number(input.min);
    const maximum = Number(input.max);
    const current = Number(input.value);
    const increment = Number(button.dataset.quantityStep);
    const startingQuantity = Number.isFinite(current) ? current : minimum;
    input.value = String(Math.max(minimum, Math.min(maximum, startingQuantity + increment)));
    updateQuantityControls(input);
});

document.addEventListener('input', (event) => {
    const input = event.target;
    if (input instanceof HTMLInputElement && input.matches('[data-quantity-control] .quantity-input')) {
        updateQuantityControls(input);
    }
});

document.addEventListener('change', (event) => {
    const input = event.target;
    if (!(input instanceof HTMLInputElement) || !input.matches('[data-quantity-control] .quantity-input')) {
        return;
    }

    const minimum = Number(input.min);
    const maximum = Number(input.max);
    const quantity = Number(input.value);
    const roundedQuantity = Number.isFinite(quantity)
        ? Math.round(quantity * 100) / 100
        : minimum;
    input.value = String(Math.max(minimum, Math.min(maximum, roundedQuantity)));
    updateQuantityControls(input);
});

document.querySelectorAll('[data-quantity-control] .quantity-input').forEach(updateQuantityControls);

document.querySelectorAll('[data-nav-toggle]').forEach((toggle) => {
    const navigation = document.getElementById(toggle.getAttribute('aria-controls'));
    const isOperationsLayout = document.body.classList.contains('operations-layout');
    const navigationBreakpoint = isOperationsLayout ? 900 : 1180;
    const mobileNavigation = window.matchMedia(`(max-width: ${navigationBreakpoint}px)`);

    const setToggleState = (expanded, label) => {
        toggle.setAttribute('aria-expanded', String(expanded));
        toggle.setAttribute('aria-label', label);
    };

    const closeNavigation = (restoreFocus = false) => {
        navigation?.classList.remove('is-open');
        setToggleState(false, 'Open navigation');
        if (restoreFocus) {
            toggle.focus();
        }
    };

    const syncNavigationForViewport = (event) => {
        navigation?.classList.remove('is-open');
        if (isOperationsLayout) {
            document.body.classList.remove('sidebar-collapsed');
        }

        const isCollapsed = isOperationsLayout && document.body.classList.contains('sidebar-collapsed');
        const isExpanded = !event.matches && !isCollapsed;
        const label = event.matches
            ? 'Open navigation'
            : isOperationsLayout
                ? (isCollapsed ? 'Expand navigation' : 'Collapse navigation')
                : 'Close navigation';
        setToggleState(isExpanded, label);
    };

    syncNavigationForViewport(mobileNavigation);
    mobileNavigation.addEventListener('change', syncNavigationForViewport);

    toggle.addEventListener('click', () => {
        if (document.body.classList.contains('operations-layout')
            && window.matchMedia('(min-width: 901px)').matches
        ) {
            const isCollapsed = document.body.classList.toggle('sidebar-collapsed');
            setToggleState(!isCollapsed, isCollapsed ? 'Expand navigation' : 'Collapse navigation');
            return;
        }

        if (!navigation || !mobileNavigation.matches) {
            return;
        }

        const isOpen = navigation.classList.toggle('is-open');
        setToggleState(isOpen, isOpen ? 'Close navigation' : 'Open navigation');
    });

    navigation?.addEventListener('click', (event) => {
        if (
            mobileNavigation.matches
            && event.target instanceof Element
            && event.target.closest('a')
        ) {
            closeNavigation();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (
            event.key === 'Escape'
            && mobileNavigation.matches
            && navigation?.classList.contains('is-open')
        ) {
            closeNavigation(true);
        }
    });
});

document.querySelectorAll('[data-password-toggle]').forEach((toggle) => {
    toggle.addEventListener('click', () => {
        const input = document.getElementById(toggle.dataset.passwordToggle);
        if (!(input instanceof HTMLInputElement)) {
            return;
        }

        const showPassword = input.type === 'password';
        input.type = showPassword ? 'text' : 'password';
        toggle.textContent = showPassword ? 'Hide' : 'Show';
        toggle.setAttribute('aria-label', showPassword ? 'Hide password' : 'Show password');
    });
});

const shopProducts = Array.from(document.querySelectorAll('[data-shop-product]'));
if (shopProducts.length) {
    const searchInput = document.querySelector('[data-shop-search]');
    const categorySelect = document.querySelector('[data-shop-category]');
    const availabilitySelect = document.querySelector('[data-shop-availability]');
    const sortSelect = document.querySelector('[data-shop-sort]');
    const resultCount = document.querySelector('[data-shop-count]');
    const noResults = document.querySelector('[data-shop-no-results]');
    const resetButton = document.querySelector('[data-shop-reset]');
    const productGrid = shopProducts[0].parentElement;

    shopProducts.forEach((product, index) => {
        product.dataset.originalOrder = String(index);
    });

    const updateShopProducts = () => {
        const searchTerm = searchInput.value.trim().toLocaleLowerCase();
        const category = categorySelect.value;
        const availability = availabilitySelect.value;
        const sortBy = sortSelect.value;
        const matchingProducts = shopProducts.filter((product) => {
            const matchesSearch = product.dataset.search.toLocaleLowerCase().includes(searchTerm);
            const matchesCategory = category === 'all' || product.dataset.category === category;
            const hasStock = Number(product.dataset.stock) > 0;
            const matchesAvailability = availability === 'all'
                || (availability === 'available' && hasStock)
                || (availability === 'out' && !hasStock);

            product.hidden = !matchesSearch || !matchesCategory || !matchesAvailability;
            return !product.hidden;
        });

        matchingProducts.sort((first, second) => {
            if (sortBy === 'name') {
                return first.dataset.name.localeCompare(second.dataset.name);
            }
            if (sortBy === 'price-low') {
                return Number(first.dataset.price) - Number(second.dataset.price);
            }
            if (sortBy === 'price-high') {
                return Number(second.dataset.price) - Number(first.dataset.price);
            }
            if (sortBy === 'stock-high') {
                return Number(second.dataset.stock) - Number(first.dataset.stock)
                    || first.dataset.name.localeCompare(second.dataset.name);
            }
            return Number(first.dataset.originalOrder) - Number(second.dataset.originalOrder);
        });

        matchingProducts.forEach((product) => productGrid.append(product));
        if (resultCount) {
            resultCount.textContent = `${matchingProducts.length} of ${shopProducts.length} produce items`;
        }
        if (noResults) {
            noResults.hidden = matchingProducts.length > 0;
        }
    };

    searchInput.addEventListener('input', updateShopProducts);
    categorySelect.addEventListener('change', updateShopProducts);
    availabilitySelect.addEventListener('change', updateShopProducts);
    sortSelect.addEventListener('change', updateShopProducts);
    if (resetButton) {
        resetButton.addEventListener('click', () => {
            searchInput.value = '';
            categorySelect.value = 'all';
            availabilitySelect.value = 'all';
            sortSelect.value = 'featured';
            updateShopProducts();
            searchInput.focus();
        });
    }
}

function renderDashboardChart(canvas) {
    let labels;
    let values;
    try {
        labels = JSON.parse(canvas.dataset.labels || '[]');
        values = JSON.parse(canvas.dataset.values || '[]');
    } catch (error) {
        console.error('Unable to read dashboard chart data.', error);
        return;
    }

    if (
        !Array.isArray(labels)
        || !Array.isArray(values)
        || labels.length !== values.length
        || values.some((value) => !Number.isFinite(Number(value)))
    ) {
        console.error('Dashboard chart data is invalid.');
        return;
    }

    const bounds = canvas.getBoundingClientRect();
    if (bounds.width === 0 || bounds.height === 0) {
        return;
    }

    const ratio = Math.max(1, window.devicePixelRatio || 1);
    canvas.width = Math.round(bounds.width * ratio);
    canvas.height = Math.round(bounds.height * ratio);
    const context = canvas.getContext('2d');
    if (!context) {
        return;
    }
    context.setTransform(ratio, 0, 0, ratio, 0, 0);

    const width = bounds.width;
    const height = bounds.height;
    const chartType = canvas.dataset.chart;
    const palette = ['#218653', '#76a85b', '#e0b84c', '#5b9c9b', '#9a82b4', '#d98661', '#5478a5'];
    const ink = '#718078';
    const lineColor = '#e7eee7';
    const numericValues = values.map(Number);

    context.clearRect(0, 0, width, height);
    context.font = '11px system-ui, sans-serif';
    context.fillStyle = ink;

    if (chartType === 'doughnut') {
        const total = numericValues.reduce((sum, value) => sum + value, 0);
        if (total <= 0) {
            return;
        }

        const centerX = width / 2;
        const centerY = height / 2;
        const radius = Math.min(width, height) * .43;
        let startAngle = -Math.PI / 2;
        numericValues.forEach((value, index) => {
            if (value <= 0) {
                return;
            }
            const endAngle = startAngle + (value / total) * Math.PI * 2;
            context.beginPath();
            context.arc(centerX, centerY, radius, startAngle, endAngle);
            context.arc(centerX, centerY, radius * .62, endAngle, startAngle, true);
            context.closePath();
            context.fillStyle = palette[index % palette.length];
            context.fill();
            startAngle = endAngle;
        });
        context.textAlign = 'center';
        context.textBaseline = 'middle';
        context.fillStyle = '#24352b';
        context.font = '700 15px system-ui, sans-serif';
        context.fillText(String(total.toFixed(2)), centerX, centerY - 5);
        context.fillStyle = ink;
        context.font = '10px system-ui, sans-serif';
        context.fillText('in stock', centerX, centerY + 12);
        return;
    }

    if (chartType === 'horizontal-bar') {
        const left = Math.min(115, width * .38);
        const right = 42;
        const rowHeight = height / Math.max(1, labels.length);
        const plotWidth = width - left - right;
        const maximum = Math.max(1, ...numericValues);
        labels.forEach((label, index) => {
            const y = index * rowHeight + rowHeight / 2;
            const barWidth = Math.max(0, (numericValues[index] / maximum) * plotWidth);
            context.textAlign = 'right';
            context.textBaseline = 'middle';
            context.fillStyle = ink;
            context.fillText(String(label).slice(0, 16), left - 9, y);
            context.fillStyle = '#eaf2eb';
            context.fillRect(left, y - 6, plotWidth, 12);
            context.fillStyle = palette[index % palette.length];
            context.fillRect(left, y - 6, barWidth, 12);
            context.textAlign = 'left';
            context.fillStyle = '#24352b';
            context.fillText(numericValues[index].toFixed(2), left + plotWidth + 7, y);
        });
        return;
    }

    const left = chartType === 'bar' ? 36 : 42;
    const top = 14;
    const right = 10;
    const bottom = 34;
    const plotWidth = width - left - right;
    const plotHeight = height - top - bottom;
    const maximum = Math.max(1, ...numericValues) * 1.12;

    for (let tick = 0; tick <= 4; tick++) {
        const y = top + (plotHeight * tick) / 4;
        context.beginPath();
        context.strokeStyle = lineColor;
        context.lineWidth = 1;
        context.moveTo(left, y);
        context.lineTo(width - right, y);
        context.stroke();
        context.textAlign = 'right';
        context.textBaseline = 'middle';
        context.fillStyle = ink;
        const tickValue = maximum * (4 - tick) / 4;
        const tickLabel = chartType === 'line'
            ? `₱${tickValue.toFixed(0)}`
            : tickValue.toFixed(1);
        context.fillText(tickLabel, left - 7, y);
    }

    const step = labels.length > 1 ? plotWidth / (labels.length - 1) : 0;
    if (chartType === 'line') {
        const points = numericValues.map((value, index) => ({
            x: left + step * index,
            y: top + plotHeight - (value / maximum) * plotHeight,
        }));
        if (points.length) {
            context.beginPath();
            context.moveTo(points[0].x, top + plotHeight);
            points.forEach((point) => context.lineTo(point.x, point.y));
            context.lineTo(points[points.length - 1].x, top + plotHeight);
            context.closePath();
            context.fillStyle = '#21865317';
            context.fill();

            context.beginPath();
            points.forEach((point, index) => {
                if (index === 0) {
                    context.moveTo(point.x, point.y);
                } else {
                    context.lineTo(point.x, point.y);
                }
            });
            context.strokeStyle = '#218653';
            context.lineWidth = 2.5;
            context.lineJoin = 'round';
            context.stroke();
            points.forEach((point) => {
                context.beginPath();
                context.arc(point.x, point.y, 3.5, 0, Math.PI * 2);
                context.fillStyle = '#fff';
                context.fill();
                context.strokeStyle = '#218653';
                context.lineWidth = 2;
                context.stroke();
            });
        }
    } else {
        const slotWidth = plotWidth / Math.max(1, labels.length);
        const barWidth = Math.min(28, slotWidth * .58);
        numericValues.forEach((value, index) => {
            const barHeight = (value / maximum) * plotHeight;
            const x = left + slotWidth * index + (slotWidth - barWidth) / 2;
            const y = top + plotHeight - barHeight;
            context.fillStyle = '#218653';
            context.fillRect(x, y, barWidth, barHeight);
        });
    }

    context.textAlign = 'center';
    context.textBaseline = 'top';
    context.fillStyle = ink;
    const labelInterval = Math.max(1, Math.ceil(labels.length / 10));
    labels.forEach((label, index) => {
        const x = chartType === 'line'
            ? left + step * index
            : left + (plotWidth / Math.max(1, labels.length)) * (index + .5);
        if (index % labelInterval === 0 || index === labels.length - 1) {
            context.fillText(String(label), x, top + plotHeight + 10);
        }
    });
}

function attachDashboardChartTooltip(canvas, chartIndex) {
    let labels;
    let values;
    try {
        labels = JSON.parse(canvas.dataset.labels || '[]');
        values = JSON.parse(canvas.dataset.values || '[]').map(Number);
    } catch (error) {
        console.error('Unable to prepare dashboard chart tooltips.', error);
        return;
    }

    if (!labels.length || labels.length !== values.length) {
        return;
    }

    const container = canvas.closest('.dashboard-canvas-wrap, .dashboard-inventory-chart');
    if (!container) {
        return;
    }

    const tooltip = document.createElement('span');
    tooltip.id = `dashboard-chart-tooltip-${chartIndex}`;
    tooltip.className = 'dashboard-chart-tooltip';
    tooltip.setAttribute('role', 'tooltip');
    tooltip.setAttribute('aria-live', 'polite');
    tooltip.hidden = true;
    container.append(tooltip);
    container.classList.add('dashboard-tooltip-container');
    canvas.tabIndex = 0;
    canvas.setAttribute('aria-description', 'Use the arrow keys, Home, and End to inspect chart values.');
    canvas.setAttribute('aria-keyshortcuts', 'ArrowLeft ArrowRight ArrowUp ArrowDown Home End');
    canvas.setAttribute('aria-describedby', tooltip.id);

    let selectedIndex = 0;
    const chartType = canvas.dataset.chart;
    const formatValue = (value) => {
        if (canvas.dataset.format === 'currency') {
            return new Intl.NumberFormat(undefined, {
                style: 'currency',
                currency: 'PHP',
            }).format(value);
        }

        return `${Number(value).toLocaleString(undefined, { maximumFractionDigits: 2 })} units`;
    };
    const showTooltip = (index, clientX, clientY) => {
        selectedIndex = Math.max(0, Math.min(labels.length - 1, index));
        tooltip.textContent = `${labels[selectedIndex]}: ${formatValue(values[selectedIndex])}`;
        tooltip.hidden = false;

        const containerBounds = container.getBoundingClientRect();
        const left = Math.max(8, Math.min(
            clientX - containerBounds.left + 12,
            containerBounds.width - Math.min(240, containerBounds.width - 16)
        ));
        const top = Math.max(8, Math.min(
            clientY - containerBounds.top + 12,
            containerBounds.height - 42
        ));
        tooltip.style.left = `${left}px`;
        tooltip.style.top = `${top}px`;
    };

    canvas.addEventListener('pointermove', (event) => {
        const bounds = canvas.getBoundingClientRect();
        const x = event.clientX - bounds.left;
        const y = event.clientY - bounds.top;
        let index;

        if (chartType === 'horizontal-bar') {
            index = Math.floor((y / bounds.height) * labels.length);
        } else if (chartType === 'doughnut') {
            const centerX = bounds.width / 2;
            const centerY = bounds.height / 2;
            const deltaX = x - centerX;
            const deltaY = y - centerY;
            const distance = Math.hypot(deltaX, deltaY);
            const outerRadius = Math.min(bounds.width, bounds.height) * .43;
            if (distance < outerRadius * .62 || distance > outerRadius) {
                tooltip.hidden = true;
                return;
            }
            const angle = (Math.atan2(deltaY, deltaX) + Math.PI * 2.5) % (Math.PI * 2);
            const total = values.reduce((sum, value) => sum + value, 0);
            let accumulated = 0;
            index = values.findIndex((value) => {
                if (value <= 0) {
                    return false;
                }
                accumulated += total > 0 ? (value / total) * Math.PI * 2 : 0;
                return angle < accumulated;
            });
            if (index < 0) {
                index = values.length - 1;
            }
        } else if (chartType === 'line') {
            const left = 42;
            const plotWidth = bounds.width - left - 10;
            index = Math.round(((x - left) / plotWidth) * (labels.length - 1));
        } else {
            index = Math.floor((x / bounds.width) * labels.length);
        }

        showTooltip(index, event.clientX, event.clientY);
    });

    canvas.addEventListener('pointerleave', () => {
        if (document.activeElement !== canvas) {
            tooltip.hidden = true;
        }
    });

    canvas.addEventListener('focus', () => {
        const bounds = canvas.getBoundingClientRect();
        showTooltip(selectedIndex, bounds.left + bounds.width / 2, bounds.top + 12);
    });

    canvas.addEventListener('blur', () => {
        tooltip.hidden = true;
    });

    canvas.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
            selectedIndex = Math.min(labels.length - 1, selectedIndex + 1);
        } else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
            selectedIndex = Math.max(0, selectedIndex - 1);
        } else if (event.key === 'Home') {
            selectedIndex = 0;
        } else if (event.key === 'End') {
            selectedIndex = labels.length - 1;
        } else {
            return;
        }

        event.preventDefault();
        const bounds = canvas.getBoundingClientRect();
        showTooltip(selectedIndex, bounds.left + bounds.width / 2, bounds.top + 12);
    });
}

const dashboardCharts = Array.from(document.querySelectorAll('[data-chart]'));
dashboardCharts.forEach((canvas, index) => {
    renderDashboardChart(canvas);
    attachDashboardChartTooltip(canvas, index);
});
if ('ResizeObserver' in window) {
    const chartObserver = new ResizeObserver((entries) => {
        entries.forEach((entry) => renderDashboardChart(entry.target));
    });
    dashboardCharts.forEach((canvas) => chartObserver.observe(canvas));
} else {
    window.addEventListener('resize', () => dashboardCharts.forEach(renderDashboardChart));
}

const orderStatusUrl = document.body.dataset.orderStatusUrl;
const orderStatusElements = document.querySelectorAll('[data-order-status-for]');
const liveOrderStatuses = new Set([
    'pending',
    'confirmed',
    'preparing',
    'ready_for_delivery',
    'out_for_delivery',
    'delivered',
    'cancelled',
    'pending_payment',
    'paid',
    'failed',
    'refunded',
]);
const filipinoOrderStatusLabels = {
    pending: 'nakabinbin',
    confirmed: 'kumpirmado',
    preparing: 'inihahanda',
    ready_for_delivery: 'handa na para ihatid',
    out_for_delivery: 'nasa biyahe',
    delivered: 'naideliver',
    cancelled: 'kinansela',
    pending_payment: 'naghihintay ng bayad',
    paid: 'bayad na',
    failed: 'hindi nagtagumpay',
    refunded: 'na-refund',
};

if (orderStatusUrl && orderStatusElements.length > 0) {
    const trackingStatusIndices = new Map([
        ['confirmed', 0],
        ['preparing', 1],
        ['ready_for_delivery', 2],
        ['out_for_delivery', 3],
    ]);

    function updateOrderTracker(orderCode, status) {
        document.querySelectorAll('[data-order-tracker-for]').forEach((tracker) => {
            if (tracker.dataset.orderTrackerFor !== orderCode) {
                return;
            }
            if (status === 'delivered') {
                tracker.remove();
                return;
            }
            if (status === 'cancelled') {
                tracker.classList.add('is-cancelled');
                tracker.dataset.orderTrackerStatus = status;
                const statusBadge = tracker.querySelector('.app-order-tracker-heading .status-badge');
                if (statusBadge) {
                    statusBadge.className = 'status-badge status-cancelled';
                    statusBadge.textContent = document.documentElement.lang === 'fil'
                        ? 'Kinansela ang order'
                        : 'Order cancelled';
                }
                tracker.querySelector('.app-order-tracker-list')?.remove();
                tracker.querySelector('.app-order-tracker-live')?.remove();
                tracker.querySelector('.app-order-tracker-pending')?.remove();
                if (!tracker.querySelector('.app-order-tracker-cancelled')) {
                    const message = document.createElement('p');
                    message.className = 'app-order-tracker-cancelled';
                    message.setAttribute('role', 'status');
                    message.textContent = document.documentElement.lang === 'fil'
                        ? 'Makipag-ugnayan sa bukid kung kailangan mo ng tulong sa order na ito.'
                        : 'Contact the farm if you need help with this order.';
                    tracker.appendChild(message);
                }
                return;
            }
            const activeIndex = status === 'pending' ? -1 : trackingStatusIndices.get(status);
            if (activeIndex === undefined) {
                return;
            }
            tracker.classList.remove('is-cancelled');
            tracker.dataset.orderTrackerStatus = status;
            tracker.querySelectorAll('.app-order-tracker-step').forEach((step, index) => {
                step.className = index < activeIndex
                    ? 'is-complete'
                    : index === activeIndex
                        ? 'is-current'
                        : 'is-upcoming';
                step.classList.add('app-order-tracker-step');
                const marker = step.querySelector('.app-order-tracker-marker');
                if (marker) {
                    marker.textContent = index < activeIndex ? '✓' : String(index + 1);
                }
                if (index === activeIndex) {
                    step.setAttribute('aria-current', 'step');
                } else {
                    step.removeAttribute('aria-current');
                }
            });
            const statusLabels = document.documentElement.lang === 'fil'
                ? {
                    pending: 'Naghihintay ng kumpirmasyon ng bukid',
                    confirmed: 'Kumpirmado ang order',
                    preparing: 'Isinasagawa ang pag-aani',
                    ready_for_delivery: 'Naka-pack at sinusuri ang kalidad',
                    out_for_delivery: 'Nasa biyahe na para ihatid',
                }
                : {
                    pending: 'Waiting for farm confirmation',
                    confirmed: 'Order confirmed',
                    preparing: 'Harvesting in progress',
                    ready_for_delivery: 'Packed & quality check',
                    out_for_delivery: 'Out for delivery',
                };
            const statusBadge = tracker.querySelector('.app-order-tracker-heading .status-badge');
            if (statusBadge) {
                statusBadge.className = `status-badge status-${status}`;
                statusBadge.textContent = statusLabels[status] || status;
            }
            let pendingNotice = tracker.querySelector('.app-order-tracker-pending');
            if (status === 'pending' && !pendingNotice) {
                pendingNotice = document.createElement('p');
                pendingNotice.className = 'app-order-tracker-pending';
                pendingNotice.setAttribute('role', 'status');
                pendingNotice.textContent = document.documentElement.lang === 'fil'
                    ? 'Kukumpirmahin muna ng bukid ang order bago simulan ang pag-aani.'
                    : 'The farm will confirm your order before harvesting begins.';
                tracker.querySelector('.app-order-tracker-heading')?.after(pendingNotice);
            } else if (status !== 'pending') {
                pendingNotice?.remove();
            }
        });
    }

    async function refreshBuyerOrderStatuses() {
        if (document.visibilityState !== 'visible') {
            return;
        }

        try {
            const response = await fetch(orderStatusUrl, {
                headers: { Accept: 'application/json' },
                cache: 'no-store',
                credentials: 'same-origin',
            });
            if (!response.ok) {
                throw new Error(`Order status refresh failed with HTTP ${response.status}.`);
            }

            const result = await response.json();
            if (!result || !Array.isArray(result.orders)) {
                throw new Error('Order status response has an invalid shape.');
            }

            result.orders.forEach((order) => {
                if (typeof order.order_code !== 'string') {
                    return;
                }
                if (liveOrderStatuses.has(order.order_status)) {
                    updateOrderTracker(order.order_code, order.order_status);
                    if (['delivered', 'cancelled'].includes(order.order_status)) {
                        document.querySelectorAll('[data-active-order-for]').forEach((element) => {
                            if (element.dataset.activeOrderFor === order.order_code) {
                                element.remove();
                            }
                        });
                    }
                }

                [
                    ['order_status', '[data-order-status-for]'],
                    ['payment_status', '[data-payment-status-for]'],
                ].forEach(([field, selector]) => {
                    const value = order[field];
                    if (!liveOrderStatuses.has(value)) {
                        return;
                    }

                    document.querySelectorAll(selector).forEach((element) => {
                        const attribute = field === 'order_status'
                            ? 'orderStatusFor'
                            : 'paymentStatusFor';
                        if (element.dataset[attribute] !== order.order_code) {
                            return;
                        }

                        element.textContent = document.documentElement.lang === 'fil'
                            ? (filipinoOrderStatusLabels[value] || value)
                            : value.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
                        if (element.classList.contains('status-badge')) {
                            element.className = `status-badge status-${value}`;
                        }
                    });
                });
            });
        } catch (error) {
            console.error('Unable to refresh buyer order statuses.', error);
        }
    }

    void refreshBuyerOrderStatuses();
    window.setInterval(() => void refreshBuyerOrderStatuses(), 30000);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            void refreshBuyerOrderStatuses();
        }
    });
}

const notificationsUrl = document.body.dataset.notificationsUrl;
const notificationBadge = document.querySelector('[data-notification-badge]');
if (notificationsUrl && notificationBadge) {
    let latestNotificationId = Number(document.body.dataset.notificationsLatest || 0);
    const pendingReturnBadge = document.querySelector('[data-pending-return-badge]');
    const notificationStack = document.createElement('div');
    notificationStack.className = 'live-notification-stack';
    notificationStack.setAttribute('aria-live', 'polite');
    notificationStack.setAttribute('aria-relevant', 'additions');
    const notificationHost = document.querySelector('main.container') || document.body;
    notificationHost.prepend(notificationStack);

    function showLiveNotification(notification) {
        if (
            !notification
            || typeof notification.message !== 'string'
            || typeof notification.url !== 'string'
        ) {
            return;
        }

        let target;
        try {
            target = new URL(notification.url, window.location.href);
        } catch (error) {
            console.error('Notification link is invalid.', error);
            return;
        }
        if (target.origin !== window.location.origin) {
            return;
        }

        notificationStack.replaceChildren();
        const toast = document.createElement('div');
        toast.className = 'live-notification-toast notice success';
        toast.setAttribute('role', 'status');
        const link = document.createElement('a');
        link.href = target.href;
        link.textContent = notification.message;
        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'live-notification-close';
        close.setAttribute('aria-label', 'Dismiss notification');
        close.textContent = '×';
        toast.append(link, close);
        notificationStack.appendChild(toast);

        close.addEventListener('click', () => {
            toast.remove();
        });
    }

    async function refreshNotifications() {
        if (document.visibilityState !== 'visible') {
            return;
        }
        try {
            const url = new URL(notificationsUrl, window.location.href);
            url.searchParams.set('after_id', String(latestNotificationId));
            const response = await fetch(url, {
                headers: { Accept: 'application/json' },
                cache: 'no-store',
                credentials: 'same-origin',
            });
            if (!response.ok) {
                throw new Error(`Notification refresh failed with HTTP ${response.status}.`);
            }
            const result = await response.json();
            if (
                !result
                || !Array.isArray(result.notifications)
                || !Number.isInteger(result.unread_count)
                || !Number.isInteger(result.latest_id)
            ) {
                throw new Error('Notification response has an invalid shape.');
            }
            result.notifications.slice(-3).forEach(showLiveNotification);
            latestNotificationId = Math.max(latestNotificationId, result.latest_id);
            notificationBadge.textContent = String(result.unread_count);
            notificationBadge.dataset.unreadCount = String(result.unread_count);
            notificationBadge.hidden = result.unread_count === 0;
            if (pendingReturnBadge && Number.isInteger(result.pending_return_count)) {
                pendingReturnBadge.textContent = String(result.pending_return_count);
                pendingReturnBadge.hidden = result.pending_return_count === 0;
                pendingReturnBadge.closest('a')?.setAttribute(
                    'aria-label',
                    `Returns, ${result.pending_return_count} pending`
                );
            }
        } catch (error) {
            console.error('Unable to refresh notifications.', error);
        }
    }
    if (Number(notificationBadge.dataset.unreadCount) === 0) {
        notificationBadge.hidden = true;
    }
    void refreshNotifications();
    window.setInterval(() => void refreshNotifications(), 30000);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            void refreshNotifications();
        }
    });
}
