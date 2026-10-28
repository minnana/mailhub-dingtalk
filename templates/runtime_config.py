#!/usr/bin/env python3
"""Validate non-secret bootstrap settings and restricted generated env files."""
import ipaddress, json, pathlib, re, subprocess, sys

ROOT = pathlib.Path('/opt/mailhub')
KEYS = {'DOMAIN','PUBLIC_PORT','BIND_ADDRESS','ADMIN_USER','ACME_EMAIL','PROXY_SUBNET',
        'PROXY_IP','PROXY_DYNAMIC_RANGE','BACKEND_SUBNET','DNS_PROVIDER','APP_MEMORY_MB',
        'APP_CPUS','DB_MEMORY_MB','DB_BUFFER_POOL_MB','DB_CPUS','CRON_MEMORY_MB','CRON_CPUS',
        'NEXTCLOUD_IMAGE','MARIADB_IMAGE','REDIS_IMAGE','NGINX_IMAGE','ACME_IMAGE',
        'MAIL_VERSION','MAIL_SHA256'}
OPTIONAL_KEYS = {'MAIL_DNS_PRIMARY', 'MAIL_DNS_SECONDARY'}

def fail(message):
    raise SystemExit(message)

def check_env(path):
    seen = set()
    for line in pathlib.Path(path).read_text().splitlines():
        if not line or line.startswith('#'):
            continue
        key, sep, value = line.partition('=')
        safe_value=(value=='' and key=='ACME_EMAIL') or re.fullmatch(r'[A-Za-z0-9_./:@+\-]+',value)
        if key not in KEYS | OPTIONAL_KEYS or key in seen or not sep or not safe_value:
            fail('Invalid or unsafe generated environment entry: '+key)
        if key in OPTIONAL_KEYS:
            try: address = ipaddress.IPv4Address(value)
            except ipaddress.AddressValueError: fail('Invalid DNS server address: '+key)
            if address.is_loopback or address.is_unspecified or address.is_multicast or address.is_link_local:
                fail('DNS server must be reachable outside the container: '+key)
        seen.add(key)
    if not KEYS <= seen:
        fail('Generated environment is incomplete: '+','.join(sorted(KEYS-seen)))

