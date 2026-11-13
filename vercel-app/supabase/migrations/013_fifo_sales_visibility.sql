create policy "Farm staff can view FIFO order allocations"
on public.inventory_lot_allocations for select to authenticated
using (public.is_farm_staff());

grant select on public.inventory_lot_allocations to authenticated;
