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
    public function consultations(Request $r) {
        $data=$r->validate(['month'=>'required|date_format:Y-m','specialty_id'=>'nullable|integer|exists:specialties,id','status'=>'nullable|in:scheduled,confirmed,completed,cancelled,no_show','page'=>'nullable|integer|min:1']);
        $from=Carbon::createFromFormat('Y-m-d',$data['month'].'-01');
        return Appointment::with('client','slot.doctor','slot.specialty')
            ->whereHas('slot',fn($q)=>$q->whereBetween('date',[$from->toDateString(),$from->copy()->endOfMonth()->toDateString()])
                ->when($r->filled('specialty_id'),fn($q)=>$q->where('specialty_id',$r->integer('specialty_id'))))
            ->when($r->filled('status'),fn($q)=>$q->where('appointments.status',$data['status']))
            ->join('slots','slots.id','=','appointments.slot_id')->select('appointments.*')
            ->orderByDesc('slots.date')->orderBy('slots.shift')->orderByDesc('appointments.id')->paginate(30);
    }
    public function save(Request $r,BookingService $service,?int $id=null) {
        $data=$r->validate(['client_id'=>'required|integer|exists:clients,id','slot_id'=>'required|integer|exists:slots,id']); return $service->book($data['client_id'],$data['slot_id'],$id);
    }
    public function transition(Request $r,int $id) {
        $data=$r->validate(['status'=>'required|in:confirmed,completed,cancelled,no_show']);
        return DB::transaction(function() use($id,$data) {
            $a=Appointment::with('slot')->lockForUpdate()->findOrFail($id);
            $allowed=['scheduled'=>['confirmed','cancelled','no_show'],'confirmed'=>['completed','cancelled','no_show'],'completed'=>[],'cancelled'=>[],'no_show'=>[]];
            abort_unless(in_array($data['status'],$allowed[$a->status]),422,'Esta consulta já mudou de situação. Atualize a tela.');
            if($data['status']!=='cancelled') abort_unless($a->slot->date->isToday(),422,'Presença, atendimento e falta só podem ser registrados no dia da consulta.');
            $a->status=$data['status']; if($a->status==='confirmed') $a->confirmed_at=now(); if($a->status==='completed') $a->completed_at=now();
            $a->save(); Audit::record($a->status,'appointments',$id); return $a;
        },3);
    }
    public function historical(Request $r) {
        $data=$r->validate([
            'client_id'=>'required|integer|exists:clients,id','doctor_id'=>'required|integer|exists:doctors,id','specialty_id'=>'required|integer|exists:specialties,id',
            'date'=>'required|date_format:Y-m-d|before_or_equal:today','shift'=>'required|in:morning,afternoon',
            'status'=>'required|in:confirmed,completed,cancelled,no_show','description'=>'nullable|string|max:200',
        ]);
        abort_unless(Doctor::findOrFail($data['doctor_id'])->specialties()->where('specialties.id',$data['specialty_id'])->exists(),422,'Selecione uma especialidade vinculada ao médico.');
        return DB::transaction(function() use($data) {
            DB::select('SELECT pg_advisory_xact_lock(170901, ?)',[(int)$data['specialty_id']]);
            $slot=Slot::where(collect($data)->only(['doctor_id','specialty_id','date','shift'])->all())->lockForUpdate()->first();
            if(!$slot) $slot=Slot::create(collect($data)->only(['doctor_id','specialty_id','date','shift'])->all()+['description'=>$data['description']?:'Consulta cadastrada retroativamente','capacity'=>1]);
            $booked=$slot->appointments()->where('status','!=','cancelled')->count();
            if($data['status']!=='cancelled' && $booked >= $slot->capacity) $slot->update(['capacity'=>$booked+1]);
            abort_if($data['status']!=='cancelled' && $slot->appointments()->where('client_id',$data['client_id'])->where('status','!=','cancelled')->exists(),422,'Esta consulta já foi cadastrada para o paciente.');
            $when=Carbon::parse($data['date'])->setHour(12);
            $appointment=Appointment::create(['client_id'=>$data['client_id'],'slot_id'=>$slot->id,'status'=>$data['status'],'created_by'=>auth()->id(),
                'confirmed_at'=>in_array($data['status'],['confirmed','completed'])?$when:null,'completed_at'=>$data['status']==='completed'?$when:null]);
            Audit::record('historical_create','appointments',$appointment->id);
            return $appointment->load('client','slot.doctor','slot.specialty');
        },3);
    }
    public function slots(Request $r) {
        $r->validate(['month'=>'required|date_format:Y-m','specialty_id'=>'nullable|integer|exists:specialties,id','doctor_id'=>'nullable|integer|exists:doctors,id']);
        $date=Carbon::createFromFormat('Y-m-d',$r->input('month').'-01');
        return Slot::with('doctor','specialty')->withCount(['appointments as booked'=>fn($q)=>$q->where('status','!=','cancelled')])
            ->whereBetween('date',[$date->toDateString(),$date->copy()->endOfMonth()->toDateString()])
            ->when($r->filled('doctor_id'),fn($q)=>$q->where('doctor_id',$r->integer('doctor_id')))->when($r->filled('specialty_id'),fn($q)=>$q->where('specialty_id',$r->integer('specialty_id')))->orderBy('date')->orderBy('shift')->get();
    }
    public function createSlots(Request $r) {
        $this->manage();
        $data=$r->validate(['doctor_id'=>'required|integer|exists:doctors,id','specialty_id'=>'required|integer|exists:specialties,id','capacity'=>'required|integer|min:1|max:1000',
            'shift'=>'required|in:morning,afternoon','description'=>'nullable|string|max:200',
            'dates'=>'nullable|array|max:31','dates.*'=>'required|date_format:Y-m-d|after_or_equal:today|distinct','repeat_months'=>'nullable|array|max:24','repeat_months.*'=>'date_format:Y-m|distinct',
            'weekdays'=>'nullable|array|max:7','weekdays.*'=>'integer|between:1,7|distinct','recurrence_start_month'=>'nullable|required_with:weekdays|date_format:Y-m','recurrence_end_month'=>'nullable|required_with:weekdays|date_format:Y-m|after_or_equal:recurrence_start_month']);
        abort_if(empty($data['dates']) && empty($data['weekdays']),422,'Selecione ao menos uma data ou dia da semana.');
        abort_unless(Doctor::findOrFail($data['doctor_id'])->specialties()->where('specialties.id',$data['specialty_id'])->exists(),422,'Selecione uma especialidade vinculada ao médico.');
        $dates=$data['dates']??[]; $skipped=[];
        foreach($data['repeat_months']??[] as $month) foreach($data['dates']??[] as $original) {
            $day=(int)substr($original,8,2); $base=Carbon::parse($month.'-01');
            if($day>$base->daysInMonth) { $skipped[]=$month.'-'.str_pad($day,2,'0',STR_PAD_LEFT); continue; }
            $date=$base->day($day); if($date->lt(today())) continue; $dates[]=$date->toDateString();
        }
        if(!empty($data['weekdays'])) {
            $cursor=Carbon::parse($data['recurrence_start_month'].'-01')->max(today());
            $end=Carbon::parse($data['recurrence_end_month'].'-01')->endOfMonth();
            for(;$cursor->lte($end);$cursor->addDay()) if(in_array($cursor->isoWeekday(),$data['weekdays'])) $dates[]=$cursor->toDateString();
        }
        return DB::transaction(function() use($data,$dates,$skipped) {
            $created=0; $existing=0;
            foreach(array_unique($dates) as $date) {
                $slot=Slot::firstOrCreate(['doctor_id'=>$data['doctor_id'],'specialty_id'=>$data['specialty_id'],'date'=>$date,'shift'=>$data['shift']],['capacity'=>$data['capacity'],'description'=>$data['description']??null]);
                if($slot->wasRecentlyCreated) { $created++; Audit::record('create','slots',$slot->id); } else $existing++;
            }
            return ['created'=>$created,'existing'=>$existing,'skipped'=>$skipped];
        });
    }
    public function updateSlot(Request $r,int $id) {
        $this->manage(); $data=$r->validate(['capacity'=>'required|integer|min:1|max:1000','description'=>'nullable|string|max:200','status'=>'required|in:available,cancelled']);
        abort_if($data['status']==='cancelled',422,'Use o cancelamento com realocação para tornar uma data indisponível.');
        return DB::transaction(function() use($id,$data) {
            $slot=Slot::lockForUpdate()->findOrFail($id); abort_if($slot->date->lt(today()),422,'Não é possível editar vagas passadas.');
            abort_if($slot->appointments()->where('status','!=','cancelled')->count()>$data['capacity'],422,'A quantidade não pode ser menor que os agendamentos existentes.');
            $slot->update($data); Audit::record('update','slots',$id); return $slot;
        });
    }
    public function cancelSlot(int $id,BookingService $service) { $this->manage(); return $service->cancelSlot($id); }
    public function bulkReschedule(Request $r, BookingService $service) {
        $this->manage();
        $data=$r->validate(['source_date'=>'required|date_format:Y-m-d|after_or_equal:today','target_date'=>'required|date_format:Y-m-d|after_or_equal:today|different:source_date','specialty_id'=>'nullable|integer|exists:specialties,id']);
        return $service->moveDay($data['source_date'],$data['target_date'],$data['specialty_id']??null);
    }
    public function deleteSlot(int $id) {
        $this->manage(); return DB::transaction(function() use($id) {
            $slot=Slot::lockForUpdate()->findOrFail($id); abort_if($slot->appointments()->exists(),422,'Esta data possui histórico. Use cancelar com realocação.');
            $slot->delete(); Audit::record('delete','slots',$id); return response()->noContent();
        });
    }
}
