<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void {
        Schema::table('users', function(Blueprint $t) {
            $t->string('cpf',11)->unique()->nullable(); $t->string('level')->default('recepcao'); $t->boolean('active')->default(true);
        });
        Schema::create('leaders', function(Blueprint $t) { $t->id(); $t->string('name'); $t->string('phone',20); $t->string('neighborhood'); $t->timestamps(); });
        Schema::create('specialties', function(Blueprint $t) { $t->id(); $t->string('name')->unique(); $t->timestamps(); });
        Schema::create('doctors', function(Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('cpf',11)->unique(); $t->string('crm',30)->unique(); $t->string('phone',20); $t->timestamps();
        });
        Schema::create('doctor_specialty', function(Blueprint $t) {
            $t->foreignId('doctor_id')->constrained()->cascadeOnDelete(); $t->foreignId('specialty_id')->constrained()->restrictOnDelete(); $t->primary(['doctor_id','specialty_id']);
        });
        Schema::create('clients', function(Blueprint $t) {
            $t->id(); $t->string('name')->index(); $t->string('mother')->nullable(); $t->string('rg',30)->nullable(); $t->string('cpf',11)->unique(); $t->date('birth_date');
            $t->string('street')->nullable(); $t->string('number',20)->nullable(); $t->string('neighborhood')->nullable()->index(); $t->string('city')->nullable(); $t->string('state',2)->nullable();
            $t->string('zip',8)->nullable(); $t->string('sus',15)->nullable(); $t->string('phone',20); $t->string('phone2',20)->nullable(); $t->string('marital_status',30)->nullable(); $t->string('profession')->nullable();
            $t->foreignId('leader_id')->nullable()->constrained('leaders')->restrictOnDelete(); $t->string('photo_path')->nullable(); $t->decimal('latitude',10,7)->nullable(); $t->decimal('longitude',10,7)->nullable();
            $t->boolean('whatsapp_opt_in')->default(false); $t->timestamp('whatsapp_opt_in_at')->nullable(); $t->timestamps();
        });
        Schema::create('slots', function(Blueprint $t) {
            $t->id(); $t->foreignId('doctor_id')->constrained()->restrictOnDelete(); $t->foreignId('specialty_id')->constrained()->restrictOnDelete(); $t->date('date')->index();
            $t->unsignedInteger('capacity'); $t->string('status')->default('available'); $t->timestamps(); $t->unique(['doctor_id','specialty_id','date']);
        });
        Schema::create('appointments', function(Blueprint $t) {
            $t->id(); $t->foreignId('client_id')->constrained()->restrictOnDelete(); $t->foreignId('slot_id')->constrained()->restrictOnDelete(); $t->string('status')->default('scheduled')->index();
            $t->timestamp('confirmed_at')->nullable()->index(); $t->timestamp('completed_at')->nullable()->index(); $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); $t->timestamps();
        });
        DB::statement("CREATE UNIQUE INDEX appointments_active_unique ON appointments (client_id, slot_id) WHERE status <> 'cancelled'");
        DB::statement("ALTER TABLE slots ADD CONSTRAINT slots_capacity_positive CHECK (capacity > 0)");
        DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_status_valid CHECK (status IN ('scheduled','confirmed','completed','cancelled'))");
        Schema::create('menus', function(Blueprint $t) { $t->id(); $t->string('key')->unique(); $t->string('label'); $t->string('icon'); $t->string('group')->nullable(); $t->integer('position'); $t->json('roles'); });
        Schema::create('settings', function(Blueprint $t) { $t->string('key')->primary(); $t->text('value')->nullable(); });
        Schema::create('audit_logs', function(Blueprint $t) {
            $t->id(); $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); $t->string('action'); $t->string('entity'); $t->unsignedBigInteger('entity_id')->nullable(); $t->timestamp('created_at')->useCurrent();
        });
        Schema::create('messages', function(Blueprint $t) {
            $t->id(); $t->foreignId('client_id')->constrained()->restrictOnDelete(); $t->string('kind'); $t->string('template'); $t->json('parameters'); $t->string('dedup_key')->unique();
            $t->string('status')->default('queued')->index(); $t->string('provider_id')->nullable()->index(); $t->string('error')->nullable(); $t->timestamp('sent_at')->nullable(); $t->timestamps();
        });
    }
    public function down(): void {
        foreach(['messages','audit_logs','settings','menus','appointments','slots','clients','doctor_specialty','doctors','specialties','leaders'] as $table) Schema::dropIfExists($table);
        Schema::table('users', fn(Blueprint $t) => $t->dropColumn(['cpf','level','active']));
    }
};
