<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Carbon\Carbon;

class CheckSubscription
{
    public function handle(Request $request, Closure $next)
    {
        // Allow user to access checkout itself
        if ($request->is('api/subscription/checkout')) {
            return $next($request);
        }

        $user = $request->user();

        // Super admin bypass
        if ($user->email === 'griffinb@invictusconnect.com') {
            return $next($request);
        }

        // If current period end exists and is in future -> ALWAYS allow
        if (!empty($user->current_period_end)) {
            $expiry = Carbon::parse($user->current_period_end);

            if ($expiry->isFuture()) {
                // User is either on trial OR paid period
                return $next($request);
            }
        }

        // At this point, the period is expired. Now block depending on status.
        // Allowed statuses only during valid period:
        $allowedStatuses = ['active', 'trialing'];

        if (!in_array($user->subscription_status, $allowedStatuses)) {
            return response()->json([
                'message' => 'Subscription expired',
                'redirect' => '/checkout'
            ], 403);
        }

        // Default allow
        return $next($request);
    }
}
