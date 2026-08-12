<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class UserController extends Controller
{
    /**
     * Mengambil data user yang sedang login (Digunakan oleh frontend)
     */
    public function getUser(Request $request)
    {
        return response()->json($request->user());
    }

}