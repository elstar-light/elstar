import os
"""Enrich the approved supplier snapshot; keep retail prices unchanged."""
import json, pathlib, xml.etree.ElementTree as E, re, collections
root=pathlib.Path(os.environ.get('ELSTAR_CATALOG_ROOT',pathlib.Path(__file__).resolve().parents[1]))
data=json.loads((root/'public/catalog.json').read_text())
source=pathlib.Path(os.environ.get('ELSTAR_ISONEX_FEED',root.parent/'upload/stocks.yml'))
params={o.get('id'):{p.get('name'):(p.text or '').strip() for p in o.findall('param')} for o in E.parse(source).getroot().findall('.//offer')}
keys=['Площадь освещения, кв.м','Цветовая температура','Тип монтажа','Светильник Длина, мм','Светильник Ширина, мм','Светильник Высота полная, мм','Светильник Высота встраиваемой части, мм','Материал плафонов','Материал арматуры','Диммирование','Световой поток, Лм']
def valid(x):return bool(x and x.strip() not in ['_','-','НЕТ','0'])
def direction(p):
 c=p.get('supplierCategory','').lower(); n=p['name'].lower()
 # A fixture with a remote is still a fixture, not a controller.
 if any(x in n for x in ['пульт дистанционного','контроллер','блок управления','датчик движения для','диммер для']) or 'драйвер' in c:return 'control'
 if any(x in c for x in ['улич','ландшафт']) or any(x in n for x in ['фасадный светильник','ландшафтный светильник']):return 'outdoor'
 if 'неон' in c or 'светодиодная лента' in n:return 'strip'
 if 'светодиодные модули' in c or re.search(r'\bлампа (светодиодная|галогенная|накаливания)',n) or 'модуль led' in n:return 'sources'
 if any(x in c for x in ['трек','модульн','под покраску','подсветка лестниц','fino']):return 'architectural'
 if any(x in c for x in ['nt','настенно-потолоч','комплектующ']):return 'functional'
 return 'decorative'
chunks=collections.defaultdict(dict)
for i,p in enumerate(data['items']):
 if p['id'] not in params:continue
 d=params[p['id']]
 if 'detailChunk' in p:
  old=root/f"public/catalog-details/{p['detailChunk']}.json"
  if old.exists():p.update(json.loads(old.read_text()).get(p['id'],{}))
 for k in keys:
  if valid(d.get(k)):p['specs'][k]=d[k]
 p['direction']=direction(p)
 p['temperature']=d.get('Цветовая температура на сайте') if valid(d.get('Цветовая температура на сайте')) else d.get('Цветовая температура','')
 if not valid(p['temperature']):p['temperature']=''
 p['mount']=d.get('Тип монтажа','') if valid(d.get('Тип монтажа')) else ''
 p['detailChunk']=str(i//250)
 chunks[p['detailChunk']][p['id']]={k:p[k] for k in ['pictures','description','specs']}
 p['pictures']=p['pictures'][:1]
 p['description']=''
for k,v in chunks.items():(root/f'public/catalog-details/{k}.json').write_text(json.dumps(v,ensure_ascii=False,separators=(',',':')))
(root/'public/catalog.json').write_text(json.dumps(data,ensure_ascii=False,separators=(',',':')))
print('Directions',dict(collections.Counter(p['direction'] for p in data['items'])))
print('Catalog bytes', (root/'public/catalog.json').stat().st_size)
for k in ['functional','architectural','outdoor','strip','sources','control']:
 p=next((p for p in data['items'] if p['direction']==k and p['stock']>0 and p['pictures']),None)
 print(k, (p['id'],p['name'],p['lit'] or p['pictures'][0]) if p else None)
