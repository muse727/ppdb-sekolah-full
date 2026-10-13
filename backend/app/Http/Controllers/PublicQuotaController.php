<?php

namespace App\Http\Controllers;

use App\Services\PpdbCapacityService;

class PublicQuotaController extends Controller
{
    /**
     * GET /api/public/kuota
     *
     * Public, unauthenticated, read-only. Returns backend-authoritative quota
     * derived from the database via PpdbCapacityService — never hardcoded.
     * Exposes no applicant data, only aggregate numbers.
     */
    public function show(PpdbCapacityService $capacity)
    {
        return response()->json($capacity->publicQuota());
    }
}
