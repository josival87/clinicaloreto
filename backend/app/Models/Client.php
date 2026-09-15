<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Client extends Model {
    protected $guarded = ['id','created_at','updated_at']; protected $hidden = ['photo_path']; protected $appends = ['photo_url'];
    protected function casts(): array { return ['birth_date'=>'date:Y-m-d','whatsapp_opt_in'=>'boolean','latitude'=>'float','longitude'=>'float']; }
    public function leader() { return $this->belongsTo(Leader::class); }
    public function appointments() { return $this->hasMany(Appointment::class); }
    public function getPhotoUrlAttribute() { return $this->photo_path ? rtrim(parse_url(config('app.url'), PHP_URL_PATH) ?? '', '/').'/api/clients/'.$this->id.'/photo' : null; }
}
