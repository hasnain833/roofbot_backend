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
                'mode' => 'payment', 
                'success_url' => env('FRONTEND_URL') . '/signin?paid=1',
                'cancel_url'  => env('FRONTEND_URL') . '/signup?cancel=1',
            ]);

            return response()->json(['url' => $checkout->url]);
        }
         if (strtolower($plan->slug) === 'starter') {
            $firstPayment = intval(($plan->monthly_price + $plan->setup_fee) * 100);

            $checkout = \Stripe\Checkout\Session::create([
                'customer_email' => $user->email,
                'line_items' => [[
                    'price_data' => [
                        'currency' => 'usd',
                        'product_data' => [
                            'name' => 'Starter Plan',
                            'description' => 'Starter plan has Setup fee of $1000',
                        ],
                        'unit_amount' => $firstPayment,
                    ],
                    'quantity' => 1,
                ]],
                'mode' => 'payment', 
                'success_url' => env('FRONTEND_URL') . '/signin?paid=1',
                'cancel_url'  => env('FRONTEND_URL') . '/signup?cancel=1',
            ]);

            return response()->json(['url' => $checkout->url]);
        }
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
    $plan = Plan::findOrFail($request->plan_id);
    $user = $request->user();

    if (!$user) {
        return response()->json(['message' => 'Unauthenticated'], 401);
    }

    try {
        \Stripe\Stripe::setApiKey(env('STRIPE_SECRET'));

        // One-time Starter / Pro plans for resubscribe (no setup fee)
        if (strtolower($plan->slug) === 'starter' || strtolower($plan->slug) === 'pro') {
            $amount = 0;
            $name = '';
            $description = '';

            if (strtolower($plan->slug) === 'starter') {
                $amount = intval($plan->monthly_price * 100); 
                $name = 'Starter Plan';
                $description = 'Resubscribe to Starter Plan';
            } else {
                $amount = intval($plan->yearly_price * 100);
                $name = 'Pro Plan (1-Year Access)';
                $description = 'Resubscribe to Pro Plan (one-time yearly payment)';
            }

            $checkout = \Stripe\Checkout\Session::create([
                'customer_email' => $user->email,
                'line_items' => [[
                    'price_data' => [
                        'currency' => 'usd',
                        'product_data' => [
                            'name' => $name,
                            'description' => $description
                        ],
                        'unit_amount' => $amount,
                    ],
                    'quantity' => 1,
                ]],
                'mode' => 'payment',
                'success_url' => env('FRONTEND_URL') . '/dashboard/profile?paid=1',
                'cancel_url'  => env('FRONTEND_URL') . '/dashboard/profile?cancel=1',
                'metadata' => [
                    'plan_id' => $plan->id,
                    'user_id' => $user->id,
                ],
            ]);

            return response()->json(['url' => $checkout->url]);
        }

        // Fallback for other recurring plans (if any)
        $priceId = $plan->stripe_yearly_price_id ?? $plan->stripe_monthly_price_id;
        $checkout = $user->checkout($priceId, [
            'success_url' => env('FRONTEND_URL') . '/dashboard/profile?subscribed=1',
            'cancel_url'  => env('FRONTEND_URL') . '/dashboard/profile?cancel=1',
        ]);

        return response()->json(['url' => $checkout->url]);

    } catch (\Exception $e) {
        \Log::error('Subscribe failed: ' . $e->getMessage());
        return response()->json(['message' => 'Failed to start checkout: ' . $e->getMessage()], 500);
    }
}

  
 public function cancelSubscription(Request $request)
{
    $user = $request->user();

    if (!$user) {
        return response()->json(['message' => 'Unauthenticated'], 401);
    }

    if (!$user->plan_id) {
        return response()->json([
            'message' => 'No active subscription.',
            'stripe_status' => 'none'
        ], 404);
    }

    $user->subscription_status = 'canceled';
    $user->save();

    return response()->json([
        'message' => 'Subscription is cancelled. You will still have access until the end of your billing period.',
        'stripe_status' => 'canceled',
        'access_until' => $user->current_period_end,
        'plan_name' => $user->plan->name ?? null,
    ]);
}

