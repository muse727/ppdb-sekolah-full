<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    public function handle(Request $request, Closure $next, $role): Response
    {
        // Cek 1: Dia udah login belom?
        if (!Auth::check()) {
            return response()->json([
                'success' => false,
                'message' => 'Silakan login terlebih dahulu.'
            ], 401);
        }

        $userRole = Auth::user()->role;

        // === JALUR VIP: SUPER ADMIN BEBAS MASUK KEMANA AJA ===
        if ($userRole === 'super_admin') {
            return $next($request);
        }

        // Cek 2: Role-nya sesuai sama yang diminta di routes ga?
        if ($userRole !== $role) {
            return response()->json([
                'success' => false,
                'message' => 'Akses Ditolak! Anda tidak memiliki izin ke zona ini.'
            ], 403);
        }

        // Kalau aman, silakan lewat!
        return $next($request);
    }
}