<?php
namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\{Cache,Http,RateLimiter};

class GeocodingService
{
    public function locate(array $address): array
    {
        validator($address, ['state'=>'nullable|string', 'zip'=>'nullable|string'])->validate();
        $address = array_map(fn($value) => is_string($value) ? trim($value) : $value, $address);
        $address['state'] = strtoupper($address['state'] ?? '');
        $address['zip'] = preg_replace('/\D/', '', $address['zip'] ?? '');
        $data = validator($address, [
            'street'=>'required|string|max:200', 'number'=>'nullable|string|max:20',
            'neighborhood'=>'nullable|string|max:150', 'city'=>'required|string|max:150',
            'state'=>'required|in:AC,AL,AP,AM,BA,CE,DF,ES,GO,MA,MT,MS,MG,PA,PB,PR,PE,PI,RJ,RN,RS,RO,RR,SC,SP,SE,TO',
            'zip'=>'nullable|digits:8',
        ], ['street.required'=>'Preencha a rua para gerar a localização.', 'city.required'=>'Preencha a cidade para gerar a localização.', 'state.required'=>'Preencha o estado (UF) para gerar a localização.'])->validate();

        $query = implode(', ', array_filter([
            trim(($data['number'] ?? '').' '.$data['street']), $data['neighborhood'] ?? null,
            $data['city'], $data['state'], $data['zip'] ?? null, 'Brasil',
        ], fn($value) => $value !== null && $value !== ''));
        $url = config('clinic.geocode_url');
        $cacheKey = 'geocode:result:'.hash('sha256', $url.'|'.mb_strtolower($query));
        $result = Cache::get($cacheKey);
        if ($result !== null) return $this->found($result);

        $userKey = 'geocode:user:'.auth()->id();
        abort_if(RateLimiter::tooManyAttempts($userKey, 1), 429, 'Aguarde '.RateLimiter::availableIn($userKey).' segundos antes de gerar outra localização.');
        // One shared lock and a cooldown protect the public provider across all users.
        $lock = Cache::lock('geocode:provider', 15);
        abort_unless($lock->get(), 429, 'Outra localização está sendo consultada. Tente novamente em alguns segundos.');
        try {
            abort_if((float) Cache::get('geocode:next-request', 0) > microtime(true), 429, 'Aguarde alguns segundos antes de gerar outra localização.');
            RateLimiter::hit($userKey, 60);
            try {
                $response = Http::withHeaders(['User-Agent'=>config('clinic.geocode_agent')])
                    ->connectTimeout(3)->timeout(10)->get($url, [
                        'q'=>$query, 'format'=>'jsonv2', 'limit'=>1, 'countrycodes'=>'br', 'accept-language'=>'pt-BR',
                    ]);
            } catch (ConnectionException $e) {
                abort(502, 'O serviço de mapas não respondeu. Você pode salvar o cadastro e tentar localizar o endereço depois.');
            } finally {
                Cache::put('geocode:next-request', microtime(true) + 1, 15);
            }
            abort_unless($response->successful(), 502, 'O serviço de mapas está indisponível. Você pode salvar o cadastro e tentar novamente depois.');
            $body = $response->json();
            abort_unless(is_array($body) && array_is_list($body), 502, 'O serviço de mapas retornou uma resposta inválida. Tente novamente depois.');
            $hit = $body[0] ?? null;
            if ($hit === null) {
                Cache::put($cacheKey, [], now()->addMinutes(10));
                return $this->found([]);
            }
            abort_unless(is_array($hit) && validator($hit, [
                'lat'=>'required|numeric|between:-90,90', 'lon'=>'required|numeric|between:-180,180',
            ])->passes(), 502, 'O serviço de mapas retornou coordenadas inválidas. Tente novamente depois.');
            // Do not silently plot the centre of a city or neighbourhood as a street address.
            if (isset($hit['place_rank']) && $hit['place_rank'] < 26) {
                Cache::put($cacheKey, [], now()->addMinutes(10));
                return $this->found([]);
            }
            $result = ['latitude'=>(float) $hit['lat'], 'longitude'=>(float) $hit['lon'], 'display_name'=>$hit['display_name'] ?? $query];
            Cache::put($cacheKey, $result, now()->addDays(30));
            return $result;
        } finally {
            $lock->release();
        }
    }

    private function found(array $result): array
    {
        abort_unless($result, 422, 'Endereço não encontrado com precisão suficiente. Revise rua, número, cidade, UF e CEP. Você pode salvar o cadastro e localizar depois.');
        return $result;
    }
}
