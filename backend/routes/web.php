<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\{AuthController,RegistryController,AgendaController,ReportController,WhatsappController};
use App\Http\Middleware\ClinicAccess;
Route::prefix('api')->group(function() {
    Route::get('/session',[AuthController::class,'session']);
    Route::post('/login',[AuthController::class,'login'])->middleware('throttle:5,1');
    Route::match(['get','post'],'/whatsapp/webhook',[WhatsappController::class,'webhook'])->middleware('throttle:120,1');
    Route::middleware([ClinicAccess::class,'throttle:240,1'])->group(function() {
        Route::post('/logout',[AuthController::class,'logout']);
        Route::get('/options',[RegistryController::class,'options']);
        Route::get('/clients/lookup',[RegistryController::class,'lookup']);
        Route::post('/clients/geocode',[RegistryController::class,'geocodeAddress']);
        Route::match(['get','post'],'/clients/{client}/photo',[RegistryController::class,'photo']);
        Route::post('/clients/{client}/geocode',[RegistryController::class,'geocode']);
        Route::get('/dashboard',[ReportController::class,'dashboard']);
        Route::get('/reports/leaders',[ReportController::class,'leaders']);
        Route::get('/reports/clients',[ReportController::class,'clients']);
        Route::get('/reports/map',[ReportController::class,'map']);
        Route::get('/reports/appointments',[ReportController::class,'appointments']);
        Route::get('/reports/appointments/summary',[ReportController::class,'appointmentSummary']);
        Route::get('/appointments',[AgendaController::class,'index']);
        Route::post('/appointments',[AgendaController::class,'save']);
        Route::put('/appointments/{id}',[AgendaController::class,'save'])->whereNumber('id');
        Route::patch('/appointments/{id}/status',[AgendaController::class,'transition'])->whereNumber('id');
        Route::get('/slots',[AgendaController::class,'slots']);
        Route::post('/slots',[AgendaController::class,'createSlots']);
        Route::put('/slots/{id}',[AgendaController::class,'updateSlot'])->whereNumber('id');
        Route::post('/slots/{id}/cancel',[AgendaController::class,'cancelSlot'])->whereNumber('id');
        Route::delete('/slots/{id}',[AgendaController::class,'deleteSlot'])->whereNumber('id');
        Route::get('/whatsapp',[WhatsappController::class,'settings']);
        Route::put('/whatsapp',[WhatsappController::class,'save']);
        Route::post('/whatsapp/campaigns',[WhatsappController::class,'campaign']);
        Route::delete('/whatsapp/messages/{id}',[WhatsappController::class,'cancel'])->whereNumber('id');
        Route::get('/{entity}',[RegistryController::class,'index'])->whereIn('entity',['clients','doctors','leaders','specialties','users']);
        Route::post('/{entity}',[RegistryController::class,'save'])->whereIn('entity',['clients','doctors','leaders','specialties','users']);
        Route::get('/{entity}/{id}',[RegistryController::class,'show'])->whereIn('entity',['clients','doctors','leaders','specialties','users'])->whereNumber('id');
        Route::put('/{entity}/{id}',[RegistryController::class,'save'])->whereIn('entity',['clients','doctors','leaders','specialties','users'])->whereNumber('id');
        Route::delete('/{entity}/{id}',[RegistryController::class,'destroy'])->whereIn('entity',['clients','doctors','leaders','specialties','users'])->whereNumber('id');
    });
});
Route::get('/{path?}',function() {
    abort_unless(file_exists(public_path('build/index.html')),503,'Compile a interface antes de acessar.');
    return response()->file(public_path('build/index.html'),['Cache-Control'=>'no-store']);
})->where('path','(?!api(?:/|$)).*');