def render(path):
    c=json.loads(pathlib.Path(path).read_text())
    allowed={'domain','public_port','bind_address','admin_user','acme_email','proxy_subnet',
             'backend_subnet','dns_provider','app_memory_mb','app_cpus','db_memory_mb',
             'db_buffer_pool_mb','db_cpus','cron_memory_mb','cron_cpus','mail_dns_primary','mail_dns_secondary'}
    if not isinstance(c,dict) or set(c)-allowed:
        fail('Unsupported bootstrap settings; credentials must be in separate secret files.')
    required={'domain','proxy_subnet','backend_subnet','dns_provider'}
    if not required<=set(c):
        fail('Missing settings: '+','.join(sorted(required-set(c))))
    if not isinstance(c['domain'],str): fail('Invalid domain')
    domain=c['domain'].lower()
    if len(domain)>253 or '.' not in domain or any(not re.fullmatch(r'[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?',x) for x in domain.split('.')):
        fail('Invalid domain')
    port=c.get('public_port',18443)
    if type(port) is not int or not 1024<=port<=65535: fail('Port must be an integer 1024..65535')
    address=str(ipaddress.IPv4Address(c.get('bind_address','0.0.0.0')))
    admin=c.get('admin_user','mailhub-admin')
    if not isinstance(admin,str) or not re.fullmatch(r'[A-Za-z0-9_.-]{3,40}',admin): fail('Invalid administrator name')
    email=c.get('acme_email','')
    if not isinstance(email,str) or (email and not re.fullmatch(r'[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}',email)): fail('Invalid ACME email')
    if c['dns_provider'] not in ('dns_tencent','dns_dp','dns_acmedns'): fail('Unsupported DNS provider')
    nets=[ipaddress.ip_network(c[k],strict=True) for k in ('proxy_subnet','backend_subnet')]
    if any(n.version!=4 or n.prefixlen!=24 or not n.is_private for n in nets) or nets[0].overlaps(nets[1]):
        fail('Use two distinct private IPv4 /24 networks')
    if subprocess.check_output(['docker','ps','-aq','--filter','label=com.docker.compose.project=mailhub'],text=True).strip():
        fail('Mailhub containers already exist. Bootstrap will not adopt another deployment.')
    existing=[]
    for route in json.loads(subprocess.check_output(['ip','-j','-4','route','show'])):
        if route.get('dst','default')!='default': existing.append(ipaddress.ip_network(route['dst'],strict=False))
    ids=subprocess.check_output(['docker','network','ls','-q'],text=True).split()
    if ids:
        for network in json.loads(subprocess.check_output(['docker','network','inspect',*ids])):
            for cfg in network.get('IPAM',{}).get('Config') or []:
                if cfg.get('Subnet') and ':' not in cfg['Subnet']: existing.append(ipaddress.ip_network(cfg['Subnet']))
    if any(n.overlaps(other) for n in nets for other in existing): fail('A proposed network overlaps an existing route or Docker network')
    if subprocess.check_output(['ss','-H','-ltn','sport = :'+str(port)],text=True).strip(): fail('Requested host port is already in use')
    vals={'DOMAIN':domain,'PUBLIC_PORT':str(port),'BIND_ADDRESS':address,'ADMIN_USER':admin,
          'ACME_EMAIL':email,'PROXY_SUBNET':str(nets[0]),'BACKEND_SUBNET':str(nets[1]),
          'PROXY_IP':str(nets[0].network_address+2),'PROXY_DYNAMIC_RANGE':str(list(nets[0].subnets(prefixlen_diff=1))[1]),
          'DNS_PROVIDER':c['dns_provider']}
    for k, default in [('mail_dns_primary','119.29.29.29'),('mail_dns_secondary','223.5.5.5')]:
        if not isinstance(c.get(k,default),str): fail('DNS server must be an IPv4 string: '+k)
        try: dns_address = ipaddress.IPv4Address(c.get(k,default))
        except (ipaddress.AddressValueError,ValueError,TypeError): fail('Invalid DNS server: '+k)
        if dns_address.is_loopback or dns_address.is_unspecified or dns_address.is_multicast or dns_address.is_link_local:
            fail('DNS server must be reachable outside the container: '+k)
        vals[k.upper()]=str(dns_address)
    for k,default in [('app_memory_mb',4096),('db_memory_mb',1536),('db_buffer_pool_mb',512),('cron_memory_mb',1536)]:
        v=c.get(k,default)
        if type(v) is not int or not 256<=v<=65536: fail('Invalid memory setting: '+k)
        vals[k.upper()]=str(v)
    if int(vals['DB_BUFFER_POOL_MB'])+512>int(vals['DB_MEMORY_MB']): fail('Database needs at least 512 MiB above its buffer pool')
    for k,default in [('app_cpus',2.0),('db_cpus',1.0),('cron_cpus',1.0)]:
        v=c.get(k,default)
        if type(v) not in (int,float) or not 0.25<=v<=32: fail('Invalid CPU setting: '+k)
        vals[k.upper()]=str(v)
    print(''.join(k+'='+v+'\n' for k,v in vals.items()),end='')

def dns_check(path,provider):
    expected={'dns_tencent':{'Tencent_SecretId','Tencent_SecretKey'},
              'dns_dp':{'DP_Id','DP_Key'},
              'dns_acmedns':{'ACMEDNS_BASE_URL','ACMEDNS_USERNAME','ACMEDNS_PASSWORD','ACMEDNS_SUBDOMAIN'}}[provider]
    seen={}
    for line in pathlib.Path(path).read_text().splitlines():
        if not line or line.startswith('#'): continue
        key,sep,value=line.partition('=')
        if value[:1] in ("'",'"') and value[-1:]==value[:1]: value=value[1:-1]
        if key not in expected or key in seen or not sep or not re.fullmatch(r'[A-Za-z0-9_./:@+=\-]+',value): fail('Invalid DNS credentials format (values not logged)')
        seen[key]=value
    if set(seen)!=expected: fail('Missing required DNS provider credentials; automatic registration is disabled')
    if provider=='dns_acmedns' and not re.fullmatch(r'https://[A-Za-z0-9.\-]+(?::[0-9]+)?(?:/[A-Za-z0-9_./\-]*)?',seen['ACMEDNS_BASE_URL']): fail('acme-dns must use a valid HTTPS API URL')

if __name__=='__main__':
    if len(sys.argv)<3: fail('runtime_config.py command path')
    if sys.argv[1]=='check-env': check_env(sys.argv[2])
    elif sys.argv[1]=='render': render(sys.argv[2])
    elif sys.argv[1]=='check-dns': dns_check(sys.argv[2],sys.argv[3])
    else: fail('Unknown command')
