const farmRoles = new Set(['admin', 'staff', 'farmer']);

export function isFarmStaffRole(role) {
    return farmRoles.has(role);
}
