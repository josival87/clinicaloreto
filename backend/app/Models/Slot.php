<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Slot extends Model {
    protected $fillable = ['doctor_id','specialty_id','date','capacity','status'];
    protected function casts(): array { return ['date'=>'date:Y-m-d','capacity'=>'integer']; }
    public function doctor() { return $this->belongsTo(Doctor::class); }
    public function specialty() { return $this->belongsTo(Specialty::class); }
    public function appointments() { return $this->hasMany(Appointment::class); }
}
