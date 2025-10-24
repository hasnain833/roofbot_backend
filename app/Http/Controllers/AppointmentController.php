<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
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

        if ($lead && $lead->phone) {
          $payload = [
    'event' => 'appointment_created',
    'fullName' => trim($lead->first_name . ' ' . ($lead->last_name ?? '')),
    'userPhone' => $lead->phone,
    'email' => $lead->email,
    'serviceNeeded' => $serviceType ?? 'General Service',
    'preferredDateTimeISO' => \Carbon\Carbon::parse($appointment->start_time)->toIso8601String(),
    'windowEndISO' => \Carbon\Carbon::parse($appointment->end_time)->toIso8601String(),
    'tenant_id' => $appointment->tenant_id,
    'appointment_id' => $appointment->id,
];


            Http::post(env('N8N_WEBHOOK_URL'), $payload);
        }
    } catch (\Exception $e) {
        \Log::error('❌ Failed to send webhook to n8n: ' . $e->getMessage());
    }

    // ✅ Google Calendar sync
    try {
        dispatch(new SyncAppointmentToGoogle($appointment));
    } catch (\Exception $e) {
        \Log::error('❌ Failed to dispatch Google Sync job: ' . $e->getMessage());
    }

    return response()->json([
        'success' => true,
        'message' => 'Appointment created successfully',
        'data' => $appointment,
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
