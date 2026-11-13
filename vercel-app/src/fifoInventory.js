const sourcePriority = {
    opening_balance: 0,
    harvest: 1,
    manual_addition: 1,
};

export function sortFifoLots(lots, vegetableName) {
    return [...lots].sort((left, right) => (
        vegetableName(left.vegetable_id).localeCompare(vegetableName(right.vegetable_id))
        || sourcePriority[left.source_type] - sourcePriority[right.source_type]
        || left.received_on.localeCompare(right.received_on)
        || Number(left.id) - Number(right.id)
    ));
}
