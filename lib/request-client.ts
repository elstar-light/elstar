import {privacyDocumentVersion} from '@/data/privacy';
import {jinoRequestsUrl} from './request-ticket';
export type RequestSelection={requestId:string;kind:'order'|'callback'|'service';service:string;delivery:string;installation:boolean;items:{id:string;quantity:number;expectedPrice:number}[]};
export type RequestResult={reference:string;notified:boolean;message:string};
export class RequestSubmissionError extends Error {
 constructor(public data:{error?:string;code?:string;items?:unknown[];total?:number}){super(data.error||'Не удалось отправить заявку.')}
}
export async function submitJinoRequest(selection:RequestSelection,customer:FormData):Promise<RequestResult>{
 // Only nonpersonal basket metadata is sent to the Site.
 const check=await fetch('/api/request-ticket',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(selection)});
 const signed=await check.json() as {endpoint?:string;ticket?:string;signature?:string;error?:string;code?:string;items?:unknown[];total?:number};
 if(!check.ok||signed.error)throw new RequestSubmissionError(signed);
 if(signed.endpoint!==jinoRequestsUrl||typeof signed.ticket!=='string'||typeof signed.signature!=='string')throw Error('Не удалось проверить заявку.');
 const body=new FormData();
 body.set('ticket',signed.ticket);body.set('signature',signed.signature);
 const text=(key:string)=>{const value=customer.get(key);return typeof value==='string'?value:''};
 body.set('payload',JSON.stringify({requestId:selection.requestId,kind:selection.kind,consent:true,consentVersion:privacyDocumentVersion,name:text('name'),phone:text('phone'),email:text('email'),address:text('address'),comment:text('comment'),date:text('date'),website:text('website')}));
 for(const photo of customer.getAll('photos'))if(photo instanceof File&&photo.size>0)body.append('photos[]',photo,photo.name);
 let response:Response;
 try{response=await fetch(jinoRequestsUrl,{method:'POST',body,credentials:'omit'})}
 catch{throw Error('Не удалось подтвердить отправку. Повторите попытку: уже сохранённая заявка не продублируется. Или позвоните: +7 (925) 908-89-88.')}
 const data=await response.json() as Partial<RequestResult>&{error?:string};
 if(!response.ok||data.error)throw new RequestSubmissionError(data);
 if(typeof data.reference!=='string'||typeof data.message!=='string'||typeof data.notified!=='boolean')throw Error('Не удалось подтвердить отправку заявки. Повторите попытку.');
 return {reference:data.reference,message:data.message,notified:data.notified};
}
