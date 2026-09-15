<?php
namespace Tests\Feature;

use App\Models\{Client,User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache,DB,Http};
use Tests\TestCase;

class GeocodingTest extends TestCase
{
    use RefreshDatabase;

    private array $address = ['street'=>'Avenida Paulista', 'number'=>'1578', 'neighborhood'=>'Bela Vista', 'city'=>'São Paulo', 'state'=>'SP', 'zip'=>'01310-200'];
    private array $hit = ['lat'=>'-23.561414', 'lon'=>'-46.655881', 'display_name'=>'Avenida Paulista, 1578, São Paulo, Brasil', 'place_rank'=>30];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', $this->app->environment());
        $this->assertSame('loreto_test', DB::connection()->getDatabaseName());
        Http::preventStrayRequests();
        $this->actingAs(User::create(['name'=>'Recepção Teste', 'cpf'=>'52998224725', 'email'=>'geo@test.local', 'password'=>'test-password', 'level'=>'recepcao', 'active'=>true]));
    }

    private function fakeResult(): void { Http::fake(['nominatim.openstreetmap.org/*'=>Http::response([$this->hit])]); }
    private function clientData(): array { return ['name'=>'Cliente Teste', 'cpf'=>'12345678909', 'birth_date'=>'1990-01-01', 'phone'=>'11900000001']; }

    public function test_preview_uses_only_address_including_postcode_without_creating_client(): void
    {
        $this->fakeResult();
        $this->postJson('/api/clients/geocode', $this->address + $this->clientData())
            ->assertOk()->assertJsonPath('latitude', -23.561414)->assertJsonPath('longitude', -46.655881);
        $this->assertDatabaseCount('clients', 0);
        Http::assertSent(fn($request) => $request['q']==='1578 Avenida Paulista, Bela Vista, São Paulo, SP, 01310200, Brasil'
            && $request['countrycodes']==='br' && $request->hasHeader('User-Agent', config('clinic.geocode_agent'))
            && !str_contains($request->url(), '12345678909') && !str_contains($request->url(), 'Cliente'));
    }

    public function test_generated_location_is_saved_and_available_in_map_report(): void
    {
        $this->fakeResult();
        $location=$this->postJson('/api/clients/geocode', $this->address)->assertOk()->json();
        $saved=$this->postJson('/api/clients', $this->clientData()+$this->address+$location)->assertOk();
        $this->assertDatabaseHas('clients', ['id'=>$saved['id'], 'latitude'=>-23.561414, 'longitude'=>-46.655881]);
        auth()->user()->update(['level'=>'gestor']);
        $this->getJson('/api/reports/map')->assertOk()->assertJsonPath('unmapped',0)->assertJsonPath('points.0.id',$saved['id']);
    }

    public function test_edit_without_coordinates_clears_previous_location(): void
    {
        $client=Client::create($this->clientData()+['street'=>'Rua Antiga','latitude'=>-23.5,'longitude'=>-46.6]);
        $this->putJson('/api/clients/'.$client->id,$this->clientData()+$this->address)->assertOk()->assertJsonPath('latitude',null)->assertJsonPath('longitude',null);
    }

    public function test_edit_can_keep_same_coordinates_after_regenerating_for_changed_address(): void
    {
        $client=Client::create($this->clientData()+['street'=>'Rua Antiga','latitude'=>-23.5,'longitude'=>-46.6]);
        $this->putJson('/api/clients/'.$client->id,$this->clientData()+$this->address+['latitude'=>-23.5,'longitude'=>-46.6])
            ->assertOk()->assertJsonPath('latitude',-23.5)->assertJsonPath('longitude',-46.6);
    }

    public function test_saved_client_geocoding_shares_preview_cache(): void
    {
        $this->fakeResult();
        $this->postJson('/api/clients/geocode', $this->address)->assertOk();
        $client=Client::create($this->clientData()+array_replace($this->address,['zip'=>'01310200']));
        $this->postJson('/api/clients/'.$client->id.'/geocode')->assertOk()->assertJsonPath('latitude',-23.561414);
        Http::assertSentCount(1);
    }

    public function test_invalid_address_does_not_call_provider_or_prevent_corrected_retry(): void
    {
        $this->postJson('/api/clients/geocode',['street'=>' '])->assertUnprocessable()->assertJsonValidationErrors(['street','city','state']);
        Http::assertNothingSent();
        $this->fakeResult();
        $this->postJson('/api/clients/geocode',$this->address)->assertOk();
    }

    public function test_no_match_is_cached_and_client_can_still_be_saved(): void
    {
        Http::fake(['*'=>Http::response([])]);
        $this->postJson('/api/clients/geocode',$this->address)->assertUnprocessable();
        $this->postJson('/api/clients/geocode',$this->address)->assertUnprocessable();
        Http::assertSentCount(1);
        $this->postJson('/api/clients',$this->clientData()+$this->address)->assertOk()->assertJsonPath('latitude',null);
    }

    public function test_provider_errors_and_bad_coordinates_are_friendly_errors(): void
    {
        foreach ([Http::response([],503), Http::failedConnection(), Http::response(['error'=>'invalid']), Http::response([['lat'=>'bad','lon'=>'-46']]), Http::response([['lat'=>'-91','lon'=>'-46']])] as $response) {
            Cache::flush(); Http::fake(['*'=>$response]);
            $this->postJson('/api/clients/geocode',$this->address)->assertStatus(502);
        }
    }

    public function test_city_level_match_is_not_used_as_client_location(): void
    {
        Http::fake(['*'=>Http::response([array_replace($this->hit,['place_rank'=>16])])]);
        $this->postJson('/api/clients/geocode',$this->address)->assertUnprocessable();
    }

    public function test_user_limit_is_shared_by_preview_and_saved_client(): void
    {
        $this->fakeResult();
        $this->postJson('/api/clients/geocode',$this->address)->assertOk();
        $client=Client::create($this->clientData()+array_replace($this->address,['number'=>'200','zip'=>'01310200']));
        $this->postJson('/api/clients/'.$client->id.'/geocode')->assertStatus(429);
        Http::assertSentCount(1);
    }

    public function test_global_lock_and_cooldown_prevent_simultaneous_external_requests(): void
    {
        $lock=Cache::lock('geocode:provider',15); $lock->get();
        $this->postJson('/api/clients/geocode',$this->address)->assertStatus(429);
        $lock->release();
        Cache::put('geocode:next-request',microtime(true)+1,15);
        $this->postJson('/api/clients/geocode',$this->address)->assertStatus(429);
        Http::assertNothingSent();
    }

    public function test_anonymous_user_cannot_geocode(): void
    {
        auth()->logout();
        $this->postJson('/api/clients/geocode',$this->address)->assertUnauthorized();
        Http::assertNothingSent();
    }
}
