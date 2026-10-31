<?php

namespace App\Http\Controllers;

use App\Services\Integrity\HashChainService;
use Illuminate\Http\JsonResponse;

class IntegrityApiController extends Controller
{
    public function verifyLedger(HashChainService $service): JsonResponse
    {
        $result = $service->verifyLedgerIntegrity();

        return response()->json($result, $result['status'] === 'valid' ? 200 : 409);
    }
}
