import {legacyRequestsEnabled} from '@/data/storefront';
import {env} from 'cloudflare:workers';
import {validateRequest,telegramText,PriceChanged} from '@/lib/requests';
import {currentInventory} from '@/lib/catalog-storage';
export const dynamic='force-dynamic';
const bindings=()=>env as typeof env&{TELEGRAM_BOT_TOKEN?:string;TELEGRAM_CHAT_ID?:string};
const digest=async(s:string)=>Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256',new TextEncoder().encode(s)))).map(x=>x.toString(16).padStart(2,'0')).join('');
const reply=(body:unknown,status=200)=>Response.json(body,{status,headers:{'Cache-Control':'no-store'}});
export async function POST(request:Request){
 if(!legacyRequestsEnabled)return reply({error:'Оформите заказ с менеджером: +7 (925) 908-89-88 или Telegram @managerElstar.'},503);
 const e=bindings();if(!e.DB||!e.BUCKET||!e.TELEGRAM_BOT_TOKEN||!e.TELEGRAM_CHAT_ID)return reply({error:'Приём заявок временно недоступен. Позвоните: +7 (925) 908-89-88.'},503);
 if(request.headers.get('origin')!==new URL(request.url).origin)return reply({error:'Недопустимый источник запроса.'},403);
 if(Number(request.headers.get('content-length')||0)>16*1024*1024)return reply({error:'Файлы слишком большие.'},413);
 let id='',ref='',saved=false;
 try{
  const form=await request.formData();const raw=JSON.parse(String(form.get('payload')||'{}'));
  if(raw.website)return reply({error:'Не удалось отправить заявку.'},400);
  id=raw.requestId;if(typeof id!=='string'||!/^[-a-f0-9]{36}$/.test(id))return reply({error:'Обновите форму и попробуйте снова.'},400);
  const prior=await e.DB.prepare('SELECT reference,status,fingerprint,payload FROM requests WHERE id=?').bind(id).first<{reference:string;status:string;fingerprint:string;payload:string}>();
  if(prior){const savedPayload=JSON.parse(prior.payload);const snapshot=savedPayload.items.map((p:any)=>({...p,stock:p.quantity}));const candidate=validateRequest(raw,snapshot,new Date().toLocaleDateString('en-CA',{timeZone:'Europe/Moscow'}));if(await digest(JSON.stringify(candidate))!==prior.fingerprint)return reply({error:'Заявка с этим номером уже сохранена. Обновите страницу для новой заявки.'},409);return reply({reference:prior.reference,notified:prior.status==='sent',message:prior.status==='sent'?'Заявка получена! Мы свяжемся с вами с 10:00 до 20:00.':'Заявка сохранена. Если менеджер не свяжется с вами, позвоните: +7 (925) 908-89-88.'});}
  const inventory=raw.kind==='order'?await currentInventory():[];
  const payload=validateRequest(raw,inventory,new Date().toLocaleDateString('en-CA',{timeZone:'Europe/Moscow'}));
  const files=form.getAll('photos').filter((x):x is File=>typeof x!=='string'&&x.size>0);
  if(files.length>3||files.some(f=>f.size>5*1024*1024)||files.length&&payload.kind!=='service'&&!payload.installation)throw Error('Можно добавить до 3 фото по 5 МБ для заявки на монтаж или выезд.');
  const photos=[];
  for(const f of files){const b=await f.arrayBuffer();const a=new Uint8Array(b);const mime=a[0]===255&&a[1]===216?'image/jpeg':a[0]===137&&a[1]===80&&a[2]===78&&a[3]===71?'image/png':String.fromCharCode(...a.slice(0,4))==='RIFF'&&String.fromCharCode(...a.slice(8,12))==='WEBP'?'image/webp':'';if(!mime)throw Error('Прикрепите изображения JPG, PNG или WebP.');photos.push({bytes:b,mime});}
  const fingerprint=await digest(JSON.stringify(payload));
  const now=Date.now();const source=await digest(request.headers.get('cf-connecting-ip')||'unknown');
  const count=await e.DB.prepare('SELECT count(*) AS n FROM requests WHERE source_hash=? AND created_at>?').bind(source,now-600000).first<{n:number}>();
  if((count?.n||0)>=5)return reply({error:'Слишком много заявок. Попробуйте через 10 минут или позвоните.'},429);
  ref='EL-'+new Date().toISOString().slice(0,10).replaceAll('-','')+'-'+id.slice(0,8).toUpperCase();
  const photoKeys=photos.map((_,i)=>`requests/${id}/${i}`);
  await e.DB.prepare('INSERT INTO requests (id,reference,kind,fingerprint,payload,status,source_hash,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?)').bind(id,ref,payload.kind,fingerprint,JSON.stringify({...payload,photoKeys}),'pending',source,now,now).run();saved=true;
  for(let i=0;i<photos.length;i++)await e.BUCKET.put(photoKeys[i],photos[i].bytes,{httpMetadata:{contentType:photos[i].mime}});
  const text=telegramText(payload,ref);const chunks=text.match(/[\s\S]{1,3800}/g)||[];let messageId='';
  for(const chunk of chunks){const result=await fetch(`https://api.telegram.org/bot${e.TELEGRAM_BOT_TOKEN}/sendMessage`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({chat_id:e.TELEGRAM_CHAT_ID,text:chunk}),signal:AbortSignal.timeout(20000)});const answer=await result.json() as any;if(!answer.ok)throw Error('notification');messageId=String(answer.result.message_id);}
  for(let i=0;i<photos.length;i++){const f=new FormData();f.set('chat_id',e.TELEGRAM_CHAT_ID);f.set('caption',`ELSTAR · ${ref} · фото ${i+1}`);f.set('photo',new Blob([photos[i].bytes],{type:photos[i].mime}),'photo.'+(photos[i].mime==='image/jpeg'?'jpg':photos[i].mime.split('/')[1]));const response=await fetch(`https://api.telegram.org/bot${e.TELEGRAM_BOT_TOKEN}/sendPhoto`,{method:'POST',body:f,signal:AbortSignal.timeout(20000)});if(!(await response.json() as any).ok)throw Error('notification');}
  await e.DB.prepare('UPDATE requests SET status=?,telegram_message_id=?,updated_at=? WHERE id=?').bind('sent',messageId,Date.now(),id).run();
  return reply({reference:ref,notified:true,message:'Заявка получена! Мы свяжемся с вами в рабочее время — с 10:00 до 20:00.'});
 }catch(err){
  if(err instanceof PriceChanged)return reply({code:"PRICE_CHANGED",error:err.message,items:err.items,total:err.total},409);
  if(saved){try{await e.DB.prepare('UPDATE requests SET status=?,updated_at=? WHERE id=?').bind('notification_failed',Date.now(),id).run()}catch{}return reply({reference:ref,notified:false,message:'Заявка сохранена, но уведомление менеджеру не доставлено. Позвоните: +7 (925) 908-89-88 и назовите номер заявки.'});}
  const msg=err instanceof Error?err.message:'';const expected=/^(Укажите|Проверьте|Подтвердите|Выберите|Монтаж|Наличие|Минимальный|Можно добавить|Прикрепите|В заказе)/.test(msg);
  return reply({error:expected?msg:'Не удалось сохранить заявку. Данные остались в форме — попробуйте ещё раз.'},expected?400:503);
 }
}
