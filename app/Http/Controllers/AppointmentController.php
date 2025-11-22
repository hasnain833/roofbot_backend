<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Helper;
use App\Models\TenantAgentIntegration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Auth;
use App\Jobs\SyncAppointmentToGoogle;
use Carbon\Carbon;

class AppointmentController extends Controller
{
    public function index(Request $request)
    {
        $tenantId = Helper::tenant()->id;

        $appointments = Appointment::where('tenant_id', $tenantId)
             ->with(['lead', 'user', 'serviceType'])
             ->orderBy('start_time', 'asc')
             ->get();

        return response()->json([
            'success' => true,
            'data' => $appointments
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'lead_id' => 'nullable|exists:leads,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'notes' => 'nullable|string',
            'service_type_id' => 'nullable|exists:service_types,id',
            'start_time' => 'required|date',
            'end_time' => 'required|date|after:start_time',
        ]);

        $validated['tenant_id'] = Helper::tenant()->id;
        $validated['user_id'] = $request->user()->id;

        $appointment = Appointment::create($validated);

        try {
            $lead = $appointment->lead;
            $serviceType = optional($appointment->serviceType)->name;

            $googleToken = TenantAgentIntegration::where('tenant_agent_id', $appointment->user_id)
                ->where('provider', 'google')
                ->value('key');

             $twilioIntegration = TenantAgentIntegration::where('tenant_agent_id', $appointment->user_id)
            ->where('provider', 'twilio')
            ->first();

            $payload = [
                'event' => 'appointment_created',
                'fullName' => $lead ? trim($lead->first_name . ' ' . ($lead->last_name ?? '')) : null,
                'userPhone' => $lead->phone ?? null,
                'email' => $lead->email ?? null,
                'serviceNeeded' => $serviceType ?? 'General Service',
                'preferredDateTimeISO' => Carbon::parse($appointment->start_time)->toIso8601String(),
                'windowEndISO' => Carbon::parse($appointment->end_time)->toIso8601String(),
                'tenant_id' => $appointment->tenant_id,
                'appointment_id' => $appointment->id,
                'google_access_token' => $googleToken,
                'twilio_sid' => $twilioIntegration?->key,
                'twilio_token' => $twilioIntegration?->secret, 
            ];

            Http::withHeaders([
                'Accept' => 'application/json',
            ])->post(env('N8N_WEBHOOK_URL'), $payload);

        } catch (\Exception $e) {
            \Log::error('❌ Failed n8n webhook: ' . $e->getMessage());
        }

        // ✅ Google Calendar Sync Job
        try {
            dispatch(new SyncAppointmentToGoogle($appointment));
        } catch (\Exception $e) {
            \Log::error('❌ Failed Google Sync Job: ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Appointment created successfully',
            'data' => $appointment,
        ]);
    }
public function convertToJob($id, Request $request)
{
    $tenant = $request->user()->tenant ?? Helper::tenant();

    if (!$tenant) {
        \Log::warning('convertToJob: Tenant not resolved', [
            'user_id' => $request->user()->id,
            'role' => $request->user()->role ?? 'unknown',
        ]);
        return response()->json(['error' => 'Access denied: Invalid tenant'], 403);
    }

    // ✅ Fetch appointment by tenant_id AND id (any user of tenant)
    $appointment = Appointment::with('serviceType')
        ->where('id', $id)
        ->where('tenant_id', $tenant->id)
        ->first();

    if (!$appointment) {
        \Log::warning('convertToJob: Appointment not found in tenant', [
            'appointment_id' => $id,
            'tenant_id' => $tenant->id,
        ]);
        return response()->json(['error' => 'Appointment not found'], 404);
    }

    if (\App\Models\CrmJob::where('appointment_id', $appointment->id)->exists()) {
        return response()->json(['error' => 'Job already exists'], 400);
    }

   $serviceTypeName = $appointment->serviceType ? $appointment->serviceType->name : 'General Service';
\Log::info('ServiceType', ['serviceType' => $appointment->serviceType]);

$job = \App\Models\CrmJob::create([
    'tenant_id' => $tenant->id,
    'lead_id' => $appointment->lead_id,
    'appointment_id' => $appointment->id,
    'user_id' => $request->user()->id,
    'title' => $appointment->title,
    'description' => $appointment->description,
    'status' => 'Active',
    'start_date' => $appointment->start_time,
    'end_date' => $appointment->end_time,
    'service_type' => $serviceTypeName, 
]);

    $appointment->update(['status' => 'Converted to Job']);

    return response()->json([
        'success' => true,
        'message' => 'Appointment converted to job successfully',
        'data' => $job,
    ]);
}

    public function update(Request $request, $id)
    {
        $appointment = Appointment::findOrFail($id);

        $appointment->update($request->only([
            'title', 'description', 'notes', 'status', 'start_time', 'end_time'
        ]));

        if ($appointment->google_event_id) {
            dispatch(new SyncAppointmentToGoogle($appointment, true));
        }

        return response()->json([
            'success' => true,
            'message' => 'Appointment updated successfully',
            'data' => $appointment
        ]);
    }

