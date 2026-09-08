<?php
namespace App\Http\Controllers;
use App\Models\{Client,Doctor,Leader,Specialty,User};
use App\Rules\Cpf;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Storage,Http};
use Illuminate\Validation\Rule;

class RegistryController extends Controller {
    private const MODELS = ['clients'=>Client::class,'doctors'=>Doctor::class,'leaders'=>Leader::class,'specialties'=>Specialty::class,'users'=>User::class];
    private function model(string $entity, bool $write = false): string {
        abort_unless(isset(self::MODELS[$entity]),404);
        if ($entity === 'users' || ($write && in_array($entity,['doctors','leaders','specialties']))) abort_unless(auth()->user()->level==='admin',403,'Apenas administradores podem alterar este cadastro.');
        return self::MODELS[$entity];
    }
    public function index(Request $r, string $entity) {
        $class=$this->model($entity); $q=$class::query();
        $r->validate(['search'=>'nullable|string|max:150','page'=>'nullable|integer|min:1','per_page'=>'nullable|integer|min:1|max:100']);
        if ($entity==='doctors') $q->with('specialties');
        if ($entity==='clients') $q->with('leader');
        if ($entity==='leaders') $q->withCount('clients');
        if ($s=$r->input('search')) $q->where(function($q) use($s,$entity) { $q->where('name','ilike','%'.$s.'%'); $digits=preg_replace('/\D/','',$s); if($digits!=='' && in_array($entity,['clients','doctors','users'])) $q->orWhere('cpf','like','%'.$digits.'%'); });
        if ($entity==='clients') foreach(['neighborhood','street'] as $field) if($r->filled($field)) $q->where($field,$r->input($field));
        return $q->orderBy('name')->paginate($r->integer('per_page',20));
    }
    public function show(string $entity, int $id) {
        $class=$this->model($entity); $row=$class::findOrFail($id);
        if($entity==='clients') $row->load(['leader','appointments'=>fn($q)=>$q->with('slot.doctor','slot.specialty')->latest()]);
        if($entity==='doctors') $row->load('specialties');
        Audit::record('view',$entity,$id); return $row;
    }
    public function lookup(Request $r) {
        $cpf=preg_replace('/\D/','',(string)$r->query('cpf'));
        validator(['cpf'=>$cpf],['cpf'=>['required',new Cpf]])->validate();
        return ['client'=>Client::with('leader')->where('cpf',$cpf)->first()];
    }
    public function options() {
        return ['doctors'=>Doctor::with('specialties')->orderBy('name')->get(),'specialties'=>Specialty::orderBy('name')->get(),'leaders'=>Leader::orderBy('name')->get()];
    }
    public function save(Request $r, string $entity, ?int $id=null) {
        $class=$this->model($entity,true); $row=$id ? $class::findOrFail($id) : new $class;
        foreach(['cpf','phone','phone2','zip','sus'] as $f) if($r->has($f) && $r->input($f)!==null) $r->merge([$f=>preg_replace('/\D/','',(string)$r->input($f))]);
        $rules=['name'=>'required|string|max:150'];
        if(in_array($entity,['clients','doctors','users'])) $rules['cpf']=['required',new Cpf,Rule::unique($entity,'cpf')->ignore($id)];
        if(in_array($entity,['clients','doctors','leaders'])) $rules['phone']='required|regex:/^\d{10,13}$/';
        if($entity==='clients') $rules += [
            'mother'=>'nullable|string|max:150','rg'=>'nullable|string|max:30','birth_date'=>'required|date|before_or_equal:today|after:1900-01-01',
            'street'=>'nullable|string|max:200','number'=>'nullable|string|max:20','neighborhood'=>'nullable|string|max:150','city'=>'nullable|string|max:150',
            'state'=>'nullable|in:AC,AL,AP,AM,BA,CE,DF,ES,GO,MA,MT,MS,MG,PA,PB,PR,PE,PI,RJ,RN,RS,RO,RR,SC,SP,SE,TO','zip'=>'nullable|digits:8','sus'=>'nullable|digits:15',
            'phone2'=>'nullable|regex:/^\d{10,13}$/','marital_status'=>'nullable|string|max:30','profession'=>'nullable|string|max:150','leader_id'=>'nullable|exists:leaders,id',
            'whatsapp_opt_in'=>'boolean','latitude'=>'nullable|numeric|between:-90,90|required_with:longitude','longitude'=>'nullable|numeric|between:-180,180|required_with:latitude'
        ];
        if($entity==='leaders') $rules['neighborhood']='required|string|max:150';
        if($entity==='specialties') $rules['name']=['required','string','max:100',Rule::unique('specialties')->ignore($id)];
        if($entity==='doctors') $rules += ['crm'=>['required','string','max:30',Rule::unique('doctors')->ignore($id)],'specialty_ids'=>'required|array|min:1','specialty_ids.*'=>'required|integer|distinct|exists:specialties,id'];
        if($entity==='users') $rules += ['password'=>($id?'nullable':'required').'|string|min:10|max:128','level'=>'required|in:admin,recepcao,gestor','active'=>'required|boolean'];
        $data=$r->validate($rules,['cpf.unique'=>'Este CPF já está cadastrado. Localize o cadastro existente.']);
        return DB::transaction(function() use($row,$data,$entity,$id) {
            if($entity==='users') {
                User::orderBy('id')->lockForUpdate()->get();
                if($id===auth()->id()) abort_if(!$data['active'] || $data['level']!=='admin',422,'Você não pode desativar ou rebaixar sua própria conta.');
                if($row->level==='admin' && $row->active && (!$data['active'] || $data['level']!=='admin')) abort_if(User::where('level','admin')->where('active',true)->count()<=1,422,'Mantenha pelo menos um administrador ativo.');
                if(empty($data['password'])) unset($data['password']);
                $data['email']=$data['cpf'].'@loreto.local';
            }
            if($entity==='clients' && array_key_exists('whatsapp_opt_in',$data)) $data['whatsapp_opt_in_at']=$data['whatsapp_opt_in'] ? ($row->whatsapp_opt_in_at ?: now()) : null;
            if($entity==='clients' && $id) {
                $addressChanged=collect(['street','number','neighborhood','city','state','zip'])->contains(fn($key)=>array_key_exists($key,$data) && $data[$key]!==$row->$key);
                if($addressChanged && (($data['latitude']??null)==$row->latitude) && (($data['longitude']??null)==$row->longitude)) { $data['latitude']=null; $data['longitude']=null; }
            }
            $specialties=$data['specialty_ids']??[]; unset($data['specialty_ids']);
            if($entity==='doctors' && $id) {
                $used=\App\Models\Slot::where('doctor_id',$id)->where('date','>=',today())->where('status','available')->pluck('specialty_id');
                abort_if($used->diff($specialties)->isNotEmpty(),422,'Há vagas futuras nas especialidades removidas. Ajuste as vagas primeiro.');
            }
            $row->fill($data)->save(); if($entity==='doctors') $row->specialties()->sync($specialties);
            Audit::record($id?'update':'create',$entity,$row->id); return $row->fresh();
        });
    }
    public function destroy(string $entity, int $id) {
        $class=$this->model($entity,true);
        abort_unless(auth()->user()->level==='admin',403,'Apenas administradores podem excluir cadastros.');
        abort_if($entity==='users' && auth()->id()===$id,422,'Você não pode excluir sua própria conta.');
        try {
            DB::transaction(function() use($entity,$class,$id) {
                if($entity==='users') User::orderBy('id')->lockForUpdate()->get();
                $row=$class::findOrFail($id);
                if($entity==='users' && $row->level==='admin' && $row->active) abort_if(User::where('level','admin')->where('active',true)->count()<=1,422,'Mantenha um administrador ativo.');
                $photo=$entity==='clients' ? $row->photo_path : null;
                $row->delete(); Audit::record('delete',$entity,$id);
                if($photo) DB::afterCommit(fn()=>Storage::disk('local')->delete($photo));
            });
        } catch(\Illuminate\Database\QueryException $e) {
            if($e->getCode()==='23503') abort(422,'Este registro possui vínculos e não pode ser excluído.'); throw $e;
        }
        return response()->noContent();
    }
    public function photo(Request $r, Client $client) {
        if($r->isMethod('get')) { abort_unless($client->photo_path && Storage::disk('local')->exists($client->photo_path),404); return Storage::disk('local')->response($client->photo_path,null,['Cache-Control'=>'no-store']); }
        $r->validate(['photo'=>'required|image|mimes:jpg,jpeg,png,webp|max:4096|dimensions:max_width=6000,max_height=6000']);
        $old=$client->photo_path; $path=$r->file('photo')->store('client-photos','local');
        $client->update(['photo_path'=>$path]); if($old) Storage::disk('local')->delete($old); Audit::record('photo','clients',$client->id); return $client;
    }
    public function geocode(Client $client) {
        abort_unless($client->street && $client->city && $client->state,422,'Preencha rua, cidade e estado antes de localizar o endereço.');
        $query=implode(', ',array_filter([$client->street.' '.$client->number,$client->neighborhood,$client->city,$client->state,'Brasil']));
        $response=Http::withHeaders(['User-Agent'=>config('clinic.geocode_agent')])->timeout(10)->get('https://nominatim.openstreetmap.org/search',['q'=>$query,'format'=>'jsonv2','limit'=>1,'countrycodes'=>'br']);
        abort_unless($response->successful(),502,'O serviço de mapas está indisponível. Tente novamente.');
        $hit=$response->json()[0]??null; abort_unless($hit,422,'Endereço não encontrado. Revise os dados ou informe as coordenadas.');
        $client->update(['latitude'=>$hit['lat'],'longitude'=>$hit['lon']]); Audit::record('geocode','clients',$client->id); return $client;
    }
}
