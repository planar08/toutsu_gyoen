<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Taipei');
ini_set('display_errors', '0');
$config = require __DIR__ . '/config.php';
function config(string $key) { global $config; return $config[$key] ?? null; }
function is_https(): bool { return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443; }
function require_https(): void { if (config('require_https') && !is_https()) throw new RuntimeException('請使用 HTTPS 開啟網站；若主機使用反向代理，請先由主機商設定正確的 HTTPS 環境變數。'); }
function db(): PDO {
 static $pdo=null;if ($pdo) return $pdo;
 if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('主機尚未啟用 PDO_SQLITE，請請主機商協助啟用。');
 $dir=dirname(config('db_path'));if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('無法建立資料目錄');
 $pdo=new PDO('sqlite:'.config('db_path'),null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
 $pdo->exec('PRAGMA foreign_keys=ON');$pdo->exec('PRAGMA busy_timeout=5000');
 $stmt=$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='schema_version'");
 if(!$stmt->fetchColumn())$pdo->exec(file_get_contents(__DIR__.'/schema.sql'));
 @chmod(config('db_path'),0600);return $pdo;
}
function q(string $sql,array $args=[]): PDOStatement {$s=db()->prepare($sql);$s->execute($args);return $s;}
function tx(callable $fn) {db()->exec('BEGIN IMMEDIATE');try{$r=$fn();db()->exec('COMMIT');return $r;}catch(Throwable $e){db()->exec('ROLLBACK');throw $e;}}
function start_session(): void {
 if(session_status()===PHP_SESSION_ACTIVE)return;
 $dir=config('session_path');if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('無法建立登入資料目錄');
 ini_set('session.use_strict_mode','1');ini_set('session.use_only_cookies','1');ini_set('session.gc_maxlifetime','28800');session_save_path($dir);session_name('DONGJIN_SESSION');
 session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>is_https(),'httponly'=>true,'samesite'=>'Strict']);
 if(!session_start())throw new RuntimeException('無法啟動安全工作階段');
 if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));
}
function csrf(): string {start_session();return $_SESSION['csrf'];}
function check_csrf(array $b): void {
 start_session();$origin=$_SERVER['HTTP_ORIGIN']??'';
 if($origin){$o=parse_url($origin);$host=strtolower((string)($o['host']??''));$port=isset($o['port'])?':'.$o['port']:'';$current=strtolower($_SERVER['HTTP_HOST']??'');if($host.$port!==$current||($o['scheme']??'')!==(is_https()?'https':'http'))throw new RuntimeException('請由本站提交表單');}
 if(!is_string($b['csrf']??null)||!hash_equals($_SESSION['csrf'],$b['csrf']))throw new RuntimeException('表單已過期，請重新整理頁面');
}
function current_admin(): ?array {
 start_session();$a=$_SESSION['admin']??null;if(!$a)return null;
 $expired=time()-($a['last']??0)>(int)config('session_idle_seconds')||time()-($a['created']??0)>(int)config('session_max_seconds');
 $u=$expired?false:q('SELECT username,version FROM admin_users WHERE id=1')->fetch();
 if(!$u||(int)$u['version']!==(int)$a['version']){unset($_SESSION['admin']);return null;}
 $_SESSION['admin']['last']=time();return $u;
}
function require_admin(): array {$a=current_admin();if(!$a)throw new RuntimeException('請先登入管理後台');return $a;}
function signout(): void {start_session();unset($_SESSION['admin']);session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(32));}
function throttle(string $type,string $identity,int $limit=10): void {
 $bucket=(int)floor(time()/900);$ip=$_SERVER['REMOTE_ADDR']??'unknown';$key=hash('sha256',$type.'|'.$ip.'|'.$identity.'|'.$bucket);
 $n=tx(function()use($bucket,$key){q('INSERT INTO attempts(key,count,bucket) VALUES(?,1,?) ON CONFLICT(key) DO UPDATE SET count=count+1',[$key,$bucket]);q('DELETE FROM attempts WHERE bucket<?',[$bucket-2]);return (int)q('SELECT count FROM attempts WHERE key=?',[$key])->fetchColumn();});
 if($n>$limit)throw new RuntimeException('操作次數過多，請15分鐘後再試');
}
function valid_username($s): bool {return is_string($s)&&(bool)preg_match('/^[A-Za-z0-9@._-]{3,80}$/D',$s);}
function valid_password($s): bool {return is_string($s)&&strlen($s)>=12&&strlen($s)<=128;}
function hash_password(string $s): string {return password_hash($s,defined('PASSWORD_ARGON2ID')?PASSWORD_ARGON2ID:PASSWORD_DEFAULT);}
function rooms(): array {
 $rows=[['101','和室雙人房',2,3000,3400,4000,true,'浴缸・榻榻米'],['102','洋式雙人房',2,3000,3400,4000,false,'浴缸・加大雙人床'],['103','洋式雙人房',2,2600,3000,3600,true,'乾濕分離浴室'],['104','和室雙人房',2,2600,3000,3600,true,'榻榻米・乾濕分離浴室'],['105','洋式雙人房',2,2400,2800,3200,false,'乾濕分離浴室'],['201','和室三人房',3,3000,3600,4500,true,'和室・家庭同行'],['202','洋式雙人房',2,2500,2900,3500,false,'乾濕分離浴室'],['203','洋式雙人房',2,2500,2900,3500,false,'乾濕分離浴室'],['204','和室六人房',6,5300,5900,7300,true,'寬敞和室・團體同行']];
 return array_map(fn($r)=>array_combine(['id','name','capacity','weekday','holiday','rack','extra','feature'],$r),$rows);
}
function room(string $id): array {foreach(rooms() as $r)if($r['id']===$id)return $r;throw new RuntimeException('請選擇有效房號');}
function valid_date($s): bool {if(!is_string($s))return false;$d=DateTimeImmutable::createFromFormat('!Y-m-d',$s);return $d&&$d->format('Y-m-d')===$s;}
function nights(string $in,string $out,bool $future=true): array {
 if(!valid_date($in)||!valid_date($out)||$out<=$in||($future&&$in<date('Y-m-d')))throw new RuntimeException('請選擇有效入住與退房日期');
 $a=new DateTimeImmutable($in);$b=new DateTimeImmutable($out);$n=(int)$a->diff($b)->days;
 if($n>(int)config('max_nights')||($future&&$a>new DateTimeImmutable('+366 days')))throw new RuntimeException('可申請一年內入住，每筆最多30晚');
 $days=[];for($i=0;$i<$n;$i++)$days[]=$a->modify('+'.$i.' days')->format('Y-m-d');return $days;
}
function rates(): array {return q('SELECT day,kind FROM calendar')->fetchAll(PDO::FETCH_KEY_PAIR);}
function quote(array $room,array $days,int $extra,int $breakfast): array {
 $rates=rates();$lines=[];$subtotal=0;foreach($days as $day){$kind=$rates[$day]??(in_array((int)date('w',strtotime($day)),[5,6],true)?'holiday':'weekday');$rate=$room[$kind];$lines[]=['date'=>$day,'kind'=>$kind,'rate'=>$rate];$subtotal+=$rate;}
 $n=count($days);return ['lines'=>$lines,'nights'=>$n,'roomTotal'=>$subtotal,'extraTotal'=>$extra*600*$n,'breakfastTotal'=>$breakfast*100*$n,'total'=>$subtotal+($extra*600+$breakfast*100)*$n];
}
function busy(string $room,string $in,string $out): bool {return (bool)q('SELECT day FROM nights WHERE room=? AND day>=? AND day<? UNION SELECT day FROM blocks WHERE room=? AND day>=? AND day<?',[$room,$in,$out,$room,$in,$out])->fetch();}
function reply(array $data,int $status=200): never {http_response_code($status);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;}
function input_json(): array {if((int)($_SERVER['CONTENT_LENGTH']??0)>12000)throw new RuntimeException('表單過大');$raw=file_get_contents('php://input',false,null,0,12001);if(strlen($raw)>12000)throw new RuntimeException('表單過大');$b=json_decode($raw,true,512,JSON_THROW_ON_ERROR);if(!is_array($b))throw new RuntimeException('表單格式錯誤');return $b;}
function public_receipt(array $b): array {return array_intersect_key($b,array_flip(['id','room','checkin','checkout','adults','children','extra','breakfast','total','status','quote','created']));}
