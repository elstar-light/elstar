"""Jino filesystem refresh, Python 3.6+, no credentials or external packages."""
import datetime, fcntl, hashlib, json, math, os, pathlib, shutil, sys, tempfile
import urllib.request
from catalog_normalize import normalize

SOURCES = {'isonex': 'https://isonex.ru/upload/stocks.yml',
           'lightstar': 'https://lightstar.ru/image/yml/lightstar_rozn_stock.yml'}
NAMES = ['catalog_normalize.py', 'enrich-catalog.py', 'import-lightstar.py',
         'classify-directions.py', 'refine-sections.py']

def encode(value):
    return json.dumps(value, ensure_ascii=False, separators=(',', ':'), allow_nan=False).encode('utf-8')

def read(path):
    return json.loads(path.read_text(encoding='utf-8'))

def stamp():
    return datetime.datetime.utcnow().strftime('%Y-%m-%dT%H:%M:%S.000Z')

def date_key(value):
    # Both suppliers express their feed dates in Moscow time.
    text = str(value).replace('T', ' ')[:19]
    for fmt in ('%Y-%m-%d %H:%M:%S', '%Y-%m-%d %H:%M', '%Y-%m-%d'):
        try: return datetime.datetime.strptime(text, fmt)
        except ValueError: pass
    raise ValueError('Invalid supplier date')

def atomic(path, body):
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, temporary = tempfile.mkstemp(prefix='.update-', dir=str(path.parent))
    try:
        with os.fdopen(fd, 'wb') as out:
            out.write(body); out.flush(); os.fsync(out.fileno())
        os.chmod(temporary, 0o644)
        os.replace(temporary, str(path))
    finally:
        if os.path.exists(temporary): os.unlink(temporary)

def bundle(supplier, source):
    recipe = hashlib.sha256()
    for name in NAMES: recipe.update((pathlib.Path(__file__).parent / name).read_bytes())
    digest = hashlib.sha256(recipe.digest()); digest.update(source.read_bytes())
    revision = digest.hexdigest()
    data, details = normalize(supplier, source)
    date_key(data['date'])
    files = {}; inventory = []; seen = set()
    chunks = {old: 'details-' + str(i) for i, old in enumerate(sorted(details))}
    for item in data['items']:
        if not item['id'] or item['id'] in seen: raise ValueError('Duplicate product')
        seen.add(item['id'])
        if not math.isfinite(item['price']) or item['price'] < 0 or item['stock'] < 0: raise ValueError('Invalid price or stock')
        old = item['detailChunk']
        if item['id'] not in details[old]: raise ValueError('Missing product details')
        item['detailChunk'] = supplier + '/' + revision + '/' + chunks[old]
        inventory.append({key: item[key] for key in ('id', 'sku', 'name', 'price', 'stock')})
    if len(inventory) < 100: raise ValueError('Incomplete catalog')
    for old, chunk in chunks.items(): files[chunk + '.json'] = encode(details[old])
    files['catalog.json'] = encode(data)
    files['inventory.json'] = encode(inventory)
    return revision, data, files

def verify(directory, files, count):
    for name, body in files.items():
        if directory.joinpath(name).read_bytes() != body: raise ValueError('Saved file mismatch')
    if len(read(directory / 'inventory.json')) != count: raise ValueError('Inventory count mismatch')
    if len(read(directory / 'catalog.json')['items']) != count: raise ValueError('Catalog count mismatch')

def publish(root, supplier, revision, data, files):
    base = root / 'catalog-data' / supplier
    pointer = base / 'current.json'
    previous = read(pointer)
    if previous.get('supplier') != supplier: raise ValueError('Invalid existing manifest')
    if date_key(data['date']) < date_key(previous['date']): raise ValueError('Older feed preserved')
    if len(data['items']) < max(100, previous['count'] * .8): raise ValueError('Suspiciously truncated feed preserved')
    dest = base / revision
    if not dest.exists():
        staging = pathlib.Path(tempfile.mkdtemp(prefix='.revision-', dir=str(base)))
        try:
            os.chmod(str(staging), 0o755)
            for name, body in files.items(): atomic(staging / name, body)
            verify(staging, files, len(data['items']))
            os.rename(str(staging), str(dest))
        finally:
            if staging.exists(): shutil.rmtree(str(staging))
    verify(dest, files, len(data['items']))
    now = stamp()
    manifest = dict(previous, revision=revision, date=data['date'], count=len(data['items']),
                    checkedAt=now, updatedAt=previous['updatedAt'] if previous['revision'] == revision else now,
                    files={name: hashlib.sha256(body).hexdigest() for name, body in files.items()})
    # Immutable revision first, manifest replacement last. Readers see one complete revision.
    atomic(pointer, encode(manifest))
    if read(pointer) != manifest: raise ValueError('Manifest readback mismatch')
    return manifest

def download(url, source):
    size = 0
    with urllib.request.urlopen(urllib.request.Request(url, headers={'User-Agent':'ELSTAR Catalog Refresh/1.0'}), timeout=90) as response:
        with source.open('wb') as out:
            while True:
                block = response.read(1024 * 1024)
                if not block: break
                size += len(block)
                if size > 150 * 1024 * 1024: raise ValueError('Feed exceeds size limit')
                out.write(block)
    if not size: raise ValueError('Empty feed')

def run():
    home = pathlib.Path.home()
    roots = [home / 'domains' / host for host in ('elstar-light.ru', '3d2ef918ae94.hosting.myjino.ru')]
    for root in roots:
        if not (root / 'catalog-data/isonex/current.json').is_file(): raise ValueError('Catalog installation missing: ' + str(root))
    private = home / 'elstar-private'
    with (private / 'catalog-update.lock').open('a') as lock:
        os.chmod(str(private / 'catalog-update.lock'), 0o600)
        try: fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            print('Another refresh is running; skipped.', flush=True); return 0
        failed = False
        for supplier, url in SOURCES.items():
            try:
                with tempfile.TemporaryDirectory(prefix='catalog-refresh-', dir=str(private)) as tmp:
                    source = pathlib.Path(tmp) / 'source.yml'
                    download(url, source)
                    revision, data, files = bundle(supplier, source)
                    for root in roots:
                        result = publish(root, supplier, revision, data, files)
                        print(json.dumps({'supplier':supplier, 'site':root.name, 'status':'verified',
                                          'count':result['count'], 'revision':revision, 'checkedAt':result['checkedAt']}), flush=True)
            except Exception as error:
                failed = True
                print(json.dumps({'supplier':supplier, 'status':'failed', 'error':str(error)[:400],
                                  'lastValidCatalogRetained':True}), flush=True)
        return 1 if failed else 0

if __name__ == '__main__':
    try: sys.exit(run())
    except Exception as error:
        print(json.dumps({'status':'failed', 'error':str(error)[:400]}), flush=True); sys.exit(1)
