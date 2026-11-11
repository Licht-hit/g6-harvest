import test from 'node:test';
import assert from 'node:assert/strict';
import { filterAndSortProducts } from '../src/shopFilters.js';

const products = [
    { id: 1, name: 'Carrot', category: 'Root', variety: 'Nantes', description: 'Sweet', price: 25, quantity: 10 },
    { id: 2, name: 'Broccoli', category: 'Greens', variety: 'Calabrese', description: 'Fresh', price: 20, quantity: 0 },
    { id: 3, name: 'Bell Pepper', category: 'Fruit', variety: 'California Wonder', description: 'Crisp', price: 40, quantity: 5 },
];

test('search matches names, varieties, and descriptions without case sensitivity', () => {
    assert.deepEqual(filterAndSortProducts(products, { search: 'CALIFORNIA' }).map((item) => item.id), [3]);
    assert.deepEqual(filterAndSortProducts(products, { search: 'sweet' }).map((item) => item.id), [1]);
});

test('category and in-stock filters can be combined', () => {
    assert.deepEqual(filterAndSortProducts(products, {
        category: 'Fruit',
        inStockOnly: true,
    }).map((item) => item.id), [3]);
    assert.deepEqual(filterAndSortProducts(products, {
        category: 'Greens',
        inStockOnly: true,
    }), []);
});

test('sort options order by price and available quantity', () => {
    assert.deepEqual(filterAndSortProducts(products, { sort: 'price_asc' }).map((item) => item.id), [2, 1, 3]);
    assert.deepEqual(filterAndSortProducts(products, { sort: 'price_desc' }).map((item) => item.id), [3, 1, 2]);
    assert.deepEqual(filterAndSortProducts(products, { sort: 'stock_desc' }).map((item) => item.id), [1, 3, 2]);
});
