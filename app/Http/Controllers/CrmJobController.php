<?php

namespace App\Http\Controllers;

use App\Helper;
use App\Models\CrmJob;
use App\Models\Appointment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CrmJobController extends Controller
{
    public function index(Request $request)
    {
        $tenant = Helper::tenant();
        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 400);
        }

        $jobs = CrmJob::where('tenant_id', $tenant->id)
            ->with(['lead', 'appointment', 'user'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $jobs,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'appointment_id' => 'required|exists:appointments,id',
            'status' => 'nullable|string',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'description' => 'nullable|string',
        ]);

        $tenant = Helper::tenant();
        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 400);
        }

        $appointment = Appointment::with(['lead', 'serviceType'])->findOrFail($validated['appointment_id']);

        $job = CrmJob::create([
            'tenant_id' => $tenant->id,
            'lead_id' => $appointment->lead_id,
            'appointment_id' => $appointment->id,
            'user_id' => Auth::id(),
            'title' => $appointment->title,
            'description' => $validated['description'] ?? $appointment->description,
            'status' => $validated['status'] ?? 'Active',
            'start_date' => $validated['start_date'] ?? $appointment->start_time,
            'end_date' => $validated['end_date'] ?? $appointment->end_time,
        ]);

        // Optional: update appointment status
        $appointment->update(['status' => 'Converted to Job']);

        return response()->json([
            'success' => true,
            'message' => 'Job created successfully from appointment',
            'data' => $job,
        ], 201);
    }

    public function show($id)
    {
        $tenant = Helper::tenant();
        $job = CrmJob::where('tenant_id', $tenant->id)
            ->with(['lead', 'appointment', 'user'])
            ->findOrFail($id);

        return response()->json(['success' => true, 'data' => $job]);
    }

    public function update(Request $request, $id)
    {
        $tenant = Helper::tenant();
        $job = CrmJob::where('tenant_id', $tenant->id)->findOrFail($id);

        $job->update($request->only([
            'status', 'start_date', 'end_date', 'description', 'title'
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Job updated successfully',
            'data' => $job,
        ]);
    }

    public function destroy($id)
    {
        $tenant = Helper::tenant();
        $job = CrmJob::where('tenant_id', $tenant->id)->findOrFail($id);

        $job->delete();

        return response()->json(['success' => true, 'message' => 'Job deleted successfully']);
    }
}
