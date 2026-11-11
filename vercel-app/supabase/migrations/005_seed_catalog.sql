insert into public.vegetables
    (name, category, variety, description, unit, price, active)
select seed.name, seed.category, seed.variety, seed.description, seed.unit, seed.price, true
from (values
    ('Tomato', 'Fruiting vegetables', 'Roma', 'Firm, flavorful tomatoes, ideal for salads and cooking.', 'kg', 40.50::numeric),
    ('Carrot', 'Root vegetables', 'Nantes', 'Sweet, crisp carrots freshly harvested from the farm.', 'kg', 25.25::numeric),
    ('Lettuce', 'Leafy greens', 'Butterhead', 'Tender leafy lettuce, harvested to order.', 'head', 10.75::numeric),
    ('Cucumber', 'Cucurbits', 'Marketmore', 'Crisp cucumbers for salads, sandwiches, and pickling.', 'kg', 10.80::numeric),
    ('Spinach', 'Leafy greens', 'Baby Leaf', 'Young, tender spinach leaves, packed fresh.', 'bunch', 15.00::numeric),
    ('Bell Pepper', 'Fruiting vegetables', 'California Wonder', 'Colorful sweet peppers with a crisp texture.', 'kg', 40.25::numeric),
    ('Zucchini', 'Cucurbits', 'Black Beauty', 'Fresh green summer squash, versatile for cooking.', 'kg', 20.60::numeric),
    ('Broccoli', 'Cruciferous vegetables', 'Calabrese', 'Compact green florets, rich in flavor.', 'head', 20.40::numeric),
    ('Kale', 'Leafy greens', 'Curly Green', 'Hearty leafy greens for salads, soups, and sautés.', 'bunch', 20.15::numeric),
    ('Green Beans', 'Legumes', 'Provider', 'Tender green beans, picked at peak freshness.', 'kg', 30.20::numeric)
) as seed(name, category, variety, description, unit, price)
where not exists (
    select 1
    from public.vegetables existing
    where lower(existing.name) = lower(seed.name)
);
