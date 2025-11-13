<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Cashier\Checkout;
use Stripe\Stripe;
use Stripe\Checkout\Session;


class BillingController extends Controller
{
    public function plans()
    {
        return response()->json([
            'success' => true,
            'plans'   => Plan::all(),
        ]);
    }

    // Get current subscription info
  public function getSubscription(Request $request)
{
    $user = $request->user();

    if (!$user) {
        return response()->json(['message' => 'Unauthenticated'], 401);
    }
    if (!$user->plan_id) {
        return response()->json(['data' => null]);
    }

    $plan = \App\Models\Plan::find($user->plan_id);

    return response()->json([
        'data' => [
            'plan_id' => $user->plan_id,
            'plan_name' => $plan ? $plan->name : 'Unknown',
            'stripe_status' => $user->subscription_status,
            'current_period_end' => $user->current_period_end,
        ],
    ]);
}


    // New Registration Subscription Checkout
  public function checkout(Request $request)
{
    $request->validate(['plan_id' => 'required|exists:plans,id']);
    $plan = Plan::findOrFail($request->plan_id);
    $user = $request->user();

    if (!$user) {
        return response()->json(['message' => 'Unauthenticated'], 401);
    }

    try {
        \Stripe\Stripe::setApiKey(env('STRIPE_SECRET'));

        // ✅ If this is the PRO plan, use one-time payment mode
        if (strtolower($plan->slug) === 'pro') {
            $checkout = \Stripe\Checkout\Session::create([
                'customer_email' => $user->email,
                'line_items' => [[
                    'price_data' => [
                        'currency' => 'usd',
                        'product_data' => [
                            'name' => 'Pro Plan (1-Year Access)',
                            'description' => 'One-time payment for 12 months access'
                        ],
                        'unit_amount' => intval($plan->yearly_price * 100),
                    ],
                    'quantity' => 1,
                ]],
                'mode' => 'payment', // ✅ one-time payment mode
                'success_url' => env('FRONTEND_URL') . '/signin?paid=1',
                'cancel_url'  => env('FRONTEND_URL') . '/signup?cancel=1',
            ]);

            return response()->json(['url' => $checkout->url]);
        }

        // 🔁 Otherwise, default to subscription for Starter plan
        $priceId = $plan->stripe_yearly_price_id ?? $plan->stripe_monthly_price_id;

        $checkout = \Stripe\Checkout\Session::create([
            'customer_email' => $user->email,
            'line_items' => [[
                'price' => $priceId,
                'quantity' => 1,
            ]],
            'mode' => 'subscription',
            'success_url' => env('FRONTEND_URL') . '/signin?paid=1',
            'cancel_url'  => env('FRONTEND_URL') . '/signup?cancel=1',
        ]);

        return response()->json(['url' => $checkout->url]);

    } catch (\Exception $e) {
        \Log::error('Checkout session failed: ' . $e->getMessage());
        return response()->json(['message' => 'Failed to start checkout.'], 500);
    }
}

    public function subscribe(Request $request)
    {
        $request->validate(['plan_id' => 'required|exists:plans,id']);
        $plan    = Plan::findOrFail($request->plan_id);
        $priceId = $plan->stripe_yearly_price_id ?? $plan->stripe_monthly_price_id;
        $user    = $request->user();

        $subscription = $user->newSubscription('default', $priceId)
            ->trialDays(0)
            ->create();

        $isYearly = str_contains($priceId, 'year');
        $user->plan_id             = $plan->id;
        $user->subscription_status = 'active';
        $user->stripe_customer_id  = $subscription->stripe_id;
        $user->current_period_end  = $isYearly ? now()->addYear() : now()->addMonth();
        $user->save();

        $checkout = $user->checkout($priceId, [
            'success_url' => env('FRONTEND_URL') . '/dashboard/profile?subscribed=1',
            'cancel_url'  => env('FRONTEND_URL') . '/dashboard/profile?cancel=1',
        ]);

        return response()->json(['url' => $checkout->url]);
    }

    // Cancel subscription
    public function cancelSubscription(Request $request)
    {
        $subscription = $request->user()->subscription('default');
        $subscription->cancel();

        return response()->json([
            'message'       => 'Subscription will end at the current period.',
            'stripe_status' => $subscription->stripe_status,
            'ends_at'       => $subscription->ends_at?->toDateString(),
        ]);
    }

    // Existing user upgrade
    public function upgradeSubscription(Request $request)
    {
        $request->validate(['plan_id' => 'required|exists:plans,id']);
        $plan    = Plan::findOrFail($request->plan_id);
        $priceId = $plan->stripe_yearly_price_id ?? $plan->stripe_monthly_price_id;

        $user = $request->user();
        $user->subscription('default')->swap($priceId);

        $isYearly = str_contains($priceId, 'year');
        $user->plan_id            = $plan->id;
        $user->current_period_end = $isYearly ? now()->addYear() : now()->addMonth();
        $user->save();

        return response()->json([
            'message'  => 'Plan upgraded',
            'redirect' => '/dashboard/profile?upgraded=1',
        ]);
    }

