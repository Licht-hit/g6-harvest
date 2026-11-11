alter table public.vegetables
add column if not exists category varchar(80) not null default 'Other';
