<?php

namespace App\Http\Controllers;

use App\Helper;
use App\Models\TenantAgent;
use App\Models\TenantAgentIntegration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Twilio\Rest\Client;
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
public function updateGoogle(Request $request)
{
    $request->validate([
        'provider' => 'required',
        'key' => 'required',
    ]);

    $tenant_agent = TenantAgent::where('tenant_id', Helper::tenant()->id)->first();

    if (!$tenant_agent) {
        return response()->json(['error' => 'Tenant agent not found'], 404);
    }

    $integration = TenantAgentIntegration::updateOrCreate(
        [
            'tenant_agent_id' => $tenant_agent->id,
            'provider' => $request->provider
        ],
        [
            'key' => $request->key,
            'secret' => $request->secret ?? '',
            'meta' => json_encode([])
        ]
    );

    return response()->json([
        'message' => 'Google integration updated successfully',
        'integration' => $integration
    ]);
    
}
public function updateTwilio(Request $request)
{
    $tenant_agent = TenantAgent::where('tenant_id', Helper::tenant()->id)->first();

    $request->validate([
        'key' => 'required|string',    
        'secret' => 'required|string', 
    ]);
 try {
        $client = new Client($request->key, $request->secret);
        $client->api->v2010->accounts->read(); 
    } catch (\Exception $e) {
        return response()->json([
            'message' => 'Invalid Twilio credentials. Please check SID or Auth Token.',
            'error' => $e->getMessage()
        ], 400);
    }

    $integration = TenantAgentIntegration::updateOrCreate(
        [
            'tenant_agent_id' => $tenant_agent->id,
            'provider' => 'twilio',
        ],
        [
            'key' => $request->key,
            'secret' => $request->secret,
            'meta' => json_encode([]),
        ]
    );

    return response()->json([
        'message' => 'Twilio connected successfully!',
        'integration' => $integration,
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
public function getGoogleCredentials(Request $request)
{
    $tenant_agent = TenantAgent::where('tenant_id', Helper::tenant()->id)->first();

    if (!$tenant_agent) {
        return response()->json(['error' => 'Tenant agent not found'], 404);
    }

    $integration = TenantAgentIntegration::where('tenant_agent_id', $tenant_agent->id)
        ->where('provider', 'google')
        ->first();

    if (!$integration) {
        return response()->json(['error' => 'Google integration not found'], 404);
    }

    return response()->json([
        'key' => $integration->key,        // OAuth code / access token
        'secret' => $integration->secret,  // Optional
        'tenant_agent_id' => $tenant_agent->id
    ]);
}


public function getTwilioCredentials(Request $request)
{
    $tenant_agent = TenantAgent::where('tenant_id', Helper::tenant()->id)->first();

    if (!$tenant_agent) {
        return response()->json(['error' => 'Tenant agent not found'], 404);
    }

    $integration = TenantAgentIntegration::where('tenant_agent_id', $tenant_agent->id)
        ->where('provider', 'twilio')
        ->first();

    if (!$integration) {
        return response()->json(['error' => 'Twilio integration not found'], 404);
    }

    return response()->json([
        'key' => $integration->key,        // SID
        'secret' => $integration->secret,  // Auth Token
        'tenant_agent_id' => $tenant_agent->id
    ]);
}

}
