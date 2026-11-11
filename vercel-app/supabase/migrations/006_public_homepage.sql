create or replace function public.get_public_featured_products()
returns table (
    id bigint,
    name varchar(120),
    category varchar(80),
    variety varchar(120),
    description text,
    unit varchar(30),
    price numeric(10, 2),
    in_stock boolean
)
language sql
stable
security definer
set search_path = public, pg_temp
as $$
    select v.id, v.name, v.category, v.variety, v.description, v.unit, v.price,
        coalesce(i.quantity, 0) > 0
    from public.vegetables v
    left join public.inventory i on i.vegetable_id = v.id
    where v.active = true
    order by (coalesce(i.quantity, 0) > 0) desc, v.name
    limit 6
$$;

create or replace function public.get_public_home_stats()
returns table (
    vegetable_count bigint,
    available_count bigint,
    completed_orders bigint,
    harvest_count bigint
)
language sql
stable
security definer
set search_path = public, pg_temp
as $$
    select
        (select count(*) from public.vegetables where active = true),
        (select count(*) from public.vegetables v
            join public.inventory i on i.vegetable_id = v.id
            where v.active = true and i.quantity > 0),
        (select count(*) from public.orders where order_status = 'delivered'),
        (select count(*) from public.harvests where archived_at is null)
$$;

revoke all on function public.get_public_featured_products() from public, authenticated;
revoke all on function public.get_public_home_stats() from public, authenticated;
grant execute on function public.get_public_featured_products() to anon, authenticated;
grant execute on function public.get_public_home_stats() to anon, authenticated;
