import xml.etree.ElementTree as E,json,shutil,pathlib
base=pathlib.Path('/workspace/scratch/291d1acd8cf0')
dest=base/'elstar/public'
r=E.parse(base/'upload/stocks.yml').getroot()
items=[]
for o in r.findall('.//offer'):
 p={x.get('name'):(x.text or '').strip() for x in o.findall('param')}
 def valid(v): return v and v not in ['-','_','НЕТ']
 pics=[x.text for x in o.findall('picture') if x.text and x.text.startswith('https://')]
 stock=sum(int(x.get('instock') or '0') for x in o.findall('./outlets/outlet'))
 arrival=next((v for k,v in p.items() if ('поступ' in k.lower() or 'приход' in k.lower()) and valid(v)),'')
 keys=['Цвет','Материал','Стиль','Тип цоколя лампы','Общая мощность, Вт','Световой поток','Цветовая температура','Светильник Высота, мм','Светильник Диаметр, мм','IP, степень пылевлагозащиты','Количество ламп','Гарантия, месяцы']
 items.append(dict(id=o.get('id'),sku=o.findtext('vendorCode'),name=o.findtext('name'),brand=o.findtext('vendor'),price=float(o.findtext('price') or 0),old=float(o.findtext('oldprice') or 0),category=p.get('Категория Товара') or (o.findtext('categoryId') or '').split(';')[0],stock=stock,arrival=arrival,pictures=pics,lit=p.get('Ссылка на фото на цветном фоне_вкл') or p.get('Ссылка на фото на белом фоне_вкл') or '',unlit=p.get('Ссылка на фото на цветном фоне_выкл') or '',description=o.findtext('description'),color=p.get('Цвет арматуры') or p.get('Цвет') or '',socket=p.get('Тип цоколя лампы') or '',style=p.get('Стиль') or '',specs={k:p[k] for k in keys if valid(p.get(k))}))
(dest/'catalog.json').write_text(json.dumps({'date':r.get('date'),'items':items},ensure_ascii=False,separators=(',',':')))
shutil.copy(next((base/'attachments').glob('*/Золотой*.png')),dest/'logo.png')
photos=list((base/'attachments').glob('*/XXXL*.webp'))
photos.sort(key=lambda p: (p.name!='XXXL (3).webp',p.name))
for n,p in enumerate(photos): shutil.copy(p,dest/f'showroom-{n}.webp')
print('Imported',len(items),'products; visible',sum(x['stock']>0 or bool(x['arrival']) for x in items))
