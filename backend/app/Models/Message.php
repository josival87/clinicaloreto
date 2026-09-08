<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Message extends Model {
    protected $guarded = ['id']; protected function casts(): array { return ['parameters'=>'array']; }
    public function client() { return $this->belongsTo(Client::class); }
}
