const farmRoles = new Set(['admin', 'staff', 'farmer']);

// Keep role checks consistent between the browser and Vercel API functions.
export function isFarmStaffRole(role) {
    return farmRoles.has(role);
}

export function isFarmManagerRole(role) {
    return role === 'admin' || role === 'farmer';
}
