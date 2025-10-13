<?php

namespace App\Http\Controllers;

use App\Helper;
use App\Models\TenantAgent;
use App\Models\TenantAgentIntegration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AgentIntegrationController extends Controller
{
    public function index()
    {
        $tenant_agent = TenantAgent::where('tenant_id', Helper::tenant()->id)->first();
        $data = TenantAgentIntegration::where('tenant_agent_id', $tenant_agent->id)->get();
        return response()->json([
            'data' => $data,
            'tenant_agent' => $tenant_agent
        ]);
    }

    public function update(Request $request, TenantAgent $agent)
    {
        $request->validate([
            'provider' => 'required',
            'key' => 'required',
            'secret' => 'required'
        ]);

        $integration = TenantAgentIntegration::updateOrCreate([
            'tenant_agent_id' => $agent->id,
            'provider' => $request->provider
        ], [
            'key' => $request->key,
            'secret' => $request->secret,
            'meta' => json_encode([])
        ]);

        return response()->json([
            'message' => 'Integration updated successfully',
            'integration' => $integration
        ]);
    }
}
