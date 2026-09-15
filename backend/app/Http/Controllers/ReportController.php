<?php
namespace App\Http\Controllers;
use App\Models\{Client,Appointment,Slot,Leader,Specialty};
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Http};
use Carbon\Carbon;
class ReportController extends Controller {
    private function range(Request $r): array {
        $r->validate(['from'=>'nullable|date_format:Y-m-d','to'=>'nullable|date_format:Y-m-d|after_or_equal:from']);
        return [$r->input('from',today()->startOfMonth()->toDateString()),$r->input('to',today()->endOfMonth()->toDateString())];
    }
    private function access(): void { abort_unless(in_array(auth()->user()->level,['admin','gestor']),403,'Relatórios disponíveis para administradores e gestores.'); }
    public function dashboard(Request $r) {
        $r->validate(['month'=>'nullable|date_format:Y-m']); $month=Carbon::parse($r->input('month',today()->format('Y-m')).'-01');
        $from=$month->toDateString(); $to=$month->copy()->endOfMonth()->toDateString();
        $completed=Appointment::where('status','completed')->whereBetween('completed_at',[$from.' 00:00:00',$to.' 23:59:59']);
        $series=DB::table('appointments')->selectRaw('DATE(completed_at) as date, COUNT(*) as count')->where('status','completed')->whereBetween('completed_at',[$from.' 00:00:00',$to.' 23:59:59'])->groupByRaw('DATE(completed_at)')->orderBy('date')->get();
        $slots=Slot::with('specialty')->withCount(['appointments as booked'=>fn($q)=>$q->where('status','!=','cancelled')])->whereBetween('date',[$from,$to])->where('status','available')->get();
        $specialties=$slots->groupBy('specialty_id')->map(fn($rows)=>['name'=>$rows->first()->specialty->name,'capacity'=>$rows->sum('capacity'),'booked'=>$rows->sum('booked')])->values();
        $today=Appointment::with('client','slot.doctor','slot.specialty')->whereHas('slot',fn($q)=>$q->whereDate('date',today()))->get();
        $waiting=$today->where('status','confirmed')->count(); $insights=[]; $insightAvailable=false;
        try { $response=Http::connectTimeout(1)->timeout(2)->post(config('clinic.cognition_url').'/insights',['specialties'=>$specialties->all(),'waiting'=>$waiting]); if($response->successful()) { $insights=$response->json('insights',[]); $insightAvailable=true; } } catch(\Throwable $e) { /* Core dashboard stays available when the insight service is down. */ }
        return ['month'=>$month->format('Y-m'),'stats'=>['month_appointments'=>(clone $completed)->count(),'new_clients'=>Client::whereBetween('created_at',[$from.' 00:00:00',$to.' 23:59:59'])->count(),'total_clients'=>Client::count(),'total_appointments'=>Appointment::where('status','completed')->count()],
            'today'=>['scheduled'=>$today->where('status','scheduled')->count(),'waiting'=>$waiting,'completed'=>$today->where('status','completed')->count()],
            'appointments'=>$today->whereIn('status',['scheduled','confirmed'])->take(5)->values(),'series'=>$series,'specialties'=>$specialties,'insights'=>$insights,'insights_available'=>$insightAvailable];
    }
    public function leaders(Request $r) {
        $this->access(); [$from,$to]=$this->range($r);
        return Leader::withCount('clients')->get()->map(function($leader) use($from,$to) {
            $leader->appointments_count=Appointment::where('status','completed')->whereBetween('completed_at',[$from.' 00:00:00',$to.' 23:59:59'])->whereHas('client',fn($q)=>$q->where('leader_id',$leader->id))->count(); return $leader;
        });
    }
    public function clients(Request $r) {
        $this->access(); $r->validate(['neighborhood'=>'nullable|string|max:150','street'=>'nullable|string|max:200']); Audit::record('report','clients');
        $q=Client::query(); if($r->has('neighborhood')) $q->whereRaw("COALESCE(neighborhood, '') = ?",[$r->input('neighborhood')??'']);
        if($r->has('street')) {
            $q->whereRaw("COALESCE(street, '') = ?",[$r->input('street')??'']);
            return ['kind'=>'addresses','total'=>$q->count(),'rows'=>$q->select('id','name','street','number','neighborhood','city','state','zip','phone')->orderBy('street')->orderBy('number')->orderBy('name')->get()];
        }
        $field=$r->has('neighborhood')?'street':'neighborhood';
        return ['kind'=>$field==='street'?'streets':'neighborhoods','total'=>$q->count(),'rows'=>$q->selectRaw("COALESCE($field, '') as name, COUNT(*) as count")->groupByRaw("COALESCE($field, '')")->orderByDesc('count')->get()];
    }
    public function map(Request $r) {
        $this->access(); $r->validate(['neighborhood'=>'nullable|string|max:150']);
        $q=Client::query()->when($r->filled('neighborhood'),fn($q)=>$q->where('neighborhood',$r->input('neighborhood')));
        return ['unmapped'=>(clone $q)->whereNull('latitude')->count(),'points'=>$q->whereNotNull('latitude')->whereNotNull('longitude')->select('id','name','phone','latitude','longitude','street','number','neighborhood')->get()];
    }
    public function appointmentSummary(Request $r) {
        $this->access();
        $r->validate([
            'mode'=>'nullable|in:month,year',
            'month'=>'nullable|date_format:Y-m',
            'year'=>'nullable|integer|between:1900,2100',
            'status'=>'nullable|in:scheduled,confirmed,completed,cancelled',
            'specialty_id'=>'nullable|integer|exists:specialties,id',
        ]);
        $mode=$r->input('mode','month');
        $start=$mode==='year'
            ? Carbon::create($r->integer('year',today()->year),1,1)->startOfDay()
            : Carbon::createFromFormat('!Y-m',$r->input('month',today()->format('Y-m')));
        $end=$mode==='year' ? $start->copy()->endOfYear() : $start->copy()->endOfMonth();
        $months=$mode==='year' ? range(1,12) : [$start->month];

        // Aggregate every matching appointment in PostgreSQL, independent of list pagination.
        // The reporting date is the scheduled date, consistent with the individual report.
        $counts=DB::table('appointments as a')->join('slots as s','s.id','=','a.slot_id')
            ->whereBetween('s.date',[$start->toDateString(),$end->toDateString()])
            ->when($r->filled('status'),fn($q)=>$q->where('a.status',$r->input('status')))
            ->when($r->filled('specialty_id'),fn($q)=>$q->where('s.specialty_id',$r->integer('specialty_id')))
            ->selectRaw('s.specialty_id, EXTRACT(MONTH FROM s.date)::integer as month, COUNT(*)::integer as count')
            ->groupByRaw('s.specialty_id, EXTRACT(MONTH FROM s.date)')->get();
        $bySpecialty=$counts->groupBy('specialty_id');
        $rows=Specialty::query()->when($r->filled('specialty_id'),fn($q)=>$q->whereKey($r->integer('specialty_id')))
            ->orderBy('name')->get(['id','name'])->map(function($specialty) use($bySpecialty,$months) {
                $values=$bySpecialty->get($specialty->id,collect())->pluck('count','month');
                $monthly=array_map(fn($month)=>(int)$values->get($month,0),$months);
                return ['specialty_id'=>$specialty->id,'name'=>$specialty->name,'counts'=>$monthly,'total'=>array_sum($monthly)];
            });
        $monthlyTotals=array_map(fn($index)=>(int)$rows->sum(fn($row)=>$row['counts'][$index]),array_keys($months));
        return [
            'mode'=>$mode,'year'=>$start->year,'month'=>$start->format('Y-m'),
            'from'=>$start->toDateString(),'to'=>$end->toDateString(),
            'date_basis'=>'scheduled_date','status'=>$r->input('status') ?: 'all',
            'months'=>$months,'rows'=>$rows,'monthly_totals'=>$monthlyTotals,
            'total'=>array_sum($monthlyTotals),'active_specialties'=>$rows->where('total','>',0)->count(),
        ];
    }
    public function appointments(Request $r) {
        $this->access(); [$from,$to]=$this->range($r); $r->validate(['status'=>'nullable|in:scheduled,confirmed,completed,cancelled','specialty_id'=>'nullable|integer|exists:specialties,id','page'=>'nullable|integer|min:1']);
        $q=Appointment::with('client','slot.doctor','slot.specialty')->whereHas('slot',fn($q)=>$q->whereBetween('date',[$from,$to])->when($r->filled('specialty_id'),fn($q)=>$q->where('specialty_id',$r->integer('specialty_id'))));
        if($r->filled('status')) $q->where('status',$r->input('status'));
        return $q->latest()->paginate(50);
    }
}
