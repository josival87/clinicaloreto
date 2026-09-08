<?php
namespace App\Support;
use Illuminate\Support\Facades\DB;
class Audit {
    public static function record(string $action, string $entity, ?int $id = null): void {
        DB::table('audit_logs')->insert(['user_id'=>auth()->id(),'action'=>$action,'entity'=>$entity,'entity_id'=>$id,'created_at'=>now()]);
    }
}
