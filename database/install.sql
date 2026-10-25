-- 東津御苑 GitHub Pages 版。請在自己的 Supabase 專案 SQL Editor 執行。
-- 不包含密碼或服務端金鑰，不會刪除既有訂單。
begin;
create schema if not exists dg_private;
revoke all on schema dg_private from public, anon, authenticated;
create table if not exists dg_private.rooms(id text primary key,name text not null,capacity int not null,weekday int not null,holiday int not null,rack int not null,extra boolean not null,feature text not null);
create table if not exists dg_private.bookings(id uuid primary key,token_hash text not null,room text not null references dg_private.rooms(id),checkin date not null,checkout date not null,name text not null,phone text not null,email text not null,adults int not null,children int not null,extra int not null,breakfast int not null,notes text not null,total int not null,quote jsonb not null,status text not null default 'pending' check(status in('pending','confirmed','cancelled')),created timestamptz not null default now(),updated timestamptz not null default now());
create table if not exists dg_private.nights(room text not null references dg_private.rooms(id),day date not null,booking uuid not null references dg_private.bookings(id),primary key(room,day));
create table if not exists dg_private.blocks(room text not null references dg_private.rooms(id),day date not null,primary key(room,day));
create table if not exists dg_private.calendar(day date primary key,kind text not null check(kind in('weekday','holiday','rack')));
create table if not exists dg_private.admins(user_id uuid primary key references auth.users(id) on delete cascade);
create table if not exists dg_private.revoked_sessions(id uuid primary key,revoked_at timestamptz not null default now());
create index if not exists dg_bookings_created on dg_private.bookings(created desc);
create index if not exists dg_bookings_phone_created on dg_private.bookings(phone,created);
insert into dg_private.rooms values
('101','和室雙人房',2,3000,3400,4000,true,'浴缸・榻榻米'),('102','洋式雙人房',2,3000,3400,4000,false,'浴缸・加大雙人床'),('103','洋式雙人房',2,2600,3000,3600,true,'乾濕分離浴室'),('104','和室雙人房',2,2600,3000,3600,true,'榻榻米・乾濕分離浴室'),('105','洋式雙人房',2,2400,2800,3200,false,'乾濕分離浴室'),('201','和室三人房',3,3000,3600,4500,true,'和室・家庭同行'),('202','洋式雙人房',2,2500,2900,3500,false,'乾濕分離浴室'),('203','洋式雙人房',2,2500,2900,3500,false,'乾濕分離浴室'),('204','和室六人房',6,5300,5900,7300,true,'寬敞和室・團體同行')
on conflict(id) do update set name=excluded.name,capacity=excluded.capacity,weekday=excluded.weekday,holiday=excluded.holiday,rack=excluded.rack,extra=excluded.extra,feature=excluded.feature;
alter table dg_private.rooms enable row level security;
alter table dg_private.bookings enable row level security;
alter table dg_private.nights enable row level security;
alter table dg_private.blocks enable row level security;
alter table dg_private.calendar enable row level security;
alter table dg_private.admins enable row level security;
alter table dg_private.revoked_sessions enable row level security;
revoke all on all tables in schema dg_private from public,anon,authenticated;

create or replace function dg_private.today() returns date language sql stable set search_path='' as $$select (now() at time zone 'Asia/Taipei')::date$$;
create or replace function dg_private.validate_dates(p_in date,p_out date) returns void language plpgsql stable set search_path='' as $$begin
 if p_in is null or p_out is null or p_in<dg_private.today() or p_out<=p_in or p_out-p_in>30 or p_in>dg_private.today()+366 then raise exception '請選擇一年內有效入住日期，退房須晚於入住，每筆最多30晚';end if;
end$$;
create or replace function dg_private.is_admin() returns boolean language sql stable security definer set search_path='' as $$
 select auth.uid() is not null and exists(select 1 from dg_private.admins a where a.user_id=auth.uid())
 and exists(select 1 from auth.sessions s where s.id=nullif(auth.jwt()->>'session_id','')::uuid and s.user_id=auth.uid())
 and not exists(select 1 from dg_private.revoked_sessions r where r.id=nullif(auth.jwt()->>'session_id','')::uuid)
