import test from 'node:test';
import assert from 'node:assert/strict';
import { sortFifoLots } from '../src/fifoInventory.js';

test('FIFO batches sort by vegetable, then opening stock, then received date', () => {
    const lots = [
        { id: 5, vegetable_id: 1, source_type: 'harvest', received_on: '2026-03-01' },
        { id: 3, vegetable_id: 1, source_type: 'opening_balance', received_on: '2026-11-01' },
        { id: 6, vegetable_id: 1, source_type: 'manual_addition', received_on: '2026-11-01' },
        { id: 4, vegetable_id: 1, source_type: 'harvest', received_on: '2026-02-01' },
        { id: 1, vegetable_id: 2, source_type: 'harvest', received_on: '2025-01-01' },
    ];
    const vegetableNames = new Map([[1, 'Tomato'], [2, 'Carrot']]);

    const sorted = sortFifoLots(lots, (id) => vegetableNames.get(id));

    assert.deepEqual(sorted.map((lot) => lot.id), [1, 3, 4, 5, 6]);
    assert.deepEqual(lots.map((lot) => lot.id), [5, 3, 6, 4, 1]);
});
