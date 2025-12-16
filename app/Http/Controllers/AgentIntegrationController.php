<?php

namespace App\Http\Controllers;

use App\Helper;
use App\Models\TenantAgent;
use App\Models\TenantAgentIntegration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Twilio\Rest\Client;
use Google\Client as GoogleClient;
use Illuminate\Support\Facades\Http;
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

    $code = $request->key; // authorization code
    $client = new GoogleClient();
    $client->setClientId(env('GOOGLE_CLIENT_ID'));
    $client->setClientSecret(env('GOOGLE_CLIENT_SECRET'));
    $client->setRedirectUri(env('GOOGLE_REDIRECT_URI'));
    $client->setAccessType('offline'); 
    $client->setScopes(['https://www.googleapis.com/auth/calendar']);

    try {
        $token = $client->fetchAccessTokenWithAuthCode($code);

        if (isset($token['error'])) {
            return response()->json(['error' => $token['error_description']], 400);
        }

        $integration = TenantAgentIntegration::updateOrCreate(
            [
                'tenant_agent_id' => $tenant_agent->id,
                'provider' => 'google'
            ],
            [
                'key' => $token['access_token'],
                'secret' => $token['refresh_token'] ?? '',
                'meta' => json_encode([
                    'expires_in' => $token['expires_in'] ?? null,
                    'created' => time()
                ])
            ]
        );

        return response()->json([
            'message' => 'Google integration updated successfully',
            'integration' => $integration
        ]);
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
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
public function updateOpenAI(Request $request)
{
    $tenant_agent = \App\Models\TenantAgent::where('tenant_id', \App\Helper::tenant()->id)->first();

    $request->validate([
        'key' => 'required|string', // OpenAI API key
    ]);

    // Store the key under provider "openai"
    $integration = \App\Models\TenantAgentIntegration::updateOrCreate(
        [
            'tenant_agent_id' => $tenant_agent->id,
            'provider' => 'openai',
        ],
        [
            'key' => $request->key,
            'secret' => '', 
            'meta' => json_encode(['note' => 'OpenAI API key']),
        ]
    );

    return response()->json([
        'message' => 'OpenAI API key saved successfully!',
        'integration' => $integration,
    ]);
}
public function getOpenAiKey()
{
    $tenant_agent = TenantAgent::where('tenant_id', Helper::tenant()->id)->first();

    $integration = TenantAgentIntegration::where('tenant_agent_id', $tenant_agent->id)
        ->where('provider', 'openai')
        ->first();

    return response()->json([
        'key' => $integration?->key
    ]);
}



public function getGoogleAccessToken(Request $request)
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

    $accessToken = $integration->key; 
    if ($integration->secret) {
        try {
            $client = new GoogleClient();
            $client->setClientId(env('GOOGLE_CLIENT_ID'));
            $client->setClientSecret(env('GOOGLE_CLIENT_SECRET'));
            $client->setAccessType('offline'); 
            $client->setScopes(['https://www.googleapis.com/auth/calendar']);
            $client->refreshToken($integration->secret);

            $accessTokenArray = $client->getAccessToken();
            $accessToken = $accessTokenArray['access_token'] ?? $accessToken;

            $integration->update([
                'key' => $accessToken,
                'meta' => json_encode([
                    'expires_in' => $accessTokenArray['expires_in'] ?? null,
                    'updated_at' => now()->toDateTimeString()
                ])
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to refresh Google access token',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    return response()->json([
        'access_token' => $accessToken,
        'refresh_token' => $integration->secret,
        'tenant_agent_id' => $tenant_agent->id
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

   $integration = TenantAgentIntegration::where('tenant_agent_id', $tenant_agent->id)
    ->where('provider', 'google')
    ->first();

$accessToken = $integration->key;
if ($integration->secret) {
    $client = new GoogleClient();
    $client->setClientId(env('GOOGLE_CLIENT_ID'));
    $client->setClientSecret(env('GOOGLE_CLIENT_SECRET'));
    $client->setAccessType('offline');
    $client->setScopes(['https://www.googleapis.com/auth/calendar']);
    $client->refreshToken($integration->secret);
    $accessToken = $client->getAccessToken()['access_token'];
}

return response()->json([
    'key' => $accessToken,
    'secret' => $integration->secret,
    'tenant_agent_id' => $tenant_agent->id
]);

}
public function disconnect(Request $request)
{
    $request->validate([
        'provider' => 'required|string',
    ]);

    $tenant_agent = TenantAgent::where('tenant_id', Helper::tenant()->id)->first();

    if (!$tenant_agent) {
        return response()->json(['error' => 'Tenant agent not found'], 404);
    }

    $integration = TenantAgentIntegration::where('tenant_agent_id', $tenant_agent->id)
        ->where('provider', $request->provider)
        ->first();

    if (!$integration) {
        return response()->json(['message' => 'Integration already disconnected'], 200);
    }

    $integration->delete();

    return response()->json([
        'message' => ucfirst($request->provider) . ' disconnected successfully'
    ]);
}


public function updateOutlook(Request $request)
{
    $request->validate([
        'provider' => 'required',
        'key' => 'required', 
    ]);

    $tenant_agent = TenantAgent::where('tenant_id', Helper::tenant()->id)->first();

    $response = Http::asForm()->post(
        'https://login.microsoftonline.com/common/oauth2/v2.0/token',
        [
            'client_id' => env('OUTLOOK_CLIENT_ID'),
            'client_secret' => env('OUTLOOK_CLIENT_SECRET'),
            'grant_type' => 'authorization_code',
            'code' => $request->key,
            'redirect_uri' => env('OUTLOOK_REDIRECT_URI'),
            'scope' => 'offline_access Calendars.ReadWrite User.Read',
        ]
    );

    if (!$response->successful()) {
        return response()->json([
            'error' => 'Failed to connect Outlook',
            'details' => $response->body()
        ], 400);
    }

    $token = $response->json();

    $integration = TenantAgentIntegration::updateOrCreate(
        [
            'tenant_agent_id' => $tenant_agent->id,
            'provider' => 'outlook',
        ],
        [
            'key' => $token['access_token'],
            'secret' => $token['refresh_token'] ?? '',
            'meta' => json_encode([
                'expires_in' => $token['expires_in'],
                'created' => time(),
            ]),
        ]
    );

    return response()->json([
        'message' => 'Outlook Calendar connected successfully!',
        'integration' => $integration,
    ]);
}
public function getOutlookAccessToken()
{
    $tenant_agent = TenantAgent::where('tenant_id', Helper::tenant()->id)->first();

    $integration = TenantAgentIntegration::where('tenant_agent_id', $tenant_agent->id)
        ->where('provider', 'outlook')
        ->first();

    if (!$integration) {
        return response()->json(['error' => 'Outlook not connected'], 404);
    }

    if (!$integration->secret) {
        return response()->json(['access_token' => $integration->key]);
    }

    $response = Http::asForm()->post(
        'https://login.microsoftonline.com/common/oauth2/v2.0/token',
        [
            'client_id' => env('OUTLOOK_CLIENT_ID'),
            'client_secret' => env('OUTLOOK_CLIENT_SECRET'),
            'grant_type' => 'refresh_token',
            'refresh_token' => $integration->secret,
            'scope' => 'offline_access Calendars.ReadWrite User.Read',
        ]
    );

    $token = $response->json();

    $integration->update([
        'key' => $token['access_token'],
        'meta' => json_encode([
            'expires_in' => $token['expires_in'],
            'updated_at' => now(),
        ]),
    ]);

    return response()->json([
        'access_token' => $token['access_token'],
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
        'key' => $integration->key,      
        'secret' => $integration->secret,  
        'tenant_agent_id' => $tenant_agent->id
    ]);
}

}
