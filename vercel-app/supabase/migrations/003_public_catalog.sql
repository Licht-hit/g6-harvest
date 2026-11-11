grant select on public.vegetables to anon;

drop policy if exists "Public can view active vegetables" on public.vegetables;
create policy "Public can view active vegetables"
on public.vegetables for select to anon
using (active = true);