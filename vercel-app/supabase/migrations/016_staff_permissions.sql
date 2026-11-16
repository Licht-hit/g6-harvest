drop policy if exists "Farm staff manage vegetables" on public.vegetables;
create policy "Farm managers manage vegetables"
on public.vegetables for all to authenticated
using (public.current_role() in ('admin', 'farmer'))
with check (public.current_role() in ('admin', 'farmer'));

drop policy if exists "Farm staff can upload vegetable photos" on storage.objects;
create policy "Farm managers can upload vegetable photos"
on storage.objects for insert to authenticated
with check (
    bucket_id = 'vegetable-images'
    and public.current_role() in ('admin', 'farmer')
);

drop policy if exists "Farm staff can update vegetable photos" on storage.objects;
create policy "Farm managers can update vegetable photos"
on storage.objects for update to authenticated
using (
    bucket_id = 'vegetable-images'
    and public.current_role() in ('admin', 'farmer')
)
with check (
    bucket_id = 'vegetable-images'
    and public.current_role() in ('admin', 'farmer')
);

drop policy if exists "Farm staff can delete vegetable photos" on storage.objects;
create policy "Farm managers can delete vegetable photos"
on storage.objects for delete to authenticated
using (
    bucket_id = 'vegetable-images'
    and public.current_role() in ('admin', 'farmer')
);
