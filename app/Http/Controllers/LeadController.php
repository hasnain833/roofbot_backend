<?php

namespace App\Http\Controllers;

use App\Helper;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LeadController extends Controller
{
    // ✅ Fetch all leads for current tenant
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

        $leads = Lead::where('tenant_id', $tenant->id)->get();

        return response()->json(['data' => $leads]);
    }

    // ✅ Store new lead
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
            'service_type_id' => 'nullable|integer',
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

        return response()->json(['message' => 'Lead created successfully', 'data' => $lead]);
    }

    // ✅ Update lead
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
            'service_type_id' => 'nullable|integer',
        ]);

        $lead->update($validated);

        return response()->json(['message' => 'Lead updated successfully', 'data' => $lead]);
    }

    // ✅ Delete lead
    public function destroy(Lead $lead)
    {
        $this->authorizeLead($lead);
        $lead->delete();

        return response()->json(['message' => 'Lead deleted successfully']);
    }

    // 🔒 Authorization
    private function authorizeLead(Lead $lead)
    {
        $tenant = Helper::tenant();
        if (!$tenant || $lead->tenant_id !== $tenant->id) {
            abort(403, 'Unauthorized action.');
        }
    }
}
