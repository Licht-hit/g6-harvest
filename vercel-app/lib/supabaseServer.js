import { createClient } from '@supabase/supabase-js';
import { assertMatchingSupabaseUrl } from '../src/authSession.js';

const supabaseUrl = process.env.SUPABASE_URL || process.env.VITE_SUPABASE_URL;

if (!supabaseUrl) {
    throw new Error('SUPABASE_URL is not configured.');
}
assertMatchingSupabaseUrl(supabaseUrl, process.env.VITE_SUPABASE_URL);

export const serviceClient = createClient(
    supabaseUrl,
    requiredEnvironmentValue('SUPABASE_SERVICE_ROLE_KEY'),
    { auth: { autoRefreshToken: false, persistSession: false } }
);

function requiredEnvironmentValue(name) {
    const value = process.env[name];
    if (!value) {
        throw new Error(`${name} is not configured.`);
    }
    return value;
}

export async function getRequestUser(request) {
    const authorization = request.headers.authorization || '';
    const match = authorization.match(/^Bearer\s+(.+)$/i);
    if (!match) {
        return { error: 'Sign in is required.', status: 401 };
    }

    const anonKey = process.env.SUPABASE_ANON_KEY || process.env.VITE_SUPABASE_ANON_KEY;
    if (!anonKey) {
        throw new Error('SUPABASE_ANON_KEY is not configured.');
    }

    const client = createClient(supabaseUrl, anonKey, {
        auth: { autoRefreshToken: false, persistSession: false },
        global: { headers: { Authorization: `Bearer ${match[1]}` } },
    });
    const { data: authData, error: authError } = await client.auth.getUser(match[1]);
    if (authError || !authData.user) {
        if (authError) {
            console.error('Supabase rejected the API access token:', authError.message);
        }
        return { error: 'Your session has expired. Sign in again.', status: 401 };
    }

    const { data: profile, error: profileError } = await serviceClient
        .from('profiles')
        .select('id, name, email, role, email_verified_at')
        .eq('auth_user_id', authData.user.id)
        .maybeSingle();
    if (profileError) {
        throw profileError;
    }
    if (!profile || !profile.email_verified_at) {
        return { error: 'Verify your email address before continuing.', status: 403 };
    }

    return { client, authUser: authData.user, profile };
}

export function sendJson(response, status, body) {
    response.status(status).setHeader('Content-Type', 'application/json; charset=utf-8');
    response.end(JSON.stringify(body));
}

export async function readJsonBody(request, maximumBytes = 1024 * 1024) {
    const chunks = [];
    let size = 0;
    for await (const chunk of request) {
        size += chunk.length;
        if (size > maximumBytes) {
            throw new RangeError('Request body is too large.');
        }
        chunks.push(chunk);
    }
    return JSON.parse(Buffer.concat(chunks).toString('utf8'));
}
