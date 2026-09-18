"""Root-only, explicit self-mailbox deployment test. No inverter/device commands.

Creates a disposable portal account for the configured sender, exercises HTTPS
verification/login/reset using tokens received over IMAPS, then removes that
test account. Refuses to touch an existing account. Never prints credentials.
"""
import email
import http.cookiejar
import imaplib
import json
import os
import re
import secrets
import ssl
import subprocess
import time
import urllib.parse
import urllib.request

ROOT = '/var/www/clients/client3/web63/web'
if os.geteuid() != 0:
    raise SystemExit('Root required')

def php(code):
    return subprocess.check_output(['php8.2', '-r', code], text=True)

cfg = json.loads(php(f'$c=require "{ROOT}/config.php"; echo json_encode(["host"=>$c["smtp_host"],"user"=>$c["smtp_user"],"password"=>$c["smtp_password"]]);'))
password = secrets.token_hex(24)
new_password = secrets.token_hex(24)
address = cfg['user']
base = 'https://solpanel.linder.dk/'
jar = http.cookiejar.CookieJar()
http = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))

def request(page, data=None):
    encoded = urllib.parse.urlencode(data).encode() if data is not None else None
    with http.open(base+'?page='+page, encoded, timeout=20) as response:
        return response.geturl(), response.read().decode()

def post(page, action, **fields):
    _, body = request(page)
    csrf = re.search(r'name="csrf" value="([a-f0-9]+)"', body).group(1)
    return request(page, dict(action=action, csrf=csrf, **fields))

def maintenance():
    subprocess.run(['runuser','-u','web63','--','php8.2',ROOT+'/bin/maintenance.php'],check=True,stdout=subprocess.DEVNULL)

def received_token(purpose, after):
    for _ in range(15):
        with imaplib.IMAP4_SSL(cfg['host'],993,ssl_context=ssl.create_default_context()) as mail:
            mail.login(address,cfg['password'])
            mail.select('INBOX',readonly=True)
            _, ids = mail.search(None,'ALL')
            for uid in reversed(ids[0].split()[-20:]):
                _, raw = mail.fetch(uid,'(BODY.PEEK[])')
                msg = email.message_from_bytes(next(p[1] for p in raw if isinstance(p,tuple)))
                dt = email.utils.parsedate_to_datetime(msg['Date']).timestamp()
                if dt < after-2:
                    continue
                parts = list(msg.walk()) if msg.is_multipart() else [msg]
                for part in parts:
                    if part.get_content_type() != 'text/plain':
                        continue
                    body = part.get_payload(decode=True).decode(part.get_content_charset() or 'utf-8')
                    token = re.search(r'page='+purpose+r'#token=([a-f0-9]{64})',body)
                    if token:
                        return token.group(1)
        time.sleep(1)
    raise RuntimeError('Expected email not received')

uid = None
try:
    start = time.time()
    # Registration stays closed publicly; only this disposable account is seeded
    # through the actual registration service, with its real mail queue.
    code = f'require "{ROOT}/bootstrap.php"; $email={json.dumps(address)}; $q=$db->prepare("SELECT id FROM cloud_users WHERE email=?"); $q->execute([$email]); if($q->fetch()) exit(9); $config["registration_enabled"]=true; (new SolportalCloud\\Auth($db,$config))->register($email,{json.dumps(password)}); echo $db->query("SELECT MAX(id) FROM cloud_users")->fetchColumn();'
    uid = int(php(code))
    maintenance()
    token = received_token('verify',start)
    print('PASS verification mail received via authenticated IMAPS')
    _, body = post('login','login',email=address,password=password)
    assert 'Bekræft først' in body, 'Unverified login not blocked'
    url, body = post('verify','verify',token=token)
    assert 'page=login' in url and 'E-mailen er bekræftet' in body
    _, body = post('verify','verify',token=token)
    assert 'Linket er ugyldigt' in body
    print('PASS HTTPS verification, unverified-login denial and token replay denial')
    url, body = post('login','login',email=address,password=password)
    assert 'page=dashboard' in url
    session_cookie = next(c for c in jar if c.name.startswith('__Host-'))
    assert session_cookie.secure
    post('dashboard','logout')
    start = time.time()
    post('forgot','forgot',email=address)
    maintenance()
    token = received_token('reset',start)
    url, body = post('reset','reset',token=token,password=new_password,password_confirm=new_password)
    assert 'page=login' in url and 'Adgangskoden er ændret' in body
    _, body = post('login','login',email=address,password=password)
    assert 'E-mail eller adgangskode er forkert' in body
    url, body = post('login','login',email=address,password=new_password)
    assert 'page=dashboard' in url
    post('dashboard','logout')
    maintenance()
    print('PASS received reset email, HTTPS password reset, old-password denial and new login')
finally:
    if uid is not None:
        php(f'require "{ROOT}/bootstrap.php"; $q=$db->prepare("DELETE FROM cloud_users WHERE id=? AND email=?"); $q->execute([{uid},{json.dumps(address)}]);')
        print('Disposable test account removed; no installations or inverter settings touched')
