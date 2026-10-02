"""Unattended supplier refresh. Credentials arrive on hidden stdin, never argv/files."""
import contextlib, hashlib, json, pathlib, sys, tempfile, time, urllib.request, urllib.error
from catalog_normalize import normalize

SOURCES={'isonex':'https://isonex.ru/upload/stocks.yml','lightstar':'https://lightstar.ru/image/yml/lightstar_rozn_stock.yml'}
SITE='https://elstar-light.elstar1-ru.chatgpt.site'

def encode(data):return json.dumps(data,ensure_ascii=False,separators=(',',':')).encode()

def credentials():
    previous=None
    if sys.stdin.isatty():
        import termios
        previous=termios.tcgetattr(sys.stdin); hidden=termios.tcgetattr(sys.stdin);hidden[3]&=~termios.ECHO;termios.tcsetattr(sys.stdin,termios.TCSADRAIN,hidden)
    print('Ready for catalog access JSON on stdin (input is hidden).',flush=True)
    try:config=json.loads(sys.stdin.readline())
    finally:
        if previous is not None:termios.tcsetattr(sys.stdin,termios.TCSADRAIN,previous)
    if config.get('url')!=SITE or not config.get('token'):raise ValueError('Use this Site URL and its supported service credential')
    return config

def run(config):
    # Normalizer source participates in the revision: a mapping correction cannot overwrite existing immutable files.
    recipe=hashlib.sha256()
    for name in ['catalog_normalize.py','enrich-catalog.py','import-lightstar.py','classify-directions.py','refine-sections.py']:
        recipe.update((pathlib.Path(__file__).parent/name).read_bytes())
    def api(path,data=None):
        if not path.startswith('/api/catalog'):raise ValueError('Unexpected endpoint')
        headers={'OAI-Sites-Authorization':'Bearer '+config['token'],'X-Catalog-Authorization':'Bearer '+config['token']}
        if data is not None:headers['Content-Type']='application/json'
        request=urllib.request.Request(SITE+path,data=data,headers=headers,method='POST' if data is not None else 'GET')
        # Do not forward service credentials through a redirect.
        class NoRedirect(urllib.request.HTTPRedirectHandler):
            def redirect_request(self,*args,**kwargs):return None
        opener=urllib.request.build_opener(NoRedirect())
        for attempt in range(3):
            try:
                with opener.open(request,timeout=90) as response:return json.load(response)
            except urllib.error.HTTPError as e:
                if e.code<500 or attempt==2:raise RuntimeError('Site HTTP '+str(e.code)+': '+e.read(1000).decode(errors='replace')) from None
            except (TimeoutError,urllib.error.URLError):
                if attempt==2:raise RuntimeError('Site temporarily unreachable') from None
            time.sleep(1+attempt)
    state={s['supplier']:s for s in api('/api/catalog')['sources']}
    results=[]
    for supplier,url in SOURCES.items():
        started=int(time.time()*1000)
        try:
            with tempfile.TemporaryDirectory(prefix='elstar-refresh-') as directory:
                source=pathlib.Path(directory)/'source.yml';digest=hashlib.sha256(recipe.digest());size=0
                with urllib.request.urlopen(urllib.request.Request(url,headers={'User-Agent':'ELSTAR Catalog Refresh/1.0'}),timeout=90) as response,source.open('wb') as out:
                    while block:=response.read(1024*1024):
                        size+=len(block)
                        if size>150*1024*1024:raise ValueError('Supplier feed exceeds size limit')
                        digest.update(block);out.write(block)
                revision=digest.hexdigest()
                data,details=normalize(supplier,source)
                # Detail paths pin the same revision as the product summary.
                chunks={old:'details-'+str(i) for i,old in enumerate(sorted(details))}
                for p in data['items']:p['detailChunk']=f"{supplier}/{revision}/{chunks[p['detailChunk']]}"
                files={}
                base=f'/api/catalog/update?supplier={supplier}&revision={revision}'
                for old,chunk in chunks.items():
                    filename=chunk+'.json';body=encode(details[old]);answer=api(base+'&action=upload&file='+filename,body)
                    files[filename]=hashlib.sha256(body).hexdigest()
                    if answer.get('sha256')!=files[filename]:raise ValueError('Uploaded detail checksum mismatch')
                body=encode(data);answer=api(base+'&action=upload&file=catalog.json',body);files['catalog.json']=hashlib.sha256(body).hexdigest()
                if answer.get('sha256')!=files['catalog.json']:raise ValueError('Uploaded summary checksum mismatch')
                result=api(base+'&action=commit',encode({'previousRevision':state[supplier]['revision'],'startedAt':started,'date':data['date'],'files':files}))
                check=next(s for s in api('/api/catalog')['sources'] if s['supplier']==supplier)
                readback=api(f'/api/catalog/file/{supplier}/{revision}/catalog.json')
                if check['revision']!=revision or check['count']!=len(data['items']) or hashlib.sha256(encode(readback)).hexdigest()!=files['catalog.json']:
                    raise ValueError('Published catalog readback mismatch')
                result['status']='verified';results.append(result);print(json.dumps(result),flush=True)
        except Exception as error:
            # Failure in one supplier never clears or blocks the other supplier's last valid snapshot.
            result={'supplier':supplier,'status':'failed','error':str(error)[:1000],'previousCatalogPreserved':True}
            results.append(result);print(json.dumps(result),flush=True)
    if any(r['status']!='verified' for r in results):return 1
    return 0

if __name__=='__main__':sys.exit(run(credentials()))
