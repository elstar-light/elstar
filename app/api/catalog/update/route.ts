import {authorizedUpdater,bucket,limitedText,manifestKey,objectKey,sha256,validFile,validRevision,validSupplier,CatalogManifest} from '@/lib/catalog-storage';
export const dynamic='force-dynamic';
const reply=(data:unknown,status=200)=>Response.json(data,{status,headers:{'Cache-Control':'no-store'}});
export async function POST(request:Request){
 if(!await authorizedUpdater(request))return reply({error:'Unauthorized'},401);
 try{
  const u=new URL(request.url),supplier=u.searchParams.get('supplier')||'',revision=u.searchParams.get('revision')||'',action=u.searchParams.get('action');
  if(!validSupplier(supplier)||!validRevision(revision))return reply({error:'Invalid supplier or revision'},400);
  const b=bucket();
  if(action==='upload'){
   const file=u.searchParams.get('file')||'';
   if(!validFile(file)||file==='inventory.json')return reply({error:'Invalid file'},400);
   const body=await limitedText(request,16*1024*1024);JSON.parse(body);
   const hash=await sha256(body),key=objectKey(supplier,revision,file);
   const old=await b.head(key);
   if(old){if(old.customMetadata?.sha256!==hash)return reply({error:'Immutable revision conflict'},409);return reply({sha256:hash})}
   const written=await b.put(key,body,{onlyIf:{etagDoesNotMatch:'*'},httpMetadata:{contentType:'application/json'},customMetadata:{sha256:hash}});
   if(!written)return reply({error:'Upload raced; retry'},409);
   return reply({sha256:hash});
  }
  if(action!=='commit')return reply({error:'Invalid action'},400);
  const input=JSON.parse(await limitedText(request,100000));
  const current=await b.get(manifestKey(supplier));const previous=current?await current.json<CatalogManifest>():null;
  if((previous?.revision||null)!==input.previousRevision&&previous?.revision!==revision)return reply({error:'Catalog changed during update; rerun'},409);
  if(!Number.isFinite(input.startedAt)||input.startedAt>Date.now()+60000||input.startedAt<(previous?.startedAt||0))return reply({error:'Stale update'},409);
  const sourceTime=Date.parse(input.date?.includes('T')?input.date:input.date?.replace(' ','T')+'+03:00');
  if(!Number.isFinite(sourceTime)||sourceTime>Date.now()+86400000||sourceTime<(previous?.sourceTime||0))return reply({error:'Invalid or older supplier date'},409);
  const files=input.files as Record<string,string>;
  if(!files||!files['catalog.json']||Object.keys(files).length>200)return reply({error:'Incomplete files'},400);
  for(const [file,hash] of Object.entries(files)){
   if(!validFile(file)||file==='inventory.json'||typeof hash!=='string'||!validRevision(hash))return reply({error:'Invalid file manifest'},400);
   const o=await b.head(objectKey(supplier,revision,file));if(!o||o.customMetadata?.sha256!==hash)return reply({error:'Missing or corrupt uploaded file'},409);
  }
  const summary=await b.get(objectKey(supplier,revision,'catalog.json'));if(!summary)throw Error('Missing summary');
  const data=await summary.json<{date:string;items:any[]}>();
  if(data.date!==input.date||!Array.isArray(data.items)||data.items.length<100||data.items.length>30000||previous&&data.items.length<previous.count*.5)return reply({error:'Unexpected catalog size/date; previous catalog kept'},400);
  const seen=new Set<string>();const inventory=[];
  for(const p of data.items){
   const prefix=`${supplier}/${revision}/`;const chunk=typeof p.detailChunk==='string'&&p.detailChunk.startsWith(prefix)?p.detailChunk.slice(prefix.length)+'.json':'';
   if(typeof p.id!=='string'||seen.has(p.id)||p.id.startsWith('lightstar:')!==(supplier==='lightstar')||typeof p.name!=='string'||!p.name||typeof p.sku!=='string'||!Number.isFinite(p.price)||p.price<0||!Number.isFinite(p.old)||p.old<0||!Number.isSafeInteger(p.stock)||p.stock<0||p.price===0&&p.stock>0||!files[chunk]||!Array.isArray(p.pictures)||!Array.isArray(p.quickTags)||!p.specs)return reply({error:'Invalid product; previous catalog kept'},400);
   seen.add(p.id);inventory.push({id:p.id,sku:p.sku,name:p.name,price:p.price,stock:p.stock});
  }
  await b.put(objectKey(supplier,revision,'inventory.json'),JSON.stringify(inventory),{httpMetadata:{contentType:'application/json'}});
  const now=new Date().toISOString();const next:CatalogManifest={supplier,revision,date:input.date,sourceTime,count:inventory.length,updatedAt:previous?.revision===revision?previous.updatedAt:now,checkedAt:now,startedAt:input.startedAt,files};
  const saved=await b.put(manifestKey(supplier),JSON.stringify(next),{onlyIf:current?{etagMatches:current.etag}:{etagDoesNotMatch:'*'},httpMetadata:{contentType:'application/json'}});
  if(!saved)return reply({error:'Another update completed; rerun'},409);
  return reply({supplier,revision,count:inventory.length,updatedAt:next.updatedAt,checkedAt:now});
 }catch{return reply({error:'Catalog update failed; previous catalog kept'},503)}
}
