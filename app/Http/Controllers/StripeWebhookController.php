<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Http\Controllers\WebhookController as CashierWebhookController;
use Carbon\Carbon;

class StripeWebhookController extends CashierWebhookController
{
    public function handleWebhook(Request $request)
    {
        Log::info("Stripe Webhook RECEIVED", ['payload' => $request->all()]);
        return parent::handleWebhook($request);
    }

    public function handleCheckoutSessionCompleted(array $payload)
    {
        Log::info("CheckoutSessionCompleted received");
        $this->syncUserData($payload);
    }

    public function handleCustomerSubscriptionUpdated(array $payload)
    {
        Log::info("SubscriptionUpdated received", $payload);

        $object = $payload['data']['object'];

        if (
            $object['status'] === 'active' &&
            !empty($object['trial_end']) &&
            $object['trial_end'] < time()
        ) {
            $customerId = $object['customer'];
            $user = User::where('stripe_id', $customerId)->first();
            if (!$user) return;

            $sessionId = $object['latest_invoice'];
            $invoice = $user->stripe()->invoices->retrieve($sessionId);

            $setupAllowed = $invoice->lines->data[0]->metadata->should_add_setup_fee ?? 'no';

            if ($setupAllowed === 'yes') {
                $user->invoiceFor(
                    'Starter Setup Fee',
                    $user->plan->setup_fee * 100
                );

                Log::info("Setup fee charged after trial for user {$user->id}");
            }
        }

        parent::handleCustomerSubscriptionUpdated($payload);
    }
    public function handleCustomerSubscriptionDeleted(array $payload)
{
    Log::info("Subscription deleted event processed", $payload);
    return parent::handleCustomerSubscriptionDeleted($payload);
}


    private function syncUserData(array $payload)
    {
        $object = $payload['data']['object'] ?? null;
        if (!$object) return;

        $customerId = $object['customer'] ?? null;
        if (!$customerId) return;

        $user = User::where('stripe_id', $customerId)->first();
        if (!$user) return;

        $subscriptionId = $object['subscription'] ?? $object['id'] ?? null;
        if (!$subscriptionId) return;

        $subscription = $user->subscription('default');

        if (!$subscription) {

            $stripeSub = $user->stripe()->subscriptions->retrieve($subscriptionId);

            $trialEndsAt = $stripeSub->trial_end
                ? Carbon::createFromTimestamp($stripeSub->trial_end)
                : null;

            $subscription = $user->subscriptions()->create([
                'type' => 'default',
                'stripe_id' => $stripeSub->id,
                'stripe_status' => $stripeSub->status,
                'stripe_price' => $stripeSub->items->data[0]->price->id ?? null,
                'quantity' => $stripeSub->items->data[0]->quantity ?? 1,
                'trial_ends_at' => $trialEndsAt,  
                'ends_at' => $stripeSub->current_period_end
                    ? Carbon::createFromTimestamp($stripeSub->current_period_end)
                    : null,
            ]);

            // Assign plan
            $priceId = $stripeSub->items->data[0]->price->id ?? null;
            if ($priceId) {
                $plan = Plan::where('stripe_monthly_price_id', $priceId)
                    ->orWhere('stripe_yearly_price_id', $priceId)
                    ->first();
                if ($plan) $user->plan_id = $plan->id;
            }

        } else {

            $stripeSub = $subscription->asStripeSubscription();

            // ⬇ NEW TRIAL LOGIC ADDED
            $subscription->trial_ends_at = $stripeSub->trial_end
                ? Carbon::createFromTimestamp($stripeSub->trial_end)
                : null;

            $subscription->stripe_status = $stripeSub->status;
            $subscription->ends_at = $stripeSub->current_period_end
                ? Carbon::createFromTimestamp($stripeSub->current_period_end)
                : null;

            $subscription->save();
        }

        // UPDATE USER FIELDS
        $stripeSub = $subscription->asStripeSubscription();

        $user->subscription_status = $stripeSub->status;

        $user->trial_ends_at = $stripeSub->trial_end
            ? Carbon::createFromTimestamp($stripeSub->trial_end)
            : null;

        $user->current_period_end = $stripeSub->current_period_end
            ? Carbon::createFromTimestamp($stripeSub->current_period_end)
            : null;

        $user->save();

        Log::info("Subscription data synced successfully", [
            "user_id" => $user->id,
            "email" => $user->email,
            "plan_id" => $user->plan_id,
            "subscription_status" => $user->subscription_status,
            "trial_ends_at" => $user->trial_ends_at?->format('Y-m-d H:i:s'),
            "current_period_end" => $user->current_period_end?->format('Y-m-d H:i:s'),
            "stripe_id" => $user->stripe_id,
            "event" => $payload['type'] ?? null,
        ]);
    }
}