$$;
create or replace function dg_private.require_admin() returns void language plpgsql stable security definer set search_path='' as $$begin
 if not dg_private.is_admin() then raise exception '請先登入已授權的管理帳號' using errcode='42501';end if;
end$$;
create or replace function dg_private.quote(p_room text,p_in date,p_out date,p_extra int,p_breakfast int) returns jsonb language sql stable set search_path='' as $$
 with days as(select (p_in+i)::date as day from generate_series(0,p_out-p_in-1) i),
 priced as(select d.day,coalesce(c.kind,case when extract(dow from d.day) in(5,6) then 'holiday' else 'weekday' end) kind,r.* from days d cross join dg_private.rooms r left join dg_private.calendar c on c.day=d.day where r.id=p_room),
 lines as(select day,kind,case kind when 'rack' then rack when 'holiday' then holiday else weekday end price from priced),
 agg as(select coalesce(sum(price),0)::int subtotal,jsonb_agg(jsonb_build_object('date',day,'kind',kind,'rate',price) order by day) details from lines)
 select jsonb_build_object('lines',details,'nights',p_out-p_in,'roomTotal',subtotal,'extraTotal',p_extra*600*(p_out-p_in),'breakfastTotal',p_breakfast*100*(p_out-p_in),'total',subtotal+(p_extra*600+p_breakfast*100)*(p_out-p_in)) from agg
$$;
create or replace function dg_private.public_receipt(b dg_private.bookings) returns jsonb language sql stable set search_path='' as $$
 select jsonb_build_object('id',b.id,'room',b.room,'checkin',b.checkin,'checkout',b.checkout,'adults',b.adults,'children',b.children,'extra',b.extra,'breakfast',b.breakfast,'total',b.total,'quote',b.quote,'status',b.status,'created',b.created)
$$;
create or replace function public.dg_meta() returns jsonb language sql stable security definer set search_path='' as $$
 select jsonb_build_object('version',1,'installed',exists(select 1 from dg_private.admins),'admin',case when dg_private.is_admin() then (select jsonb_build_object('username',u.email) from auth.users u where u.id=auth.uid()) else null end)
$$;
create or replace function public.dg_catalog(p_in date,p_out date) returns jsonb language plpgsql stable security definer set search_path='' as $$declare list jsonb;cal jsonb;begin
 perform dg_private.validate_dates(p_in,p_out);
 select jsonb_agg(to_jsonb(r)||jsonb_build_object('available',not exists(select 1 from dg_private.nights n where n.room=r.id and n.day>=p_in and n.day<p_out) and not exists(select 1 from dg_private.blocks b where b.room=r.id and b.day>=p_in and b.day<p_out),'quote',dg_private.quote(r.id,p_in,p_out,0,0)) order by r.id) into list from dg_private.rooms r;
 select coalesce(jsonb_object_agg(day::text,kind),'{}'::jsonb) into cal from dg_private.calendar;return jsonb_build_object('rooms',list,'calendar',cal);
