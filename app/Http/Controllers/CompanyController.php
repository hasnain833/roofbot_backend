<?php

namespace App\Http\Controllers;

use App\Helper;
use App\Models\Tenant;
use App\Models\Chatbot;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
   public function index()
{
    $tenant = Helper::tenant();

    $chatbot = \App\Models\Chatbot::where('tenant_id', $tenant->id)->first();

    return response()->json([
        'data' => [
            'company' => $tenant->company,
            'domain' => $tenant->domain,
            'chatbot' => $chatbot ? [
                'id' => $chatbot->id,
                'name' => $chatbot->name,
                'bot_token' => $chatbot->bot_token,
                'iframe_url' => $chatbot->iframe_url,
            ] : null,
        ]
    ]);
}

    public function update(Request $request)
{
    $tenant = Helper::tenant(); 

    $request->validate([
        'company' => 'required|string|max:255',
        'domain' => 'required|string|max:255',
    ]);

    $existing = Tenant::where('domain', $request->domain)
        ->where('id', '!=', $tenant->id)
        ->first();

    if ($existing) {
        return response()->json([
            'message' => 'Company already exists with this domain!'
        ], 409);
    }

    $tenant->update([
        'company' => $request->company,
        'domain' => $request->domain,
    ]);

    $chatbot = Chatbot::firstOrCreate(
        ['tenant_id' => $tenant->id],
        [
            'name' => $tenant->company . ' Bot',
            'bot_token' => 'bot_' . bin2hex(random_bytes(8)),
            'status' => 'active'
        ]
    );

    $iframeUrl = url('/chatbot/' . $chatbot->bot_token);

    $chatbot->update([
        'iframe_url' => $iframeUrl,
        'settings' => ['iframe_url' => $iframeUrl],
    ]);

    return response()->json([
        'message' => 'Company updated successfully',
        'tenant' => $tenant,
        'chatbot' => [
            'id' => $chatbot->id,
            'name' => $chatbot->name,
            'bot_token' => $chatbot->bot_token,
            'iframe_url' => $iframeUrl,
        ]
    ]);
}
}
