import {env} from 'cloudflare:workers';
import {currentInventory,limitedText} from '@/lib/catalog-storage';
import {PriceChanged} from '@/lib/requests';
import {createRequestTicket,requestTicketOrigins,signRequestTicket,validateTicketSelection} from '@/lib/request-ticket';

export const dynamic='force-dynamic';
const reply=(body:unknown,status=200)=>Response.json(body,{status,headers:{'Cache-Control':'no-store'}});

export async function POST(request:Request){
 if(!requestTicketOrigins.includes(request.headers.get('origin')??''))return reply({error:'Недопустимый источник запроса.'},403);
 if(!request.headers.get('content-type')?.startsWith('application/json'))return reply({error:'Проверьте заявку.'},400);
 const privateKey=(env as typeof env&{REQUEST_TICKET_PRIVATE_KEY?:string}).REQUEST_TICKET_PRIVATE_KEY;
 if(!privateKey)return reply({error:'Проверка корзины временно недоступна.'},503);
 let selection:ReturnType<typeof validateTicketSelection>;
 try{selection=validateTicketSelection(JSON.parse(await limitedText(request,16000)))}
 catch(error){return reply({error:error instanceof Error&&error.message==='Payload too large'?'Слишком большой запрос.':error instanceof SyntaxError?'Проверьте заявку.':error instanceof Error?error.message:'Проверьте заявку.'},400)}
 let inventory;
 try{inventory=selection.kind==='order'?await currentInventory():[]}
 catch{return reply({error:'Не удалось проверить наличие. Попробуйте ещё раз.'},503)}
 let ticket;
 try{ticket=createRequestTicket(selection,inventory)}
 catch(error){
  if(error instanceof PriceChanged)return reply({code:'PRICE_CHANGED',error:error.message,items:error.items,total:error.total},409);
  return reply({error:error instanceof Error?error.message:'Проверьте корзину.'},400);
 }
 try{return reply(await signRequestTicket(ticket,privateKey))}
 catch{return reply({error:'Не удалось проверить корзину. Попробуйте ещё раз.'},503)}
}
