<?php

namespace App\Http\Controllers;

use App\Helper;
use App\Models\TenantAgent;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class ChatbotController extends Controller
{
    public function sessionInfo(Request $request)
    {
        $tenant = Helper::tenant();
        if (!$tenant) {
            Log::warning('Chatbot sessionInfo: Tenant not resolved');
            return response()->json([
                'error' => 'Tenant not found'
            ], 404);
        }

        $agent = TenantAgent::where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->first();

        if (!$agent) {
            Log::warning('Chatbot sessionInfo: No active agent found for tenant', [
                'tenant_id' => $tenant->id,
                'tenant_company' => $tenant->company,
            ]);
        }

        $agentId = $agent ? $agent->id : null;

        $ipAddress = $request->ip();

        $sessionId = md5($ipAddress . '_' . time() . '_' . Str::uuid() . '_' . Str::random(8));

        Log::info('Chatbot sessionInfo generated', [
            'tenant_id' => $tenant->id,
            'tenant_company' => $tenant->company,
            'agent_id' => $agentId,
            'session_id' => $sessionId,
            'ip_address' => $ipAddress
        ]);

        return response()->json([
            'agent_id' => $agentId,
            'session_id' => $sessionId,
            'ip_address' => $ipAddress,
        ]);
    }
    public function sessionInfoIframe(Request $request)
{
    $companyName = $request->query('company'); 
    $tenant = \App\Models\Tenant::where('company', $companyName)->first();

    if (!$tenant) {
        return response()->json(['error' => 'Tenant not found'], 404);
    }

    $agent = $tenant->agents()->where('status','active')->first();

    $sessionId = md5($request->ip() . '_' . time() . '_' . Str::uuid() . '_' . Str::random(8));

    return response()->json([
        'agent_id' => $agent ? $agent->id : null,
        'session_id' => $sessionId,
        'ip_address' => $request->ip(),
    ]);
}

    
}
