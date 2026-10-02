"""Normalize one supplier in an isolated directory; no changes to site assets."""
import contextlib, io, json, math, os, pathlib, runpy, tempfile
import xml.etree.ElementTree as ET

SCRIPTS = pathlib.Path(__file__).resolve().parent

def normalize(supplier, source):
    document = ET.parse(source).getroot()
    if document.tag != 'yml_catalog' or not document.get('date'):
        raise ValueError('Invalid YML document/date')
    offers = document.findall('.//offer')
    if len(offers) < 100:
        raise ValueError('Incomplete supplier catalog')
    ids = [o.get('id') for o in offers]
    if not all(ids) or len(set(ids)) != len(ids):
        raise ValueError('Missing or duplicate supplier IDs')
    for o in offers:
        price = float(o.findtext('price') or 0)
        if not math.isfinite(price) or price < 0:
            raise ValueError('Invalid retail price')
        currency = o.findtext('currencyId')
        if currency and currency not in ('RUR', 'RUB'):
            raise ValueError('Expected ruble retail price')
    with tempfile.TemporaryDirectory(prefix='elstar-normalize-') as tmp:
        root = pathlib.Path(tmp)
        (root/'public/catalog-details').mkdir(parents=True)
        (root/'data').mkdir()
        values = {'ELSTAR_CATALOG_ROOT':str(root), 'ELSTAR_ISONEX_FEED':str(source) if supplier=='isonex' else str(root/'missing.yml'), 'ELSTAR_LIGHTSTAR_FEED':str(source) if supplier=='lightstar' else str(root/'missing.yml')}
        before = {k:os.environ.get(k) for k in values}
        os.environ.update(values)
        try:
            if supplier == 'isonex':
                items = []
                keys = ['Цвет','Материал','Стиль','Тип цоколя лампы','Общая мощность, Вт','Световой поток','Цветовая температура','Светильник Высота, мм','Светильник Диаметр, мм','IP, степень пылевлагозащиты','Количество ламп','Гарантия, месяцы']
                for o in offers:
                    p = {x.get('name'):(x.text or '').strip() for x in o.findall('param')}
                    def valid(v): return bool(v and v not in ['-','_','НЕТ'])
                    c = p.get('Категория Товара') or (o.findtext('categoryId') or '').split(';')[0]
                    lower = c.lower()
                    category = 'Потолочные светильники'
                    for match,label in [(('люстр',),'Люстры'),(('улич','ландшафт'),'Уличное освещение'),(('трек',),'Трековые системы'),(('торшер','напольн'),'Торшеры'),(('настоль',),'Настольные лампы'),(('бра','настенн'),'Бра'),(('подвес',),'Подвесы'),(('встраив',),'Встраиваемые светильники'),(('подсветк',),'Подсветка'),(('комплект','драйвер','модул','систем','неон'),'Системы и комплектующие')]:
                        if any(s in lower for s in match) and not (label=='Бра' and 'потолочн' in lower):
                            category=label;break
                    stock = sum(int(x.get('instock') or '0') for x in o.findall('./outlets/outlet'))
                    price=float(o.findtext('price') or 0)
                    items.append(dict(id=o.get('id'),sku=o.findtext('vendorCode') or '',name=o.findtext('name') or '',brand=o.findtext('vendor') or '',supplier='Isonex',supplierCategory=c,category=category,price=price,old=float(o.findtext('oldprice') or 0),stock=max(0,stock) if price>0 else 0,arrival=next((v for k,v in p.items() if ('поступ' in k.lower() or 'приход' in k.lower()) and valid(v)),''),pictures=[x.text for x in o.findall('picture') if x.text and x.text.startswith('https://')],lit=p.get('Ссылка на фото на цветном фоне_вкл') or p.get('Ссылка на фото на белом фоне_вкл') or '',unlit=p.get('Ссылка на фото на цветном фоне_выкл') or '',description=o.findtext('description') or '',color=p.get('Цвет арматуры') or p.get('Цвет') or '',socket=p.get('Тип цоколя лампы') or '',style=p.get('Стиль') or '',room=p.get('Тип светильника_По помещению') or '',specs={k:p[k] for k in keys if valid(p.get(k))}))
                data={'date':document.get('date'),'items':items}
            else:
                data={'date':'','items':[]}
            (root/'public/catalog.json').write_text(json.dumps(data,ensure_ascii=False))
            scripts = ['enrich-catalog.py'] if supplier=='isonex' else ['import-lightstar.py']
            with contextlib.redirect_stdout(io.StringIO()):
                for script in scripts+['classify-directions.py','refine-sections.py']:
                    runpy.run_path(str(SCRIPTS/script),run_name='__main__')
            data=json.loads((root/'public/catalog.json').read_text())
            details={p.stem:json.loads(p.read_text()) for p in (root/'public/catalog-details').glob('*.json')}
            for p in data['items']:
                if not p['name'] or not math.isfinite(p['old']) or p['stock']<0:
                    raise ValueError('Invalid normalized product')
                assert p['id'] in details[p['detailChunk']]
            return {'date':document.get('date'),'items':data['items']},details
        finally:
            for k,v in before.items():
                if v is None: os.environ.pop(k,None)
                else: os.environ[k]=v
