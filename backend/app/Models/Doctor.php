<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Doctor extends Model {
    protected $fillable = ['name','cpf','crm','phone']; public function specialties() { return $this->belongsToMany(Specialty::class); }
}
