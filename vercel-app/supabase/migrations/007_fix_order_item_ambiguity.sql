create or replace function public.create_order(
    p_items jsonb, p_delivery_address text, p_payment_method text, p_checkout_token text
)
returns jsonb
language plpgsql
security definer
set search_path = public, pg_temp
as $$
declare
    buyer_key bigint := public.current_profile_id();
    token_hash text;
    request_hash text;
    existing_order public.orders%rowtype;
    normalized_items jsonb;
    order_key bigint;
    order_number text;
    subtotal_value numeric := 0;
    eligible_value numeric := 0;
    discount_rate numeric := 0;
    discount_value numeric := 0;
    delivery_value numeric := 20;
    cart_item jsonb;
    product_key bigint;
    quantity_value numeric;
    price_value numeric;
    line_total numeric;
    stock_value numeric;
begin
    if buyer_key is null or public.current_role() <> 'buyer' then raise exception 'A buyer account is required.'; end if;
    if p_payment_method not in ('cod', 'online') then raise exception 'Choose a supported payment method.'; end if;
    if char_length(trim(p_delivery_address)) < 10 or char_length(trim(p_delivery_address)) > 1000 then
        raise exception 'Delivery address must be between 10 and 1,000 characters.';
    end if;
    if p_checkout_token is null or p_checkout_token !~ '^[a-f0-9]{64}$' then
        raise exception 'Checkout token is invalid; reload checkout.';
    end if;
    if jsonb_typeof(p_items) <> 'array' or jsonb_array_length(p_items) = 0 then raise exception 'Your cart is empty.'; end if;

    select jsonb_agg(jsonb_build_object('id', item_id, 'quantity', item_quantity) order by item_id)
    into normalized_items
    from (
        select (value ->> 'id')::bigint as item_id,
            sum(round((value ->> 'quantity')::numeric, 2)) as item_quantity
        from jsonb_array_elements(p_items)
        group by (value ->> 'id')::bigint
    ) cart;
    if normalized_items is null or exists (
        select 1
        from jsonb_array_elements(normalized_items) as cart_entry(value)
        where (cart_entry.value ->> 'quantity')::numeric <= 0
            or (cart_entry.value ->> 'quantity')::numeric > 10000
            or (cart_entry.value ->> 'quantity')::numeric::text in ('NaN', 'Infinity', '-Infinity')
    ) then
        raise exception 'Cart quantity is invalid.';
    end if;

    token_hash := encode(extensions.digest(p_checkout_token, 'sha256'), 'hex');
    perform pg_advisory_xact_lock(hashtextextended(buyer_key::text || token_hash, 0));
    request_hash := encode(extensions.digest(jsonb_build_object(
        'items', normalized_items, 'address', trim(p_delivery_address), 'payment_method', p_payment_method
    )::text, 'sha256'), 'hex');
    select * into existing_order from public.orders
    where buyer_id = buyer_key and checkout_token_hash = token_hash;
    if found then
        if existing_order.checkout_request_hash <> request_hash then
            raise exception 'This checkout form was already used for a different order.';
        end if;
        return to_jsonb(existing_order);
    end if;

    for cart_item in
        select value
        from jsonb_array_elements(normalized_items)
        order by (value ->> 'id')::bigint
    loop
        product_key := (cart_item ->> 'id')::bigint;
        quantity_value := (cart_item ->> 'quantity')::numeric;
        if quantity_value <= 0 or quantity_value > 10000 then raise exception 'Cart quantity is invalid.'; end if;
        select v.price, i.quantity into price_value, stock_value
        from public.vegetables v join public.inventory i on i.vegetable_id = v.id
        where v.id = product_key and v.active for update of i;
        if not found then raise exception 'A cart item is no longer available.'; end if;
        if stock_value < quantity_value then raise exception 'There is not enough stock to complete this order.'; end if;
        eligible_value := eligible_value + quantity_value;
        subtotal_value := subtotal_value + round(price_value * quantity_value, 2);
    end loop;
    subtotal_value := round(subtotal_value, 2);
    eligible_value := round(eligible_value, 2);
    if eligible_value >= 20 then discount_rate := 10; end if;
    discount_value := round(subtotal_value * discount_rate / 100, 2);

    order_number := 'VS-' || to_char(clock_timestamp(), 'YYMMDDHH24MISS') || upper(substr(encode(extensions.gen_random_bytes(4), 'hex'), 1, 8));
    insert into public.orders (
        order_code, buyer_id, checkout_token_hash, checkout_request_hash,
        subtotal, eligible_quantity, discount_percentage, discount_amount, delivery_fee,
        final_total, delivery_address, payment_method, payment_status, order_status
    ) values (
        order_number, buyer_key, token_hash, request_hash,
        subtotal_value, eligible_value, discount_rate, discount_value, delivery_value,
        round(subtotal_value - discount_value + delivery_value, 2), trim(p_delivery_address),
        p_payment_method, 'pending_payment', 'confirmed'
    ) returning id into order_key;

    for cart_item in
        select value
        from jsonb_array_elements(normalized_items)
        order by (value ->> 'id')::bigint
    loop
        product_key := (cart_item ->> 'id')::bigint;
        quantity_value := (cart_item ->> 'quantity')::numeric;
        select price into price_value from public.vegetables where id = product_key and active;
        line_total := round(price_value * quantity_value, 2);
        update public.inventory set quantity = quantity - quantity_value, updated_at = now()
        where vegetable_id = product_key and quantity >= quantity_value;
        if not found then raise exception 'Inventory changed while placing the order; please retry.'; end if;
        insert into public.sales (order_code, buyer_id, vegetable_id, quantity, unit_price, total)
        values (order_number, buyer_key, product_key, round(quantity_value, 2), price_value, line_total);
    end loop;

    insert into public.payment_history (order_code, payment_method, event_type, payment_status, amount, actor_id)
    values (order_number, p_payment_method, 'order_created', 'pending_payment',
        round(subtotal_value - discount_value + delivery_value, 2), buyer_key);
    perform public.write_activity_log('created', 'order', order_key, order_number);
    select * into existing_order from public.orders where id = order_key;
    return to_jsonb(existing_order);
end;
$$;
