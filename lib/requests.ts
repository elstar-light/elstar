export type Item={id:string;sku:string;name:string;price:number;stock:number};
export class PriceChanged extends Error {
 constructor(public items:{id:string;name:string;price:number;previousPrice:number|null;stock:number;quantity:number}[],public total:number){super('Цена изменилась. Проверьте новую сумму и подтвердите заказ ещё раз.')}
}
export const services=['Монтаж люстры','Монтаж электроприборов','Выезд и подбор освещения','Авторское сопровождение'];
export function validateRequest(raw:any,inventory:Item[],today:string){
 const text=(v:any,max:number)=>typeof v==='string'?v.trim().slice(0,max):'';
 const kind=raw.kind;if(!['order','callback','service'].includes(kind))throw Error('Неизвестный тип заявки.');
 const name=text(raw.name,150),phone=text(raw.phone,40),email=text(raw.email,200),address=text(raw.address,500),comment=text(raw.comment,1500),date=text(raw.date,10),service=text(raw.service,100);
 if(name.length<2)throw Error('Укажите имя.');
 if(!/^\+?[\d\s()\-]+$/.test(phone)||phone.replace(/\D/g,'').length<10||phone.replace(/\D/g,'').length>15)throw Error('Проверьте номер телефона.');
 if(email&&!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email))throw Error('Проверьте электронную почту.');
 if(raw.consent!==true)throw Error('Подтвердите согласие на обработку данных заявки.');
 if(date&&(!/^\d{4}-\d{2}-\d{2}$/.test(date)||Number.isNaN(Date.parse(date))||date<today))throw Error('Укажите будущую дату выезда.');
 if(kind==='service'&&!services.includes(service))throw Error('Выберите услугу.');
 let delivery=kind==='order'?raw.delivery:'',installation=kind==='order'&&raw.installation===true;
 if(kind==='order'&&!['pickup','moscow','russia'].includes(delivery))throw Error('Выберите способ получения.');
 if(installation&&delivery==='russia')throw Error('Монтаж доступен только в Москве и Московской области.');
 if((kind==='service'||(kind==='order'&&(delivery!=='pickup'||installation)))&&address.length<8)throw Error('Укажите полный адрес объекта или доставки.');
 const items:any[]=[];let total=0;
 if(kind==='order'){
  if(!Array.isArray(raw.items)||!raw.items.length||raw.items.length>50)throw Error('В заказе должно быть от 1 до 50 разных товаров.');
  const seen=new Set();const map=new Map(inventory.map(p=>[p.id,p]));
  for(const row of raw.items){const p=map.get(row.id);if(!p||seen.has(row.id)||!Number.isInteger(row.quantity)||row.quantity<1||row.quantity>p.stock||p.stock<1)throw Error('Наличие товара изменилось. Обновите страницу и проверьте корзину.');seen.add(row.id);items.push({id:p.id,sku:p.sku,name:p.name,quantity:row.quantity,price:p.price});total+=Math.round(p.price*100)*row.quantity;}
  total/=100;
  if(raw.items.some((row:any)=>!Number.isFinite(row.expectedPrice)||Math.round(row.expectedPrice*100)!==Math.round(map.get(row.id)!.price*100)))throw new PriceChanged(items.map(p=>({...p,stock:map.get(p.id)!.stock,previousPrice:Number.isFinite(raw.items.find((r:any)=>r.id===p.id).expectedPrice)?raw.items.find((r:any)=>r.id===p.id).expectedPrice:null})),total);
  if(total<(delivery==='pickup'?150:5000))throw Error(delivery==='pickup'?'Минимальный заказ для самовывоза — 150 ₽.':'Минимальный заказ для доставки — 5 000 ₽.');
 }
 return {kind,name,phone,email,address,comment,date,service,delivery,installation,items,total,prepayment:delivery==='moscow'?Math.round(total*50)/100:delivery==='russia'?total:0,consent:true};
}
export function telegramText(p:ReturnType<typeof validateRequest>,ref:string){
 const money=(n:number)=>new Intl.NumberFormat('ru-RU').format(n)+' ₽';
 return [p.kind==='order'?'НОВЫЙ ЗАКАЗ':p.kind==='service'?'ЗАЯВКА НА УСЛУГУ':'ОБРАТНЫЙ ЗВОНОК',`ELSTAR · № ${ref}`,`Имя: ${p.name}`,`Телефон: ${p.phone}`,p.email&&`Почта: ${p.email}`,p.service&&`Услуга: ${p.service}`,p.address&&`Адрес: ${p.address}`,p.date&&`Желаемая дата: ${p.date} (требует подтверждения)`,p.installation&&'Нужна установка',...p.items.map(x=>`${x.sku} · ${x.name}\n${x.quantity} шт. × ${money(x.price)}`),p.kind==='order'&&`Товары: ${money(p.total)}`,p.delivery&&`Получение: ${{pickup:'Самовывоз',moscow:'Москва / МО',russia:'Другой регион России'}[p.delivery as 'pickup']}`,p.delivery&&`Доставка: ${p.delivery==='pickup'?'бесплатно':p.delivery==='russia'?'по тарифам ТК':p.total>30000?'бесплатно; крупногабарит — уточнить':'2 000–3 000 ₽; крупногабарит — уточнить'}`,p.kind==='order'&&`Предоплата за товары: ${money(p.prepayment)}`,p.comment&&`Комментарий: ${p.comment}`].filter(Boolean).join('\n\n');
}
