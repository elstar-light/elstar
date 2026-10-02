'use client';
import {useRef,useState} from 'react';
import {submitJinoRequest,RequestSubmissionError,type RequestResult,type RequestSelection} from '@/lib/request-client';
type TestItem={id:string;name:string;sku:string;price:number;stock:number};
const money=(value:number)=>new Intl.NumberFormat('ru-RU').format(value)+' ₽';
async function testProduct():Promise<TestItem>{
 const response=await fetch('/api/catalog',{cache:'no-store'});if(!response.ok)throw Error('Не удалось проверить каталог.');
 const state=await response.json() as {sources:{supplier:string;revision:string|null}[]};
 for(const source of state.sources){
  const url=source.revision?`/api/catalog/file/${source.supplier}/${source.revision}/catalog.json`:'/catalog.json';
  const part=await fetch(url);if(!part.ok)throw Error('Не удалось загрузить товары для проверки.');
  const data=await part.json() as {items:TestItem[]};
  const product=data.items.find(item=>item.stock>0&&item.price>=150);
  if(product)return product;
 }
 throw Error('Нет товара в наличии для тестового заказа.');
}
function testPhoto(){
 // Tiny fixed PNG fixture, contains no user photo or metadata.
 const encoded='iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGOYV2P2HwAFXAJQZsM2cAAAAABJRU5ErkJggg==';
 return new File([Uint8Array.from(atob(encoded),c=>c.charCodeAt(0))],'test-photo.png',{type:'image/png'});
}
export default function ConnectionCheck(){
 const callbackId=useRef(''),orderId=useRef(''),productRef=useRef<TestItem|null>(null);
 const [busy,setBusy]=useState(false),[error,setError]=useState(''),[result,setResult]=useState<(RequestResult&{kind:string;product?:TestItem})|null>(null);
 async function check(kind:'order'|'callback'){
  if(busy)return;setBusy(true);setError('');
  const id=kind==='order'?orderId:callbackId;if(!id.current)id.current=crypto.randomUUID();
  const data=new FormData();data.set('name',kind==='order'?'ТЕСТ ELSTAR — заказ с товаром и фото':'ТЕСТ ELSTAR — проверка подключения');data.set('phone','+70000000000');data.set('comment','Техническая проверка. Не заказ клиента, не резервировать товар, не звонить.');
  let selection:RequestSelection={requestId:id.current,kind,service:'',delivery:'',installation:false,items:[]};
  try{
   if(kind==='order'){
    const product=productRef.current??await testProduct();productRef.current=product;
    selection={...selection,delivery:'pickup',installation:true,items:[{id:product.id,quantity:1,expectedPrice:product.price}]};
    data.set('address','Тестовый адрес объекта, Москва');data.append('photos',testPhoto());
   }
   const saved=await submitJinoRequest(selection,data);setResult({...saved,kind,product:kind==='order'?productRef.current??undefined:undefined});
  }catch(e){
   if(e instanceof RequestSubmissionError&&e.data.code==='PRICE_CHANGED'&&e.data.items&&productRef.current){
    const changed=e.data.items as {id:string;price:number}[];const item=changed.find(row=>row.id===productRef.current?.id);
    if(item)productRef.current={...productRef.current,price:item.price};orderId.current='';
    setError('Цена товара изменилась. Нажмите проверку заказа ещё раз, чтобы подтвердить новую цену.');
   }else setError(e instanceof Error?e.message:'Проверка не выполнена.');
  }finally{setBusy(false)}
 }
 return <main className="section content-page store-conditions"><span className="eyebrow">ELSTAR / ПРОВЕРКА ПОДКЛЮЧЕНИЯ</span><h1>Проверка заявок</h1><p>Проверки используют вымышленные контакты. Тестовый заказ содержит один товар в наличии, самовывоз, заявку на установку и маленькое тестовое изображение. Оплата и резерв товара не выполняются.</p><div className="request-contact-actions"><button className="primary" disabled={busy} onClick={()=>check('order')}>{busy?'Проверяем…':orderId.current?'Повторить тест заказа без дубликата':'Проверить заказ с товаром и фото'}</button><button className="outline" disabled={busy} onClick={()=>check('callback')}>Проверить обратный звонок</button></div>{error&&<p className="request-error" role="alert">{error}</p>}{result&&<section role="status"><h2>Заявка сохранена в Джино</h2><p>Номер: <strong>{result.reference}</strong></p>{result.product&&<><p>{result.product.name}<br/>Артикул: {result.product.sku}<br/>1 шт. × {money(result.product.price)}<br/>Сумма товаров: {money(result.product.price)}<br/>Предоплата: 0 ₽ (самовывоз)</p><p>В кабинете также должны отображаться установка, тестовый адрес и одно изображение размером 1 × 1 пиксель.</p></>}<p>Откройте кабинет и найдите заявку «{result.kind==='order'?'ТЕСТ ELSTAR — заказ с товаром и фото':'ТЕСТ ELSTAR — проверка подключения'}».</p><p>Повторная проверка на этой странице использует тот же номер заявки.</p></section>}<p><a href="/">Вернуться на сайт</a></p></main>;
}
