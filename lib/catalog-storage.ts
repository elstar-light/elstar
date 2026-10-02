import {env} from 'cloudflare:workers';
import baseline from '@/data/inventory.json';
import type {Item} from './requests';

export const suppliers=['isonex','lightstar'] as const;
export type Supplier=typeof suppliers[number];
export type CatalogManifest={supplier:Supplier;revision:string;date:string;sourceTime:number;count:number;updatedAt:string;checkedAt:string;startedAt:number;files:Record<string,string>};
export const bucket=()=>{if(!env.BUCKET)throw Error('Catalog storage unavailable');return env.BUCKET};
export const manifestKey=(s:Supplier)=>`catalog/${s}/current.json`;
export const objectKey=(s:Supplier,r:string,file:string)=>`catalog/${s}/${r}/${file}`;
export const validSupplier=(s:string):s is Supplier=>suppliers.includes(s as Supplier);
export const validRevision=(r:string)=>/^[a-f0-9]{64}$/.test(r);
export const validFile=(f:string)=>/^(catalog|inventory|details-\d{1,3})\.json$/.test(f);
export const sha256=async(s:string|ArrayBuffer)=>Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256',typeof s==='string'?new TextEncoder().encode(s):s))).map(x=>x.toString(16).padStart(2,'0')).join('');
export async function manifest(s:Supplier){const o=await bucket().get(manifestKey(s));return o?await o.json<CatalogManifest>():null;}
export async function currentInventory():Promise<Item[]>{
 const parts=await Promise.all(suppliers.map(async s=>{
  const m=await manifest(s);
  if(!m)return baseline.filter(p=>p.id.startsWith('lightstar:')===(s==='lightstar'));
  const o=await bucket().get(objectKey(s,m.revision,'inventory.json'));
  if(!o)throw Error('Catalog inventory unavailable');
  return o.json<Item[]>();
 }));
 return parts.flat();
}
export async function authorizedUpdater(request:Request){
 const expected=(env as typeof env&{CATALOG_UPDATE_TOKEN_SHA256?:string}).CATALOG_UPDATE_TOKEN_SHA256;
 const supplied=request.headers.get('X-Catalog-Authorization')?.replace(/^Bearer /,'');
 if(!expected||!supplied)return false;
 const actual=await sha256(supplied);let diff=actual.length^expected.length;
 for(let i=0;i<actual.length;i++)diff|=actual.charCodeAt(i)^(expected.charCodeAt(i)||0);
 return diff===0;
}
export async function limitedText(request:Request,max:number){
 if(Number(request.headers.get('content-length')||0)>max)throw Error('Payload too large');
 const reader=request.body?.getReader();if(!reader)return '';
 let size=0;const chunks:Uint8Array[]=[];
 try{while(true){const {value,done}=await reader.read();if(done)break;size+=value.length;if(size>max){await reader.cancel();throw Error('Payload too large')}chunks.push(value)}}finally{reader.releaseLock()}
 const bytes=new Uint8Array(size);let offset=0;for(const c of chunks){bytes.set(c,offset);offset+=c.length}
 return new TextDecoder().decode(bytes);
}
