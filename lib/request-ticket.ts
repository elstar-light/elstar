import {validateRequest,services,type Item} from './requests';

export const requestTicketOrigin='https://elstar-light.elstar1-ru.chatgpt.site';
export const requestTicketOrigins=[requestTicketOrigin,'https://elstar-light.ru'];
export const jinoRequestsUrl='https://3d2ef918ae94.hosting.myjino.ru/elstar-requests.php';
const fields=['requestId','kind','service','delivery','installation','items'];

// Only selection metadata crosses this endpoint. Customer fields go directly to Jino.
export function validateTicketSelection(raw:unknown){
 if(!raw||typeof raw!=='object'||Array.isArray(raw))throw Error('Проверьте заявку.');
 const value=raw as Record<string,unknown>;
 if(Object.keys(value).some(key=>!fields.includes(key)))throw Error('Передайте только состав корзины и способ получения.');
 if(typeof value.requestId!=='string'||!/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(value.requestId))throw Error('Обновите форму и попробуйте снова.');
 if(!['order','callback','service'].includes(String(value.kind)))throw Error('Выберите тип заявки.');
 if(value.service!==undefined&&value.service!==''&&!services.includes(String(value.service)))throw Error('Выберите услугу.');
 if(value.delivery!==undefined&&value.delivery!==''&&!['pickup','moscow','russia'].includes(String(value.delivery)))throw Error('Выберите способ получения.');
 if(value.installation!==undefined&&typeof value.installation!=='boolean')throw Error('Проверьте заявку.');
 if(value.items!==undefined){
  if(!Array.isArray(value.items)||value.items.length>50)throw Error('В заказе должно быть до 50 разных товаров.');
  for(const row of value.items){
   if(!row||typeof row!=='object'||Array.isArray(row)||Object.keys(row).some(key=>!['id','quantity','expectedPrice'].includes(key)))throw Error('Проверьте состав корзины.');
   if(typeof row.id!=='string'||row.id.length>150||!Number.isInteger(row.quantity)||row.quantity<1||!Number.isFinite(row.expectedPrice)||row.expectedPrice<0)throw Error('Проверьте состав корзины.');
  }
 }
 return value;
}

export function createRequestTicket(raw:ReturnType<typeof validateTicketSelection>,inventory:Item[],now=Date.now()){
 const checked=validateRequest({...raw,name:'Проверка корзины',phone:'+70000000000',address:'Адрес проверки',consent:true},inventory,new Date(now).toLocaleDateString('en-CA',{timeZone:'Europe/Moscow'}));
 return {v:1,aud:requestTicketOrigin,requestId:raw.requestId,expires:Math.floor(now/1000)+300,kind:checked.kind,
  service:checked.service,delivery:checked.delivery,installation:checked.installation,items:checked.items,total:checked.total,prepayment:checked.prepayment};
}

export async function signRequestTicket(ticket:ReturnType<typeof createRequestTicket>,privatePem:string){
 const bytes=new TextEncoder().encode(JSON.stringify(ticket));
 const encode=(value:Uint8Array)=>btoa(Array.from(value,byte=>String.fromCharCode(byte)).join('')).replaceAll('+','-').replaceAll('/','_').replace(/=+$/,'');
 const pem=privatePem.replace(/-----[^-]+-----|\s/g,'');
 const key=await crypto.subtle.importKey('pkcs8',Uint8Array.from(atob(pem),c=>c.charCodeAt(0)),{name:'RSASSA-PKCS1-v1_5',hash:'SHA-256'},false,['sign']);
 const encoded=encode(bytes);
 const signature=await crypto.subtle.sign('RSASSA-PKCS1-v1_5',key,new TextEncoder().encode(encoded));
 return {ticket:encoded,signature:encode(new Uint8Array(signature)),endpoint:jinoRequestsUrl};
}
