alter table public.profiles
    drop constraint if exists profiles_role_check;

alter table public.profiles
    add constraint profiles_role_check
    check (role in ('admin', 'staff', 'farmer', 'buyer'));

create or replace function public.is_farm_staff()
returns boolean
language sql
stable
security definer
set search_path = public, pg_temp
as $$
    select coalesce(public.current_role() in ('admin', 'staff', 'farmer'), false)
$$;

create or replace function public.apply_paymongo_event(
    p_event_id text, p_event_type text, p_session_id text, p_amount_cents bigint, p_reference text
)
returns void
language plpgsql
security definer
set search_path = public, pg_temp
as $$
declare
    current_order public.orders%rowtype;
    next_status text;
    review_details text;
begin
    if auth.role() <> 'service_role' then raise exception 'Not authorized.'; end if;
    select * into current_order
    from public.orders
    where gateway_session_id = p_session_id
    for update;
    if not found or current_order.payment_method <> 'online' then
        raise exception 'Payment order was not found.';
    end if;
    if exists (
        select 1 from public.payment_history where provider_event_id = p_event_id
    ) then
        return;
    end if;

    if p_event_type = 'checkout_session.payment.paid' then
        if p_amount_cents is null then raise exception 'Payment amount is missing.'; end if;
        if p_amount_cents <> round(current_order.final_total * 100)::bigint then
            raise exception 'Payment amount does not match the order total.';
        end if;
        next_status := 'paid';
        if current_order.order_status = 'cancelled'
            or current_order.gateway_expired_at is not null
            or current_order.payment_status in ('paid', 'refunded') then
            review_details :=
                'Paid event arrived after cancellation, checkout expiry, or a previous payment. Review and reconcile manually.';
        end if;
    elsif p_event_type = 'checkout_session.payment.failed' then
        if current_order.order_status = 'cancelled'
            or current_order.payment_status in ('paid', 'refunded') then
            next_status := current_order.payment_status;
            review_details := 'Late payment failure event was recorded without changing the order.';
        else
            next_status := 'failed';
        end if;
    elsif p_event_type = 'payment.refunded' then
        if p_amount_cents is null then raise exception 'Refund amount is missing.'; end if;
        if p_amount_cents <> round(current_order.final_total * 100)::bigint then
            raise exception 'Refund amount does not match the order total.';
        end if;
        if current_order.payment_status <> 'paid' then
            next_status := current_order.payment_status;
            review_details := 'Refund event received for an order not marked paid. Review the payment manually.';
        else
            next_status := 'refunded';
        end if;
    else
        raise exception 'Unsupported PayMongo event.';
    end if;

    if review_details is not null then
        insert into public.payment_history (
            order_code, payment_method, event_type, payment_status, amount,
            payment_reference, provider_event_id, details
        )
        values (
            current_order.order_code, 'online', 'manual_review_required', next_status,
            coalesce(p_amount_cents / 100.0, current_order.final_total),
            nullif(p_reference, ''), p_event_id, left(review_details, 255)
        );
        perform public.write_activity_log(
            'manual_review_required',
            'payment',
            current_order.id,
            'PayMongo event requires manual review: ' || left(p_event_id, 160)
        );
        return;
    end if;

    update public.orders
    set payment_status = next_status,
        payment_reference = coalesce(nullif(p_reference, ''), payment_reference),
        updated_at = now()
    where order_code = current_order.order_code;

    if p_event_type = 'checkout_session.payment.paid' then
        update public.sales set status = 'paid'
        where order_code = current_order.order_code;
    end if;

    insert into public.payment_history (
        order_code, payment_method, event_type, payment_status,
        amount, payment_reference, provider_event_id
    )
    values (
        current_order.order_code, 'online', p_event_type, next_status,
        current_order.final_total, nullif(p_reference, ''), p_event_id
    );
end;
$$;
