<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Helper;
use App\Models\TenantAgentIntegration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Auth;
use App\Jobs\SyncAppointmentToGoogle;
use App\Jobs\SyncAppointmentToOutlook;
use Carbon\Carbon;
use Twilio\Rest\Client;
use App\Models\TenantSmsTemplate;
use App\Models\TenantAgent;
use App\Models\Message;

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
            'service_type' => 'nullable|string|max:255',
            'start_time' => 'required|date',
            'end_time' => 'required|date|after:start_time',
        ]);

        $validated['tenant_id'] = Helper::tenant()->id;
        $validated['user_id'] = $request->user()->id;

        $appointment = Appointment::create($validated);
        $this->createReminder($appointment);
       
try {
    $lead = $appointment->lead;

    if ($lead && $lead->phone) {

        $tenantAgent = TenantAgent::where('tenant_id', Helper::tenant()->id)->first();

        $twilioIntegration = TenantAgentIntegration::where('tenant_agent_id', $tenantAgent->id)
            ->where('provider', 'twilio')
            ->first();

        if ($twilioIntegration) {

            $client = new Client(
                $twilioIntegration->key,
                $twilioIntegration->secret
            );

            $numbers = $client->incomingPhoneNumbers->read();
            $fromNumber = $numbers[0]->phoneNumber ?? env('TWILIO_PHONE');

       
            $template = TenantSmsTemplate::where('tenant_id', Helper::tenant()->id)
                ->where('type', 'appointment')
                ->first();

            $defaultBody =
                "Hi {first_name}, your appointment for {service_type} is scheduled on {date_time}. See you soon!";

            $body = $template ? $template->message : $defaultBody;

       
            $body = str_replace('{first_name}', $lead->first_name, $body);
            $body = str_replace(
                '{service_type}',
                $appointment->service_type ?? 'our services',
                $body
            );
            $body = str_replace(
                '{date_time}',
                Carbon::parse($appointment->start_time)->format('M d, Y h:i A'),
                $body
            );

          
            $client->messages->create($lead->phone, [
                'from' => $fromNumber,
                'body' => $body,
            ]);

            Message::create([
                'lead_id' => $lead->id,
                'text' => $body,
                'out' => true,
                'status' => 'sent',
            ]);
        }
    }

} catch (\Exception $e) {
    \Log::error('Appointment SMS failed: ' . $e->getMessage());
}

        try {
            $lead = $appointment->lead;
          $serviceType = $appointment->service_type ?? 'General Service';


            $tenantAgent = \App\Models\TenantAgent::where('tenant_id', $appointment->tenant_id)->first();
            if (!$tenantAgent) {
                throw new \Exception('TenantAgent not found for tenant ID: ' . $appointment->tenant_id);
            }

            $googleIntegration = TenantAgentIntegration::where('tenant_agent_id', $tenantAgent->id)
                ->where('provider', 'google')
                ->first();

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
                'google_access_token' => $googleIntegration?->key,
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
            \Log::info('Dispatching SyncAppointmentToGoogle job', [
                'appointment_id' => $appointment->id,
                'tenant_id' => $appointment->tenant_id,
            ]);
            dispatch(new SyncAppointmentToGoogle($appointment));
            dispatch(new SyncAppointmentToOutlook($appointment));

            \Log::info('SyncAppointmentToGoogle job dispatched successfully', [
                'appointment_id' => $appointment->id,
            ]);
        } catch (\Exception $e) {
            \Log::error('❌ Failed Google Sync Job: ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Appointment created successfully',
            'data' => $appointment,
        ]);
    }
    private function createReminder(Appointment $appointment)
    {
        $appointment->reminders()->delete(); 

        \App\Models\Reminder::create([
            'lead_id' => $appointment->lead_id,
            'appointment_id' => $appointment->id,
            'reminder_date' => Carbon::parse($appointment->start_time)->subHours(24),
            'type' => 'appointment',
            'done' => false,
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

      $serviceTypeName = $appointment->service_type ?? 'General Service';

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
            'title',
            'description',
            'notes',
            'status',
            'service_type',
            'start_time',
            'end_time'
        ]));
        if ($request->has('start_time')) {
            $this->createReminder($appointment);
        }
        if ($appointment->google_event_id) {
            dispatch(new SyncAppointmentToGoogle($appointment, true));
        }
        if ($appointment->outlook_event_id) {
            dispatch(new SyncAppointmentToOutlook($appointment, true));
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
        if ($appointment->outlook_event_id) {
            dispatch(new \App\Jobs\DeleteOutlookEvent($appointment));
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
            'service_type' => 'nullable|string|max:255',
            'start_time' => 'required|date',
            'end_time' => 'required|date|after:start_time',
        ]);

        $validated['user_id'] = null;

        $appointment = Appointment::create($validated);
       
        try {
            $lead = $appointment->lead;
           $serviceType = $appointment->service_type ?? 'General Service';

            $tenantAgent = \App\Models\TenantAgent::where('tenant_id', $appointment->tenant_id)->first();
            if (!$tenantAgent) {
                throw new \Exception('TenantAgent not found for tenant ID: ' . $appointment->tenant_id);
            }

            $googleIntegration = TenantAgentIntegration::where('tenant_agent_id', $tenantAgent->id)
                ->where('provider', 'google')
                ->first();

            $twilioIntegration = TenantAgentIntegration::where('tenant_agent_id', $tenantAgent->id)
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
                'google_access_token' => $googleIntegration?->key,
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
            'title',
            'description',
            'notes',
            'status',
            'start_time',
            'end_time'
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
