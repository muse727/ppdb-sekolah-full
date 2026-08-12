<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Biodata;
use Illuminate\Support\Facades\Auth;

class BiodataController extends Controller
{
    
    public function show()
    {
        $biodata = Biodata::where('user_id', Auth::id())->first();
        return response()->json($biodata);
    }

    
    public function store(Request $request)
    {
        $user = Auth::user();
        
      
        $biodata = Biodata::updateOrCreate(
            ['user_id' => $user->id],
            $request->all() // Ambil semua isian dari Next.js
        );

        
        if ($user->step < 3) {
       
        }

        return response()->json(['success' => true, 'message' => 'Biodata berhasil disimpan.']);
    }
}