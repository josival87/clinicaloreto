<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Appointment extends Model {
    protected $fillable = ['client_id','slot_id','status','confirmed_at','completed_at','created_by'];
    public function client() { return $this->belongsTo(Client::class); } public function slot() { return $this->belongsTo(Slot::class); }
}
