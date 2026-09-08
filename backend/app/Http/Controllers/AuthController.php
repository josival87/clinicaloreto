<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
class AuthController extends Controller {
    public function session(Request $r) {
        $user = $r->user(); if ($user && !$user->active) { Auth::logout(); $user = null; }
        $menus = $user ? DB::table('menus')->orderBy('position')->get()->filter(fn($m) => in_array($user->level,json_decode($m->roles,true)))->values() : [];
        return response()->json(['user'=>$user,'csrf'=>csrf_token(),'menus'=>$menus])->header('Cache-Control','no-store');
    }
    public function login(Request $r) {
        $r->merge(['cpf'=>preg_replace('/\D/','',(string)$r->input('cpf'))]); $data=$r->validate(['cpf'=>'required|string|size:11','password'=>'required|string|max:200']);
        if (!Auth::attempt([...$data,'active'=>true])) return response()->json(['message'=>'CPF ou senha incorretos.'],422);
        $r->session()->regenerate(); \App\Support\Audit::record('login','users',Auth::id()); return $this->session($r);
    }
    public function logout(Request $r) { Auth::logout(); $r->session()->invalidate(); $r->session()->regenerateToken(); return ['csrf'=>csrf_token()]; }
}
