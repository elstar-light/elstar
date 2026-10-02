import {bucket,objectKey,validSupplier,validRevision,validFile} from '@/lib/catalog-storage';
export const dynamic='force-dynamic';
export async function GET(_request:Request,context:{params:Promise<{path:string[]}>}){
 const {path}=await context.params;const [s,r,file]=path;
 if(path.length!==3||!validSupplier(s)||!validRevision(r)||!validFile(file)||file==='inventory.json')return new Response('Not found',{status:404});
 try{const o=await bucket().get(objectKey(s,r,file));if(!o)return new Response('Not found',{status:404});return new Response(o.body,{headers:{'Content-Type':'application/json; charset=utf-8','Cache-Control':'public, max-age=31536000, immutable','ETag':o.httpEtag}})}
 catch{return new Response('Catalog unavailable',{status:503})}
}
