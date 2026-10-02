'use client';
import {useState} from 'react';
export type Direction={id:string;name:string;text:string;image:string};
export function CategoryStage({directions,onOpen}:{directions:Direction[];onOpen:(id:string)=>void}){
 const [active,setActive]=useState(directions[0].id);
 const [loaded,setLoaded]=useState<Record<string,boolean>>({});
 const shown=loaded[active]?active:directions[0].id;
 const current=directions.find(d=>d.id===active)!;
 return <section className="category-stage" aria-label="Направления освещения">
  <div className="stage-backgrounds" aria-hidden="true">{directions.map((d,i)=><img key={d.id} className={shown===d.id?'stage-image active':'stage-image'} src={d.image} alt="" fetchPriority={i===0?'high':'low'} onLoad={()=>setLoaded(v=>({...v,[d.id]:true}))}/>)}</div>
  <div className="stage-content"><span className="eyebrow">ELSTAR · СВЕТ ДЛЯ ВАШЕГО ПРОСТРАНСТВА</span><h1 className="sr-only">Каталог освещения ELSTAR</h1>
   <nav className="stage-categories" aria-label="Основные разделы">{directions.map((d,i)=><button key={d.id} style={{animationDelay:`${i*45}ms`}} className={active===d.id?'active':''} aria-pressed={active===d.id} onPointerEnter={e=>{if(e.pointerType==='mouse')setActive(d.id)}} onFocus={()=>setActive(d.id)} onClick={()=>{setActive(d.id);if(matchMedia('(hover: hover) and (pointer: fine)').matches)onOpen(d.id)}}><span>{d.name}</span></button>)}</nav>
   <div className="stage-bottom"><p>{current.text}</p><button className="stage-open" onClick={()=>onOpen(active)}>{active==='services'?'Подробнее об услугах':'Открыть раздел'}</button></div>
  </div>
 </section>
}
