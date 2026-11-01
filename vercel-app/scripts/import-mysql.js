import { randomBytes } from 'node:crypto';
import mysql from 'mysql2/promise';
import { createClient } from '@supabase/supabase-js';

function required(name) {
    const value = process.env[name];
    if (!value) throw new Error(`Set ${name} before running the import.`);
    return value;
}

const service = createClient(required('SUPABASE_URL'), required('SUPABASE_SERVICE_ROLE_KEY'), {
    auth: { autoRefreshToken: false, persistSession: false },
});
const source = await mysql.createConnection({
    host: required('MYSQL_HOST'),
    port: Number(process.env.MYSQL_PORT || 3306),
    database: required('MYSQL_DATABASE'),
    user: required('MYSQL_USER'),
    password: required('MYSQL_PASSWORD'),
    charset: 'utf8mb4',
});

async function sourceRows(table) {
    const [rows] = await source.query(`SELECT * FROM \`${table}\``);
    return rows;
}

async function insertRows(table, rows, conflict = 'id') {
    for (let offset = 0; offset < rows.length; offset += 100) {
        const batch = rows.slice(offset, offset + 100);
        const { error } = await service.from(table).upsert(batch, { onConflict: conflict });
        if (error) throw new Error(`Import into ${table} failed: ${error.message}`);
    }
    console.log(`Imported ${rows.length} ${table} record(s).`);
}

async function findAuthUser(email) {
    for (let page = 1; page <= 100; page += 1) {
        const { data, error } = await service.auth.admin.listUsers({ page, perPage: 1000 });
        if (error) throw new Error(`Could not check Supabase accounts: ${error.message}`);
        const match = data.users.find((user) => user.email?.toLowerCase() === email.toLowerCase());
        if (match) return match;
        if (data.users.length < 1000) return null;
    }
    throw new Error('Too many auth users to safely find the next imported account.');
}

async function importProfiles() {
    const users = await sourceRows('users');
    let imported = 0;
    for (const user of users) {
        let authUser = await findAuthUser(user.email);
        if (!authUser) {
            const { data, error } = await service.auth.admin.createUser({
                email: user.email,
                password: randomBytes(48).toString('base64url'),
                email_confirm: Boolean(user.email_verified_at),
                user_metadata: { name: user.name },
            });
            if (error) throw new Error(`Could not create an auth account for one imported user: ${error.message}`);
            authUser = data.user;
        }

        const { data: profile, error: profileError } = await service.from('profiles')
            .upsert({
                auth_user_id: authUser.id,
                name: user.name,
                email: user.email,
                role: user.role,
                email_verified_at: user.email_verified_at,
                created_at: user.created_at,
            }, { onConflict: 'auth_user_id' })
            .select('id')
            .single();
        if (profileError) throw new Error(`Could not import an account profile: ${profileError.message}`);
        userProfileIds.set(Number(user.id), profile.id);
        imported += 1;
    }
    console.log(`Imported ${imported} account profile(s). Existing passwords were not copied.`);
}

const userProfileIds = new Map();

function mapProfiles(rows, fields) {
    return rows.map((row) => {
        const result = { ...row };
        for (const field of fields) {
            if (result[field] !== null && result[field] !== undefined) {
                const profileId = userProfileIds.get(Number(result[field]));
                if (!profileId) throw new Error(`Account reference ${result[field]} in ${field} has no imported profile.`);
                result[field] = profileId;
            }
        }
        return result;
    });
}

async function importData() {
    const vegetables = (await sourceRows('vegetables')).map((row) => ({
        ...row,
        active: Boolean(Number(row.active)),
    }));
    await insertRows('vegetables', vegetables);

    await insertRows('plantings', await sourceRows('plantings'));
    await insertRows('harvests', await sourceRows('harvests'));
    await insertRows('inventory', await sourceRows('inventory'), 'vegetable_id');

    const orders = mapProfiles(await sourceRows('orders'), ['buyer_id', 'payment_confirmed_by']);
    await insertRows('orders', orders);

    const sales = mapProfiles(await sourceRows('sales'), ['buyer_id']);
    await insertRows('sales', sales);
    const paymentHistory = mapProfiles(await sourceRows('payment_history'), ['actor_id']);
    await insertRows('payment_history', paymentHistory);
    const inspections = mapProfiles(await sourceRows('quality_inspections'), ['inspector_id']);
    await insertRows('quality_inspections', inspections);
    const activity = mapProfiles(await sourceRows('activity_logs'), ['user_id']);
    await insertRows('activity_logs', activity);

    const { error } = await service.rpc('reset_identity_sequences');
    if (error) throw new Error(`Could not prepare generated IDs after import: ${error.message}`);
}

try {
    await source.ping();
    await importProfiles();
    await importData();
    console.log('Import finished. Ask existing users to use Forgot password to set a new Supabase password.');
} finally {
    await source.end();
}
