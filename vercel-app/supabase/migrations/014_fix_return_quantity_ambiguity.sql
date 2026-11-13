create or replace function public.create_return_request(
    p_order_code text,
    p_items jsonb,
    p_reason text,
    p_notes text default null
)
returns bigint
language plpgsql
security definer
set search_path = public, pg_temp
as $$
declare
    buyer_key bigint := public.current_profile_id();
    current_order public.orders%rowtype;
    normalized_items jsonb;
    item_row jsonb;
    sale_key bigint;
    line_requested_quantity numeric;
    sold_quantity numeric;
    already_requested numeric;
    return_request_key bigint;
begin
    if buyer_key is null or public.current_role() <> 'buyer' then
        raise exception 'A buyer account is required to request a return.';
    end if;
    if p_reason is null or p_reason not in ('damaged', 'quality_issue') then
        raise exception 'Returns are accepted only for damaged items or quality issues.';
    end if;
    if char_length(coalesce(p_notes, '')) > 2000 then
        raise exception 'Return notes cannot exceed 2,000 characters.';
    end if;
    if p_items is null or jsonb_typeof(p_items) <> 'array' then
        raise exception 'Select at least one order item to return.';
    end if;
    if jsonb_array_length(p_items) = 0 then
        raise exception 'Select at least one order item to return.';
    end if;
    if exists (
        select 1
        from jsonb_array_elements(p_items) as entry(value)
        where jsonb_typeof(entry.value) <> 'object'
            or coalesce(entry.value ->> 'sales_id', '') !~ '^[1-9][0-9]*$'
            or coalesce(entry.value ->> 'quantity', '') !~ '^[0-9]+([.][0-9]{1,2})?$'
    ) then
        raise exception 'A return item or quantity is invalid.';
    end if;

    select jsonb_agg(jsonb_build_object(
        'sales_id', normalized.sales_id,
        'quantity', normalized.total_requested_quantity
    ) order by normalized.sales_id)
    into normalized_items
    from (
        select (entry.value ->> 'sales_id')::bigint as sales_id,
            sum(round((entry.value ->> 'quantity')::numeric, 2)) as total_requested_quantity
        from jsonb_array_elements(p_items) as entry(value)
        group by (entry.value ->> 'sales_id')::bigint
    ) as normalized;

    select * into current_order
    from public.orders
    where order_code = p_order_code and buyer_id = buyer_key
    for update;
    if not found then raise exception 'Order not found.'; end if;
    if current_order.order_status <> 'delivered'
        or current_order.delivered_at is null
        or current_order.delivered_at > now()
        or current_order.delivered_at < now() - interval '24 hours' then
        raise exception 'Return requests must be submitted within 24 hours after delivery.';
    end if;

    for item_row in
        select value
        from jsonb_array_elements(normalized_items)
        order by (value ->> 'sales_id')::bigint
    loop
        sale_key := (item_row ->> 'sales_id')::bigint;
        line_requested_quantity := round((item_row ->> 'quantity')::numeric, 2);
        if line_requested_quantity <= 0 or line_requested_quantity > 10000 then
            raise exception 'Return quantities must be positive and no more than 10,000.';
        end if;

        select quantity into sold_quantity
        from public.sales
        where id = sale_key
            and order_code = current_order.order_code
            and buyer_id = buyer_key
            and status <> 'cancelled'
        for update;
        if not found then raise exception 'A selected item does not belong to this order.'; end if;

        select coalesce(sum(rri.quantity), 0) into already_requested
        from public.return_request_items rri
        join public.return_requests rr on rr.id = rri.return_request_id
        where rri.sales_id = sale_key
            and rr.status in ('pending', 'approved', 'completed');
        if line_requested_quantity > sold_quantity - already_requested then
            raise exception 'The requested return quantity exceeds the unreturned quantity.';
        end if;
    end loop;

    insert into public.return_requests (order_code, buyer_id, reason, notes)
    values (current_order.order_code, buyer_key, p_reason, nullif(trim(p_notes), ''))
    returning id into return_request_key;

    for item_row in select value from jsonb_array_elements(normalized_items) loop
        insert into public.return_request_items (return_request_id, sales_id, quantity)
        values (
            return_request_key,
            (item_row ->> 'sales_id')::bigint,
            round((item_row ->> 'quantity')::numeric, 2)
        );
    end loop;

    perform public.write_activity_log(
        'created', 'return_request', return_request_key,
        'Return request submitted for order ' || current_order.order_code
    );
    return return_request_key;
end;
$$;
