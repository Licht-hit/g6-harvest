`create or replace function public.admin_change_profile_role(
    p_actor_id bigint,
    p_target_id bigint,
    p_next_role text
)
returns void
language plpgsql
security definer
set search_path = public, pg_temp
as $$
declare
    actor_role text;
    target_profile public.profiles%rowtype;
    administrator_count integer;
begin
    if coalesce(auth.role(), '') <> 'service_role' then
        raise exception 'Not authorized.';
    end if;

    if p_next_role not in ('admin', 'staff', 'farmer', 'buyer') then
        raise exception 'Choose a valid role.';
    end if;

    perform pg_advisory_xact_lock(1729901, 1);

    select role into actor_role
    from public.profiles
    where id = p_actor_id;
    if actor_role is distinct from 'admin' then
        raise exception 'Only administrators can manage user roles.';
    end if;

    if p_actor_id = p_target_id then
        raise exception 'You cannot change your own role.';
    end if;

    select * into target_profile
    from public.profiles
    where id = p_target_id
    for update;
    if not found then
        raise exception 'User not found.';
    end if;
    if target_profile.role = p_next_role then
        raise exception 'That account already has this role.';
    end if;
    if p_next_role <> 'buyer' and target_profile.email_verified_at is null then
        raise exception 'Verify their email before granting an operations role.';
    end if;

    if target_profile.role = 'admin' and p_next_role <> 'admin' then
        select count(*) into administrator_count
        from public.profiles
        where role = 'admin';
        if administrator_count <= 1 then
            raise exception 'You cannot remove the role from the last administrator.';
        end if;
    end if;

    update public.profiles
    set role = p_next_role
    where id = p_target_id;

    insert into public.activity_logs (user_id, action, entity_type, entity_id, details)
    values (
        p_actor_id,
        'user_role_changed',
        'user',
        p_target_id,
        left('Role changed from ' || target_profile.role || ' to ' || p_next_role || '.', 255)
    );
end;
$$;

revoke all on function public.admin_change_profile_role(bigint, bigint, text) from public, anon, authenticated;
grant execute on function public.admin_change_profile_role(bigint, bigint, text) to service_role;
`