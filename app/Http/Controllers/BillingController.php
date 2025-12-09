<?php
namespace App\Http\Controllers;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Cashier\Checkout;
use Stripe\Stripe;
use Stripe\Checkout\Session;
use Laravel\Cashier\Subscription; // Import for local model

class BillingController extends Controller
{
    public function plans()
    {
        return response()->json([
            'success' => true,
            'plans' => Plan::all(),
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
            $priceId = $plan->stripe_yearly_price_id ?? $plan->stripe_monthly_price_id;
            $lineItems = [
                [
                    'price' => $priceId,
                    'quantity' => 1,
                ],
            ];
            if ($plan->setup_fee > 0) {
                $lineItems[] = [
                    'price_data' => [
                        'currency' => 'usd',
                        'product_data' => [
                            'name' => $plan->name . ' Setup Fee',
                            'description' => 'One-time setup fee for new customers',
                        ],
                        'unit_amount' => intval($plan->setup_fee * 100),
                    ],
                    'quantity' => 1,
                ];
            }
            $checkout = \Stripe\Checkout\Session::create([
                'customer_email' => $user->email,
                'line_items' => $lineItems,
                'mode' => 'subscription',
                'success_url' => env('FRONTEND_URL') . '/signin?paid=1',
                'cancel_url' => env('FRONTEND_URL') . '/signup?cancel=1',
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
        $priceId = $plan->stripe_yearly_price_id ?? $plan->stripe_monthly_price_id;
        $user = $request->user();
        $subscription = $user->newSubscription('default', $priceId)
            ->trialDays(0)
            ->create();
        $isYearly = str_contains($priceId, 'year');
        $user->plan_id = $plan->id;
        $user->subscription_status = 'active';
         $user->current_period_end = $isYearly ? now()->addYear() : now()->addMonth();
        $user->save();
        $checkout = $user->checkout($priceId, [
            'success_url' => env('FRONTEND_URL') . '/dashboard/profile?subscribed=1',
            'cancel_url' => env('FRONTEND_URL') . '/dashboard/profile?cancel=1',
        ]);
        return response()->json(['url' => $checkout->url]);
    }

    // Cancel subscription
    public function cancelSubscription(Request $request)
    {
        $subscription = $request->user()->subscription('default');
        $subscription->cancel();
        return response()->json([
            'message' => 'Subscription will end at the current period.',
            'stripe_status' => $subscription->stripe_status,
            'ends_at' => $subscription->ends_at?->toDateString(),
        ]);
    }

    // Existing user upgrade
    public function upgradeSubscription(Request $request)
    {
        $request->validate(['plan_id' => 'required|exists:plans,id']);
        $plan = Plan::findOrFail($request->plan_id);
        $priceId = $plan->stripe_yearly_price_id ?? $plan->stripe_monthly_price_id;
        $user = $request->user();
        $user->subscription('default')->swap($priceId);
        $isYearly = str_contains($priceId, 'year');
        $user->plan_id = $plan->id;
        $user->current_period_end = $isYearly ? now()->addYear() : now()->addMonth();
        $user->save();
        return response()->json([
            'message' => 'Plan upgraded',
            'redirect' => '/dashboard/profile?upgraded=1',
        ]);
    }

    // Stripe webhook
    public function stripeWebhook(Request $request)
    {
        \Stripe\Stripe::setApiKey(env('STRIPE_SECRET'));
        $payload = $request->getContent();
        $sig = $request->header('Stripe-Signature');
        $secret = env('STRIPE_WEBHOOK_SECRET');
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
    \Stripe\Stripe::setApiKey(env('STRIPE_SECRET'));

    $session = $event->data->object;

    try {
        $session = \Stripe\Checkout\Session::retrieve($session->id, [
            'expand' => [
                'line_items',
                'line_items.data.price', 
                'subscription'
            ]
        ]);
    } catch (\Exception $e) {
        \Log::warning('Failed to expand checkout session: ' . $e->getMessage());
        return response('OK', 200);
    }

    $email = $session->customer_details->email ?? null;
    if (!$email) {
        \Log::warning('No email in checkout session', ['session_id' => $session->id]);
        return response('OK', 200);
    }

    $user = User::where('email', $email)->first();
    if (!$user) {
        \Log::warning('User not found', ['email' => $email]);
        return response('OK', 200);
    }

    $priceId = null;
    if (!empty($session->line_items->data)) {
       foreach ($session->line_items->data as $item) {
            if (isset($item->price->id) && $item->price->type === 'recurring') {
                $priceId = $item->price->id;
                break;
            }
        }
    }

    if (!$priceId && $session->subscription) {
        try {
            $subObj = is_object($session->subscription)
                ? $session->subscription
                : \Stripe\Subscription::retrieve($session->subscription, ['expand' => ['items.data.price']]);
            if (!empty($subObj->items->data[0]->price->id)) {
                $priceId = $subObj->items->data[0]->price->id;
            }
        } catch (\Exception $e) {
            \Log::warning('Failed to get price from subscription: ' . $e->getMessage());
        }
    }

    if (!$priceId) {
        \Log::warning('No price ID found anywhere', ['session_id' => $session->id]);
       
    }

    $plan = null;
    if ($priceId) {
        $plan = Plan::where('stripe_monthly_price_id', $priceId)
                    ->orWhere('stripe_yearly_price_id', $priceId)
                    ->first();
        if (!$plan) {
            \Log::warning('Plan not found for price ID', ['price_id' => $priceId]);
        }
    }

   $currentPeriodEnd = now()->addMonth(); 
$currentPeriodStart = now();

if ($session->subscription) {
    try {
        $subObj = is_object($session->subscription)
            ? $session->subscription
            : \Stripe\Subscription::retrieve($session->subscription);

        if (isset($subObj->current_period_end) && $subObj->current_period_end > 0) {
            $currentPeriodEnd = \Carbon\Carbon::createFromTimestamp($subObj->current_period_end);
            $currentPeriodStart = \Carbon\Carbon::createFromTimestamp($subObj->current_period_start ?? time());
            \Log::info('Used correct Stripe period_end', ['end' => $currentPeriodEnd->toDateTimeString()]);
        }
    } catch (\Exception $e) {
        \Log::warning('Failed to get period from subscription object: ' . $e->getMessage());
    }
}
if ($plan && $plan->stripe_yearly_price_id && $priceId === $plan->stripe_yearly_price_id) {
    $expectedEnd = now()->addYear();
    if ($currentPeriodEnd->lessThan($expectedEnd->copy()->subDays(30))) {
        $currentPeriodEnd = $expectedEnd;
        \Log::info('Forced correct yearly period_end for Pro Plan');
    }
}
    $user->subscription_status = 'active';
    $user->stripe_customer_id = $session->customer;
    $user->current_period_end = $currentPeriodEnd;
    if ($plan) {
        $user->plan_id = $plan->id;
    }
    $user->save();

    $subId = is_string($session->subscription) ? $session->subscription : ($subObj->id ?? null);
    if ($subId) {
        try {
            $exists = \DB::table('subscriptions')
                ->where('user_id', $user->id)
                ->where('stripe_id', $subId)
                ->exists();

            if (!$exists) {
                \DB::table('subscriptions')->insert([
                    'user_id' => $user->id,
                    'stripe_id' => $subId,
                    'stripe_status' => 'active',
                    'stripe_price' => $priceId,
                    'quantity' => 1,
                    'current_period_start' => $currentPeriodStart,
                    'current_period_end' => $currentPeriodEnd,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        } catch (\Exception $e) {
            \Log::error('Failed to save local subscription: ' . $e->getMessage());
        }
    }

    \Log::info('✅ Subscription activated successfully', [
        'user' => $user->email,
        'plan' => $plan ? $plan->name : 'Unknown (priceId: ' . $priceId . ')',
        'plan_id' => $plan ? $plan->id : 'Not set',
        'period_end' => $currentPeriodEnd->toDateTimeString(),
    ]);

    return response('OK', 200);
}
        if (
            in_array($event->type, [
                'customer.subscription.created',
                'customer.subscription.updated',
                'customer.subscription.deleted'
            ])
        ) {
            $sub = $event->data->object;
            $user = User::where('stripe_customer_id', $sub->customer)->first();
            if (!$user)
                return response('OK', 200);
            $localSub = $user->subscription('default');
            if ($localSub) {
                if ($event->type === 'customer.subscription.deleted') {
                    $localSub->stripe_status = 'canceled';
                    $localSub->ends_at = \Carbon\Carbon::createFromTimestamp($sub->current_period_end);
                    $localSub->save();
                    $user->subscription_status = 'canceled';
                } else {
                    $localSub->stripe_status = $sub->status;
                    $localSub->current_period_end = \Carbon\Carbon::createFromTimestamp($sub->current_period_end);
                    $localSub->stripe_price = $sub->items->data[0]->price->id ?? $localSub->stripe_price;
                    $localSub->save();
                    $user->subscription_status = $sub->status === 'active' ? 'active' : 'pending';
                }
            }
            $user->current_period_end = \Carbon\Carbon::createFromTimestamp($sub->current_period_end);
         
            $currentPriceId = $sub->items->data[0]->price->id ?? null;
            if ($currentPriceId) {
                $plan = Plan::where('stripe_monthly_price_id', $currentPriceId)
                    ->orWhere('stripe_yearly_price_id', $currentPriceId)
                    ->first();
                if ($plan) {
                    $user->plan_id = $plan->id;
                }
            }
            $user->save();
            \Log::info("🔄 Subscription event handled: {$event->type}", [
                'user' => $user->email,
                'status' => $user->subscription_status,
            ]);
        }
        return response('OK', 200);
    }
}