<?php
declare(strict_types=1);
try {
 require dirname(__DIR__,2).'/private/bootstrap.php';
 require_https();$action=$_GET['action']??'';$method=$_SERVER['REQUEST_METHOD'];
 if($method==='GET'&&$action==='me'){reply(['csrf'=>csrf(),'admin'=>current_admin(),'installed'=>(bool)q('SELECT id FROM admin_users WHERE id=1')->fetch()]);}
 if($method==='GET'&&$action==='catalog'){
  $in=(string)($_GET['in']??'');$out=(string)($_GET['out']??'');$days=nights($in,$out);$list=[];foreach(rooms()as$r)$list[]=array_merge($r,['available'=>!busy($r['id'],$in,$out),'quote'=>quote($r,$days,0,0)]);reply(['rooms'=>$list,'calendar'=>rates()]);
 }
 if($method==='GET'&&$action==='admin'){require_admin();reply(['bookings'=>q('SELECT id,room,checkin,checkout,name,phone,email,adults,children,extra,breakfast,notes,total,quote,status,created,updated FROM bookings ORDER BY created DESC LIMIT 500')->fetchAll(),'calendar'=>rates(),'blocks'=>q('SELECT room,day FROM blocks ORDER BY day,room')->fetchAll()]);}
 if($method!=='POST')reply(['error'=>'操作無效'],404);
 $b=input_json();check_csrf($b);
 if($action==='install'){
  throttle('install','setup');$keyFile=dirname(__DIR__,2).'/private/setup.key';$expected=is_file($keyFile)?trim(file_get_contents($keyFile)):'';
  if(strlen($expected)<32||!is_string($b['key']??null)||!hash_equals($expected,$b['key']))throw new RuntimeException('安裝碼不正確，請查看主機上的 private/setup.key');
  if(!valid_username($b['username']??null)||!valid_password($b['password']??null))throw new RuntimeException('帳號需3至80個英數字或 @ . _ -，密碼需12至128個位元組');
  $digest=hash_password($b['password']);tx(function()use($b,$digest){if(q('SELECT id FROM admin_users WHERE id=1')->fetch())throw new RuntimeException('管理帳號已建立，請直接登入');q('INSERT INTO admin_users(id,username,password_hash,version) VALUES(1,?,?,1)',[strtolower($b['username']),$digest]);});@unlink($keyFile);reply(['ok'=>true]);
 }
 if($action==='login'){
  $username=$b['username']??'';$password=$b['password']??'';
  if(!valid_username($username)||!is_string($password)||strlen($password)>128)throw new RuntimeException('帳號或密碼不正確');throttle('login',strtolower($username));
  $u=q('SELECT * FROM admin_users WHERE id=1 AND username=?',[strtolower($username)])->fetch();
  $dummy='$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
  if(!password_verify($password,$u['password_hash']??$dummy)||!$u)throw new RuntimeException('帳號或密碼不正確');
  session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(32));$_SESSION['admin']=['username'=>$u['username'],'version'=>(int)$u['version'],'created'=>time(),'last'=>time()];reply(['ok'=>true,'csrf'=>csrf()]);
 }
 if($action==='logout'){signout();reply(['ok'=>true,'csrf'=>csrf()]);}
 if($action==='password'){
  require_admin();throttle('password','admin');if(!valid_password($b['password']??null)||!is_string($b['oldPassword']??null)||strlen($b['oldPassword'])>128)throw new RuntimeException('新密碼需12至128個位元組');
  $u=q('SELECT * FROM admin_users WHERE id=1')->fetch();if(!password_verify($b['oldPassword'],$u['password_hash']))throw new RuntimeException('目前密碼不正確');
  q('UPDATE admin_users SET password_hash=?,version=version+1 WHERE id=1',[hash_password($b['password'])]);signout();reply(['ok'=>true]);
 }
 if($action==='booking'){
  if(!q('SELECT id FROM admin_users WHERE id=1')->fetch())throw new RuntimeException('訂房系統尚未安裝完成，請聯絡民宿');throttle('booking','all',30);if(!empty($b['website']))throw new RuntimeException('無法提交');
  $r=room((string)($b['room']??''));$in=(string)($b['checkin']??'');$out=(string)($b['checkout']??'');$days=nights($in,$out);
  foreach(['adults','children','extra','breakfast']as$k)if(!isset($b[$k])||!is_int($b[$k]))throw new RuntimeException('人數格式錯誤');
  if($b['extra']<0||$b['extra']>1||(!$r['extra']&&$b['extra'])||$b['adults']<1||$b['adults']>$r['capacity']+$b['extra']||$b['children']<0||$b['children']>6||$b['breakfast']<0||$b['breakfast']>$b['adults']+$b['children'])throw new RuntimeException('人數或加購數量不符合房型');
  foreach(['name','phone','email','notes','id','token']as$k)if(!is_string($b[$k]??null))throw new RuntimeException('請完整填寫資料');
  if(!trim($b['name'])||strlen($b['name'])>150||!preg_match('/^\+?[0-9 ()-]{8,22}$/D',$b['phone'])||strlen($b['email'])>150||($b['email']&&!filter_var($b['email'],FILTER_VALIDATE_EMAIL))||strlen($b['notes'])>1800||($b['consent']??false)!==true||!preg_match('/^[a-f0-9-]{36}$/D',$b['id'])||!preg_match('/^[a-f0-9]{64}$/D',$b['token']))throw new RuntimeException('請檢查聯絡資料並同意訂房須知');
  $saved=tx(function()use($b,$r,$in,$out,$days){$old=q('SELECT * FROM bookings WHERE id=?',[$b['id']])->fetch();if($old){if(!hash_equals($old['token_hash'],hash('sha256',$b['token'])))throw new RuntimeException('申請識別碼重複');return $old;}
   if(busy($r['id'],$in,$out))throw new RuntimeException('此房間日期已訂滿或關房，請重新選擇');$quote=quote($r,$days,$b['extra'],$b['breakfast']);$now=date(DATE_ATOM);
   q('INSERT INTO bookings(id,token_hash,room,checkin,checkout,name,phone,email,adults,children,extra,breakfast,notes,total,quote,status,created,updated) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$b['id'],hash('sha256',$b['token']),$r['id'],$in,$out,trim($b['name']),$b['phone'],$b['email'],$b['adults'],$b['children'],$b['extra'],$b['breakfast'],$b['notes'],$quote['total'],json_encode($quote,JSON_UNESCAPED_UNICODE),'pending',$now,$now]);return q('SELECT * FROM bookings WHERE id=?',[$b['id']])->fetch();});reply(['booking'=>public_receipt($saved)],201);
 }
 if($action==='lookup'){
  throttle('lookup','all',60);if(!is_string($b['id']??null)||!is_string($b['token']??null)||!preg_match('/^[a-f0-9]{64}$/D',$b['token']))throw new RuntimeException('查詢資料不正確');
  $order=q('SELECT * FROM bookings WHERE id=? AND token_hash=?',[$b['id'],hash('sha256',$b['token'])])->fetch();if(!$order)throw new RuntimeException('找不到訂單，請確認訂單編號與查詢碼');reply(['booking'=>public_receipt($order)]);
 }
 if($action==='admin'){
  require_admin();$op=$b['op']??'';
  if($op==='rate'){if(!valid_date($b['day']??null)||!in_array($b['kind']??'', ['weekday','holiday','rack','default'],true))throw new RuntimeException('房價設定錯誤');if($b['kind']==='default')q('DELETE FROM calendar WHERE day=?',[$b['day']]);else q('INSERT INTO calendar(day,kind) VALUES(?,?) ON CONFLICT(day) DO UPDATE SET kind=excluded.kind',[$b['day'],$b['kind']]);reply(['ok'=>true]);}
  if($op==='block'){room((string)($b['room']??''));if(!valid_date($b['day']??null)||!is_bool($b['closed']??null))throw new RuntimeException('關房日期錯誤');tx(function()use($b){if($b['closed']){if(q('SELECT day FROM nights WHERE room=? AND day=?',[$b['room'],$b['day']])->fetch())throw new RuntimeException('此日期已有確認訂單，無法關房');q('INSERT OR IGNORE INTO blocks(room,day) VALUES(?,?)',[$b['room'],$b['day']]);}else q('DELETE FROM blocks WHERE room=? AND day=?',[$b['room'],$b['day']]);});reply(['ok'=>true]);}
  tx(function()use($op,$b){$o=q('SELECT * FROM bookings WHERE id=?',[(string)($b['id']??'')])->fetch();if(!$o)throw new RuntimeException('找不到訂單');
   if($op==='confirm'){if($o['status']!=='pending')throw new RuntimeException('此訂單狀態已變更');if($o['checkin']<date('Y-m-d'))throw new RuntimeException('已過入住日期');if(busy($o['room'],$o['checkin'],$o['checkout']))throw new RuntimeException('房間日期衝突或已關房，無法確認');foreach(nights($o['checkin'],$o['checkout'],false)as$day)q('INSERT INTO nights(room,day,booking) VALUES(?,?,?)',[$o['room'],$day,$o['id']]);q("UPDATE bookings SET status='confirmed',updated=? WHERE id=?",[date(DATE_ATOM),$o['id']]);}
   elseif($op==='cancel'){q('DELETE FROM nights WHERE booking=?',[$o['id']]);q("UPDATE bookings SET status='cancelled',updated=? WHERE id=?",[date(DATE_ATOM),$o['id']]);}
   else throw new RuntimeException('操作無效');});reply(['ok'=>true]);
 }
 reply(['error'=>'操作無效'],404);
} catch(Throwable $e) {
 if(!function_exists('reply')){http_response_code(503);header('Content-Type: application/json; charset=utf-8');echo json_encode(['error'=>'找不到 private/bootstrap.php，請依安裝說明放置 private 與 public_html 資料夾。'],JSON_UNESCAPED_UNICODE);exit;}
 $message=$e instanceof PDOException?'資料庫暫時無法操作，請稍後重試或聯絡管理者。':($e instanceof JsonException?'資料格式錯誤':$e->getMessage());reply(['error'=>$message],400);
}
