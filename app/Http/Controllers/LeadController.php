<?php

namespace App\Http\Controllers;

use App\Helper;
use App\Models\Lead;
use App\Models\ServiceType;
use App\Models\TenantAgentIntegration;
use App\Models\TenantAgent;
use App\Models\Followup;
use Illuminate\Http\Request;
use Twilio\Rest\Client;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SendGrid;
use SendGrid\Mail\Mail;



class LeadController extends Controller
{
    public function index(Request $request)
    {
        $tenant = Helper::tenant();
        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 400);
        }
        $pageSize = (int) $request->query('pageSize', 10);
        $query = Lead::where('tenant_id', $tenant->id)->with('serviceType');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $leads = $query->orderBy('id', 'desc')
            ->paginate($pageSize);

        $leadData = $leads->map(function ($lead) {
            return [
                'id' => $lead->id,
                'first_name' => $lead->first_name,
                'last_name' => $lead->last_name,
                'email' => $lead->email,
                'address' => $lead->address,
                'phone' => $lead->phone,
                'city' => $lead->city,
                'state' => $lead->state,
                'zip' => $lead->zip,
                'country' => $lead->country,
                'status' => $lead->status,
                'service_type' => optional($lead->serviceType)->name ?? 'Unspecified',
            ];
        });
        return response()->json($leads);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'first_name' => 'required|string',
            'last_name' => 'nullable|string',
            'email' => 'nullable|email',
            'phone' => 'nullable|string',
            'address' => 'nullable|string',
            'city' => 'nullable|string',
            'state' => 'nullable|string',
            'zip' => 'nullable|string',
            'country' => 'nullable|string',
            'status' => 'nullable|string',
            'service_type_name' => 'nullable|string|max:255',
            'service_type_id' => 'nullable|exists:service_types,id',
        ]);

        $tenant = Helper::tenant();
        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 400);
        }

        $lead = Lead::create([
            ...$validated,
            'tenant_id' => $tenant->id,
            'user_id' => Auth::id(),
        ]);
        $this->createFollowups($lead);


        if ($lead->phone) {
            $tenant_agent = TenantAgent::where('tenant_id', Helper::tenant()->id)->first();
            $integration = TenantAgentIntegration::where('tenant_agent_id', $tenant_agent->id)
                ->where('provider', 'twilio')
                ->first();

            if ($integration) {
                try {
                    $client = new Client($integration->key, $integration->secret);
                    $numbers = $client->incomingPhoneNumbers->read();
                    $fromNumber = $numbers[0]->phoneNumber ?? env('TWILIO_PHONE');
                    $template = \App\Models\TenantSmsTemplate::where('tenant_id', $tenant->id)
                        ->where('type', 'lead')
                        ->first();

                    $body = $template ? $template->message : "Hello {first_name}, thank you for showing interest in {service_type} services.";

                    $body = str_replace('{first_name}', $lead->first_name, $body);
                    $body = str_replace('{service_type}', optional($lead->serviceType)->name ?? ' services', $body);

                    $statusCallbackUrl = env('APP_URL') . '/api/twilio/status';

                    $twilioMessage = $client->messages->create($lead->phone, [
                        'from' => $fromNumber,
                        'body' => $body,
                        'statusCallback' => $statusCallbackUrl,
                        'statusCallbackMethod' => 'POST',
                    ]);

                    \App\Models\Message::create([
                        'lead_id' => $lead->id,
                        'text' => $body,
                        'out' => true,
                        'status' => $twilioMessage->status,
                        'sid' => $twilioMessage->sid,
                    ]);

                } catch (\Exception $e) {
                    Log::error("Twilio send failed: " . $e->getMessage());
                    return response()->json([
                        'message' => 'Lead created but failed to send SMS',
                        'sms_error' => $e->getMessage(),
                        'data' => $lead
                    ], 200);
                }
            }
        }
        // ================== SEND LEAD EMAIL ==================
        if ($lead->email && $tenant_agent) {
            $sendgridIntegration = TenantAgentIntegration::where('tenant_agent_id', $tenant_agent->id)
                ->where('provider', 'sendgrid')
                ->first();

            if ($sendgridIntegration) {
                try {
                    $template = \App\Models\TenantEmailTemplate::where('tenant_id', $tenant->id)
                        ->where('type', 'lead')
                        ->first();

                    $defaultSubject = 'Thank You';
                    $defaultBody = "Hi {first_name},\n\nThank you for your interest in our services";

                    $subject = $template?->subject ?? $defaultSubject;
                    $body = $template?->message ?? $defaultBody;

                    $body = str_replace('{first_name}', $lead->first_name, $body);
                    $body = str_replace(
                        '{service_type}',
                        optional($lead->serviceType)->name ?? 'our services',
                        $body
                    );

                    $email = new Mail();

                    $fromEmail = $sendgridIntegration->from_email ?? 'no-reply@yourcompany.com';

                    $email->setFrom($fromEmail, $tenant->company ?? 'Your Company');
                    $email->setSubject($subject);

                    $fullName = trim($lead->first_name . ' ' . ($lead->last_name ?? ''));
                    $email->addTo($lead->email, $fullName);
                    $email->addContent("text/plain", $body);

                    $sendgrid = new SendGrid($sendgridIntegration->key);
                    $sendgrid->send($email);

                    \Log::info('Lead email sent', [
                        'lead_id' => $lead->id,
                        'email' => $lead->email,
                    ]);
                } catch (\Exception $e) {
                    \Log::error('Lead email failed: ' . $e->getMessage(), [
                        'lead_id' => $lead->id,
                    ]);
                }
            }
        }


        return response()->json([
            'message' => 'Lead created successfully',
            'data' => [
                'id' => $lead->id,
                'first_name' => $lead->first_name,
                'last_name' => $lead->last_name,
                'email' => $lead->email,
                'phone' => $lead->phone,
                'city' => $lead->city,
                'state' => $lead->state,
                'zip' => $lead->zip,
                'country' => $lead->country,
                'status' => $lead->status,
                'service_type' => $lead->service_type_name ?? 'Unspecified',
            ]
        ]);
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


    public function summarize(Request $request)
    {
        $lead = Lead::with('serviceType')->find($request->input('lead_id'));
        if (!$lead) {
            return response()->json(['error' => 'Lead not found'], 404);
        }

        $apiKey = $this->getTenantOpenAiKey();
        if (!$apiKey || !str_starts_with($apiKey, 'sk-')) {
            return response()->json([
                'error' => 'OpenAI API key not configured.'
            ], 400);
        }

        $serviceType = $lead->serviceType?->name ?? $lead->service_type_name ?? 'Unspecified';


        try {
            $prompt = "Summarize this lead's details in 2-3 short sentences:
Name: {$lead->first_name} {$lead->last_name}
Email: {$lead->email}
Phone: {$lead->phone}
City: {$lead->city}
Service Type: {$serviceType}";

            $response = Http::withToken($apiKey)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => 'gpt-3.5-turbo',
                    'messages' => [
                        ['role' => 'system', 'content' => 'You summarize customer lead information.'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'max_tokens' => 100,
                ]);

            $summary = $response->json('choices.0.message.content') ?? 'No summary generated.';
            $lead->update(['ai_summary' => $summary]);

            return response()->json(['summary' => $summary]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to generate summary',
                'message' => $e->getMessage()
            ], 500);
        }
    }


    public function customAnswers($leadId)
    {
        $tenant = Helper::tenant();

        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        $lead = Lead::where('id', $leadId)
            ->where('tenant_id', $tenant->id)
            ->with('customAnswers')
            ->firstOrFail();

        return response()->json([
            'data' => $lead->customAnswers->map(function ($a) {
                return [
                    'id' => $a->id,
                    'question' => $a->question,
                    'answer' => $a->answer,
                ];
            }),
        ]);
    }


    public function update(Request $request, Lead $lead)
    {
        $this->authorizeLead($lead);
        $validated = $request->validate([
            'first_name' => 'required|string',
            'last_name' => 'nullable|string',
            'email' => 'nullable|email',
            'phone' => 'nullable|string',
            'address' => 'nullable|string',
            'city' => 'nullable|string',
            'state' => 'nullable|string',
            'zip' => 'nullable|string',
            'country' => 'nullable|string',
            'status' => 'nullable|string',
            'service_type_id' => 'nullable|exists:service_types,id',
        ]);

        $lead->update($validated);
        $this->createFollowups($lead);
        return response()->json(['message' => 'Lead updated successfully', 'data' => $lead]);
    }

    public function destroy(Lead $lead)
    {
        $this->authorizeLead($lead);
        $lead->delete();
        return response()->json(['message' => 'Lead deleted successfully']);
    }

    private function authorizeLead(Lead $lead)
    {
        $tenant = Helper::tenant();
        if (!$tenant || $lead->tenant_id !== $tenant->id) {
            abort(403, 'Unauthorized action.');
        }
    }
    private function createFollowups(Lead $lead)
    {
        $lead->followups()->delete(); // Recreate on change

        $status = $lead->status ?? 'New';
        if (!in_array($status, ['New', 'Contacted', 'Proposal Sent'])) {
            return;
        }

        $followupDays = [1, 3, 5];

        foreach ($followupDays as $attempt => $days) {
            Followup::create([
                'lead_id' => $lead->id,
                'followup_date' => now()->addDays($days),
                'note' => "Followup attempt " . ($attempt + 1) . " for status {$status}",
                'type' => $status,
                'done' => false,
            ]);
        }
    }

    public function publicStore(Request $request)
    {
        $validated = $request->validate([
            'tenant_id' => 'required|exists:tenants,id',
            'first_name' => 'required|string',
            'last_name' => 'nullable|string',
            'email' => 'nullable|email',
            'phone' => 'nullable|string',
            'address' => 'nullable|string',
            'city' => 'nullable|string',
            'state' => 'nullable|string',
            'zip' => 'nullable|string',
            'country' => 'nullable|string',
            'status' => 'nullable|string',
            'service_type_id' => 'nullable|exists:service_types,id',
        ]);

        $lead = Lead::create([
            ...$validated,
            'user_id' => null,
        ]);
        $followupDays = [1, 3, 5];

        foreach ($followupDays as $days) {
            \App\Models\Followup::create([
                'lead_id' => $lead->id,
                'followup_date' => now()->addDays($days),
                'attempt_number' => 1,
                'type' => 'NEW',
                'sent' => false,
            ]);
        }


        \App\Models\Reminder::create([
            'lead_id' => $lead->id,
            'reminder_date' => now()->addDay(),
            'type' => 'appointment',
        ]);

        if ($lead->phone) {
            $tenant_agent = TenantAgent::where('tenant_id', $validated['tenant_id'])->first();
            $integration = TenantAgentIntegration::where('tenant_agent_id', $tenant_agent->id)
                ->where('provider', 'twilio')
                ->first();

            if ($integration) {
                try {
                    $client = new Client($integration->key, $integration->secret);
                    $numbers = $client->incomingPhoneNumbers->read();
                    $fromNumber = $numbers[0]->phoneNumber ?? env('TWILIO_PHONE');
                    $serviceName = optional($lead->serviceType)->name ?? 'our service';
                    $body = "Hello {$lead->first_name}, thank you for showing interest in {$serviceName}. We will contact you shortly!";

                    $client->messages->create($lead->phone, [
                        'from' => $fromNumber,
                        'body' => $body,
                    ]);

                    \App\Models\Message::create([
                        'lead_id' => $lead->id,
                        'text' => $body,
                        'out' => true,
                        'status' => 'sent',
                    ]);
                } catch (\Exception $e) {
                    Log::error("Twilio send failed: " . $e->getMessage());
                    return response()->json([
                        'message' => 'Lead created but failed to send SMS',
                        'sms_error' => $e->getMessage(),
                        'data' => $lead
                    ], 200);
                }
            }
        }

        return response()->json([
            'message' => 'Lead created successfully',
            'data' => [
                'id' => $lead->id,
                'first_name' => $lead->first_name,
                'last_name' => $lead->last_name,
                'email' => $lead->email,
                'phone' => $lead->phone,
                'city' => $lead->city,
                'state' => $lead->state,
                'zip' => $lead->zip,
                'country' => $lead->country,
                'status' => $lead->status,
                'service_type' => optional($lead->serviceType)->name ?? 'Unspecified',
            ]
        ]);
    }

    public function publicUpdate(Request $request, $id)
    {
        $validated = $request->validate([
            'tenant_id' => 'required|exists:tenants,id',
            'first_name' => 'required|string',
            'last_name' => 'nullable|string',
            'email' => 'nullable|email',
            'phone' => 'nullable|string',
            'address' => 'nullable|string',
            'city' => 'nullable|string',
            'state' => 'nullable|string',
            'zip' => 'nullable|string',
            'country' => 'nullable|string',
            'status' => 'nullable|string',
            'service_type_id' => 'nullable|exists:service_types,id',
        ]);

        $lead = Lead::findOrFail($id);
        if ($lead->tenant_id !== $validated['tenant_id']) {
            return response()->json(['error' => 'Unauthorized: Tenant mismatch'], 403);
        }

        $lead->update($validated);
        return response()->json(['message' => 'Lead updated successfully', 'data' => $lead]);
    }

    public function publicShow(Request $request, $id)
    {
        $tenantId = $request->query('tenant_id');
        if (!$tenantId) {
            return response()->json(['error' => 'tenant_id required'], 400);
        }

        $lead = Lead::where('id', $id)->where('tenant_id', $tenantId)->with('serviceType')->first();
        if (!$lead) {
            return response()->json(['error' => 'Lead not found'], 404);
        }

        return response()->json([
            'data' => [
                'id' => $lead->id,
                'first_name' => $lead->first_name,
                'last_name' => $lead->last_name,
                'email' => $lead->email,
                'address' => $lead->address,
                'phone' => $lead->phone,
                'city' => $lead->city,
                'state' => $lead->state,
                'zip' => $lead->zip,
                'country' => $lead->country,
                'status' => $lead->status,
                'service_type' => optional($lead->serviceType)->name ?? 'Unspecified',
            ]
        ]);
    }

    public function publicIndex(Request $request)
    {
        $tenantId = $request->query('tenant_id');
        if (!$tenantId) {
            return response()->json(['error' => 'tenant_id required'], 400);
        }

        $query = Lead::where('tenant_id', $tenantId)->with('serviceType');


        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $leads = $query->orderBy('id', 'desc')->get();

        $leadData = $leads->map(function ($lead) {
            return [
                'id' => $lead->id,
                'first_name' => $lead->first_name,
                'last_name' => $lead->last_name,
                'email' => $lead->email,
                'address' => $lead->address,
                'phone' => $lead->phone,
                'city' => $lead->city,
                'state' => $lead->state,
                'zip' => $lead->zip,
                'country' => $lead->country,
                'status' => $lead->status,
                'service_type' => optional($lead->serviceType)->name ?? 'Unspecified',
            ];
        });
        return response()->json(['data' => $leadData]);
    }
}
