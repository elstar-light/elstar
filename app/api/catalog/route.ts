import {manifest,suppliers} from '@/lib/catalog-storage';
export const dynamic='force-dynamic';
export async function GET(){
 try{const data=await Promise.all(suppliers.map(async supplier=>{const m=await manifest(supplier);return {supplier,revision:m?.revision||null,date:m?.date||null,count:m?.count||0,updatedAt:m?.updatedAt||null,checkedAt:m?.checkedAt||null}}));return Response.json({sources:data},{headers:{'Cache-Control':'no-store'}})}
 catch{return Response.json({error:'Каталог временно недоступен.'},{status:503,headers:{'Cache-Control':'no-store'}})}
}
