import {useEffect,useRef,useState,type ReactNode} from 'react';
import {X,LoaderCircle,Search,ChevronLeft,ChevronRight,Inbox,AlertCircle} from 'lucide-react';
export type Row = Record<string, any>;
export type Ctx = {user:Row; options:Row; notify:(s:string)=>void; refresh:()=>void; navigate:(s:string)=>void};
let csrf='';
export const APP_BASE_PATH=import.meta.env.BASE_URL.replace(/\/build\/$/,'');
export function setCsrf(value:string){csrf=value}
export async function api(path:string,method='GET',body?:unknown){
  const isForm=body instanceof FormData;
  const r=await fetch(APP_BASE_PATH+'/api'+path,{method,credentials:'same-origin',headers:{Accept:'application/json',...(method!=='GET'?{'X-CSRF-TOKEN':csrf}:{}),...(!isForm&&body?{'Content-Type':'application/json'}:{})},body:body?(isForm?body:JSON.stringify(body)):undefined});
  const data=r.status===204?null:await r.json().catch(()=>({message:'O servidor não respondeu como esperado.'}));
  if(!r.ok){if(r.status===401)window.dispatchEvent(new Event('session-expired'));throw new Error(data.errors?Object.values(data.errors).flat().join(' '):data.message||'Não foi possível concluir. Tente novamente.');}
  if(data?.csrf)setCsrf(data.csrf); return data;
}
export function useApi(path:string|null,version=0){
  const [data,setData]=useState<any>(null),[error,setError]=useState(''),[loading,setLoading]=useState(true),[tick,setTick]=useState(0);
  useEffect(()=>{let active=true;if(!path){setLoading(false);return;}setLoading(true);setError('');api(path).then(v=>{if(active)setData(v)}).catch(e=>{if(active)setError(e.message)}).finally(()=>{if(active)setLoading(false)});return()=>{active=false}},[path,version,tick]);
  return {data,error,loading,reload:()=>setTick(x=>x+1)};
}
export const today=()=>{const d=new Date();return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`};
export const monthNow=()=>today().slice(0,7);
export const dateBR=(s?:string)=>s?new Date(s.slice(0,10)+'T12:00:00').toLocaleDateString('pt-BR'):'—';
export const timeBR=(s?:string)=>s?new Date(s.includes('T')?s:s.replace(' ','T')+'-03:00').toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit'}):'—';
export const number=(n:number)=>new Intl.NumberFormat('pt-BR').format(n||0);
export const initials=(s:string)=>s?.split(' ').filter(Boolean).slice(0,2).map(s=>s[0]).join('')||'CL';
export const phone=(s?:string)=>s?.replace(/^(\d{2})(\d{5})(\d{4})$/,'($1) $2-$3')||'—';
export const cpf=(s?:string)=>s?.replace(/^(\d{3})(\d{3})(\d{3})(\d{2})$/,'$1.$2.$3-$4')||'—';
export function query(v:Row){return new URLSearchParams(Object.entries(v).filter(([,v])=>v!==undefined&&v!==null).map(([k,v])=>[k,String(v)])).toString()}
export function Avatar({name,src}:{name:string;src?:string}){return src?<img className="avatar" src={src} alt={name}/>:<span className="avatar">{initials(name)}</span>}
export function Status({value}:{value:string}){const labels:Row={scheduled:'Agendada',confirmed:'Na fila',completed:'Finalizada',cancelled:'Cancelada',available:'Disponível',queued:'Aguardando envio',sending:'Enviando',sent:'Enviada',delivered:'Entregue',read:'Lida',failed:'Falhou',uncertain:'Verificar envio'};return <span className={'badge '+value}><i/>{labels[value]||value}</span>}
export function Empty({title='Nenhum registro encontrado',text='Os registros aparecerão aqui quando forem cadastrados.'}:{title?:string;text?:string}){return <div className="empty"><Inbox size={30}/><strong>{title}</strong><p>{text}</p></div>}
export function Loading(){return <div className="loading"><LoaderCircle className="spin" size={22}/> Carregando…</div>}
export function ErrorBox({message}:{message?:string}){return message?<div className="error" role="alert"><AlertCircle size={18}/><span>{message}</span></div>:null}
export function SearchBox({value,onChange,placeholder='Buscar por nome ou CPF…'}:{value:string;onChange:(s:string)=>void;placeholder?:string}){return <label className="search"><Search size={18}/><input aria-label={placeholder} value={value} onChange={e=>onChange(e.target.value)} placeholder={placeholder}/></label>}
export function Modal({title,children,onClose,wide=false}:{title:string;children:ReactNode;onClose:()=>void;wide?:boolean}){
  const ref=useRef<HTMLDialogElement>(null);useEffect(()=>{const el=ref.current;el?.showModal();const old=document.body.style.overflow;document.body.style.overflow='hidden';return()=>{document.body.style.overflow=old;el?.close()}},[]);
  return <dialog className={wide?'modal wide':'modal'} ref={ref} onCancel={e=>{e.preventDefault();onClose()}} aria-label={title}><div className="modal-head"><h2>{title}</h2><button className="icon-button" onClick={onClose} aria-label="Fechar"><X/></button></div><div className="modal-content">{children}</div></dialog>
}
export function Confirm({title,text,onClose,onConfirm,label='Confirmar',danger=false}:{title:string;text:string;onClose:()=>void;onConfirm:()=>Promise<void>;label?:string;danger?:boolean}){
  const[busy,setBusy]=useState(false),[error,setError]=useState('');return <Modal title={title} onClose={()=>{if(!busy)onClose()}}><p>{text}</p><ErrorBox message={error}/><div className="form-actions"><button className="button secondary" disabled={busy} onClick={onClose}>Voltar</button><button className={'button '+(danger?'danger':'')} disabled={busy} onClick={async()=>{setBusy(true);try{await onConfirm();onClose()}catch(e){setError((e as Error).message)}finally{setBusy(false)}}}>{busy?'Processando…':label}</button></div></Modal>
}
export function Pager({data,onPage}:{data:Row;onPage:(n:number)=>void}){if(!data?.total)return null;return <div className="pager"><span>{data.from}–{data.to} de {number(data.total)} registros</span><div><button className="icon-button" disabled={data.current_page<=1} onClick={()=>onPage(data.current_page-1)} aria-label="Página anterior"><ChevronLeft size={18}/></button><span>{data.current_page} / {data.last_page}</span><button className="icon-button" disabled={data.current_page>=data.last_page} onClick={()=>onPage(data.current_page+1)} aria-label="Próxima página"><ChevronRight size={18}/></button></div></div>}
export function Field({label,children,hint}:{label:string;children:ReactNode;hint?:string}){return <label className="field"><span>{label}</span>{children}{hint&&<small>{hint}</small>}</label>}
export function Calendar({month,onMonth,slots=[],selected=[],onSelect,multi=false}:{month:string;onMonth:(s:string)=>void;slots?:Row[];selected?:string[];onSelect:(d:string)=>void;multi?:boolean}){
 const [y,m]=month.split('-').map(Number),count=new Date(y,m,0).getDate(),offset=(new Date(y,m-1,1).getDay()+6)%7;
 const change=(n:number)=>{const d=new Date(y,m-1+n,1);onMonth(`${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}`)};
 return <div className="calendar"><div className="calendar-head"><h3>{new Date(y,m-1,1).toLocaleDateString('pt-BR',{month:'long',year:'numeric'})}</h3><div><button type="button" className="icon-button" onClick={()=>change(-1)} aria-label="Mês anterior"><ChevronLeft size={18}/></button><button type="button" className="icon-button" onClick={()=>change(1)} aria-label="Próximo mês"><ChevronRight size={18}/></button></div></div><div className="calendar-grid">{['S','T','Q','Q','S','S','D'].map((s,i)=><span className="weekday" key={i}>{s}</span>)}{Array.from({length:offset},(_,i)=><span key={'e'+i}/>)}{Array.from({length:count},(_,i)=>{const day=`${month}-${String(i+1).padStart(2,'0')}`,rows=slots.filter(s=>s.date===day&&s.status==='available'),free=rows.reduce((a,s)=>a+Math.max(0,s.capacity-s.booked),0);const state=rows.length?(free?'open':'full'):'none';return <button type="button" aria-label={`${dateBR(day)}: ${multi?'selecionar':state==='open'?free+' vagas':state==='full'?'lotado':'sem oferta'}`} aria-pressed={selected.includes(day)} disabled={day<today()} key={day} className={`day ${multi?'pick':state} ${selected.includes(day)?'selected':''} ${day===today()?'today':''}`} onClick={()=>onSelect(day)}><span>{i+1}</span>{!multi&&<i/>}</button>})}</div>{!multi&&<div className="legend"><span><i className="green"/>Com vagas</span><span><i className="red"/>Lotado</span><span><i className="yellow"/>Sem oferta</span></div>}</div>
}
