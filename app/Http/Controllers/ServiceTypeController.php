<?php

namespace App\Http\Controllers;

use App\Helper;
use App\Models\ServiceType;
use Illuminate\Http\Request;

class ServiceTypeController extends Controller
{
   
    public function index()
    {
        $tenant = Helper::tenant();
        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 400);
        }

        $types = ServiceType::where('tenant_id', $tenant->id)
            ->orderBy('id', 'desc')
            ->get();

        return response()->json(['data' => $types]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $tenant = Helper::tenant();
        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 400);
        }

        $exists = ServiceType::where('tenant_id', $tenant->id)
            ->where('name', $validated['name'])
            ->exists();

        if ($exists) {
            return response()->json([
                'error' => 'Service type already exists'
            ], 422);
        }

        $type = ServiceType::create([
            ...$validated,
            'tenant_id' => $tenant->id,
        ]);

        return response()->json([
            'message' => 'Service type created successfully',
            'data' => $type
        ]);
    }

  
    public function show(ServiceType $serviceType)
    {
        $this->authorizeTenant($serviceType);
        return response()->json(['data' => $serviceType]);
    }

  
    public function update(Request $request, ServiceType $serviceType)
    {
        $this->authorizeTenant($serviceType);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $serviceType->update($validated);

        return response()->json([
            'message' => 'Service type updated successfully',
            'data' => $serviceType
        ]);
    }

    // ✅ DELETE
    public function destroy(ServiceType $serviceType)
    {
        $this->authorizeTenant($serviceType);

        $serviceType->delete();

        return response()->json([
            'message' => 'Service type deleted successfully'
        ]);
    }


    private function authorizeTenant(ServiceType $serviceType)
    {
        $tenant = Helper::tenant();

        if (!$tenant || $serviceType->tenant_id !== $tenant->id) {
            abort(403, 'Unauthorized action.');
        }
    }
}
