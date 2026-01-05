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
use Twilio\TwiML\VoiceResponse;
use App\AiAgents\ChatAgent;

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
            if ($request->lead_id && $request->boolean('human')) {
                Lead::where('id', $request->lead_id)
                    ->where('tenant_id', Helper::tenant()->id)
                    ->update(['missed_call_active' => false]);

            }

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
        $to = $request->input('To');
        $body = trim($request->input('Body'));

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

            $tenantAgent = TenantAgent::find($integration->tenant_agent_id);
            if (!$tenantAgent) {
                Log::warning("❌ No tenant agent found for integration ID: {$integration->id}");
                return response('Tenant not found', 200);
            }

            $lead = Lead::where('phone', $from)
                ->where('tenant_id', $tenantAgent->tenant_id)
                ->first();

            if (!$lead) {
                Log::info("ℹ️ Inbound from unknown number $from — could auto-create lead here if desired");
                return response('No lead found', 200);
            }

            Message::create([
                'lead_id' => $lead->id,
                'text' => $body,
                'out' => false,
                'status' => 'received',
            ]);

            Log::info("✅ Message saved for Lead ID {$lead->id}");

            $lastOutbound = Message::where('lead_id', $lead->id)
                ->where('out', true)
                ->latest()
                ->first();

            $shouldAutoRespond = false;

            // CASE 1: Lead is replying after missed call → AI ON
            if ($lead->missed_call_active) {
                $shouldAutoRespond = true;
            }


            if ($shouldAutoRespond && !empty($body)) {
                $apiKey = $this->getTenantOpenAiKey();
                if (!$apiKey) {
                    Log::warning("No OpenAI key for tenant {$tenantAgent->tenant_id}");
                    return response('Message received', 200);
                }

                $tenantPrompt = $tenantAgent->tenant->chatbot_prompt ?? null;

                $agent = (new ChatAgent((string) $lead->id))
                    ->setTenantContext($apiKey, $tenantAgent->tenant_id)
                    ->setTenantPrompt($tenantPrompt);

                $responseText = $agent->handleMessage($body);

                $endTriggers = ['thanks', 'thank you', 'bye', 'stop', 'no thanks'];

                foreach ($endTriggers as $word) {
                    if (str_contains(strtolower($body), $word)) {
                        $lead->update(['missed_call_active' => false]);
                        break;
                    }
                }

                if (!empty($responseText)) {
                    $client = new Client($integration->key, $integration->secret);
                    $message = $client->messages->create($from, [
                        'from' => $to,
                        'body' => $responseText,
                    ]);

                    Message::create([
                        'lead_id' => $lead->id,
                        'text' => $responseText,
                        'out' => true,
                        'status' => $message->status,
                        'sid' => $message->sid,
                    ]);
                }

            }

            return response('Message processed', 200);

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
    private function getTenantOpenAiKey()
    {
        $tenant = Helper::tenant();
        if (!$tenant) {
            return null;
        }

        $tenantAgent = TenantAgent::where('tenant_id', $tenant->id)->first();
        if (!$tenantAgent) {
            return null;
        }

        $integration = TenantAgentIntegration::where('tenant_agent_id', $tenantAgent->id)
            ->where('provider', 'openai')
            ->first();

        return $integration?->key
            ?? $tenant->openai_api_key
            ?? null;
    }
    public function summarizeChat(Request $request)
    {
        $leadId = $request->input('lead_id');
        $lead = Lead::find($leadId);
        if (!$lead) {
            return response()->json(['error' => 'Lead not found'], 404);
        }

        $apiKey = $this->getTenantOpenAiKey();
        if (!$apiKey || !str_starts_with($apiKey, 'sk-')) {
            return response()->json([
                'error' => 'OpenAI API key not configured for this tenant'
            ], 400);
        }

        $messages = Message::where('lead_id', $leadId)
            ->orderBy('created_at', 'asc')
            ->get();

        if ($messages->isEmpty()) {
            return response()->json(['summary' => 'No messages to summarize.']);
        }

        $chatText = '';
        foreach ($messages as $msg) {
            $sender = $msg->out ? 'Agent' : 'Lead';
            $chatText .= "{$sender}: {$msg->text}\n";
        }

        try {
            $prompt = "Summarize this SMS conversation between customer and company in 3-4 short sentences.
Highlight intent, questions, tone, and next steps:\n\n{$chatText}";

            $response = Http::withToken($apiKey)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => 'gpt-3.5-turbo',
                    'messages' => [
                        ['role' => 'system', 'content' => 'You summarize SMS conversations.'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'max_tokens' => 150,
                ]);

            $summary = $response->json('choices.0.message.content') ?? 'No summary generated.';
            $lead->update(['ai_chat_summary' => $summary]);

            return response()->json(['summary' => $summary]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to generate summary',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    public function handleInboundCall(Request $request)
    {
        Log::info('📞 Inbound call received:', $request->all());

        $from = $request->input('From'); // Lead's phone
        $to = $request->input('To');     // Twilio number called

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
            $response = new VoiceResponse();
            $response->say('Sorry, this number is not configured.');
            $response->hangup();
            return response($response)->header('Content-Type', 'text/xml');
        }

        $tenantAgent = TenantAgent::find($integration->tenant_agent_id);
        if (!$tenantAgent) {
            Log::warning("❌ No tenant agent found for integration ID: {$integration->id}");
            $response = new VoiceResponse();
            $response->say('Sorry, we could not connect your call.');
            $response->hangup();
            return response($response)->header('Content-Type', 'text/xml');
        }

        $lead = Lead::where('phone', $from)
            ->where('tenant_id', $tenantAgent->tenant_id)
            ->first();

        if (!$lead) {
            Log::warning("❌ No lead found for number: $from (tenant {$tenantAgent->tenant_id})");
            $response = new VoiceResponse();
            $response->say('Sorry, we could not find your information. Please text us instead.');
            $response->hangup();
            return response($response)->header('Content-Type', 'text/xml');
        }

        $tenant = $tenantAgent->tenant;
        $tenantPhone = $tenant->phone;
        if (!$tenantPhone) {
            Log::warning("❌ No tenant phone found for tenant ID: {$tenant->id}");
            $response = new VoiceResponse();
            $response->say('Sorry, we are unable to connect at this time. Please text us.');
            $response->hangup();
            return response($response)->header('Content-Type', 'text/xml');
        }

        // Forward call to tenant
        $response = new VoiceResponse();
        $dial = $response->dial($tenantPhone, [
            'timeout' => 25,
            'callerId' => $to,
            'statusCallback' => env('APP_URL') . '/api/twilio/voice-status',
            'statusCallbackMethod' => 'POST',
            'statusCallbackEvent' => ['no-answer', 'busy', 'failed', 'completed'],
        ]);

        $response->say('Sorry, we missed your call. How we can help you today.');

        return response($response)->header('Content-Type', 'text/xml');
    }
    public function handleVoiceStatus(Request $request)
    {
        Log::info('📞 Voice status callback:', $request->all());

        $status = $request->input('CallStatus');
        $from = $request->input('From');
        $to = $request->input('To');
        $callSid = $request->input('CallSid');

        if (in_array($status, ['no-answer', 'busy', 'failed'])) {

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
                Log::warning("❌ No integration for missed call: $to");
                return response()->json(['success' => false]);
            }

            $tenantAgent = TenantAgent::find($integration->tenant_agent_id);
            $lead = Lead::where('phone', $from)
                ->where('tenant_id', $tenantAgent->tenant_id)
                ->first();
            $recentMissedSms = Message::where('lead_id', $lead->id)
                ->where('text', 'like', '%sorry we missed your call%')
                ->where('created_at', '>', now()->subMinutes(10))
                ->exists();

            if ($recentMissedSms) {
                return response()->json(['success' => true]); // Already sent
            }

            if ($lead) {
                try {
                    $client = new Client($integration->key, $integration->secret);
                    $fromNumber = $to;

                    $body = "Sorry we missed your call, {$lead->first_name}. How can we help? Reply here to chat or book an appointment.";

                    $message = $client->messages->create($from, [
                        'from' => $fromNumber,
                        'body' => $body,
                    ]);

                    Message::create([
                        'lead_id' => $lead->id,
                        'text' => $body,
                        'out' => true,
                        'status' => $message->status,
                        'sid' => $message->sid,
                    ]);

                    $lead->update([
                        'status' => 'missed_call',
                        'missed_call_active' => true,
                    ]);

                } catch (\Exception $e) {
                    Log::error("Missed call SMS failed: " . $e->getMessage());
                }
            }
        }

        return response()->json(['success' => true]);
    }


}
