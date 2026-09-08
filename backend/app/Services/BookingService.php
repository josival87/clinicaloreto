<?php
namespace App\Services;
use App\Models\{Appointment,Client,Slot};
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

class BookingService {
    public function book(int $clientId,int $slotId,?int $appointmentId=null): Appointment {
        return DB::transaction(function() use($clientId,$slotId,$appointmentId) {
            $specialty=Slot::findOrFail($slotId)->specialty_id;
            // Serialize same-specialty reallocation and booking, including different doctors.
            DB::select('SELECT pg_advisory_xact_lock(170901, ?)',[(int)$specialty]);
            // A client lock serializes concurrent bookings across different slots.
            Client::whereKey($clientId)->lockForUpdate()->firstOrFail();
            $slot=Slot::whereKey($slotId)->lockForUpdate()->firstOrFail();
            $appointment=$appointmentId?Appointment::whereKey($appointmentId)->lockForUpdate()->firstOrFail():null;
            abort_if($appointment && $appointment->status!=='scheduled',422,'Somente consultas agendadas podem ser remarcadas.');
            abort_unless($slot->status==='available' && $slot->date->startOfDay()->gte(today()),422,'Esta data não está disponível.');
            $used=$slot->appointments()->where('status','!=','cancelled')->when($appointmentId,fn($q)=>$q->where('id','!=',$appointmentId))->count();
            abort_if($used >= $slot->capacity,422,'As vagas desta data estão esgotadas. Escolha outro dia.');
            $duplicate=Appointment::where('client_id',$clientId)->where('status','!=','cancelled')->when($appointmentId,fn($q)=>$q->where('id','!=',$appointmentId))
                ->whereHas('slot',fn($q)=>$q->whereDate('date',$slot->date)->where('specialty_id',$slot->specialty_id))->exists();
            abort_if($duplicate,422,'O cliente já possui consulta nesta especialidade e data.');
            if($appointment) $appointment->update(['client_id'=>$clientId,'slot_id'=>$slotId]);
            else $appointment=Appointment::create(['client_id'=>$clientId,'slot_id'=>$slotId,'created_by'=>auth()->id()]);
            Audit::record($appointmentId?'reschedule':'book','appointments',$appointment->id); return $appointment->load('client','slot.doctor','slot.specialty');
        },3);
    }
    public function cancelSlot(int $id): array {
        return DB::transaction(function() use($id) {
            $origin=Slot::findOrFail($id);
            DB::select('SELECT pg_advisory_xact_lock(170901, ?)',[(int)$origin->specialty_id]);
            // Lock all involved slots in chronological order before changing appointments.
            $slots=Slot::where('doctor_id',$origin->doctor_id)->where('specialty_id',$origin->specialty_id)->where('date','>=',$origin->date)->orderBy('date')->orderBy('id')->lockForUpdate()->get();
            $origin=$slots->firstWhere('id',$id);
            abort_unless($origin->status==='available' && $origin->date->gte(today()),422,'Esta agenda não pode ser cancelada.');
            abort_if($origin->appointments()->whereIn('status',['confirmed','completed'])->exists(),422,'Esta agenda já possui presenças confirmadas ou atendimentos. Resolva os atendimentos antes de cancelar.');
            $pending=$origin->appointments()->where('status','scheduled')->orderBy('id')->lockForUpdate()->get();
            $future=$slots->filter(fn($s)=>$s->id!==$id && $s->status==='available'); $moves=[];
            foreach($pending as $appointment) {
                $target=$future->first(function($slot) use($appointment) {
                    if($slot->appointments()->where('status','!=','cancelled')->count()>=$slot->capacity) return false;
                    return !Appointment::where('client_id',$appointment->client_id)->where('status','!=','cancelled')->whereHas('slot',fn($q)=>$q->whereDate('date',$slot->date)->where('specialty_id',$slot->specialty_id))->exists();
                });
                abort_unless($target,422,'Não há vagas futuras suficientes para realocar todos. Ofereça novas datas antes de cancelar. Nenhuma consulta foi alterada.');
                $appointment->update(['slot_id'=>$target->id]); Audit::record('auto_reschedule','appointments',$appointment->id);
                $moves[]=['appointment_id'=>$appointment->id,'client_id'=>$appointment->client_id,'date'=>$target->date->toDateString()];
            }
            $origin->update(['status'=>'cancelled']); Audit::record('cancel','slots',$id); return ['moved'=>$moves,'count'=>count($moves)];
        },3);
    }
}
