<?php

namespace App;

use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;

class Helper
{
    public static function tenant()
    {
        return app('tenant');
    }
    public static function resolveTenant()
    {
        if (Auth::user()->role == 'superadmin') {
            return Tenant::where('user_id', Auth::user()->id)->first();
        }
        return Tenant::where('id', Auth::user()->tenantUser->tenant_id)->first();
    }
}
