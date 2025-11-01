<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use Illuminate\Http\Request;
use App\Models\User;

class BillingController extends Controller
{
    public function plans()
    {
        return response()->json([
            'success' => true,
            'plans' => Plan::all(),
        ]);
    }

    public function getSubscription(Request $request)
    {
        $subscription = $request->user()->subscription('default');
        if (!$subscription) return response()->json(['data' => null]);

        $plan = Plan::where('stripe_monthly_price_id', $subscription->stripe_price)
                    ->orWhere('stripe_yearly_price_id', $subscription->stripe_price)
                    ->first();

        return response()->json([
            'data' => [
                'plan_id' => $plan?->id,
                'stripe_status' => $subscription->stripe_status,
                'current_period_end' => $subscription->current_period_end,
            ]
        ]);
    }

    public function cancelSubscription(Request $request)
    {
        $user = $request->user();

        $user->subscription('default')->cancelNow();

        return response()->json([
            'message' => 'Subscription cancelled',
            'stripe_status' => 'canceled'
        ]);
    }

    public function subscribe(Request $request)
    {
        $request->validate(['plan_id' => 'required|exists:plans,id']);
        $plan = Plan::findOrFail($request->plan_id);
        $priceId = $plan->stripe_yearly_price_id ?? $plan->stripe_monthly_price_id;

        $checkout = $request->user()->newSubscription('default', $priceId)
            ->checkout([
                'success_url' => env('FRONTEND_URL') . '/dashboard/profile?success=1',
                'cancel_url' => env('FRONTEND_URL') . '/dashboard/profile?cancel=1',
            ]);

        return response()->json(['url' => $checkout->url]);
    }

    public function upgradeSubscription(Request $request)
    {
        $user = $request->user();
        $plan = Plan::findOrFail($request->plan_id);
        $priceId = $plan->stripe_yearly_price_id ?? $plan->stripe_monthly_price_id;

        $user->subscription('default')->swap($priceId);

        return response()->json(['success' => true]);
    }

    // STRIPE WEBHOOK 
    public function stripeWebhook(Request $request)
    {
        $payload = $request->getContent();
        $sig = $request->header('Stripe-Signature');
        $secret = env('STRIPE_WEBHOOK_SECRET');

        try {
            $event = \Stripe\Webhook::constructEvent($payload, $sig, $secret);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }

        $object = $event->data->object;
        $user = isset($object->customer) ? User::where('stripe_id', $object->customer)->first() : null;
        if (!$user) return response('OK', 200);

        switch ($event->type) {
            case 'customer.subscription.deleted':
               $user->subscription('default')->cancel();

                break;

            case 'customer.subscription.updated':
            case 'checkout.session.completed':
                $user->subscription('default')->syncStripeStatus();
                break;
        }

        return response('OK', 200);
    }
}
