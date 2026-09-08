<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use App\Models\User;
class AdminSeeder extends Seeder {
    public function run(): void {
        if(User::where('cpf',config('clinic.admin_cpf'))->exists()) return;
        $password=config('clinic.admin_password');
        if(!$password || strlen($password)<10) throw new \RuntimeException('Defina ADMIN_PASSWORD com pelo menos 10 caracteres antes de executar a seed.');
        User::create(['name'=>'Administrador Loreto','cpf'=>config('clinic.admin_cpf'),'email'=>'admin@loreto.local','password'=>$password,'level'=>'admin','active'=>true]);
    }
}
