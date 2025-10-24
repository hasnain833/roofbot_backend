<?php

namespace App\Http\Controllers;

use App\Helper;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

class LeadController extends Controller
{
    public function index(Request $request)
    {
        $tenant = Helper::tenant();
        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 400);
        }

        $query = Lead::where('tenant_id', $tenant->id);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $leads = $query->orderBy('id', 'desc')->get();
        return response()->json(['data' => $leads]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'first_name' => 'required|string',
            'last_name'  => 'nullable|string',
            'email'      => 'nullable|email',
            'phone'      => 'nullable|string',
            'address'    => 'nullable|string',
            'city'       => 'nullable|string',
            'state'      => 'nullable|string',
            'zip'        => 'nullable|string',
            'country'    => 'nullable|string',
            'status'     => 'nullable|string',
            'service_type' => 'nullable|string',
        ]);

        $tenant = Helper::tenant();
        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 400);
        }

        $lead = Lead::create([
            ...$validated,
            'tenant_id' => $tenant->id,
            'user_id'   => Auth::id(),
        ]);

        try {
            $webhookUrl = env('N8N_WEBHOOK_URL');
            if ($webhookUrl) {
                Http::post($webhookUrl, [
                    'lead_id' => $lead->id,
                    'first_name' => $lead->first_name,
                    'last_name' => $lead->last_name,
                    'email' => $lead->email,
                    'phone' => $lead->phone,
                    'city' => $lead->city,
                    'service_type' => $lead->service_type,
                    'tenant_id' => $tenant->id,
                ]);
            }
        } catch (\Exception $e) {
            \Log::error('N8N Webhook Error: '.$e->getMessage());
        }

        return response()->json(['message' => 'Lead created successfully', 'data' => $lead]);
    }

    public function summarize(Request $request)
    {
        $leadId = $request->input('lead_id');
        $lead = Lead::find($leadId);

        if (!$lead) {
            return response()->json(['error' => 'Lead not found'], 404);
        }

        try {
            $prompt = "Summarize this lead's details in 2-3 short sentences:
            Name: {$lead->first_name} {$lead->last_name}
            Email: {$lead->email}
            Phone: {$lead->phone}
            City: {$lead->city}
            Service Type: {$lead->service_type}";

            $response = Http::withToken(env('OPENAI_API_KEY'))
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => 'gpt-3.5-turbo',
                    'messages' => [
                        ['role' => 'system', 'content' => 'You are an assistant that summarizes customer lead information.'],
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
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, Lead $lead)
    {
        $this->authorizeLead($lead);
        $validated = $request->validate([
            'first_name' => 'required|string',
            'last_name'  => 'nullable|string',
            'email'      => 'nullable|email',
            'phone'      => 'nullable|string',
            'address'    => 'nullable|string',
            'city'       => 'nullable|string',
            'state'      => 'nullable|string',
            'zip'        => 'nullable|string',
            'country'    => 'nullable|string',
            'status'     => 'nullable|string',
            'service_type' => 'nullable|string',
        ]);

        $lead->update($validated);
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
}
