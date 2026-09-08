<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
class MenuSeeder extends Seeder {
    public function run(): void {
        $all=['admin','recepcao','gestor'];
        $menus=[['dashboard','Dashboard','LayoutDashboard',null,$all],['agenda','Agendamentos','CalendarDays',null,$all],['clients','Clientes','Users',null,$all],['doctors','Médicos','Stethoscope',null,$all],['leaders','Lideranças','Handshake',null,$all],['slots','Vagas','CalendarPlus',null,$all],['reports-leaders','Lideranças','ChartNoAxesCombined','Relatórios',['admin','gestor']],['reports-clients','Clientes','MapPin','Relatórios',['admin','gestor']],['reports-appointments','Consultas','ClipboardList','Relatórios',['admin','gestor']],['users','Administradores','ShieldCheck','Configurações',['admin']],['specialties','Especialidades','HeartPulse','Configurações',['admin']],['whatsapp','WhatsApp','MessageCircle','Configurações',['admin']]];
        foreach($menus as $i=>[$key,$label,$icon,$group,$roles]) DB::table('menus')->updateOrInsert(['key'=>$key],['label'=>$label,'icon'=>$icon,'group'=>$group,'position'=>$i,'roles'=>json_encode($roles)]);
    }
}
