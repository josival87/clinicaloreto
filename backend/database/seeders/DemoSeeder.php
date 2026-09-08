<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use App\Models\{Client,Doctor,Leader,Specialty,Slot,Appointment};
class DemoSeeder extends Seeder {
    private function cpf(int $n): string {
        $cpf=str_pad((string)$n,9,'0',STR_PAD_LEFT);
        for($t=9;$t<11;$t++) { $sum=0; for($i=0;$i<$t;$i++) $sum+=(int)$cpf[$i]*(($t+1)-$i); $cpf.=(($sum*10)%11)%10; } return $cpf;
    }
    public function run(): void {
        // Explicit demo seed only. Names, identifiers and coordinates are synthetic test fixtures.
        if(Client::exists()) { $this->command?->info('Base já possui clientes; demonstração não foi reaplicada.'); return; }
        $specialties=collect(['Clínica geral','Cardiologia','Pediatria','Ginecologia','Oftalmologia','Ortopedia'])->map(fn($name)=>Specialty::firstOrCreate(['name'=>$name]));
        $leaders=collect([['Ana Oliveira','Centro'],['José Rodrigues','São José'],['Maria dos Santos','Vila Nova'],['Paulo Almeida','Boa Vista']])->map(fn($v)=>Leader::create(['name'=>$v[0].' (teste)','neighborhood'=>$v[1],'phone'=>'11900000000']));
        $doctors=collect(['Dra. Helena Costa','Dr. Rafael Almeida','Dra. Camila Souza','Dra. Beatriz Lima','Dr. Lucas Ribeiro','Dr. André Martins'])->map(function($name,$i) use($specialties) {
            $d=Doctor::create(['name'=>$name.' (teste)','cpf'=>$this->cpf(800000100+$i),'crm'=>'TESTE-'.(1000+$i).'/SP','phone'=>'11900000000']);
            $d->specialties()->sync($i===1?[$specialties[$i]->id,$specialties[0]->id]:[$specialties[$i]->id]); return $d;
        });
        $names=['Maria Helena Santos','João Pedro Oliveira','Ana Clara Ferreira','José Carlos Silva','Francisca Almeida','Antônio Souza','Juliana Rodrigues','Pedro Henrique Lima','Raimunda Costa','Carlos Eduardo Alves','Luciana Martins','Marcos Vinícius Rocha','Beatriz Ribeiro','Paulo César Melo','Camila Nascimento','Sandra Regina Lopes','Luiz Fernando Dias','Patrícia Gomes','Gabriel Santos','Fernanda Araújo','Rosa Maria Barros','Ricardo Mendes','Vitória Carvalho','Sebastião Pereira'];
        $clients=collect($names)->map(function($name,$i) use($leaders) {
            return Client::create(['name'=>$name.' (teste)','cpf'=>$this->cpf(900000100+$i),'mother'=>'Responsável de demonstração','rg'=>'TESTE-'.($i+1),'birth_date'=>today()->subYears(20+$i)->subDays($i*7)->toDateString(),'street'=>['Rua das Flores','Rua São João','Avenida Brasil','Rua da Paz'][$i%4],'number'=>(string)(100+$i*12),'neighborhood'=>$leaders[$i%4]->neighborhood,'city'=>'São Paulo','state'=>'SP','zip'=>'01001000','phone'=>'11900000000','marital_status'=>$i%2?'Casado(a)':'Solteiro(a)','profession'=>'Cadastro de teste','leader_id'=>$leaders[$i%4]->id,'latitude'=>-23.5505+($i%5)*0.003,'longitude'=>-46.6333+intdiv($i,5)*0.003,'whatsapp_opt_in'=>false,'created_at'=>today()->subDays($i*2)]);
        });
        for($day=-35;$day<=65;$day++) {
            $date=today()->addDays($day); if($date->isSunday()) continue;
            foreach($doctors as $i=>$doctor) {
                if($day!==0 && ($date->dayOfWeek+$i)%3===0) continue;
                $slot=Slot::create(['doctor_id'=>$doctor->id,'specialty_id'=>$specialties[$i]->id,'date'=>$date->toDateString(),'capacity'=>8+($i%3)*2]);
                $qty=$day<0?3:($day===0?3:($day%4===0?($slot->capacity):2));
                for($j=0;$j<$qty;$j++) {
                    $client=$clients[($i*3+$j+abs($day))%$clients->count()];
                    $status=$day<0?'completed':($day===0 && $i<2 && $j===0?'confirmed':($day===0 && $i===2 && $j===0?'completed':'scheduled'));
                    Appointment::create(['client_id'=>$client->id,'slot_id'=>$slot->id,'status'=>$status,'created_by'=>1,'confirmed_at'=>in_array($status,['confirmed','completed'])?$date->copy()->setTime(8,$i*5+$j):null,'completed_at'=>$status==='completed'?$date->copy()->setTime(9,$i*5+$j):null,'created_at'=>$date->copy()->subDays(3)]);
                }
            }
        }
    }
}
