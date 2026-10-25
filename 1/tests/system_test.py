#!/usr/bin/env python3
"""Self-contained integration checks. Uses a temporary website and database only."""
from pathlib import Path
import argparse, subprocess, tempfile, shutil, time, socket, json, secrets, datetime, urllib.request, urllib.error, http.cookiejar, sqlite3
p=argparse.ArgumentParser();p.add_argument('--php',default='php');p.add_argument('--extension-dir');args=p.parse_args()
project=Path(__file__).resolve().parents[1]
command=[args.php]
if args.extension_dir:
 command+=['-n','-d','extension='+str(Path(args.extension_dir)/'pdo.so'),'-d','extension='+str(Path(args.extension_dir)/'pdo_sqlite.so')]
with tempfile.TemporaryDirectory(prefix='dongjin-php-test-') as td:
 root=Path(td);shutil.copytree(project/'public_html',root/'public_html');shutil.copytree(project/'private',root/'private')
 config=root/'private/config.php';config.write_text(config.read_text().replace("'require_https' => true", "'require_https' => false"))
 key=secrets.token_hex(32);(root/'private/setup.key').write_text(key)
 password=secrets.token_urlsafe(24);newpassword=secrets.token_urlsafe(24)
 sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close();origin=f'http://127.0.0.1:{port}'
 log=(root/'server.log').open('wb');proc=subprocess.Popen(command+['-S',f'127.0.0.1:{port}','-t',str(root/'public_html')],stdout=log,stderr=log)
 class Client:
  def __init__(self):self.jar=http.cookiejar.CookieJar();self.opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar));self.csrf=''
  def request(self,action,body=None,params=None,origin_header=origin):
   from urllib.parse import urlencode
   url=origin+'/booking/api.php?'+urlencode({'action':action,**(params or {})});headers={}
   if body is not None:headers={'Content-Type':'application/json','Origin':origin_header};data=json.dumps({**body,'csrf':body.get('csrf',self.csrf)}).encode()
   else:data=None
   req=urllib.request.Request(url,data=data,headers=headers)
   try:r=self.opener.open(req)
   except urllib.error.HTTPError as e:r=e
   raw=r.read();result=json.loads(raw);self.csrf=result.get('csrf',self.csrf);return r.status,result,r.headers
  def me(self):return self.request('me')[1]
 try:
  for _ in range(100):
   try:urllib.request.urlopen(origin+'/index.html',timeout=.2);break
   except Exception:time.sleep(.05)
  else:raise RuntimeError('PHP server could not start: '+(root/'server.log').read_text())
  guest=Client();admin=Client();assert guest.me()['installed'] is False;admin.me()
  assert admin.request('install',{'key':'wrong','username':'manager','password':password})[0]==400
  assert admin.request('install',{'key':key,'username':'manager','password':password})[0]==200
  assert not (root/'private/setup.key').exists()
  assert admin.request('install',{'key':key,'username':'other','password':password})[0]==400
  assert guest.request('admin')[0]==400
  assert admin.request('login',{'username':'manager','password':'wrong'})[0]==400
  status,body,headers=admin.request('login',{'username':'manager','password':password});assert status==200
  assert 'HttpOnly' in headers.get('Set-Cookie','') and 'SameSite=Strict' in headers.get('Set-Cookie','')
  assert admin.me()['admin']['username']=='manager'
  guest.me();date=datetime.date.today()+datetime.timedelta(days=10)
  while date.weekday()!=4:date+=datetime.timedelta(days=1)
  checkin=str(date);checkout=str(date+datetime.timedelta(days=2))
  def payload(room='101'):
   import uuid
   return {'id':str(uuid.uuid4()),'token':secrets.token_hex(32),'room':room,'checkin':checkin,'checkout':checkout,'adults':2,'children':1,'extra':1,'breakfast':2,'name':'測試旅客<script>','phone':'0900000000','email':'','notes':'測試','consent':True}
  a,b=payload(),payload();status,cat,_=guest.request('catalog',params={'in':checkin,'out':checkout});assert status==200 and len(cat['rooms'])==9
  status,d,_=guest.request('booking',{**a,'total':1});assert status==201 and d['booking']['total']==8400
  assert guest.request('booking',a)[0]==201
  conn=sqlite3.connect(root/'private/storage/booking.sqlite');assert conn.execute('SELECT count(*) FROM bookings').fetchone()[0]==1
  assert guest.request('booking',{**payload('102')})[0]==400
  assert guest.request('booking',{**payload(),'checkin':'2026-02-30'})[0]==400
  assert guest.request('booking',{**payload(),'consent':False})[0]==400
  assert guest.request('booking',{**payload(),'csrf':'wrong'})[0]==400
  assert guest.request('lookup',{'id':a['id'],'token':'ab'*32})[0]==400
  status,d,_=guest.request('lookup',{'id':a['id'],'token':a['token']});assert status==200 and d['booking']['status']=='pending'
  assert not set(['name','phone','email','notes','token_hash']).intersection(d['booking'])
  assert guest.request('booking',b)[0]==201
  assert admin.request('admin',{'op':'confirm','id':a['id']})[0]==200
  assert admin.request('admin',{'op':'confirm','id':b['id']})[0]==400
  assert conn.execute('SELECT count(*) FROM nights').fetchone()[0]==2
  assert admin.request('admin',{'op':'block','room':'101','day':checkin,'closed':True})[0]==400
  assert guest.request('catalog',params={'in':checkin,'out':checkout})[1]['rooms'][0]['available'] is False
  assert admin.request('admin',{'op':'cancel','id':a['id']})[0]==200
  assert conn.execute('SELECT count(*) FROM nights').fetchone()[0]==0
  assert admin.request('admin',{'op':'block','room':'101','day':checkin,'closed':True})[0]==200
  assert admin.request('admin',{'op':'confirm','id':b['id']})[0]==400
  assert admin.request('admin',{'op':'block','room':'101','day':checkin,'closed':False})[0]==200
  assert admin.request('admin',{'op':'confirm','id':b['id']})[0]==200
  assert admin.request('admin',{'op':'rate','day':checkin,'kind':'rack'})[0]==200
  assert guest.request('catalog',params={'in':checkin,'out':checkout})[1]['rooms'][0]['quote']['roomTotal']==7400
  data=admin.request('admin')[1];assert len(data['bookings'])==2 and 'token_hash' not in data['bookings'][0]
  try:urllib.request.urlopen(origin+'/private/setup.key');raise AssertionError('private file exposed')
  except urllib.error.HTTPError as e:assert e.code==404
  admin2=Client();admin2.me();assert admin2.request('login',{'username':'manager','password':password})[0]==200
  assert admin.request('password',{'oldPassword':password,'password':newpassword})[0]==200
  assert admin2.me()['admin'] is None
  admin.me()
  assert admin.request('login',{'username':'manager','password':password})[0]==400
  assert admin.request('login',{'username':'manager','password':newpassword})[0]==200
  assert admin.request('admin',{'op':'rate','day':checkin,'kind':'default'},origin_header='https://foreign.example')[0]==400
  assert admin.request('logout',{})[0]==200 and admin.me()['admin'] is None
  for i in range(10):guest.request('login',{'username':'unknown','password':'invalid'})
  assert '操作次數過多' in guest.request('login',{'username':'unknown','password':'invalid'})[1]['error']
  assert conn.execute('SELECT password_hash FROM admin_users').fetchone()[0]!=newpassword
  assert conn.execute('SELECT count(*) FROM bookings').fetchone()[0]==2
  print('PASS: PHP/SQLite installation, one-time setup, login/session/CSRF/rate limit, local admin, price calculation, reservation idempotency, private lookup, validation, confirmation conflict, cancellation, closed rooms, special dates, password change invalidation, original orders preserved, private files outside document root.')
 finally:
  proc.terminate();proc.wait(timeout=10);log.close()
