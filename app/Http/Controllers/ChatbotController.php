<?php

namespace App\Http\Controllers;

use App\Helper;
use App\Models\TenantAgent;
use App\Models\ServiceType;
use Illuminate\Http\Request;
use App\AiAgents\LeadAgent;
use App\Models\Tenant;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Laragent\Traits\HasMemory;


class ChatbotController extends Controller
{
public function handleMessage(Request $request)
{
    $request->validate([
        'agent_id' => 'required|integer',
        'session_id' => 'required|string',
        'ip_address' => 'sometimes|ip',
        'message' => 'required|string',
    ]);

  $tenant = Helper::tenant();

if (!$tenant) {
    return response()->json(['error'=>'Tenant not resolved'],404);
}

// REFRESH tenant with full fields
$tenant->refresh();

Log::info('CHATBOT TENANT AFTER FRESH', [
    'id' => $tenant->id,
    'chatbot_prompt' => $tenant->chatbot_prompt,
    'chatbot_questions' => $tenant->chatbot_questions,
]);

$serviceTypes = ServiceType::where('tenant_id', $tenant->id)
    ->select('id','name')
    ->get();

Log::info('CHATBOT SERVICE TYPES', [
    'tenant_id' => $tenant->id,
    'service_types_count' => $serviceTypes->count(),
]);
    if (!$tenant) {
        return response()->json(['error' => 'Tenant not resolved'], 404);
    }

    $tenantAgent = TenantAgent::where('tenant_id', $tenant->id)
        ->where('id', $request->agent_id)
        ->first();
   


    if (!$tenantAgent) {
        return response()->json(['error' => 'Invalid or unauthorized agent'], 404);
    }

    $openAiIntegration = \App\Models\TenantAgentIntegration::where('tenant_agent_id', $tenantAgent->id)
        ->where('provider', 'openai')
        ->first();

    $apiKey = $openAiIntegration?->key 
              ?? $tenant->openai_api_key 
              ?? env('OPENAI_API_KEY');

    if (!$apiKey || !str_starts_with($apiKey, 'sk-')) {
        return response()->json([
            'reply' => "Hi! I'm ready to help, but it looks like the OpenAI API key hasn't been set up yet. 

Please add your OpenAI API key in the integrations settings to activate me fully. 

In the meantime, feel free to reach out via phone or email! 😊"
        ]);
    }

    $agent = (new LeadAgent($request->session_id))
    ->setTenantContext($apiKey, $tenant->id)
    ->setTenantPrompt($tenant->chatbot_prompt)
    ->setCustomQuestions($tenant->chatbot_questions ?? [])
    ->setServiceTypes($serviceTypes);



    try {
        $response = $agent->handleMessage($request->message);
        return response()->json(['reply' => $response]);
    } catch (\Exception $e) {
        Log::error('Chatbot handleMessage failed: ' . $e->getMessage(), [
            'trace' => $e->getTraceAsString(),
            'tenant_id' => $tenant->id,
            'agent_id' => $request->agent_id
        ]);
        return response()->json(['error' => 'Internal server error', 'message' => $e->getMessage()], 500);
    }
    
}
public function handleMessagePublic(Request $request)
    {
        $request->validate([
            'agent_id' => 'required|integer',
            'session_id' => 'required|string',
            'ip_address' => 'sometimes|ip',
            'message' => 'required|string',
        ]);

        $tenantAgent = TenantAgent::find($request->agent_id);
        if (!$tenantAgent) {
            return response()->json(['reply' => "Invalid agent"], 404);
        }

        $tenant = $tenantAgent->tenant; 
        if (!$tenant) {
            return response()->json(['reply' => 'Tenant not found for this agent'], 404);
        }
         $serviceTypes = ServiceType::where('tenant_id', $tenantAgent->id)
          ->select('id', 'name')
          ->get();

        $openAiIntegration = $tenantAgent->integrations()
            ->where('provider', 'openai')
            ->first();

        $apiKey = $openAiIntegration?->key 
                  ?? $tenant->openai_api_key 
                  ?? env('OPENAI_API_KEY');

        if (!$apiKey) {
            return response()->json([
                'reply' => "OpenAI key missing. Please contact admin."
            ]);
        }

        $agent = (new LeadAgent($request->session_id))
            ->setTenantContext($apiKey, $tenant->id)
            ->setTenantPrompt($tenant->chatbot_prompt)
            ->setCustomQuestions($tenant->chatbot_questions ?? [])
            ->setServiceTypes($serviceTypes);

        $reply = $agent->handleMessage($request->message);

        return response()->json(['reply' => $reply]);
    }
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
   // ChatbotController.php
public function sessionInfoIframe(Request $request)
{
    $token = $request->query('token');
    if (!$token) {
        return response()->json(['error' => 'Missing token'], 400);
    }

    $chatbot = \App\Models\Chatbot::where('bot_token', $token)->first();
    if (!$chatbot) {
        return response()->json(['error' => 'Chatbot not found'], 404);
    }

    $tenant = $chatbot->tenant; 
    if (!$tenant) {
        return response()->json(['error' => 'Tenant not found'], 404);
    }

    $agent = $tenant->agents()->where('status', 'active')->first();

    $sessionId = md5($request->ip() . '_' . time() . '_' . Str::uuid() . '_' . Str::random(8));

    return response()->json([
        'agent_id'   => $agent ? $agent->id : null,
        'session_id' => $sessionId,
        'ip_address' => $request->ip(),
        'company'    => $tenant->company, 
    ]);
}


    
}