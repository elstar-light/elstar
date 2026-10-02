import os
import json,re,pathlib,collections
root=pathlib.Path(os.environ.get('ELSTAR_CATALOG_ROOT',pathlib.Path(__file__).resolve().parents[1]));path=root/'public/catalog.json';data=json.loads(path.read_text())
for p in data['items']:
 n=p['name'].lower();c=p.get('supplierCategory','').lower();direction=p['direction'];tags=[]
 fixture=re.search(r'светильн|св-к|св-ник|светильн|люстр|торшер|бра\b|модуль led',n)
 component=re.search(r'\bдрайвер\b|блок питания|блок управления|контроллер|конвертер|трансформатор|беспроводной пульт|пульт дистанционного|\bpult\b',n)
 iscomponent=bool(component and (not fixture or component.start()<fixture.start()))
 if direction=='control' and not iscomponent:
  direction='decorative' if 'настенно-потолоч' in c else 'sources' if 'модули' in c else 'architectural'
 if iscomponent and (direction=='control' or re.search(r'48\s*[vв]',n)):
  direction='control'
 if direction=='control':
  tags=['Пульты'] if 'пульт' in n or 'pult' in n else ['Блоки и драйверы']
  if re.search(r'48\s*[vв]',n):tags.append('Блоки 48 В')
  if any(x in n for x in ['smart','tuya','wi-fi','wifi','zigbee','умн']):tags.append('Умные блоки')
 elif direction=='outdoor':
  if 'подвес' in c+' '+n:tags=['Уличные подвесы']
  elif 'прожектор' in n:tags=['Прожекторы']
  elif 'столб' in n or 'столбик' in n:tags=['Парковые столбы']
  elif 'парков' in n:tags=['Парковые светильники']
  elif 'настен' in c+' '+n or 'фасад' in c+' '+n or re.search(r'\bбра\b',n):tags=['Уличные бра']
  elif 'потолоч' in c:tags=['Уличные потолочные']
  elif 'комплект' in c or any(x in n for x in ['колышек','основание для']):tags=['Комплектующие']
  else:tags=['Ландшафтные светильники']
 elif direction=='architectural':
  if re.search(r'провод|кабель',n) and 'шинопровод' not in n:tags=['Провода и кабели']
  elif fixture and ('трек' in n+c or 'шинопровод' in n):tags=['Трековые светильники']
  elif fixture and ('линейн' in n+c or 'модульн' in c):tags=['Линейные светильники']
  elif 'шинопровод' in n or re.search(r'\bтрек\b',n):tags=['Треки и шины']
  elif fixture and ('встраив' in n+c or 'врезн' in n):tags=['Встраиваемые светильники']
  elif fixture and 'наклад' in n+c:tags=['Накладные светильники']
  elif fixture:tags=['Технические светильники']
  else:tags=['Комплектующие']
  if p.get('segment')=='Магнитные трековые системы' or 'магнит' in n:tags.append('Магнитные системы')
  if p.get('segment')=='Однофазные трековые системы' or 'однофаз' in n:tags.append('Однофазные системы')
  if p.get('segment')=='Трёхфазные трековые системы' or 'трехфаз' in n:tags.append('Трёхфазные системы')
  if 'под покраску' in c or 'под шпакл' in n:tags.append('Под шпаклёвку')
 elif direction=='strip':
  tags=['Блоки питания'] if iscomponent else ['Профили'] if 'профил' in n+c else ['Ленты и гибкий неон'] if 'лента' in n or 'неон' in n else ['Комплектующие']
 elif direction=='sources':tags=p.get('socketTypes',[]) or ['Другие источники света']
 else:tags=[p['category']]
 p['direction']=direction;p['quickTags']=list(dict.fromkeys(tags))
path.write_text(json.dumps(data,ensure_ascii=False,separators=(',',':')))
for d in ['control','outdoor','architectural']:print(d,collections.Counter(t for p in data['items'] if p['direction']==d for t in p['quickTags']))
