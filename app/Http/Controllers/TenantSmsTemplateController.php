<?php

namespace App\Http\Controllers;

use App\Models\TenantSmsTemplate;
use Illuminate\Http\Request;
use App\Helper;

class TenantSmsTemplateController extends Controller
{
    /**
     * Get template by type (lead / appointment)
     */
    public function getTemplate(Request $request)
    {
        $tenant = Helper::tenant();

        $type = $request->query('type', 'lead'); // default lead

        $template = TenantSmsTemplate::where('tenant_id', $tenant->id)
            ->where('type', $type)
            ->first();

        return response()->json($template);
    }

    /**
     * Store or update template
     */
    public function storeOrUpdate(Request $request)
    {
        $tenant = Helper::tenant();

        $validated = $request->validate([
            'message' => 'required|string',
            'type' => 'required|in:lead,appointment',
        ]);

        $template = TenantSmsTemplate::updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'type' => $validated['type'],
            ],
            [
                'message' => $validated['message'],
            ]
        );

        return response()->json([
            'message' => 'Template saved successfully',
            'data' => $template,
        ]);
    }
}
