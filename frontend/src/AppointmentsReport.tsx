import {useState} from 'react';
import {Printer,CalendarDays,ChartNoAxesCombined,HeartPulse} from 'lucide-react';
import {type Ctx,type Row,useApi,query,monthNow,number,Loading,ErrorBox,Empty} from './lib';
import './appointments-report.css';

const months=['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];
const statuses:Record<string,string>={'':'Todas as situações (inclui canceladas)',scheduled:'Agendadas',confirmed:'Confirmadas',completed:'Finalizadas',cancelled:'Canceladas'};
type SummaryRow={specialty_id:number;name:string;counts:number[];total:number};
type Summary={mode:'month'|'year';year:number;month:string;months:number[];rows:SummaryRow[];monthly_totals:number[];total:number;active_specialties:number;status:string};

export default function AppointmentsReport({ctx}:{ctx:Ctx}) {
  const [mode,setMode]=useState<'month'|'year'>('month');
  const [month,setMonth]=useState(monthNow());
  const [year,setYear]=useState(monthNow().slice(0,4));
  const [status,setStatus]=useState('');
  const [specialty,setSpecialty]=useState('');
  const validYear=/^\d{4}$/.test(year)&&Number(year)>=1900&&Number(year)<=2100;
  const validPeriod=mode==='month'?!!month:validYear;
  const {data,loading,error,reload}=useApi(validPeriod?'/reports/appointments/summary?'+query({mode,...(mode==='year'?{year}:{month}),...(status?{status}:{}),...(specialty?{specialty_id:specialty}:{})}):null);
  const report:Summary|null=data;
  // Do not print/show stale totals while filters are changing or a request failed.
  const current=report&&report.mode===mode&&(mode==='year'?report.year===Number(year):report.month===month)&&report.status===(status||'all');
  const ready=validPeriod&&!loading&&!error&&current;
  const period=mode==='year'?year:new Date(month+'-01T12:00:00').toLocaleDateString('pt-BR',{month:'long',year:'numeric'});
  function changeMode(next:'month'|'year') {
    if(next==='year')setYear(month.slice(0,4));
    else if(validYear)setMonth(year+month.slice(4));
    setMode(next);
  }

  return <div className={'appointments-report '+(mode==='year'?'annual-report':'monthly-report')}>
    <div className="page-heading">
      <div><div className="eyebrow">RELATÓRIOS</div><h1>Consultas por especialidade</h1><p>Compare os meses, acompanhe a evolução e veja os totais da clínica.</p></div>
      <button className="button secondary" disabled={!ready} onClick={()=>window.print()}><Printer size={18}/>Imprimir relatório</button>
    </div>
    <section className="panel appointments-filters" aria-label="Filtros do relatório">
      <div className="segmented" role="group" aria-label="Visão do relatório">
        <button className={mode==='month'?'active':''} aria-pressed={mode==='month'} onClick={()=>changeMode('month')}><CalendarDays size={17}/>Mensal</button>
        <button className={mode==='year'?'active':''} aria-pressed={mode==='year'} onClick={()=>changeMode('year')}><ChartNoAxesCombined size={17}/>Anual</button>
      </div>
      {mode==='month'?<label className="field"><span>Mês de referência</span><input type="month" aria-label="Mês de referência" value={month} onChange={e=>{if(e.target.value)setMonth(e.target.value)}}/></label>
        :<label className="field"><span>Ano de referência</span><input type="number" aria-label="Ano de referência" min={1900} max={2100} step={1} value={year} onChange={e=>setYear(e.target.value)}/></label>}
      <label className="field"><span>Situação das consultas</span><select value={status} onChange={e=>setStatus(e.target.value)}>{Object.entries(statuses).map(([value,label])=><option key={value} value={value}>{label}</option>)}</select></label>
      <label className="field"><span>Especialidade</span><select value={specialty} onChange={e=>setSpecialty(e.target.value)}><option value="">Todas as especialidades</option>{ctx.options.specialties?.map((s:Row)=><option key={s.id} value={s.id}>{s.name}</option>)}</select></label>
    </section>
    <p className="appointments-criteria">Contagem pela data agendada da consulta. {status?`Situação: ${statuses[status].toLowerCase()}.`:'Inclui agendadas, confirmadas, finalizadas e canceladas.'} Meses e especialidades sem consultas aparecem com zero.</p>
    {!validPeriod?<div className="notice">Informe um ano entre 1900 e 2100.</div>:error?<><ErrorBox message={error}/><button className="button secondary" onClick={reload}>Tentar novamente</button></>:!ready?<Loading/>:report&&<>
      <div className="appointments-summary">
        <div><span className="mini-icon blue"><CalendarDays size={22}/></span><div><span>Consultas {mode==='year'?'no ano':'no mês'}</span><strong>{number(report.total)}</strong></div></div>
        <div><span className="mini-icon teal"><HeartPulse size={22}/></span><div><span>Especialidades com consultas</span><strong>{number(report.active_specialties)}</strong></div></div>
        <div className="appointments-period"><span>Período selecionado</span><strong>{period}</strong><small>{specialty?ctx.options.specialties?.find((s:Row)=>String(s.id)===specialty)?.name:'Todas as especialidades'}</small></div>
      </div>
      {mode==='year'&&<section className="panel appointments-evolution">
        <div className="panel-head"><div><h2>Evolução mês a mês</h2><p>Total de consultas em cada mês de {report.year}, conforme os filtros selecionados.</p></div></div>
        <div className="appointments-bars" role="img" aria-label={'Consultas por mês. '+report.monthly_totals.map((v,i)=>`${months[i]}: ${v}`).join('; ')}>
          {report.monthly_totals.map((count,index)=><div className="appointments-bar-column" key={index}><span>{number(count)}</span><div className="appointments-bar-space"><i style={{height:`${count/Math.max(1,...report.monthly_totals)*100}%`}}/></div><strong>{months[index]}</strong></div>)}
        </div>
      </section>}
      <section className="panel">
        <div className="panel-head"><div><h2>{mode==='year'?'Distribuição anual por especialidade':'Consultas do mês por especialidade'}</h2><p className="appointments-table-period">{period} · {statuses[status]} · {specialty?ctx.options.specialties?.find((s:Row)=>String(s.id)===specialty)?.name:'Todas as especialidades'}</p></div></div>
        {report.total===0&&<div className="appointments-no-data" role="status">Nenhuma consulta encontrada para os filtros selecionados.</div>}
        {!report.rows.length?<Empty title="Nenhuma especialidade cadastrada"/>:<div className="table-scroll appointments-table-scroll" tabIndex={0} role="region" aria-label="Tabela de consultas por especialidade">
          <table className="appointments-totals-table"><caption className="sr-only">{mode==='year'?'Consultas por especialidade, mês a mês':'Quantidade de consultas por especialidade'} — {period}</caption>
            <thead><tr><th scope="col">Especialidade</th>{mode==='year'&&report.months.map(m=><th className="numeric" scope="col" key={m}>{months[m-1]}</th>)}<th className="numeric total-column" scope="col">{mode==='year'?'Total anual':'Consultas'}</th>{mode==='month'&&<th className="numeric" scope="col">Participação</th>}</tr></thead>
            <tbody>{report.rows.map(row=><tr key={row.specialty_id}><th scope="row">{row.name}</th>{mode==='year'&&row.counts.map((value,i)=><td className={'numeric '+(value===0?'zero-value':'')} key={i}>{number(value)}</td>)}<td className="numeric total-column"><strong>{number(row.total)}</strong></td>{mode==='month'&&<td className="numeric">{(report.total?row.total/report.total:0).toLocaleString('pt-BR',{style:'percent',maximumFractionDigits:1})}</td>}</tr>)}</tbody>
            <tfoot><tr><th scope="row">Total geral</th>{mode==='year'&&report.monthly_totals.map((value,i)=><td className="numeric" key={i}>{number(value)}</td>)}<td className="numeric total-column">{number(report.total)}</td>{mode==='month'&&<td className="numeric">{report.total?'100%':'0%'}</td>}</tr></tfoot>
          </table>
        </div>}
        <p className="print-note">Totais de todas as consultas do período, sem limite de página. Data de referência: data agendada.</p>
      </section>
    </>}
  </div>;
}
