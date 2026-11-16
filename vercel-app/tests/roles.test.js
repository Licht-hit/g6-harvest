import test from 'node:test';
import assert from 'node:assert/strict';
import { isFarmManagerRole, isFarmStaffRole } from '../src/roles.js';

test('farmers, staff, and administrators can access farm operations', () => {
    assert.equal(isFarmStaffRole('farmer'), true);
    assert.equal(isFarmStaffRole('staff'), true);
    assert.equal(isFarmStaffRole('admin'), true);
});

test('buyers and unknown roles cannot access farm operations', () => {
    assert.equal(isFarmStaffRole('buyer'), false);
    assert.equal(isFarmStaffRole('unknown'), false);
    assert.equal(isFarmStaffRole(null), false);
});

test('only administrators and farmers can manage catalog and business reports', () => {
    assert.equal(isFarmManagerRole('admin'), true);
    assert.equal(isFarmManagerRole('farmer'), true);
    assert.equal(isFarmManagerRole('staff'), false);
    assert.equal(isFarmManagerRole('buyer'), false);
});