end$$;
create or replace function public.dg_request(p jsonb) returns jsonb language plpgsql security definer set search_path='' as $$
declare r dg_private.rooms;b dg_private.bookings;v_id uuid;v_in date;v_out date;v_adults int;v_children int;v_extra int;v_breakfast int;v_quote jsonb;v_token text;v_phone text;
begin
 if not exists(select 1 from dg_private.admins) then raise exception '訂房後端尚未完成管理帳號設定';end if;
 if p is null or octet_length(p::text)>12000 or coalesce(p->>'website','')<>'' or p->'consent' is distinct from 'true'::jsonb then raise exception '請確認表單內容並同意訂房須知';end if;
 if coalesce(p->>'id','')!~'^[a-f0-9-]{36}$' or coalesce(p->>'token','')!~'^[a-f0-9]{64}$' then raise exception '申請識別資料錯誤';end if;
 v_id:=(p->>'id')::uuid;v_token:=encode(sha256(convert_to(p->>'token','UTF8')),'hex');
 -- A request UUID has its own lock so identical retries cannot produce duplicate orders.
 perform pg_advisory_xact_lock(hashtextextended('dg-request:'||v_id::text,0));
 select * into b from dg_private.bookings where id=v_id;
 if found then if b.token_hash<>v_token then raise exception '申請識別碼重複';end if;return jsonb_build_object('booking',dg_private.public_receipt(b));end if;
 select * into r from dg_private.rooms where id=p->>'room';if not found then raise exception '請選擇有效房型';end if;
 v_in:=(p->>'checkin')::date;v_out:=(p->>'checkout')::date;perform dg_private.validate_dates(v_in,v_out);
 if coalesce(p->>'adults','')!~'^\d{1,2}$' or coalesce(p->>'children','')!~'^\d{1,2}$' or coalesce(p->>'extra','')!~'^\d$' or coalesce(p->>'breakfast','')!~'^\d{1,2}$' then raise exception '入住人數格式錯誤';end if;
 v_adults:=(p->>'adults')::int;v_children:=(p->>'children')::int;v_extra:=(p->>'extra')::int;v_breakfast:=(p->>'breakfast')::int;
 if v_extra not between 0 and 1 or (not r.extra and v_extra>0) or v_adults<1 or v_adults>r.capacity+v_extra or v_children not between 0 and 6 or v_breakfast<0 or v_breakfast>v_adults+v_children then raise exception '入住人數或加購不符合房型';end if;
 v_phone:=coalesce(p->>'phone','');
 if length(trim(coalesce(p->>'name','')))=0 or length(p->>'name')>50 or v_phone!~'^\+?[0-9 ()-]{8,22}$' or length(coalesce(p->>'email',''))>150 or (coalesce(p->>'email','')<>'' and (p->>'email')!~'^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$') or length(coalesce(p->>'notes',''))>600 then raise exception '請檢查聯絡資料';end if;
 perform pg_advisory_xact_lock(hashtextextended('dg-phone:'||regexp_replace(v_phone,'\D','','g'),0));
 if (select count(*) from dg_private.bookings where regexp_replace(phone,'\D','','g')=regexp_replace(v_phone,'\D','','g') and created>now()-interval '15 minutes')>=5 then raise exception '此電話短時間申請過多，請稍後或聯絡民宿';end if;
 perform pg_advisory_xact_lock(hashtextextended('dg-room:'||r.id,0));
 if exists(select 1 from dg_private.nights where room=r.id and day>=v_in and day<v_out) or exists(select 1 from dg_private.blocks where room=r.id and day>=v_in and day<v_out) then raise exception '此房間日期已訂滿或關房，請重新選擇';end if;
 v_quote:=dg_private.quote(r.id,v_in,v_out,v_extra,v_breakfast);
 insert into dg_private.bookings(id,token_hash,room,checkin,checkout,name,phone,email,adults,children,extra,breakfast,notes,total,quote) values(v_id,v_token,r.id,v_in,v_out,trim(p->>'name'),v_phone,coalesce(p->>'email',''),v_adults,v_children,v_extra,v_breakfast,coalesce(p->>'notes',''),(v_quote->>'total')::int,v_quote) returning * into b;
 return jsonb_build_object('booking',dg_private.public_receipt(b));
end$$;
create or replace function public.dg_lookup(p_id uuid,p_token text) returns jsonb language plpgsql stable security definer set search_path='' as $$declare b dg_private.bookings;begin
 if p_token is null or p_token!~'^[a-f0-9]{64}$' then raise exception '查詢資料錯誤';end if;
 select * into b from dg_private.bookings where id=p_id and token_hash=encode(sha256(convert_to(p_token,'UTF8')),'hex');
 if not found then raise exception '找不到訂單，請確認訂單編號與查詢碼';end if;return jsonb_build_object('booking',dg_private.public_receipt(b));
end$$;
create or replace function public.dg_admin_list() returns jsonb language plpgsql stable security definer set search_path='' as $$declare orders jsonb;cal jsonb;closed jsonb;begin
 perform dg_private.require_admin();
 select coalesce(jsonb_agg(to_jsonb(b)-'token_hash' order by b.created desc),'[]'::jsonb) into orders from(select * from dg_private.bookings order by created desc limit 500)b;
 select coalesce(jsonb_object_agg(day::text,kind),'{}'::jsonb) into cal from dg_private.calendar;
 select coalesce(jsonb_agg(to_jsonb(b) order by b.day,b.room),'[]'::jsonb) into closed from dg_private.blocks b;
 return jsonb_build_object('bookings',orders,'calendar',cal,'blocks',closed);
