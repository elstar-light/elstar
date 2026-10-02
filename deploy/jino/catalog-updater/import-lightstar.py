import os
"""Import the user-provided Lightstar retail YML into the shared catalog snapshot."""
import json,xml.etree.ElementTree as E,pathlib,collections,re
root=pathlib.Path(os.environ.get('ELSTAR_CATALOG_ROOT',pathlib.Path(__file__).resolve().parents[1]))
r=E.parse(pathlib.Path(os.environ.get('ELSTAR_LIGHTSTAR_FEED',root.parent/'lightstar.yml'))).getroot()
base=json.loads((root/'public/catalog.json').read_text());base['items']=[p for p in base['items'] if not p['id'].startswith('lightstar:')]
cats={c.get('id'):(c.text or '',c.get('parentId')) for c in r.findall('.//categories/category')}
def ancestors(cid):
 out=[];seen=set()
 while cid in cats and cid not in seen:
  seen.add(cid);label,cid=cats[cid];out.append(label)
 return out
mapping={'Высота (H), мм':'Светильник Высота, мм','Высота max (H max), мм':'Светильник Высота полная, мм','Диаметр (D), мм':'Светильник Диаметр, мм','Длина (Глубина) (L), мм':'Светильник Длина, мм','Ширина (W), мм':'Светильник Ширина, мм','Материал арматуры':'Материал арматуры','Материал плафона':'Материал плафонов','Цвет арматуры':'Цвет','Цоколь ламп':'Тип цоколя лампы','Стиль':'Стиль','Тип монтажа':'Тип монтажа','Количество ламп, шт':'Количество ламп','Цветовая температура, К':'Цветовая температура','Световой поток, Лм (для лент Лм/м)':'Световой поток, Лм','Пылевлагозащита, IP':'IP, степень пылевлагозащиты','Суммарная мощность LED, Вт':'Мощность LED, Вт','Суммарная мощность ЛОН, Вт':'Мощность ламп накаливания, Вт','Диммируемость':'Диммирование','Способ управления':'Способ управления','Умный дом':'Умный дом','Индекс цветопередачи CRI, Ra':'Индекс цветопередачи, Ra'}
def valid(v):return v and v.strip() not in ['-','_','нет в справочнике']
def classify(labels,name):
 text=' '.join(labels).lower()
 if any(x in text for x in ['контроллер','драйвер']):return 'control'
 if 'уличн' in text:return 'outdoor'
 if 'лент' in text or 'неон' in text:return 'strip'
 if 'лампы светодиодные' in text:return 'sources'
 if any(x in text for x in ['трек','профил','тросовая']):return 'architectural'
 if any(x in text for x in ['люстр','подвес','бра','торшер','настольн']):return 'decorative'
 return 'functional'
def category(labels):
 s=' '.join(labels).lower()
 for stem,label in [('контроллер','Управление освещением'),('драйвер','Управление освещением'),('уличн','Уличное освещение'),('лента','Светодиодные ленты'),('неон','Гибкий неон'),('лампы светодиодные','Лампы'),('трек','Трековые системы'),('профил','Профили'),('тросов','Тросовые системы'),('люстр','Люстры'),('подвес','Подвесы'),('бра','Бра'),('торшер','Торшеры'),('настольн','Настольные лампы'),('встраив','Встраиваемые светильники')]:
  if stem in s:return label
 return labels[0] if labels else 'Комплектующие'
chunks=collections.defaultdict(dict); imported=[]
for i,o in enumerate(r.findall('.//offer')):
 d={p.get('name'):(p.text or '').strip() for p in o.findall('param')};text=lambda k:(o.findtext(k) or '').strip();pid='lightstar:'+o.get('id');labels=ancestors(text('categoryId'));specs={v:d[k] for k,v in mapping.items() if valid(d.get(k))};specs['Материал']=', '.join(dict.fromkeys(v for v in [d.get('Материал арматуры'),d.get('Материал плафона')] if valid(v)))
 watts=d.get('Суммарная мощность ЛОН, Вт') or d.get('Суммарная мощность LED, Вт')
 if valid(watts):specs['Общая мощность, Вт']=watts
 if specs.get('IP, степень пылевлагозащиты') and specs['IP, степень пылевлагозащиты'].startswith('IP'):specs['IP, степень пылевлагозащиты']=specs['IP, степень пылевлагозащиты'][2:]
 name=text('name');sku=text('vendorCode');collection=d.get('Коллекция','');pics=list(dict.fromkeys(p.text for p in o.findall('picture') if p.text and p.text.startswith('https://')));qty=int(text('quantity_in_stock') or 0)
 if o.get('available')=='false':qty=0
 price=float(text('price') or 0)
 if price<=0:qty=0
 cat=category(labels);singular={'Люстры':'Люстра','Подвесы':'Подвес','Торшеры':'Торшер','Настольные лампы':'Настольная лампа','Встраиваемые светильники':'Встраиваемый светильник','Лампы':'Лампа','Трековые системы':'Трековый светильник'}
 display=name.replace(sku,'').strip()
 p=dict(id=pid,sku=sku,name=name,displayName=display,brand=text('vendor') or 'Lightstar',supplier='Lightstar',supplierCategory=labels[0] if labels else '',price=price,old=float(text('oldprice') or 0),category=cat,direction=classify(labels,name),stock=qty,arrival='',pictures=pics[:1],lit='',unlit='',description='',color=d.get('Цвет арматуры','').lower(),socket=d.get('Цоколь ламп',''),style=d.get('Стиль','').lower(),room='',temperature=d.get('Цветовая температура, К',''),mount=d.get('Тип монтажа',''),specs=specs,detailChunk='lightstar-'+str(i//250))
 chunks[p['detailChunk']][pid]=dict(pictures=pics,description='',specs=specs)
 imported.append(p)
assert len({p['id'] for p in imported})==len(imported),'Duplicate supplier IDs'
for k,v in chunks.items():(root/f'public/catalog-details/{k}.json').write_text(json.dumps(v,ensure_ascii=False,separators=(',',':')))
base['items']+=imported;base['sources']=[{'supplier':'Isonex','date':base['date']},{'supplier':'Lightstar','date':r.get('date')}]
(root/'public/catalog.json').write_text(json.dumps(base,ensure_ascii=False,separators=(',',':')))
(root/'data/inventory.json').write_text(json.dumps([{k:p[k] for k in ['id','sku','name','price','stock']} for p in base['items']],ensure_ascii=False,separators=(',',':')))
print('Lightstar imported',len(imported),'in stock',sum(p['stock']>0 for p in imported),'sale in stock',sum(p['stock']>0 and p['old']>p['price'] for p in imported))
print('Total',len(base['items']),'directions',dict(collections.Counter(p['direction'] for p in imported)))
