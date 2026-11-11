export const MAX_VEGETABLE_PHOTO_SIZE = 5 * 1024 * 1024;

const vegetablePhotoExtensions = new Map([
    ['image/jpeg', 'jpg'],
    ['image/png', 'png'],
    ['image/webp', 'webp'],
]);

export function getVegetablePhotoExtension(file) {
    if (!file) return null;
    if (!vegetablePhotoExtensions.has(file.type)) {
        throw new Error('Choose a JPEG, PNG, or WebP image.');
    }
    if (file.size > MAX_VEGETABLE_PHOTO_SIZE) {
        throw new Error('Vegetable photos must be 5 MB or smaller.');
    }
    return vegetablePhotoExtensions.get(file.type);
}
