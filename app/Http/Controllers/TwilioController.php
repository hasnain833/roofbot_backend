<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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

            $config = json_decode($integration->meta, true);
            $fromNumber = $config['from'] ?? env('TWILIO_PHONE'); 

            $client = new Client($integration->key, $integration->secret);

            $message = $client->messages->create(
                $request->to,
                [
                    'from' => $fromNumber,
                    'body' => $request->message
                ]
            );

            Message::create([
                'lead_id' => $request->lead_id ?? null,
                'text' => $request->message,
                'out' => true,
                'status' => $message->status,
            ]);

            return response()->json([
                'sid' => $message->sid,
                'status' => $message->status,
                'message' => 'Message sent successfully!'
            ]);

        } catch (\Exception $e) {
            Log::error('Twilio send error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function inbound(Request $request)
    {
        Log::info('📩 Inbound SMS:', $request->all());

        $from = $request->input('From');
        $body = $request->input('Body');

        $lead = Lead::where('phone', $from)->first();
        if (!$lead) {
            Log::warning("No lead found for number: $from");
            return response('No lead found', 200);
        }

        Message::create([
            'lead_id' => $lead->id,
            'text' => $body,
            'out' => false,
            'status' => 'received',
        ]);

        return response('Message received', 200);
    }

    // ✅ 3. Get chat messages for a lead
    public function getMessages($leadId)
    {
        $messages = Message::where('lead_id', $leadId)
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json($messages);
    }
}
