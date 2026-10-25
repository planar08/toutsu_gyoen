<?php
// 僅限有主機SSH/終端機權限的網站擁有者使用，不能以網址執行。
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/bootstrap.php';
$user=q('SELECT id FROM admin_users WHERE id=1')->fetch();if(!$user){fwrite(STDERR,"請先使用網站安裝頁建立帳號。\n");exit(1);}
fwrite(STDOUT,"請輸入新的安裝碼以便重設帳號（至少32個英數字）：");
$key=trim(fgets(STDIN));if(!preg_match('/^[A-Za-z0-9]{32,128}$/D',$key)){fwrite(STDERR,"格式錯誤。\n");exit(1);}
// 保留所有訂單，移除管理帳號並撤銷工作階段版本。
if(file_put_contents(__DIR__.'/setup.key',$key."\n",LOCK_EX)===false){fwrite(STDERR,"無法寫入安裝碼，未變更管理帳號。\n");exit(1);}
chmod(__DIR__.'/setup.key',0600);q('DELETE FROM admin_users WHERE id=1');
foreach(glob(config('session_path').'/sess_*')?:[] as $sessionFile)if(is_file($sessionFile))@unlink($sessionFile);
fwrite(STDOUT,"請開啟 /admin/install.html，使用新安裝碼重新建立管理帳號。訂單已保留。\n");
