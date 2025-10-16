<?php

namespace App\Http\Controllers;

use App\Models\CrmJob;
use Illuminate\Http\Request;

class CrmJobController extends Controller
{
    public function index()
    {
        // Show all jobs (you can filter by tenant later)
        $jobs = CrmJob::with(['lead', 'tenant'])->get();
        return response()->json($jobs);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'lead_id' => 'required|exists:leads,id',
            'tenant_id' => 'required|exists:tenants,id',
            'service_type' => 'required|string',
            'status' => 'nullable|string',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        $job = CrmJob::create($validated);
        return response()->json($job, 201);
    }

    public function show($id)
    {
        $job = CrmJob::with(['lead', 'tenant'])->findOrFail($id);
        return response()->json($job);
    }

    public function update(Request $request, $id)
    {
        $job = CrmJob::findOrFail($id);
        $job->update($request->all());
        return response()->json($job);
    }

    public function destroy($id)
    {
        $job = CrmJob::findOrFail($id);
        $job->delete();
        return response()->json(['message' => 'Job deleted successfully']);
    }
}
