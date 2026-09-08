<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use Database\Seeders\{MenuSeeder,AdminSeeder};
class MenuSeedTest extends TestCase {
    use RefreshDatabase;
    public function test_seed_is_repeatable_and_preserves_password(): void {
        config(['clinic.admin_password'=>'Initial-password-123']);
        $this->seed([MenuSeeder::class,AdminSeeder::class]);
        User::first()->update(['password'=>'Changed-password-456']);
        $this->seed([MenuSeeder::class,AdminSeeder::class]);
        $this->assertSame(12,DB::table('menus')->count());$this->assertDatabaseCount('users',1);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('Changed-password-456',User::first()->password));
        $this->actingAs(User::first())->getJson('/api/session')->assertOk()->assertJsonCount(12,'menus');
    }
}
