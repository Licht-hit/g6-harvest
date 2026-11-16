create or replace function public.cancel_buyer_order(p_order_code text)
returns void
language plpgsql
security definer
set search_path = public, pg_temp
as $$
declare
    buyer_key bigint := public.current_profile_id();
    buyer_role text;
    current_order public.orders%rowtype;
begin
    select role into buyer_role
    from public.profiles
    where id = buyer_key;
    if buyer_key is null or buyer_role is distinct from 'buyer' then
        raise exception 'Only buyers can cancel their own orders.';
    end if;

    select * into current_order
    from public.orders
    where order_code = p_order_code and buyer_id = buyer_key
    for update;
    if not found then raise exception 'Order not found.'; end if;
    if current_order.order_status not in ('pending', 'confirmed', 'preparing', 'ready_for_delivery') then
        raise exception 'This order is no longer eligible for buyer cancellation.';
    end if;
    if current_order.payment_status in ('paid', 'refunded') then
        raise exception 'Paid orders must be handled by farm staff.';
    end if;
    if current_order.payment_method = 'online' and current_order.gateway_expired_at is null then
        raise exception 'Online checkout must be cancelled before stock can be released.';
    end if;

    update public.inventory i
    set quantity = i.quantity + s.quantity, updated_at = now()
    from public.sales s
    where s.order_code = current_order.order_code
        and i.vegetable_id = s.vegetable_id and s.status <> 'cancelled';
    perform public.restore_fifo_lots(current_order.order_code);
    update public.sales
    set status = 'cancelled'
    where order_code = current_order.order_code;
    update public.orders
    set order_status = 'cancelled', payment_status = 'failed', updated_at = now()
    where order_code = current_order.order_code;
    insert into public.payment_history (
        order_code, payment_method, event_type, payment_status, amount, actor_id
    )
    values (
        current_order.order_code,
        current_order.payment_method,
        'order_cancelled',
        'failed',
        current_order.final_total,
        buyer_key
    );
    perform public.write_activity_log(
        'buyer_order_cancelled',
        'order',
        current_order.id,
        'Buyer cancelled the order before dispatch.'
    );
end;
$$;

revoke all on function public.cancel_buyer_order(text) from public, anon;
grant execute on function public.cancel_buyer_order(text) to authenticated;
