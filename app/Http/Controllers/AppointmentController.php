<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
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
        $appointments = Appointment::where('tenant_id', $request->user()->tenant_id)
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

        $validated['tenant_id'] = $request->user()->tenant_id;
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
    $tenant = Helper::tenant();
    if (!$tenant) {
        return response()->json(['error' => 'Tenant not found'], 400);
    }

    $appointment = Appointment::where('tenant_id', $tenant->id)
        ->with('serviceType') // ← CRITICAL
        ->findOrFail($id);

    if (\App\Models\CrmJob::where('appointment_id', $appointment->id)->exists()) {
        return response()->json(['error' => 'Job already exists for this appointment'], 400);
    }

    $serviceTypeName = $appointment->serviceType?->name ?? 'General Service';

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
}
