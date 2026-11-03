export async function getAccessToken(auth) {
    const { data, error } = await auth.getSession();
    if (error) throw error;
    if (data.session?.access_token) return data.session.access_token;

    return refreshAccessToken(auth);
}

export async function refreshAccessToken(auth) {
    const { data, error } = await auth.refreshSession();
    if (error) throw error;
    return data.session?.access_token || null;
}

export function assertMatchingSupabaseUrl(serverUrl, browserUrl) {
    if (!serverUrl || !browserUrl) return;

    let serverOrigin;
    let browserOrigin;
    try {
        serverOrigin = new URL(serverUrl).origin;
        browserOrigin = new URL(browserUrl).origin;
    } catch {
        throw new Error('SUPABASE_URL and VITE_SUPABASE_URL must be valid project URLs.');
    }

    if (serverOrigin !== browserOrigin) {
        throw new Error('SUPABASE_URL and VITE_SUPABASE_URL must point to the same Supabase project.');
    }
}
