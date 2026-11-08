<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('slots', function (Blueprint $table) {
            $table->string('shift', 10)->default('morning')->after('date');
            $table->string('description', 200)->nullable()->after('shift');
            $table->dropUnique(['doctor_id', 'specialty_id', 'date']);
            $table->unique(['doctor_id', 'specialty_id', 'date', 'shift']);
        });

        DB::statement('ALTER TABLE appointments DROP CONSTRAINT appointments_status_valid');
        DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_status_valid CHECK (status IN ('scheduled','confirmed','completed','cancelled','no_show'))");

        DB::table('menus')->where('key', 'clients')->update(['label' => 'Pacientes']);
        DB::table('menus')->where('position', '>=', 2)->increment('position');
        DB::table('menus')->updateOrInsert(['key' => 'consultations'], [
            'label' => 'Consultas', 'icon' => 'ClipboardList', 'group' => null, 'position' => 2,
            'roles' => json_encode(['admin', 'recepcao', 'gestor']),
        ]);
        DB::table('menus')->where('key', 'reports-appointments')->update(['label' => 'Consultas por especialidade']);
    }

    public function down(): void
    {
        DB::table('menus')->where('key', 'consultations')->delete();
        DB::table('menus')->where('key', 'clients')->update(['label' => 'Clientes']);
        DB::statement('ALTER TABLE appointments DROP CONSTRAINT appointments_status_valid');
        DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_status_valid CHECK (status IN ('scheduled','confirmed','completed','cancelled'))");
        Schema::table('slots', function (Blueprint $table) {
            $table->dropUnique(['doctor_id', 'specialty_id', 'date', 'shift']);
            $table->dropColumn(['shift', 'description']);
            $table->unique(['doctor_id', 'specialty_id', 'date']);
        });
    }
};
