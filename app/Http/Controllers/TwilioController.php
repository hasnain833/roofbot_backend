<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Helper;
use App\Models\Lead;
use App\Models\Message; 
use App\Models\TenantAgent;
use App\Models\TenantAgentIntegration;
use Twilio\Rest\Client;

class TwilioController extends Controller
{
    public function sendMessage(Request $request)
{
    $request->validate([
        'to' => 'required|string',
        'message' => 'required|string',
    ]);

    try {
  
        $tenantAgent = TenantAgent::where('tenant_id', Helper::tenant()->id)->first();
        $integration = TenantAgentIntegration::where('tenant_agent_id', $tenantAgent->id)
            ->where('provider', 'twilio')
            ->first();

        if (!$integration) {
            return response()->json(['error' => 'Twilio integration not found'], 404);
        }

        $client = new Client($integration->key, $integration->secret);

        $numbers = $client->incomingPhoneNumbers->read();
        $fromNumber = $numbers[0]->phoneNumber ?? env('TWILIO_PHONE');

         $callbackUrl = env('APP_URL') . '/api/twilio/status';

        $message = $client->messages->create(
            $request->to,
            [
                'from' => $fromNumber,
                'body' => $request->message,
                'statusCallback' => $callbackUrl,
            ]
        );

        Message::create([
            'lead_id' => $request->lead_id ?? null,
            'text' => $request->message,
            'out' => true,
            'status' => $message->status,
            'sid' => $message->sid,
        ]);

        return response()->json([
            'sid' => $message->sid,
            'status' => $message->status,
            'message' => 'Message sent successfully!',
        ]);

    } catch (\Exception $e) {
        Log::error('Twilio send error: ' . $e->getMessage());
        return response()->json(['error' => $e->getMessage()], 500);
    }
}

   public function inbound(Request $request)
{
    Log::info('📩 Inbound SMS received:', $request->all());

    $from = $request->input('From');
    $to   = $request->input('To');   
    $body = $request->input('Body');

    try {
        $integration = null;
        $integrations = TenantAgentIntegration::where('provider', 'twilio')->get();

        foreach ($integrations as $item) {
            try {
                $client = new Client($item->key, $item->secret);
                $numbers = $client->incomingPhoneNumbers->read();

                foreach ($numbers as $num) {
                    if ($num->phoneNumber === $to) {
                        $integration = $item;
                        break 2; 
                    }
                }
            } catch (\Exception $e) {
                Log::warning("Failed checking Twilio account ({$item->id}): " . $e->getMessage());
            }
        }

        if (!$integration) {
            Log::warning("❌ No Twilio integration found for incoming number: $to");
            return response('Integration not found', 200);
        }

        // 🔹 Step 2: Get tenant agent and tenant ID
        $tenantAgent = TenantAgent::find($integration->tenant_agent_id);
        if (!$tenantAgent) {
            Log::warning("❌ No tenant agent found for integration ID: {$integration->id}");
            return response('Tenant not found', 200);
        }

        // 🔹 Step 3: Find the lead in that tenant by sender’s number
        $lead = Lead::where('phone', $from)
            ->where('tenant_id', $tenantAgent->tenant_id)
            ->first();

        if (!$lead) {
            Log::warning("❌ No lead found for number: $from (tenant {$tenantAgent->tenant_id})");
            return response('No lead found', 200);
        }

        Message::create([
            'lead_id' => $lead->id,
            'text'    => $body,
            'out'     => false,
            'status'  => 'received',
        ]);

        Log::info("✅ Message saved for Lead ID {$lead->id}: $body");
        return response('Message received', 200);

    } catch (\Exception $e) {
        Log::error("🚨 Inbound message processing failed: " . $e->getMessage());
        return response('Server error', 500);
    }
}

    public function getMessages($leadId)
    {
        $messages = Message::where('lead_id', $leadId)
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json($messages);
    }
    // TwilioController.php
public function statusCallback(Request $request)
{
    Log::info('Twilio status callback', $request->all());

    $message = Message::where('sid', $request->MessageSid)->first();
    if ($message) {
        $message->status = $request->MessageStatus;
        $message->save();
    }

    return response()->json(['success' => true]);
}
public function summarizeChat(Request $request)
{
    $leadId = $request->input('lead_id');
    $lead = Lead::find($leadId);
    if (!$lead) return response()->json(['error' => 'Lead not found'], 404);

    $messages = Message::where('lead_id', $leadId)
        ->orderBy('created_at', 'asc')
        ->get();

    if ($messages->isEmpty()) {
        return response()->json(['summary' => 'No messages to summarize.']);
    }

    // Format chat for summary
    $chatText = '';
    foreach ($messages as $msg) {
        $sender = $msg->out ? 'Agent' : 'Lead';
        $chatText .= "{$sender}: {$msg->text}\n";
    }

    try {
        $prompt = "Summarize this SMS conversation between customer and company in 3-4 short sentences
Highlight intent, questions, tone, and next steps. :\n\n{$chatText}";

        $response = Http::withToken(env('OPENAI_API_KEY'))
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-3.5-turbo',
                'messages' => [
                    ['role' => 'system', 'content' => 'You are an assistant summarizing SMS conversations between an agent and a lead.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'max_tokens' => 150,
            ]);

        $summary = $response->json('choices.0.message.content') ?? 'No summary generated.';
        $lead->update(['ai_chat_summary' => $summary]);

        return response()->json(['summary' => $summary]);
    } catch (\Exception $e) {
        return response()->json(['error' => 'Failed to generate summary', 'message' => $e->getMessage()], 500);
    }
}


}
