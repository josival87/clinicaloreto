<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
class ClinicAccess {
    public function handle(Request $request, Closure $next) {
        abort_unless($request->user() && $request->user()->active, 401, 'Entre na sua conta para continuar.');
        $response = $next($request); $response->headers->set('Cache-Control','no-store, private'); $response->headers->set('X-Content-Type-Options','nosniff'); return $response;
    }
}
