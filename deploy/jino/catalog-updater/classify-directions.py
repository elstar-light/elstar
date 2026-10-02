import os
"""Apply ELSTAR's seven-section taxonomy without changing prices or stock."""
import json,re,pathlib,xml.etree.ElementTree as E,collections
root=pathlib.Path(os.environ.get('ELSTAR_CATALOG_ROOT',pathlib.Path(__file__).resolve().parents[1]));path=root/'public/catalog.json';data=json.loads(path.read_text());raw={}
for file,prefix in [(pathlib.Path(os.environ.get('ELSTAR_ISONEX_FEED',root.parent/'upload/stocks.yml')),''),(pathlib.Path(os.environ.get('ELSTAR_LIGHTSTAR_FEED',root.parent/'lightstar.yml')),'lightstar:')]:
 if not file.exists():continue
 for o in E.parse(file).getroot().findall('.//offer'):
  raw[prefix+o.get('id')]={p.get('name'):(p.text or '').strip() for p in o.findall('param')}
  raw[prefix+o.get('id')]['description']=o.findtext('description') or ''
def sockets(value):
 v=value.upper().replace('Е','E').replace('Г','G').replace(',','.')
 found=re.findall(r'(?<![A-ZА-Я0-9])(?:GU|GX|GZ|GY|G|E|R|B)\d+(?:\.\d+)?[A-Z]?(?!\d)',v)
 return list(dict.fromkeys(found)) or ([value] if value and value not in ['-','_'] else [])
for p in data['items']:
 d=raw.get(p['id'],{});c=p.get('supplierCategory','').lower();n=p['name'].lower();old=p['direction'];text=c+' '+n
 # Exact equipment identity precedes fixture descriptions mentioning control capability.
 fixture=any(x in n for x in ['светильник','люстра','бра ','торшер','подвесной'])
 control=any(x in c for x in ['контроллер','конвертер']) or (not fixture and any(x in n for x in ['контроллер','конвертер','пульт','диммер','блок управления','усилитель','датчик']))
 driver='драйвер' in c or (any(x in text for x in ['драйвер','блок питания','трансформатор']) and not fixture)
 lowvoltage=bool(re.search(r'(?<![\d-])(?:12|24)\s*(?:v|в)(?!\w)',n))
 profile='профил' in text and 'трек' not in text and 'шинопровод' not in text
 if control:
  direction='control';segment='Умное управление' if any(x in n for x in ['smart','умн','wifi','wi-fi','tuya','zigbee']) else 'Контроллеры и конвертеры' if any(x in text for x in ['контроллер','конвертер']) else 'Пульты и элементы управления'
 elif driver:
  direction='strip' if lowvoltage else 'control';segment='Блоки питания 12/24 В' if lowvoltage else 'Драйверы и блоки управления'
 elif profile or old=='strip' or any(x in c for x in ['лента','неон']):
  direction='strip';segment='Профили и аксессуары' if profile else 'Гибкий неон' if 'неон' in c else 'Светодиодные ленты' if any(x in n for x in ['лента','неон']) else 'Комплектующие и подключение'
 elif old=='control':direction='control';segment='Драйверы и блоки управления'
 elif old=='outdoor':direction='outdoor';segment='Уличное освещение'
 elif old=='sources':direction='sources';segment='Источники света'
 elif old=='functional' and 'настенно-потолоч' in c:direction='decorative';segment='Потолочные и настенные светильники'
 elif old in ['functional','architectural']:
  direction='architectural';track=('трек' in text or 'шинопровод' in text)
  phase=(d.get('Количество фаз','')+' '+d.get('Тип шинопровода','')+' '+n).lower()
  if any(x in text for x in ['под шпакл','под шпатл','под покраску']):segment='Под шпаклёвку и покраску'
  elif track:
   if 'магнит' in phase or 'магнит' in d.get('description','').lower():segment='Магнитные трековые системы'
   elif 'трехфаз' in phase or 'трёхфаз' in phase:segment='Трёхфазные трековые системы'
   elif 'однофаз' in phase:segment='Однофазные трековые системы'
   elif 'настенн' in n:segment='Настенные трековые системы'
   elif any(x in c for x in ['nove','трос','corda']):segment='Дизайнерские трековые системы'
   else:segment='Трековые системы и комплектующие'
  elif any(x in text for x in ['встраив','врезн']):segment='Встраиваемые светильники'
  elif 'накладн' in text:segment='Накладные светильники'
  else:segment='Технические светильники и системы'
 else:direction='decorative';segment='Интерьерное освещение'
 p['direction']=direction;p['segment']=segment;p['socketTypes']=sockets(p.get('socket',''))
path.write_text(json.dumps(data,ensure_ascii=False,separators=(',',':')))
print('Directions:',dict(collections.Counter(p['direction'] for p in data['items'])))
print('Bulb sockets:',sorted(set(s for p in data['items'] if p['direction']=='sources' for s in p['socketTypes'])))
print('Architecture:',dict(collections.Counter(p['segment'] for p in data['items'] if p['direction']=='architectural')))