    // Stripe webhook
    public function stripeWebhook(Request $request)
{
    $payload = $request->getContent();
    $sig     = $request->header('Stripe-Signature');
    $secret  = env('STRIPE_WEBHOOK_SECRET');

    try {
        $event = \Stripe\Webhook::constructEvent($payload, $sig, $secret);
    } catch (\Exception $e) {
        \Log::error('Webhook signature verification failed: ' . $e->getMessage());
        return response()->json(['error' => 'Invalid signature'], 400);
    }

    \Log::info('✅ Stripe Webhook Received', [
        'type' => $event->type,
        'object' => $event->data->object ?? null,
    ]);
    if ($event->type === 'checkout.session.completed') {
    $session = $event->data->object;
    $email = $session->customer_details->email ?? null;

    if (!$email) return response('No email', 400);

    $user = User::where('email', $email)->first();
    if (!$user) return response('User not found', 404);

    // ✅ Check if one-time payment (Pro Plan)
    if ($session->mode === 'payment') {
        $plan = Plan::where('slug', 'pro')->first();
        if ($plan) {
            $user->plan_id = $plan->id;
            $user->subscription_status = 'active';
            $user->current_period_end = now()->addYear(); // one-year access
            $user->save();
        }

        \Log::info('✅ Pro plan activated for one year (one-time payment)', [
            'user' => $user->email,
            'plan' => 'pro',
        ]);

        return response('OK', 200);
    
}


        try {
            $session = \Stripe\Checkout\Session::retrieve($session->id, [
                'expand' => ['line_items', 'subscription']
            ]);
        } catch (\Exception $e) {
            \Log::warning('Failed to expand checkout session: ' . $e->getMessage());
        }

        $email = $session->customer_details->email ?? null;
        if (!$email) {
            \Log::warning('No email in checkout session', ['session_id' => $session->id]);
            return response('No email', 400);
        }

        $user = User::where('email', $email)->first();
        if (!$user) {
            \Log::warning('User not found for checkout.session.completed', ['email' => $email]);
            return response('User not found', 404);
        }

        $priceId = null;
        if (!empty($session->line_items) && isset($session->line_items->data[0]->price->id)) {
            $priceId = $session->line_items->data[0]->price->id;
        }

        $plan = Plan::where('stripe_monthly_price_id', $priceId)
                    ->orWhere('stripe_yearly_price_id', $priceId)
                    ->first();

        if (!$plan) {
            \Log::warning('Plan not found for price ID', ['price_id' => $priceId]);
            return response('Plan not found', 400);
        }

        $currentPeriodEnd = null;
        if (!empty($session->subscription)) {
            try {
                $subObj = is_object($session->subscription)
                    ? $session->subscription
                    : \Stripe\Subscription::retrieve($session->subscription);

                if (!empty($subObj->current_period_end)) {
                    $currentPeriodEnd = \Carbon\Carbon::createFromTimestamp($subObj->current_period_end);
                }
            } catch (\Exception $e) {
                \Log::warning('Failed to retrieve subscription: ' . $e->getMessage());
            }
        }

        if (!$currentPeriodEnd) {
            $currentPeriodEnd = now()->addMonth();
        }

        $user->plan_id = $plan->id;
        $user->subscription_status = 'active';
        $user->stripe_customer_id = $session->customer ?? $user->stripe_customer_id;
        $user->current_period_end = $currentPeriodEnd;
        $user->save();

        \Log::info('✅ Subscription activated successfully', [
            'user' => $user->email,
            'plan_id' => $plan->id,
            'period_end' => $currentPeriodEnd->toDateTimeString(),
        ]);

        return response('OK', 200);
    }

    if (in_array($event->type, [
        'customer.subscription.created',
        'customer.subscription.updated',
        'customer.subscription.deleted'
    ])) {
        $sub = $event->data->object;
        $user = User::where('stripe_customer_id', $sub->customer)->first();
        if (!$user) return response('OK', 200);

        if ($event->type === 'customer.subscription.deleted') {
            $user->subscription_status = 'canceled';
            $user->save();
        } else {
            $user->subscription_status = $sub->status === 'active' ? 'active' : 'pending';
            $user->current_period_end = \Carbon\Carbon::createFromTimestamp($sub->current_period_end);
            $user->save();
        }

        \Log::info("🔄 Subscription event handled: {$event->type}", [
            'user' => $user->email,
            'status' => $user->subscription_status,
        ]);
    }

    return response('OK', 200);
}


    private function syncSubscription(User $user, $sub)
    {
        $plan = Plan::where('stripe_monthly_price_id', $sub->stripe_price)
                    ->orWhere('stripe_yearly_price_id', $sub->stripe_price)
                    ->first();

        $user->plan_id             = $plan?->id ?? $user->plan_id;
        $user->subscription_status = $sub->status === 'active' ? 'active' : 'canceled';
        $user->current_period_end  = $sub->current_period_end
            ? \Carbon\Carbon::createFromTimestamp($sub->current_period_end)
            : null;
        $user->save();
    }

    private function handleCancellation(User $user, $sub)
    {
        $user->subscription_status = 'canceled';
        $user->current_period_end  = $sub->current_period_end
            ? \Carbon\Carbon::createFromTimestamp($sub->current_period_end)
            : now();
        $user->save();
    }
}