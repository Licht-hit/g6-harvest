import assert from 'node:assert/strict';
import test from 'node:test';
import { getVegetablePhotoExtension, MAX_VEGETABLE_PHOTO_SIZE } from '../src/vegetablePhoto.js';

test('accepts JPEG, PNG, and WebP vegetable photos up to 5 MB', () => {
    assert.equal(getVegetablePhotoExtension({ type: 'image/jpeg', size: MAX_VEGETABLE_PHOTO_SIZE }), 'jpg');
    assert.equal(getVegetablePhotoExtension({ type: 'image/png', size: 1 }), 'png');
    assert.equal(getVegetablePhotoExtension({ type: 'image/webp', size: 1 }), 'webp');
});

test('allows no photo but rejects unsupported types and oversized files', () => {
    assert.equal(getVegetablePhotoExtension(null), null);
    assert.throws(() => getVegetablePhotoExtension({ type: 'image/gif', size: 1 }), /JPEG, PNG, or WebP/);
    assert.throws(() => getVegetablePhotoExtension({ type: 'image/jpeg', size: MAX_VEGETABLE_PHOTO_SIZE + 1 }), /5 MB/);
});
