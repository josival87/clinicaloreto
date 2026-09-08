<?php
namespace App\Models;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
class User extends Authenticatable {
    use HasFactory;
    protected $fillable = ['name','email','cpf','password','level','active'];
    protected $hidden = ['password','remember_token','email'];
    protected function casts(): array { return ['password'=>'hashed','active'=>'boolean']; }
}
