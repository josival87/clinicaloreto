<?php
namespace App\Http\Controllers;
use App\Models\{Client,Message};
use App\Services\WhatsappService;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class WhatsappController extends Controller {
    private function access(): void { abort_unless(auth()->user()->level==='admin',403,'Apenas administradores podem configurar mensagens.'); }
    public function settings(WhatsappService $service) {
        $this->access(); $s=$service->settings(); $s['has_token']=!empty($s['access_token']); $s['has_app_secret']=!empty($s['app_secret']); $s['has_verify_token']=!empty($s['verify_token']); unset($s['access_token'],$s['app_secret'],$s['verify_token']);
        return ['settings'=>$s,'eligible'=>Client::where('whatsapp_opt_in',true)->count(),'messages'=>Message::with('client:id,name')->latest()->limit(100)->get()];
    }
    public function save(Request $r,WhatsappService $service) {
        $this->access(); $data=$r->validate(['enabled'=>'required|boolean','phone_number_id'=>'nullable|regex:/^\d{5,30}$/','access_token'=>'nullable|string|max:4000','app_secret'=>'nullable|string|max:255','verify_token'=>'nullable|string|min:16|max:255',
            'language'=>'required|in:pt_BR,en_US,es','birthdays_enabled'=>'required|boolean','reminders_enabled'=>'required|boolean','birthday_template'=>'nullable|regex:/^[a-z0-9_]{1,100}$/','reminder_template'=>'nullable|regex:/^[a-z0-9_]{1,100}$/']);
        $merged=array_merge($service->settings(),array_filter($data,fn($v)=>$v!==null && $v!==''));
        if($data['enabled']) abort_unless(!empty($merged['access_token']) && !empty($merged['phone_number_id']) && !empty($merged['app_secret']) && !empty($merged['verify_token']),422,'Informe ID do telefone, token, segredo do aplicativo e token de verificação antes de ativar.');
        if($data['birthdays_enabled']) abort_unless(!empty($merged['birthday_template']),422,'Informe o modelo de aniversário.');
        if($data['reminders_enabled']) abort_unless(!empty($merged['reminder_template']),422,'Informe o modelo de lembrete.');
        $service->store($data); Audit::record('configure','whatsapp'); return $this->settings($service);
    }
    public function campaign(Request $r,WhatsappService $service) {
        $this->access(); $data=$r->validate(['template'=>'required|regex:/^[a-z0-9_]{1,100}$/','campaign_key'=>'required|uuid','confirmed'=>'required|accepted','include_name'=>'required|boolean','neighborhood'=>'nullable|string|max:150']);
        $s=$service->settings(); abort_unless(!empty($s['enabled']) && !empty($s['access_token']),422,'Configure e ative a integração antes de enviar.');
        $q=Client::where('whatsapp_opt_in',true)->when($r->filled('neighborhood'),fn($q)=>$q->where('neighborhood',$data['neighborhood'])); $count=0;
        DB::transaction(function() use($q,$service,$data,&$count) { $q->chunkById(200,function($clients) use($service,$data,&$count) { foreach($clients as $c) { $service->enqueue($c,'campaign',$data['template'],$data['include_name']?[$c->name]:[],'campaign:'.$data['campaign_key'].':'.$c->id); $count++; } }); Audit::record('enqueue_campaign','whatsapp'); });
        return ['queued'=>$count];
    }
    public function cancel(int $id) {
        $this->access(); $changed=Message::whereKey($id)->where('status','queued')->update(['status'=>'cancelled']); abort_unless($changed,422,'Somente mensagens aguardando envio podem ser canceladas.'); Audit::record('cancel','messages',$id); return ['cancelled'=>true];
    }
    public function webhook(Request $r,WhatsappService $service) {
        $s=$service->settings();
        if($r->isMethod('get')) {
            abort_unless(!empty($s['verify_token']) && $r->query('hub_mode')==='subscribe' && hash_equals($s['verify_token'],(string)$r->query('hub_verify_token')),403);
            return response((string)$r->query('hub_challenge'),200)->header('Content-Type','text/plain');
        }
        abort_unless(!empty($s['app_secret']) && hash_equals('sha256='.hash_hmac('sha256',$r->getContent(),$s['app_secret']),(string)$r->header('X-Hub-Signature-256')),403);
        foreach($r->input('entry',[]) as $entry) foreach($entry['changes']??[] as $change) {
            foreach($change['value']['statuses']??[] as $status) {
                $state=$status['status']??''; if(!in_array($state,['sent','delivered','read','failed'])) continue;
                $allowed=match($state) { 'sent'=>['sending','uncertain'], 'delivered'=>['sending','sent','uncertain'], 'read'=>['sending','sent','delivered','uncertain'], 'failed'=>['sending','sent','uncertain'] };
                Message::where('provider_id',$status['id']??'')->whereIn('status',$allowed)->update(['status'=>$state]);
            }
            foreach($change['value']['messages']??[] as $message) {
                if(in_array(mb_strtolower(trim($message['text']['body']??'')),['sair','parar','cancelar','stop'])) {
                    $phone=preg_replace('/\D/','',$message['from']??''); if(!$phone) continue;
                    Client::whereIn('phone',[$phone,str_starts_with($phone,'55')?substr($phone,2):$phone])->update(['whatsapp_opt_in'=>false,'whatsapp_opt_in_at'=>null]);
                }
            }
        }
        return ['received'=>true];
    }
}