public function upgradeSubscription(Request $request)
{
    $request->validate(['plan_id' => 'required|exists:plans,id']);
    $plan = Plan::findOrFail($request->plan_id);
    $user = $request->user();

    if (!$user) {
        return response()->json(['message' => 'Unauthenticated'], 401);
    }

    try {
        \Stripe\Stripe::setApiKey(env('STRIPE_SECRET'));

        // If upgrading to PRO → one-time payment
        if (strtolower($plan->slug) === 'pro') {

            $checkout = \Stripe\Checkout\Session::create([
                'customer_email' => $user->email,
                'mode' => 'payment',
                'line_items' => [[
                    'price_data' => [
                        'currency' => 'usd',
                        'product_data' => [
                            'name' => 'Pro Plan (1-Year Access)',
                        ],
                        'unit_amount' => intval($plan->yearly_price * 100),
                    ],
                    'quantity' => 1,
                ]],
                'metadata' => [
                    'upgrade' => true,
                    'user_id' => $user->id,
                    'plan_id' => $plan->id,
                ],
                'success_url' => env('FRONTEND_URL') . '/dashboard/profile?subscribed=1',
                'cancel_url'  => env('FRONTEND_URL') . '/dashboard/profile?cancel=1',
            ]);

            return response()->json(['url' => $checkout->url]);
        }

        // For normal subscription-based plans
        $priceId = $plan->stripe_yearly_price_id ?? $plan->stripe_monthly_price_id;

        $session = \Stripe\Checkout\Session::create([
            'customer_email' => $user->email,
            'mode' => 'subscription',
            'line_items' => [[
                'price' => $priceId,
                'quantity' => 1,
            ]],
            'subscription_data' => [
                'metadata' => [
                    'upgrade' => true,
                    'user_id' => $user->id,
                    'plan_id' => $plan->id,
                ],
            ],
            'success_url' => env('FRONTEND_URL') . '/dashboard/profile?subscribed=1',
            'cancel_url'  => env('FRONTEND_URL') . '/dashboard/profile?cancel=1',
        ]);

        return response()->json(['url' => $session->url]);

    } catch (\Exception $e) {
        \Log::error('Upgrade subscription failed: ' . $e->getMessage());
        return response()->json(['error' => $e->getMessage()], 500);
    }
}

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
    
    if ($session->mode === 'payment') {
    $plan = Plan::where('slug', 'starter')->first();
    if ($plan) {
        $user->plan_id = $plan->id;
        $user->subscription_status = 'active';
        $user->current_period_end = now()->addMonth(); 
        $user->save();
    }

    \Log::info('✅ Starter plan first payment completed', [
        'user' => $user->email,
        'plan' => 'starter',
        'amount' => $session->amount_total / 100,
    ]);

    return response('OK', 200);
} 

    if ($session->mode === 'payment') {
        $plan = Plan::where('slug', 'pro')->first();
        if ($plan) {
            $user->plan_id = $plan->id;
            $user->subscription_status = 'active';
            $user->current_period_end = now()->addYear(); 
            $user->save();
        }

        \Log::info('✅ Pro plan activated for one year (one-time payment)', [
            'user' => $user->email,
            'plan' => 'pro',
        ]);

        return response('OK', 200);
    
}
        // Handle Upgrade to PRO (one-time charge)
if (!empty($session->metadata->upgrade) && $session->metadata->plan_id) {

    $plan = Plan::find($session->metadata->plan_id);

    if ($plan && strtolower($plan->slug) === 'pro') {
        $user->plan_id = $plan->id;
        $user->subscription_status = 'active';
        $user->current_period_end = now()->addYear();
        $user->save();

        \Log::info("User upgraded to PRO successfully", [
            'user' => $user->email,
            'plan' => 'pro'
        ]);

        return response('OK', 200);
    }
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