<?php
namespace App\Http\Controllers;
use App\Models\{Appointment,Slot,Doctor};
use App\Services\BookingService;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AgendaController extends Controller {
    private function manage(): void { abort_unless(auth()->user()->level==='admin',403,'Apenas administradores podem gerenciar vagas.'); }
    public function index(Request $r) {
        $data=$r->validate(['date'=>'required|date_format:Y-m-d','specialty_id'=>'nullable|integer|exists:specialties,id']);
        return Appointment::with('client','slot.doctor','slot.specialty')->whereHas('slot',fn($q)=>$q->whereDate('date',$data['date'])->when($r->filled('specialty_id'),fn($q)=>$q->where('specialty_id',$data['specialty_id'])))
            ->orderByRaw('confirmed_at ASC NULLS LAST')->orderBy('id')->get();
    }
    public function save(Request $r,BookingService $service,?int $id=null) {
        $data=$r->validate(['client_id'=>'required|integer|exists:clients,id','slot_id'=>'required|integer|exists:slots,id']); return $service->book($data['client_id'],$data['slot_id'],$id);
    }
    public function transition(Request $r,int $id) {
        $data=$r->validate(['status'=>'required|in:confirmed,completed,cancelled']);
        return DB::transaction(function() use($id,$data) {
            $a=Appointment::with('slot')->lockForUpdate()->findOrFail($id);
            $allowed=['scheduled'=>['confirmed','cancelled'],'confirmed'=>['completed','cancelled'],'completed'=>[],'cancelled'=>[]];
            abort_unless(in_array($data['status'],$allowed[$a->status]),422,'Esta consulta já mudou de situação. Atualize a tela.');
            if($data['status']!=='cancelled') abort_unless($a->slot->date->isToday(),422,'Presença e atendimento só podem ser registrados no dia da consulta.');
            $a->status=$data['status']; if($a->status==='confirmed') $a->confirmed_at=now(); if($a->status==='completed') $a->completed_at=now();
            $a->save(); Audit::record($a->status,'appointments',$id); return $a;
        },3);
    }
    public function slots(Request $r) {
        $r->validate(['month'=>'required|date_format:Y-m','specialty_id'=>'nullable|integer|exists:specialties,id','doctor_id'=>'nullable|integer|exists:doctors,id']);
        $date=Carbon::createFromFormat('Y-m-d',$r->input('month').'-01');
        return Slot::with('doctor','specialty')->withCount(['appointments as booked'=>fn($q)=>$q->where('status','!=','cancelled')])
            ->whereBetween('date',[$date->toDateString(),$date->copy()->endOfMonth()->toDateString()])
            ->when($r->filled('doctor_id'),fn($q)=>$q->where('doctor_id',$r->integer('doctor_id')))->when($r->filled('specialty_id'),fn($q)=>$q->where('specialty_id',$r->integer('specialty_id')))->orderBy('date')->get();
    }
    public function createSlots(Request $r) {
        $this->manage();
        $data=$r->validate(['doctor_id'=>'required|integer|exists:doctors,id','specialty_id'=>'required|integer|exists:specialties,id','capacity'=>'required|integer|min:1|max:1000',
            'dates'=>'required|array|min:1|max:31','dates.*'=>'required|date_format:Y-m-d|after_or_equal:today|distinct','repeat_months'=>'nullable|array|max:24','repeat_months.*'=>'date_format:Y-m|distinct']);
        abort_unless(Doctor::findOrFail($data['doctor_id'])->specialties()->where('specialties.id',$data['specialty_id'])->exists(),422,'Selecione uma especialidade vinculada ao médico.');
        $dates=$data['dates']; $skipped=[];
        foreach($data['repeat_months']??[] as $month) foreach($data['dates'] as $original) {
            $day=(int)substr($original,8,2); $base=Carbon::parse($month.'-01');
            if($day>$base->daysInMonth) { $skipped[]=$month.'-'.str_pad($day,2,'0',STR_PAD_LEFT); continue; }
            $date=$base->day($day); if($date->lt(today())) continue; $dates[]=$date->toDateString();
        }
        return DB::transaction(function() use($data,$dates,$skipped) {
            $created=0; $existing=0;
            foreach(array_unique($dates) as $date) {
                $slot=Slot::firstOrCreate(['doctor_id'=>$data['doctor_id'],'specialty_id'=>$data['specialty_id'],'date'=>$date],['capacity'=>$data['capacity']]);
                if($slot->wasRecentlyCreated) { $created++; Audit::record('create','slots',$slot->id); } else $existing++;
            }
            return ['created'=>$created,'existing'=>$existing,'skipped'=>$skipped];
        });
    }
    public function updateSlot(Request $r,int $id) {
        $this->manage(); $data=$r->validate(['capacity'=>'required|integer|min:1|max:1000','status'=>'required|in:available,cancelled']);
        abort_if($data['status']==='cancelled',422,'Use o cancelamento com realocação para tornar uma data indisponível.');
        return DB::transaction(function() use($id,$data) {
            $slot=Slot::lockForUpdate()->findOrFail($id); abort_if($slot->date->lt(today()),422,'Não é possível editar vagas passadas.');
            abort_if($slot->appointments()->where('status','!=','cancelled')->count()>$data['capacity'],422,'A quantidade não pode ser menor que os agendamentos existentes.');
            $slot->update($data); Audit::record('update','slots',$id); return $slot;
        });
    }
    public function cancelSlot(int $id,BookingService $service) { $this->manage(); return $service->cancelSlot($id); }
    public function deleteSlot(int $id) {
        $this->manage(); return DB::transaction(function() use($id) {
            $slot=Slot::lockForUpdate()->findOrFail($id); abort_if($slot->appointments()->exists(),422,'Esta data possui histórico. Use cancelar com realocação.');
            $slot->delete(); Audit::record('delete','slots',$id); return response()->noContent();
        });
    }
}
