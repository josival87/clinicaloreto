<?php
namespace App\Services;
use App\Models\{Client,Appointment,Message};
use Illuminate\Support\Facades\{DB,Crypt,Http};
class WhatsappService {
    public function settings(): array {
        $raw=DB::table('settings')->where('key','whatsapp')->value('value'); return $raw?json_decode(Crypt::decryptString($raw),true):[];
    }
    public function store(array $data): void {
        $current=$this->settings(); foreach(['access_token','app_secret','verify_token'] as $secret) if(empty($data[$secret])) unset($data[$secret]);
        DB::table('settings')->updateOrInsert(['key'=>'whatsapp'],['value'=>Crypt::encryptString(json_encode(array_merge($current,$data)))]);
    }
    public function enqueue(Client $client,string $kind,string $template,array $params,string $key): void {
        if(!$client->whatsapp_opt_in || !$client->phone) return;
        Message::firstOrCreate(['dedup_key'=>$key],['client_id'=>$client->id,'kind'=>$kind,'template'=>$template,'parameters'=>$params]);
    }
    public function automatic(): void {
        $s=$this->settings(); if(empty($s['enabled'])) return;
        if(!empty($s['birthdays_enabled']) && !empty($s['birthday_template'])) {
            Client::where('whatsapp_opt_in',true)->whereMonth('birth_date',today()->month)->whereDay('birth_date',today()->day)->each(fn($c)=>$this->enqueue($c,'birthday',$s['birthday_template'],[$c->name],'birthday:'.$c->id.':'.today()->toDateString()));
        }
        if(!empty($s['reminders_enabled']) && !empty($s['reminder_template'])) {
            Appointment::with('client','slot.specialty')->where('status','scheduled')->whereHas('slot',fn($q)=>$q->whereDate('date',today()))->each(fn($a)=>$this->enqueue($a->client,'reminder',$s['reminder_template'],[$a->client->name,$a->slot->date->format('d/m/Y'),$a->slot->specialty->name],'reminder:'.$a->id.':'.today()->toDateString()));
        }
    }
    public function dispatch(int $limit=30): int {
        $s=$this->settings(); if(empty($s['enabled']) || empty($s['phone_number_id']) || empty($s['access_token'])) return 0;
        // A crashed or timed-out send must not be retried blindly: Meta may have accepted it.
        Message::where('status','sending')->where('updated_at','<',now()->subMinutes(5))->update(['status'=>'uncertain','error'=>'Envio interrompido. Verifique na Meta antes de reenviar.']);
        $sent=0;
        for($i=0;$i<$limit;$i++) {
            $message=DB::transaction(function() { $m=Message::where('status','queued')->orderBy('id')->lock('FOR UPDATE SKIP LOCKED')->first(); if($m) $m->update(['status'=>'sending']); return $m; });
            if(!$message) break; $client=$message->client;
            if(!$client->whatsapp_opt_in) { $message->update(['status'=>'cancelled','error'=>'Autorização de WhatsApp revogada.']); continue; }
            if($message->kind==='reminder') {
                $id=explode(':',$message->dedup_key)[1]??0;
                $appointment=Appointment::with('slot')->find($id);
                if(!$appointment || $appointment->status!=='scheduled' || !$appointment->slot->date->isToday()) { $message->update(['status'=>'cancelled','error'=>'Consulta alterada ou fora da data.']); continue; }
            }
            $phone=preg_replace('/\D/','',$client->phone); if(strlen($phone)<=11) $phone='55'.$phone;
            $template=['name'=>$message->template,'language'=>['code'=>$s['language']??'pt_BR']];
            if($message->parameters) $template['components']=[['type'=>'body','parameters'=>array_map(fn($v)=>['type'=>'text','text'=>(string)$v],$message->parameters)]];
            try {
                $response=Http::withToken($s['access_token'])->connectTimeout(5)->timeout(20)->post('https://graph.facebook.com/'.config('clinic.graph_version').'/'.$s['phone_number_id'].'/messages',['messaging_product'=>'whatsapp','to'=>$phone,'type'=>'template','template'=>$template]);
                if($response->successful() && $response->json('messages.0.id')) { $message->update(['status'=>'sent','provider_id'=>$response->json('messages.0.id'),'sent_at'=>now(),'error'=>null]); $sent++; }
                else $message->update(['status'=>$response->serverError()?'uncertain':'failed','error'=>'Meta HTTP '.$response->status().' · código '.($response->json('error.code')??'desconhecido')]);
            } catch(\Throwable $e) { $message->update(['status'=>'uncertain','error'=>'Sem confirmação do provedor. Verifique na Meta antes de reenviar.']); }
        }
        return $sent;
    }
}