end$$;
create or replace function public.dg_admin_action(p jsonb) returns jsonb language plpgsql security definer set search_path='' as $$declare op text:=p->>'op';b dg_private.bookings;v_day date;v_room text;v_kind text;begin
 perform dg_private.require_admin();
 if op='rate' then
  v_day:=(p->>'day')::date;v_kind:=p->>'kind';if v_day is null or v_kind is null or v_kind not in('weekday','holiday','rack','default') then raise exception '房價設定錯誤';end if;
  if v_kind='default' then delete from dg_private.calendar where day=v_day;else insert into dg_private.calendar(day,kind)values(v_day,v_kind) on conflict(day)do update set kind=excluded.kind;end if;
 elsif op='block' then
  v_room:=p->>'room';v_day:=(p->>'day')::date;if v_day is null or not exists(select 1 from dg_private.rooms where id=v_room) or p->'closed' not in('true'::jsonb,'false'::jsonb) or not(p?'closed') then raise exception '關房資料錯誤';end if;
  perform pg_advisory_xact_lock(hashtextextended('dg-room:'||v_room,0));
  if (p->>'closed')::boolean then
   if exists(select 1 from dg_private.nights where room=v_room and day=v_day) then raise exception '此日期已有確認訂單，無法關房';end if;
   insert into dg_private.blocks values(v_room,v_day)on conflict do nothing;
  else delete from dg_private.blocks where room=v_room and day=v_day;end if;
 elsif op in('confirm','cancel') then
  -- Serialize operations on this reservation, then on its room.
  select * into b from dg_private.bookings where id=(p->>'id')::uuid for update;if not found then raise exception '找不到訂單';end if;
  perform pg_advisory_xact_lock(hashtextextended('dg-room:'||b.room,0));
  if op='confirm' then
   if b.status<>'pending' or b.checkin<dg_private.today() then raise exception '訂單狀態已變更或已過入住日期';end if;
   if exists(select 1 from dg_private.nights where room=b.room and day>=b.checkin and day<b.checkout) or exists(select 1 from dg_private.blocks where room=b.room and day>=b.checkin and day<b.checkout) then raise exception '房間日期衝突或已關房，無法確認';end if;
   insert into dg_private.nights(room,day,booking)select b.room,b.checkin+i,b.id from generate_series(0,b.checkout-b.checkin-1)i;
   update dg_private.bookings set status='confirmed',updated=now()where id=b.id;
  else delete from dg_private.nights where booking=b.id;update dg_private.bookings set status='cancelled',updated=now()where id=b.id;end if;
 else raise exception '操作無效';end if;
 return jsonb_build_object('ok',true);
end$$;
create or replace function public.dg_end_sessions(p_all boolean default false) returns jsonb language plpgsql security definer set search_path='' as $$begin
 perform dg_private.require_admin();
 if p_all then insert into dg_private.revoked_sessions(id)select id from auth.sessions where user_id=auth.uid() on conflict do nothing;
 else insert into dg_private.revoked_sessions(id)values((auth.jwt()->>'session_id')::uuid)on conflict do nothing;end if;
 delete from dg_private.revoked_sessions where revoked_at<now()-interval '30 days';return jsonb_build_object('ok',true);
end$$;
-- Helpers have no client access. Only bounded public RPCs can read/write data.
revoke all on all functions in schema dg_private from public,anon,authenticated;
revoke all on function public.dg_meta(),public.dg_catalog(date,date),public.dg_request(jsonb),public.dg_lookup(uuid,text),public.dg_admin_list(),public.dg_admin_action(jsonb),public.dg_end_sessions(boolean) from public,anon,authenticated;
grant execute on function public.dg_meta(),public.dg_catalog(date,date),public.dg_request(jsonb),public.dg_lookup(uuid,text) to anon,authenticated;
grant execute on function public.dg_admin_list(),public.dg_admin_action(jsonb),public.dg_end_sessions(boolean) to authenticated;
notify pgrst,'reload schema';
commit;
