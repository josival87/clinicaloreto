<?php
use Illuminate\Support\Facades\{Artisan,Schedule};
use App\Services\WhatsappService;
Artisan::command('whatsapp:prepare',function(WhatsappService $service) { $service->automatic(); $this->info('Mensagens automáticas preparadas.'); });
Artisan::command('whatsapp:dispatch',function(WhatsappService $service) { $this->info('Mensagens enviadas: '.$service->dispatch()); });
Schedule::command('whatsapp:prepare')->dailyAt('07:00')->timezone('America/Sao_Paulo')->withoutOverlapping();
Schedule::command('whatsapp:dispatch')->everyMinute()->withoutOverlapping();
