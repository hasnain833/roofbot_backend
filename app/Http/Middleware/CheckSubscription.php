<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Carbon\Carbon;

class CheckSubscription
{
    public function handle(Request $request, Closure $next)
    {
        // Allow checkout route without blocking
        if ($request->is('api/subscription/checkout')) {
            return $next($request);
        }

        $user = $request->user();

        // Super admin bypass
        if ($user->email === 'griffinb@invictusconnect.com') {
            return $next($request);
        }

        // If user has a current period in the future, allow access
        if (!empty($user->current_period_end)) {
            $expiry = Carbon::parse($user->current_period_end);
            if ($expiry->isFuture()) {
                return $next($request);
            }
        }

        // Only block if subscription is canceled AND current period has expired
        if ($user->subscription_status !== 'active') {
            return response()->json([
                'message' => 'Subscription expired',
                'redirect' => '/checkout'
            ], 403);
        }

        return $next($request);
    }
}
