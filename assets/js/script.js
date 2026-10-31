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
    if (!(image instanceof HTMLImageElement) || !image.matches('.product-image img')) {
        return;
    }

    image.hidden = true;
    const fallback = image.parentElement.querySelector('.product-image-fallback');
    if (fallback) {
        fallback.hidden = false;
    }
}, true);
