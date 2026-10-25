-- 先在 Supabase Dashboard > Authentication > Users 建立 Email/Password 管理帳號。
-- 把下面的 ADMIN_EMAIL_HERE 換成剛建立帳號的信箱，再執行。
-- 請勿在此填密碼，也不要把資料庫管理金鑰放到網站。
do $$
declare v_id uuid;
begin
 select id into v_id from auth.users where lower(email)=lower('ADMIN_EMAIL_HERE');
 if v_id is null then raise exception '找不到此信箱的Auth使用者，請先建立Email/Password帳號';end if;
 insert into dg_private.admins(user_id)values(v_id)on conflict do nothing;
end$$;
