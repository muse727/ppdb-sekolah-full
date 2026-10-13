<?php

namespace App\Http\Controllers;

use App\Models\PpdbSeatAllocation;
use Illuminate\Http\Request;

class PpdbSeatAllocationController extends Controller
{
    public function index()
    {
        return response()->json(
            PpdbSeatAllocation::with(['user:id,name,email', 'payment:id,status,verified_at'])
                ->orderByDesc('id')
                ->get(['id', 'user_id', 'payment_id', 'jenjang', 'status', 'allocated_at', 'released_at', 'release_reason'])
        );
    }

    public function release(Request $request, int $id)
    {
        // Retain the existing admin authorization boundary, but disable generic
        // release for every reason, including spoofed NOT_ACCEPTED/WITHDRAWN.
        PpdbSeatAllocation::whereKey($id)->firstOrFail();
        return response()->json([
            'message' => 'Pelepasan kursi hanya melalui perubahan kelulusan menjadi TIDAK_LULUS.',
        ], 409);
    }
}
