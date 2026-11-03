import test from 'node:test';
import assert from 'node:assert/strict';
import {
    assertMatchingSupabaseUrl,
    getAccessToken,
    refreshAccessToken,
} from '../src/authSession.js';

test('uses the existing access token when the session is active', async () => {
    let refreshCalls = 0;
    const auth = {
        async getSession() {
            return { data: { session: { access_token: 'current-token' } }, error: null };
        },
        async refreshSession() {
            refreshCalls += 1;
            return { data: { session: { access_token: 'refreshed-token' } }, error: null };
        },
    };

    assert.equal(await getAccessToken(auth), 'current-token');
    assert.equal(refreshCalls, 0);
});

test('refreshes the session when no access token is available', async () => {
    const auth = {
        async getSession() {
            return { data: { session: null }, error: null };
        },
        async refreshSession() {
            return { data: { session: { access_token: 'refreshed-token' } }, error: null };
        },
    };

    assert.equal(await getAccessToken(auth), 'refreshed-token');
});

test('returns null when the session cannot be refreshed', async () => {
    const auth = {
        async refreshSession() {
            return { data: { session: null }, error: null };
        },
    };

    assert.equal(await refreshAccessToken(auth), null);
});

test('surfaces errors from Supabase instead of hiding them', async () => {
    const expectedError = new Error('Refresh token is invalid.');
    const auth = {
        async getSession() {
            return { data: { session: null }, error: expectedError };
        },
    };

    await assert.rejects(getAccessToken(auth), expectedError);
});

test('accepts browser and server URLs for the same Supabase project', () => {
    assert.doesNotThrow(() => assertMatchingSupabaseUrl(
        'https://harvest-example.supabase.co/',
        'https://harvest-example.supabase.co'
    ));
});

test('rejects browser and server URLs for different Supabase projects', () => {
    assert.throws(
        () => assertMatchingSupabaseUrl(
            'https://harvest-server.supabase.co',
            'https://harvest-browser.supabase.co'
        ),
        /must point to the same Supabase project/
    );
});
