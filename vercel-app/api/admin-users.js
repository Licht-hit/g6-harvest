import { getRequestUser, readJsonBody, sendJson, serviceClient } from '../lib/supabaseServer.js';

const roles = new Set(['admin', 'staff', 'farmer', 'buyer']);
const pageSize = 25;

function requestParameters(request) {
    const url = new URL(request.url || '/', `https://${request.headers.host || 'localhost'}`);
    return url.searchParams;
}

function validPage(value) {
    const page = Number(value || 1);
    return Number.isSafeInteger(page) && page > 0 && page <= 1000000 ? page : null;
}

async function listUsers(request, response) {
    const parameters = requestParameters(request);
    const page = validPage(parameters.get('page'));
    if (page === null) {
        return sendJson(response, 400, { error: 'Choose a valid user-list page.' });
    }
    const rawRole = parameters.get('role') || '';
    if (rawRole && !roles.has(rawRole)) {
        return sendJson(response, 400, { error: 'Choose a valid role filter.' });
    }
    const role = rawRole;
    const search = (parameters.get('search') || '')
        .replace(/[^\p{L}\p{N}@._ +\-]/gu, '')
        .trim()
        .slice(0, 100);
    let query = serviceClient
        .from('profiles')
        .select('id, name, email, role, email_verified_at, created_at', { count: 'exact' })
        .order('name')
        .range((page - 1) * pageSize, page * pageSize - 1);
    if (role) query = query.eq('role', role);
    if (search) query = query.or(`name.ilike.%${search}%,email.ilike.%${search}%`);

    const { data, error, count } = await query;
    if (error) throw error;
    return sendJson(response, 200, {
        users: data || [],
        total: count || 0,
        page,
        pageSize,
    });
}

async function changeUserRole(request, response, actorId) {
    const payload = request.body && typeof request.body === 'object'
        ? request.body
        : await readJsonBody(request);
    const targetId = Number(payload.profileId);
    const nextRole = payload.role;
    if (!Number.isSafeInteger(targetId) || targetId < 1 || typeof nextRole !== 'string' || !roles.has(nextRole)) {
        return sendJson(response, 400, { error: 'Choose a valid user and role.' });
    }

    const { error } = await serviceClient.rpc('admin_change_profile_role', {
        p_actor_id: actorId,
        p_target_id: targetId,
        p_next_role: nextRole,
    });
    if (error) {
        const status = error.message.includes('not found') ? 404 : 400;
        if (status === 400 && !/only administrators|cannot change your own role|last administrator|already has this role|verify their email|valid role/i.test(error.message)) {
            throw error;
        }
        return sendJson(response, status, { error: error.message });
    }
    return sendJson(response, 200, { ok: true });
}

export default async function handler(request, response) {
    if (!['GET', 'POST'].includes(request.method)) {
        response.setHeader('Allow', 'GET, POST');
        return sendJson(response, 405, { error: 'Method not allowed.' });
    }

    try {
        response.setHeader('Cache-Control', 'private, no-store');
        const requestUser = await getRequestUser(request);
        if (requestUser.error) {
            return sendJson(response, requestUser.status, { error: requestUser.error });
        }
        if (requestUser.profile.role !== 'admin') {
            return sendJson(response, 403, { error: 'Only administrators can manage user roles.' });
        }

        if (request.method === 'GET') {
            return await listUsers(request, response);
        }
        return await changeUserRole(request, response, requestUser.profile.id);
    } catch (error) {
        console.error('Admin user management request failed:', error);
        return sendJson(response, 500, { error: 'The server could not complete this request.' });
    }
}
