<?php

namespace App\Http\Controllers;

use App\Helper;
use App\Models\Tenant;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    // Get settings for current tenant
    public function index()
    {
        $tenant = Helper::tenant();

        return response()->json([
            'data' => [
                'company' => $tenant->company,
                'chatbot_prompt' => $tenant->chatbot_prompt,
            ]
        ]);
    }

    // Update settings for current tenant
    public function update(Request $request)
    {
        $tenant = Helper::tenant();

        $request->validate([
            'chatbot_prompt' => 'nullable|string',
        ]);

        $tenant->update([
            'chatbot_prompt' => $request->chatbot_prompt,
        ]);

        return response()->json([
            'message' => 'Settings updated successfully',
            'company' => $tenant->company,
            'chatbot_prompt' => $tenant->chatbot_prompt,
        ]);
    }

    // Delete custom chatbot prompt
    public function delete()
    {
        $tenant = Helper::tenant();

        $tenant->update([
            'chatbot_prompt' => null,
        ]);

        return response()->json([
            'message' => 'Custom chatbot prompt deleted. Default will be used.',
            'company' => $tenant->company,
            'chatbot_prompt' => null,
        ]);
    }
}
