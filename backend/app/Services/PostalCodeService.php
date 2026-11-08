<?php
namespace App\Services;

use Illuminate\Support\Facades\{Cache,Http};

class PostalCodeService {
    public function lookup(string $zip): array {
        $zip=preg_replace('/\D/','',$zip);
        abort_unless(strlen($zip)===8,422,'Informe um CEP com 8 dígitos.');
        $key='postal-code:'.$zip;
        return Cache::remember($key,now()->addDays(30),function() use($zip) {
            try {
                $response=Http::acceptJson()->connectTimeout(3)->timeout(6)->get("https://viacep.com.br/ws/{$zip}/json/");
            } catch(\Throwable $e) {
                abort(502,'Não foi possível consultar o CEP agora. Preencha o endereço manualmente.');
            }
            abort_unless($response->successful(),502,'O serviço de CEP está indisponível. Preencha o endereço manualmente.');
            $data=$response->json();
            abort_if(!is_array($data)||($data['erro']??false),404,'CEP não encontrado. Confira os números ou preencha o endereço manualmente.');
            return ['zip'=>$zip,'street'=>$data['logradouro']??'','neighborhood'=>$data['bairro']??'','city'=>$data['localidade']??'','state'=>$data['uf']??''];
        });
    }
}
