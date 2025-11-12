<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckSubscription
{
    public function handle(Request $request, Closure $next)
    {
           if ($request->is('api/subscription/checkout')) {
    return $next($request);
}
        $user = $request->user();
        if ($user->email === 'griffinb@invictusconnect.com') {
            return $next($request);
        }
     

        if ($user->subscription_status !== 'active') {
            return response()->json([
                'message' => 'Subscription required',
                'redirect' => '/checkout'
            ], 403);
        }

        if ($user->current_period_end) {
            $expiry = \Carbon\Carbon::parse($user->current_period_end);
            if ($expiry->isPast()) {
                return response()->json([
                    'message' => 'Subscription expired',
                    'redirect' => '/checkout'
                ], 403);
            }
        }

        return $next($request);
    }
}