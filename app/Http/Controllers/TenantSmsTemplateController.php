<?php

namespace App\Http\Controllers;

use App\Models\TenantSmsTemplate;
use Illuminate\Http\Request;
use App\Helper;

class TenantSmsTemplateController extends Controller
{
    public function getTemplate()
    {
        $tenant = Helper::tenant();
        $template = TenantSmsTemplate::where('tenant_id', $tenant->id)->first();

        return response()->json($template);
    }

    public function storeOrUpdate(Request $request)
    {
        $tenant = Helper::tenant();
        $request->validate([
            'message' => 'required|string',
        ]);

        $template = TenantSmsTemplate::updateOrCreate(
            ['tenant_id' => $tenant->id],
            ['message' => $request->message]
        );

        return response()->json(['message' => 'Template saved', 'data' => $template]);
    }
}
