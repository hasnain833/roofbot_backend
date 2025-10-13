<?php

namespace App\Http\Controllers;

use App\Helper;
use App\Models\Tenant;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    public function index()
    {
        return response()->json([
            'data' => Helper::tenant()
        ]);
    }

    public function update(Request $request, Tenant $tenant)
    {
        $request->validate([
            'company' => 'required|string|max:255',
            'domain' => 'required|string'
        ]);

        $tenant->update([
            'company' => $request->company,
            'domain' => $request->domain
        ]);

        return response()->json([
            'message' => 'Company updated successfully',
            'tenant' => $tenant
        ]);
    }
}