    public function destroy($id)
    {
        $appointment = Appointment::findOrFail($id);

        if ($appointment->google_event_id) {
            dispatch(new \App\Jobs\DeleteGoogleEvent($appointment));
        }

        $appointment->delete();

        return response()->json(['success' => true, 'message' => 'Appointment deleted']);
    }

public function publicStore(Request $request)
{
    $validated = $request->validate([
        'tenant_id' => 'required|exists:tenants,id',
        'lead_id' => 'nullable|exists:leads,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'notes' => 'nullable|string',
            'service_type_id' => 'nullable|exists:service_types,id',
            'start_time' => 'required|date',
            'end_time' => 'required|date|after:start_time',
    ]);

    $validated['user_id'] = null; 

    $appointment = Appointment::create($validated);

    try {
       try {
            $lead = $appointment->lead;
            $serviceType = optional($appointment->serviceType)->name;

            $googleToken = TenantAgentIntegration::where('tenant_agent_id', $appointment->user_id)
                ->where('provider', 'google')
                ->value('key');

             $twilioIntegration = TenantAgentIntegration::where('tenant_agent_id', $appointment->user_id)
            ->where('provider', 'twilio')
            ->first();

            $payload = [
                'event' => 'appointment_created',
                'fullName' => $lead ? trim($lead->first_name . ' ' . ($lead->last_name ?? '')) : null,
                'userPhone' => $lead->phone ?? null,
                'email' => $lead->email ?? null,
                'serviceNeeded' => $serviceType ?? 'General Service',
                'preferredDateTimeISO' => Carbon::parse($appointment->start_time)->toIso8601String(),
                'windowEndISO' => Carbon::parse($appointment->end_time)->toIso8601String(),
                'tenant_id' => $appointment->tenant_id,
                'appointment_id' => $appointment->id,
                'google_access_token' => $googleToken,
                'twilio_sid' => $twilioIntegration?->key,
                'twilio_token' => $twilioIntegration?->secret, 
            ];

            Http::withHeaders([
                'Accept' => 'application/json',
            ])->post(env('N8N_WEBHOOK_URL'), $payload);

        } catch (\Exception $e) {
            \Log::error('❌ Failed n8n webhook: ' . $e->getMessage());
        }

    try {
        dispatch(new SyncAppointmentToGoogle($appointment));
    } catch (\Exception $e) {
        \Log::error('❌ Failed Google Sync Job: ' . $e->getMessage());
    }

    return response()->json([
        'success' => true,
        'message' => 'Appointment created successfully',
        'data' => $appointment,
    ]);
    }
}

public function publicUpdate(Request $request, $id)
{
    $validated = $request->validate([
        'tenant_id' => 'required|exists:tenants,id',
         'title' => 'sometimes|string|max:255',
        'description' => 'nullable|string',
        'notes' => 'nullable|string',
         'status' => 'nullable|string',
         'start_time' => 'nullable|date',
        'end_time' => 'nullable|date|after:start_time',
    ]);

    $appointment = Appointment::findOrFail($id);
    if ($appointment->tenant_id !== $validated['tenant_id']) {
        return response()->json(['error' => 'Unauthorized: Tenant mismatch'], 403);
    }

    $appointment->update($request->only([
        'title', 'description', 'notes', 'status', 'start_time', 'end_time'
    ]));

    if ($appointment->google_event_id) {
        dispatch(new SyncAppointmentToGoogle($appointment, true));
    }

    return response()->json([
        'success' => true,
        'message' => 'Appointment updated successfully',
        'data' => $appointment
    ]);
}

public function publicShow(Request $request, $id)
{
    $tenantId = $request->query('tenant_id');
    if (!$tenantId) {
        return response()->json(['error' => 'tenant_id required'], 400);
    }

    $appointment = Appointment::where('id', $id)->where('tenant_id', $tenantId)
        ->with(['lead', 'user', 'serviceType'])->first();
    if (!$appointment) {
        return response()->json(['error' => 'Appointment not found'], 404);
    }

    return response()->json(['data' => $appointment]);
}

public function publicConvertToJob(Request $request, $id)
{
    $tenantId = $request->query('tenant_id') ?? $request->input('tenant_id');
    if (!$tenantId) {
        return response()->json(['error' => 'tenant_id required'], 400);
    }

    $appointment = Appointment::with('serviceType')
        ->where('id', $id)
        ->where('tenant_id', $tenantId)
        ->first();

    if (!$appointment) {
        return response()->json(['error' => 'Appointment not found'], 404);
    }

    if (\App\Models\CrmJob::where('appointment_id', $appointment->id)->exists()) {
        return response()->json(['error' => 'Job already exists'], 400);
    }

    $serviceTypeName = $appointment->serviceType ? $appointment->serviceType->name : 'General Service';

    $job = \App\Models\CrmJob::create([
        'tenant_id' => $tenantId,
        'lead_id' => $appointment->lead_id,
        'appointment_id' => $appointment->id,
        'user_id' => null, 
        'title' => $appointment->title,
        'description' => $appointment->description,
         'status' => 'Active',
         'start_date' => $appointment->start_time,
         'end_date' => $appointment->end_time,
         'service_type' => $serviceTypeName, 
    ]);

    $appointment->update(['status' => 'Converted to Job']);

    return response()->json([
        'success' => true,
        'message' => 'Appointment converted to job successfully',
        'data' => $job,
    ]);
}

public function publicIndexByLead(Request $request)
{
    $leadId = $request->query('lead_id');
    $tenantId = $request->query('tenant_id');
    if (!$leadId || !$tenantId) {
        return response()->json(['error' => 'lead_id and tenant_id required'], 400);
    }

    $appointments = Appointment::where('tenant_id', $tenantId)
        ->where('lead_id', $leadId)
        ->with(['lead', 'user', 'serviceType'])
        ->orderBy('start_time', 'asc')
        ->get();

    return response()->json([
        'success' => true,
        'data' => $appointments
    ]);
}
}
