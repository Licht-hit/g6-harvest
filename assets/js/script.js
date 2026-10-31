document.addEventListener('submit', (event) => {
    const form = event.target;

    // Some forms can ask for confirmation before submitting.
    if (
        form instanceof HTMLFormElement
        && form.dataset.confirm
        && !window.confirm(form.dataset.confirm)
    ) {
        event.preventDefault();
    }
});

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
    toggle.addEventListener('click', () => {
        const navigation = document.getElementById(toggle.getAttribute('aria-controls'));
        if (!navigation) {
            return;
        }

        const isOpen = navigation.classList.toggle('is-open');
        toggle.setAttribute('aria-expanded', String(isOpen));
        toggle.setAttribute('aria-label', isOpen ? 'Close navigation' : 'Open navigation');
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
    labels.forEach((label, index) => {
        const x = chartType === 'line'
            ? left + step * index
            : left + (plotWidth / Math.max(1, labels.length)) * (index + .5);
        context.fillText(String(label), x, top + plotHeight + 10);
    });
}

const dashboardCharts = Array.from(document.querySelectorAll('[data-chart]'));
dashboardCharts.forEach(renderDashboardChart);
if ('ResizeObserver' in window) {
    const chartObserver = new ResizeObserver((entries) => {
        entries.forEach((entry) => renderDashboardChart(entry.target));
    });
    dashboardCharts.forEach((canvas) => chartObserver.observe(canvas));
} else {
    window.addEventListener('resize', () => dashboardCharts.forEach(renderDashboardChart));
}
