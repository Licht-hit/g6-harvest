`alter table public.vegetables
    add column if not exists image_path text;

insert into storage.buckets (id, name, public, file_size_limit, allowed_mime_types)
values (
    'vegetable-images',
    'vegetable-images',
    true,
    5242880,
    array['image/jpeg', 'image/png', 'image/webp']
)
on conflict (id) do update
set public = excluded.public,
    file_size_limit = excluded.file_size_limit,
    allowed_mime_types = excluded.allowed_mime_types;

drop policy if exists "Farm staff can upload vegetable photos" on storage.objects;
create policy "Farm staff can upload vegetable photos"
on storage.objects for insert to authenticated
with check (bucket_id = 'vegetable-images' and public.is_farm_staff());

drop policy if exists "Farm staff can update vegetable photos" on storage.objects;
create policy "Farm staff can update vegetable photos"
on storage.objects for update to authenticated
using (bucket_id = 'vegetable-images' and public.is_farm_staff())
with check (bucket_id = 'vegetable-images' and public.is_farm_staff());

drop policy if exists "Farm staff can delete vegetable photos" on storage.objects;
create policy "Farm staff can delete vegetable photos"
on storage.objects for delete to authenticated
using (bucket_id = 'vegetable-images' and public.is_farm_staff());
