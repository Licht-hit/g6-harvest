do $$
begin
    if not exists (
        select 1
        from pg_publication_tables
        where pubname = 'supabase_realtime'
          and schemaname = 'public'
          and tablename = 'return_requests'
    ) then
        alter publication supabase_realtime add table public.return_requests;
    end if;
end;
$$;
