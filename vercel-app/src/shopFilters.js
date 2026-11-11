export function filterAndSortProducts(products, filters = {}) {
    const search = String(filters.search || '').trim().toLocaleLowerCase();
    const category = String(filters.category || '');
    const inStockOnly = Boolean(filters.inStockOnly);
    const sort = String(filters.sort || 'name');

    const matching = products.filter((product) => {
        const searchableText = [
            product.name,
            product.variety,
            product.category,
            product.description,
        ].join(' ').toLocaleLowerCase();
        return (!search || searchableText.includes(search))
            && (!category || product.category === category)
            && (!inStockOnly || Number(product.quantity) > 0);
    });

    const compareName = (left, right) => String(left.name).localeCompare(String(right.name));
    const comparePrice = (left, right) => Number(left.price) - Number(right.price);
    const compareStock = (left, right) => Number(right.quantity) - Number(left.quantity);
    const comparators = {
        name: compareName,
        price_asc: comparePrice,
        price_desc: (left, right) => comparePrice(right, left),
        stock_desc: compareStock,
    };
    const comparator = comparators[sort] || compareName;

    return matching.sort((left, right) => comparator(left, right) || compareName(left, right));
}
