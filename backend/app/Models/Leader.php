<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Leader extends Model {
    protected $fillable = ['name','phone','neighborhood']; public function clients() { return $this->hasMany(Client::class); }
}
