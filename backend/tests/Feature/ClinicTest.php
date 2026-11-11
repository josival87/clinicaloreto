<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Http,Hash,Storage};
use Illuminate\Http\UploadedFile;
use App\Models\{Client,Doctor,Specialty,Slot,Appointment,User,Message};
use App\Services\WhatsappService;

class ClinicTest extends TestCase {
    use RefreshDatabase;
    private User $admin;
    private Doctor $doctor;
    private Specialty $specialty;
    private Client $client;
    protected function setUp(): void {
        parent::setUp();
        $this->assertSame('testing',$this->app->environment());
        $this->assertSame('loreto_test',DB::connection()->getDatabaseName(),'Tests must use the isolated test database.');
        Http::preventStrayRequests();
        $this->admin=User::create(['name'=>'Admin Teste','cpf'=>'52998224725','email'=>'test@loreto.local','password'=>'Loreto@2026!Teste','active'=>true,'level'=>'admin']);
        $this->specialty=Specialty::create(['name'=>'Clínica geral']);
        $this->doctor=Doctor::create(['name'=>'Dra. Teste','cpf'=>'11144477735','crm'=>'123/SP','phone'=>'11900000000']);
        $this->doctor->specialties()->attach($this->specialty);
        $this->client=Client::create(['name'=>'Cliente Teste','cpf'=>'12345678909','birth_date'=>'1990-05-01','phone'=>'11900000001','neighborhood'=>'Centro','street'=>'Rua Um']);
        $this->actingAs($this->admin);
    }
    private function slot(string $date,int $capacity=2): Slot { return Slot::create(['doctor_id'=>$this->doctor->id,'specialty_id'=>$this->specialty->id,'date'=>$date,'capacity'=>$capacity]); }
    private function otherClient(): Client { return Client::create(['name'=>'Cliente Dois','cpf'=>'98765432100','birth_date'=>'1980-03-01','phone'=>'11900000002']); }
    public function test_login_logout_and_inactive_account(): void {
        auth()->logout();
        $this->postJson('/api/login',['cpf'=>'529.982.247-25','password'=>'wrong'])->assertUnprocessable();
        $this->postJson('/api/login',['cpf'=>'529.982.247-25','password'=>'Loreto@2026!Teste'])->assertOk()->assertJsonPath('user.level','admin')->assertJsonMissingPath('user.password');
        $this->postJson('/api/logout')->assertOk();
        $this->admin->update(['active'=>false]);
        $this->postJson('/api/login',['cpf'=>'52998224725','password'=>'Loreto@2026!Teste'])->assertUnprocessable();
        $this->getJson('/api/clients')->assertUnauthorized();
    }
    public function test_cpf_required_valid_and_unique(): void {
        $base=['name'=>'Novo Cliente','birth_date'=>'1990-01-01','phone'=>'11900000000'];
        $this->postJson('/api/clients',$base)->assertUnprocessable()->assertJsonValidationErrors('cpf');
        $this->postJson('/api/clients',$base+['cpf'=>'11111111111'])->assertUnprocessable();
        $this->postJson('/api/clients',$base+['cpf'=>$this->client->cpf])->assertUnprocessable();
        $this->getJson('/api/clients/lookup?cpf='.$this->client->cpf)->assertOk()->assertJsonPath('client.id',$this->client->id);
    }
    public function test_crud_and_private_photo(): void {
        Storage::fake('local');
        $this->putJson('/api/clients/'.$this->client->id,['name'=>'Cliente Atualizado','cpf'=>$this->client->cpf,'birth_date'=>'1990-05-01','phone'=>'11900000000','whatsapp_opt_in'=>true])->assertOk();
        $this->assertNotNull($this->client->fresh()->whatsapp_opt_in_at);
        $this->post('/api/clients/'.$this->client->id.'/photo',['photo'=>UploadedFile::fake()->image('foto.jpg')],['Accept'=>'application/json'])->assertOk()->assertJsonMissingPath('photo_path');
        $this->get('/api/clients/'.$this->client->id.'/photo')->assertOk();
        auth()->logout(); $this->getJson('/api/clients/'.$this->client->id.'/photo')->assertUnauthorized();
    }
    public function test_booking_prevents_overbooking_and_duplicate_specialty_day(): void {
        $slot=$this->slot(today()->toDateString(),1);
        $this->postJson('/api/appointments',['client_id'=>$this->client->id,'slot_id'=>$slot->id])->assertCreated();
        $other=$this->otherClient();
        $this->postJson('/api/appointments',['client_id'=>$other->id,'slot_id'=>$slot->id])->assertUnprocessable();
        $slot->update(['capacity'=>2]);
        $this->postJson('/api/appointments',['client_id'=>$this->client->id,'slot_id'=>$slot->id])->assertUnprocessable();
        $this->assertDatabaseCount('appointments',1);
    }
    public function test_queue_fifo_and_state_transitions(): void {
        $slot=$this->slot(today()->toDateString()); $other=$this->otherClient();
        $a=Appointment::create(['client_id'=>$this->client->id,'slot_id'=>$slot->id]);
        $b=Appointment::create(['client_id'=>$other->id,'slot_id'=>$slot->id]);
        $this->patchJson('/api/appointments/'.$a->id.'/status',['status'=>'completed'])->assertUnprocessable();
        $this->patchJson('/api/appointments/'.$b->id.'/status',['status'=>'confirmed'])->assertOk();
        $b->update(['confirmed_at'=>now()->subMinute()]);
        $this->patchJson('/api/appointments/'.$a->id.'/status',['status'=>'confirmed'])->assertOk();
        $this->getJson('/api/appointments?date='.today()->toDateString())->assertOk()->assertJsonPath('0.id',$b->id);
        $this->patchJson('/api/appointments/'.$b->id.'/status',['status'=>'completed'])->assertOk();
        $this->patchJson('/api/appointments/'.$b->id.'/status',['status'=>'confirmed'])->assertUnprocessable();
        $this->assertNotNull($b->fresh()->completed_at);
    }
    public function test_cannot_confirm_outside_appointment_day(): void {
        $slot=$this->slot(today()->addDay()->toDateString());$a=Appointment::create(['client_id'=>$this->client->id,'slot_id'=>$slot->id]);
        $this->patchJson('/api/appointments/'.$a->id.'/status',['status'=>'confirmed'])->assertUnprocessable();
    }
    public function test_cancellation_reallocates_to_next_days_and_preserves_history(): void {
        $origin=$this->slot(today()->toDateString());$next=$this->slot(today()->addDay()->toDateString(),1);$last=$this->slot(today()->addDays(2)->toDateString(),1);
        $a=Appointment::create(['client_id'=>$this->client->id,'slot_id'=>$origin->id]); $b=Appointment::create(['client_id'=>$this->otherClient()->id,'slot_id'=>$origin->id]);
        $this->postJson('/api/slots/'.$origin->id.'/cancel')->assertOk()->assertJsonPath('count',2);
        $this->assertSame($next->id,$a->fresh()->slot_id);$this->assertSame($last->id,$b->fresh()->slot_id);$this->assertSame('cancelled',$origin->fresh()->status);
        $this->assertDatabaseCount('appointments',2);
    }
    public function test_insufficient_reallocation_rolls_back_every_change(): void {
        $origin=$this->slot(today()->toDateString());$next=$this->slot(today()->addDay()->toDateString(),1);
        $a=Appointment::create(['client_id'=>$this->client->id,'slot_id'=>$origin->id]);$b=Appointment::create(['client_id'=>$this->otherClient()->id,'slot_id'=>$origin->id]);
        $this->postJson('/api/slots/'.$origin->id.'/cancel')->assertUnprocessable();
        $this->assertSame($origin->id,$a->fresh()->slot_id);$this->assertSame($origin->id,$b->fresh()->slot_id);$this->assertSame('available',$origin->fresh()->status);
    }
    public function test_capacity_cannot_shrink_below_bookings_and_deletion_respects_links(): void {
        $slot=$this->slot(today()->toDateString());Appointment::create(['client_id'=>$this->client->id,'slot_id'=>$slot->id]);Appointment::create(['client_id'=>$this->otherClient()->id,'slot_id'=>$slot->id]);
        $this->putJson('/api/slots/'.$slot->id,['capacity'=>1,'status'=>'available'])->assertUnprocessable();
        $this->deleteJson('/api/clients/'.$this->client->id)->assertUnprocessable();
        $this->deleteJson('/api/slots/'.$slot->id)->assertUnprocessable();
    }
    public function test_repetition_skips_invalid_dates_and_does_not_overwrite_existing_capacity(): void {
        $year=today()->year+1;
        $data=['doctor_id'=>$this->doctor->id,'specialty_id'=>$this->specialty->id,'capacity'=>7,'shift'=>'morning','description'=>'Consultas gerais','dates'=>[$year.'-01-31'],'repeat_months'=>[$year.'-02',$year.'-03']];
        $this->postJson('/api/slots',$data)->assertOk()->assertJsonPath('created',2)->assertJsonPath('skipped.0',$year.'-02-31');
        $data['capacity']=99;$this->postJson('/api/slots',$data)->assertOk()->assertJsonPath('existing',2);
        $this->assertSame(7,Slot::first()->capacity);
    }
    public function test_slots_can_be_created_and_updated_without_description(): void {
        $date=today()->addDay()->toDateString();
        $this->postJson('/api/slots',['doctor_id'=>$this->doctor->id,'specialty_id'=>$this->specialty->id,'capacity'=>4,'shift'=>'morning','dates'=>[$date]])
            ->assertOk()->assertJsonPath('created',1);
        $slot=Slot::whereDate('date',$date)->firstOrFail();
        $this->assertNull($slot->description);
        $this->putJson('/api/slots/'.$slot->id,['capacity'=>5,'description'=>'','status'=>'available'])->assertOk();
        $this->assertNull($slot->fresh()->description);
    }
    public function test_role_enforcement_and_self_protection(): void {
        $this->deleteJson('/api/users/'.$this->admin->id)->assertUnprocessable();
        $reception=User::create(['name'=>'Recepção','cpf'=>'11144477735','email'=>'reception@loreto.local','password'=>'another-safe-password','level'=>'recepcao','active'=>true]);$this->actingAs($reception);
        $this->getJson('/api/users')->assertForbidden();$this->getJson('/api/reports/clients')->assertForbidden();$this->getJson('/api/whatsapp')->assertForbidden();
        $this->postJson('/api/doctors',[])->assertForbidden();$this->postJson('/api/slots',[])->assertForbidden();$this->getJson('/api/clients')->assertOk();
    }
    public function test_reports_group_neighborhood_street_and_counts_correctly(): void {
        $slot=$this->slot(today()->toDateString());Appointment::create(['client_id'=>$this->client->id,'slot_id'=>$slot->id,'status'=>'completed','completed_at'=>now()]);
        $this->getJson('/api/reports/clients')->assertOk()->assertJsonPath('total',1)->assertJsonPath('rows.0.name','Centro');
        $this->getJson('/api/reports/clients?neighborhood=Centro')->assertOk()->assertJsonPath('rows.0.name','Rua Um');
        $this->getJson('/api/reports/clients?neighborhood=Centro&street=Rua%20Um')->assertOk()->assertJsonPath('rows.0.name','Cliente Teste');
        Http::fake(['cognition:8001/*'=>Http::response(['insights'=>[]])]);
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('stats.month_appointments',1)->assertJsonPath('stats.total_appointments',1);
    }
    public function test_remarking_and_cancelled_appointments_free_capacity(): void {
        $first=$this->slot(today()->toDateString(),1);$next=$this->slot(today()->addDay()->toDateString(),1);
        $a=Appointment::create(['client_id'=>$this->client->id,'slot_id'=>$first->id]);
        $this->putJson('/api/appointments/'.$a->id,['client_id'=>$this->client->id,'slot_id'=>$next->id])->assertOk();
        $this->postJson('/api/appointments',['client_id'=>$this->otherClient()->id,'slot_id'=>$first->id])->assertCreated();
        $this->patchJson('/api/appointments/'.$a->id.'/status',['status'=>'cancelled'])->assertOk();
        $this->postJson('/api/appointments',['client_id'=>$this->client->id,'slot_id'=>$next->id])->assertCreated();
    }
    public function test_no_show_shifted_slots_and_historical_consultation(): void {
        $morning=$this->slot(today()->toDateString());
        $afternoon=Slot::create(['doctor_id'=>$this->doctor->id,'specialty_id'=>$this->specialty->id,'date'=>today(),'shift'=>'afternoon','description'=>'Retornos','capacity'=>2]);
        $appointment=Appointment::create(['client_id'=>$this->client->id,'slot_id'=>$morning->id]);
        $this->patchJson('/api/appointments/'.$appointment->id.'/status',['status'=>'no_show'])->assertOk()->assertJsonPath('status','no_show');
        $this->postJson('/api/consultations',['client_id'=>$this->otherClient()->id,'doctor_id'=>$this->doctor->id,'specialty_id'=>$this->specialty->id,'date'=>today()->subDay()->toDateString(),'shift'=>'afternoon','status'=>'completed','description'=>'Consulta lançada depois'])
            ->assertCreated()->assertJsonPath('slot.shift','afternoon')->assertJsonPath('status','completed');
        $this->getJson('/api/consultations?month='.today()->format('Y-m').'&status=no_show')->assertOk()->assertJsonPath('data.0.id',$appointment->id);
        $this->assertSame('afternoon',$afternoon->shift);
    }
    public function test_bulk_day_move_and_weekday_slot_creation(): void {
        $origin=$this->slot(today()->addDay()->toDateString(),2);
        $a=Appointment::create(['client_id'=>$this->client->id,'slot_id'=>$origin->id]);
        $target=today()->addDays(7)->toDateString();
        $this->postJson('/api/slots/reschedule-day',['source_date'=>$origin->date->toDateString(),'target_date'=>$target])->assertOk()->assertJsonPath('count',1);
        $this->assertSame($target,$a->fresh()->slot->date->toDateString());
        $this->assertSame('cancelled',$origin->fresh()->status);
        $month=today()->addMonth()->format('Y-m');
        $this->postJson('/api/slots',['doctor_id'=>$this->doctor->id,'specialty_id'=>$this->specialty->id,'capacity'=>3,'shift'=>'afternoon','description'=>'Todas as segundas','weekdays'=>[1],'recurrence_start_month'=>$month,'recurrence_end_month'=>$month])
            ->assertOk()->assertJsonPath('existing',0);
        $this->assertTrue(Slot::where('description','Todas as segundas')->get()->every(fn($slot)=>$slot->date->isoWeekday()===1));
    }
    public function test_postal_code_lookup_fills_address(): void {
        Http::fake(['viacep.com.br/*'=>Http::response(['cep'=>'01310-200','logradouro'=>'Avenida Paulista','bairro'=>'Bela Vista','localidade'=>'São Paulo','uf'=>'SP'])]);
        $this->getJson('/api/address/zip/01310200')->assertOk()->assertJson(['zip'=>'01310200','street'=>'Avenida Paulista','neighborhood'=>'Bela Vista','city'=>'São Paulo','state'=>'SP']);
    }
    public function test_whatsapp_consent_dedup_encryption_and_no_blind_retry(): void {
        $service=app(WhatsappService::class);$service->store(['enabled'=>true,'phone_number_id'=>'1234567890','access_token'=>'secret-token','app_secret'=>'secret-app','verify_token'=>'verify-token-value']);
        $this->assertStringNotContainsString('secret-token',DB::table('settings')->value('value'));
        $this->getJson('/api/whatsapp')->assertOk()->assertJsonMissingPath('settings.access_token')->assertJsonMissingPath('settings.app_secret');
        $service->enqueue($this->client,'campaign','hello',[],'dedup');$this->assertDatabaseCount('messages',0);
        $this->client->update(['whatsapp_opt_in'=>true]);$service->enqueue($this->client,'campaign','hello',[],'dedup');$service->enqueue($this->client,'campaign','hello',[],'dedup');$this->assertDatabaseCount('messages',1);
        Http::fake(['graph.facebook.com/*'=>Http::response(['error'=>['code'=>500]],500)]);
        $service->dispatch();$this->assertSame('uncertain',Message::first()->status);$service->dispatch();Http::assertSentCount(1);
    }
    public function test_whatsapp_webhook_signature_and_opt_out(): void {
        $service=app(WhatsappService::class);$service->store(['app_secret'=>'secret','verify_token'=>'verify-token-value']);
        $this->client->update(['whatsapp_opt_in'=>true]);
        $payload=['entry'=>[['changes'=>[['value'=>['messages'=>[['from'=>'55'.$this->client->phone,'text'=>['body'=>'SAIR']]]]]]]]];
        $this->postJson('/api/whatsapp/webhook',$payload)->assertForbidden();
        $body=json_encode($payload);$signature='sha256='.hash_hmac('sha256',$body,'secret');
        $this->call('POST','/api/whatsapp/webhook',[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_X_HUB_SIGNATURE_256'=>$signature],$body)->assertOk();
        $this->assertFalse($this->client->fresh()->whatsapp_opt_in);
    }
}
