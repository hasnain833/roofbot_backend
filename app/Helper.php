<?php

namespace App;

use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;

class Helper
{
    // ✅ Return current tenant
    public static function tenant()
    {
        return self::resolveTenant();
    }

    // ✅ Resolve tenant based on logged-in user
    public static function resolveTenant()
    {
        $user = Auth::user();
        if (!$user) return null;

        if ($user->role === 'superadmin') {
            return Tenant::where('user_id', $user->id)->first();
        }

        // Make sure tenantUser relation exists in User model
        if (method_exists($user, 'tenantUser')) {
            $tenantUser = $user->tenantUser()->first();
            if ($tenantUser) {
                return Tenant::find($tenantUser->tenant_id);
            }
        }

        return null;
    }
}
