#!/usr/bin/env python3
"""EIPSI canonical V2 stdlib example. Never logs credentials; no redirects with Bearer."""
import argparse, hashlib, json, os, re, time
from getpass import getpass
from pathlib import Path
from urllib.parse import urlencode, urlsplit, urlunsplit, parse_qsl, quote
from urllib.request import Request, build_opener, HTTPRedirectHandler
from urllib.error import HTTPError

# CONFIGURACIÓN: pegá los valores entre comillas o dejalos vacíos para
# ingresarlos al ejecutar. También se admiten EIPSI_API_BASE y EIPSI_API_KEY.
# La dirección base está en WordPress > Configuración > API externa > Cómo conectar.
# Creá una clave con read:studies, read:data y acceso al estudio.
API_BASE = ""
API_KEY = ""
# Ejecutar: python eipsi_api.py --study CODIGO_DEL_ESTUDIO --output datos.json --format json
# El archivo de salida debe ser nuevo.

class ApiError(RuntimeError):
    def __init__(self, status, code, request_id=None):
        super().__init__(f'HTTP {status}: {code}; request_id={request_id or "unavailable"}')
        self.status, self.code = status, code
class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None
class Client:
    def __init__(self, base=None, key=None):
        self.base=(base or API_BASE or os.environ.get('EIPSI_API_BASE','')).strip().rstrip('/')
        self.key=(key or API_KEY or os.environ.get('EIPSI_API_KEY','')).strip()
        u=urlsplit(self.base)
        if u.username or u.password or u.fragment or u.scheme not in ('https','http'):
            raise ValueError('Invalid API base URL')
        if u.scheme!='https' and u.hostname not in ('127.0.0.1','localhost','::1'):
            raise ValueError('HTTPS required outside explicit loopback examples')
        if not re.fullmatch(r'eipsi_[a-f0-9]{32}_[a-f0-9]{64}',self.key):
            raise ValueError('Invalid API key format')
        self.opener=build_opener(NoRedirect())
    def url(self,path,params=None):
        u=urlsplit(self.base);q=dict(parse_qsl(u.query))
        if 'rest_route' in q:q['rest_route']=q['rest_route'].rstrip('/')+path
        else:u=u._replace(path=u.path+path)
        q.update(params or {})
        return urlunsplit(u._replace(query=urlencode(q)))
    def request(self,path,params=None,body=None,headers=None,raw=False):
        h={'Authorization':'Bearer '+self.key,'Accept':'application/json'};h.update(headers or {})
        payload=None if body is None else json.dumps(body).encode()
        if body is not None:h['Content-Type']='application/json'
        req=Request(self.url(path,params),payload,h,method='GET' if body is None else 'POST')
        try:
            with self.opener.open(req,timeout=90) as r:
                data=r.read();status=r.status;rh=dict(r.headers)
        except HTTPError as e:
            try:d=json.loads(e.read(65536));code=d.get('code','http_error');rid=d.get('data',{}).get('request_id')
            except (ValueError,AttributeError):code='http_error';rid=None
            raise ApiError(e.code,code,rid) from None
        return (status,rh,data) if raw else json.loads(data)
    def pages(self,path,params=None):
        q=dict(params or {});seen=set()
        for _ in range(10000):
            page=self.request(path,q)
            yield from page['items']
            cursor=page.get('next_cursor')
            if cursor is None:return
            if cursor in seen:raise RuntimeError('Repeated cursor')
            seen.add(cursor);q['cursor']=cursor
        raise RuntimeError('Pagination bound exceeded')
    def export(self,code,fmt='json',partials=False,deadline=300):
        # JSON/XLSX include dictionary, process metadata and response-status annexes.
        # CSV is main data; use /dictionary with the V2 contract for its schema.
        created=self.request('/studies/'+quote(code,safe='')+'/exports',body={'contract':'EIPSI_STUDY_DATA_V2','format':fmt,'include_partials':partials,'annexes':['data','dictionary','response_status','bindings','process_metadata','randomization']})
        path='/exports/'+created['export_id'];until=time.monotonic()+deadline
        while time.monotonic()<until:
            status,h,data=self.request(path,raw=True);result=json.loads(data)
            if result['status']=='READY':return result
            if result['status']!='PREPARING':raise RuntimeError('Export '+result['status'])
            time.sleep(max(2,min(60,int(h.get('Retry-After','5')))))
        raise RuntimeError('Export polling deadline exceeded')
    def download(self,export_id,destination):
        """Stream a verified representation to a new file; never follow URLs in DTOs."""
        dest=Path(destination);part=dest.with_name(dest.name+'.part')
        if dest.exists() or part.exists():raise FileExistsError('Choose a new output filename')
        req=Request(self.url('/exports/'+quote(export_id,safe='')+'/download'),headers={'Authorization':'Bearer '+self.key})
        try:
            with self.opener.open(req,timeout=90) as response,part.open('xb') as out:
                expected=int(response.headers['Content-Length']);digest=hashlib.sha256();n=0
                while True:
                    chunk=response.read(65536)
                    if not chunk:break
                    out.write(chunk);digest.update(chunk);n+=len(chunk)
                if n!=expected or response.headers['ETag']!='"'+digest.hexdigest()+'"':raise RuntimeError('Incomplete or mismatched download')
            part.rename(dest)
        except Exception:
            # Partial is deliberately kept for investigation/resume, never presented as complete.
            raise
        return n

def main():
    p=argparse.ArgumentParser();p.add_argument('--study',required=True);p.add_argument('--output',required=True);p.add_argument('--format',choices=['json','csv','xlsx'],default='json');a=p.parse_args()
    base=API_BASE or os.environ.get('EIPSI_API_BASE','') or input('Pegá la dirección base de la API: ')
    key=API_KEY or os.environ.get('EIPSI_API_KEY','') or getpass('Pegá la clave API (no se muestra al escribir): ')
    c=Client(base,key)
    c.request('/status');studies=list(c.pages('/studies',{'limit':50}));code=quote(a.study,safe='');c.request('/studies/'+code)
    rows=list(c.pages('/studies/'+code+'/data',{'limit':100,'contract':'EIPSI_STUDY_DATA_V2'}));dictionary=c.request('/studies/'+code+'/dictionary',{'contract':'EIPSI_STUDY_DATA_V2'})
    export=c.export(a.study,a.format);n=c.download(export['export_id'],a.output)
    print(json.dumps({'studies':len(studies),'live_rows':len(rows),'dictionary_items':len(dictionary['items']),'export_id':export['export_id'],'download_bytes':n}))
if __name__=='__main__':
    try:main()
    except (ApiError,RuntimeError,ValueError,KeyError,OSError) as e:
        # Do not print arbitrary exception text (URLs/headers may contain secrets).
        raise SystemExit(str(e) if isinstance(e,ApiError) else type(e).__name__+': operation failed') from None
