<?php

namespace App\Http\Controllers;

use App\Models\TenantEmailTemplate;
use Illuminate\Http\Request;
use App\Helper;

class TenantEmailTemplateController extends Controller
{
    public function getTemplate(Request $request)
    {
        $tenant = Helper::tenant();

        $type = $request->query('type', 'lead');

        $template = TenantEmailTemplate::where('tenant_id', $tenant->id)
            ->where('type', $type)
            ->first();

        return response()->json($template);
    }

    public function storeOrUpdate(Request $request)
    {
        $tenant = Helper::tenant();

        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'message' => 'required|string',
            'type' => 'required|in:lead,appointment,followup,reminder',
        ]);

        $template = TenantEmailTemplate::updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'type' => $validated['type'],
            ],
            [
                'subject' => $validated['subject'],
                'message' => $validated['message'],
            ]
        );

        return response()->json([
            'message' => 'Template saved successfully',
            'data' => $template,
        ]);
    }
}