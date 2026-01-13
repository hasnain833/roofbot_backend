<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\Followup;
use App\Models\TenantAgentIntegration;
use App\Models\TenantAgent;
use App\Helper;
use Twilio\Rest\Client;
use SendGrid;
use SendGrid\Mail\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class SendFollowupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $followup;

    public function __construct(Followup $followup)
    {
        $this->followup = $followup;
    }

    public function handle()
    {
        $lead = $this->followup->lead;
        $tenantAgent = TenantAgent::where('tenant_id', $lead->tenant_id)->first();
        $tenant = $tenantAgent->tenant;

        // SMS via Twilio
        $twilioIntegration = TenantAgentIntegration::where('tenant_agent_id', $tenantAgent->id)
            ->where('provider', 'twilio')
            ->first();

        if ($twilioIntegration && $lead->phone) {
            try {
                $guzzleClient = new \GuzzleHttp\Client(['verify' => false]);
                $client = new Client($twilioIntegration->key, $twilioIntegration->secret, null, null, new \Twilio\Http\GuzzleClient($guzzleClient));
                $numbers = $client->incomingPhoneNumbers->read();
                $fromNumber = $numbers[0]->phoneNumber ?? env('TWILIO_PHONE');

                $template = \App\Models\TenantSmsTemplate::where('tenant_id', $lead->tenant_id)
                    ->where('type', 'followup')
                    ->first();

                $defaultBody = "Hi {first_name},We are following up on your interest in our services";

                $body = $template ? $template->message : $defaultBody;

                $body = str_replace('{first_name}', $lead->first_name, $body);
                $body = str_replace('{status}', $lead->status, $body);
                $body = str_replace('{note}', $this->followup->note ?? '', $body);

                $client->messages->create($lead->phone, [
                    'from' => $fromNumber,
                    'body' => $body,
                ]);

                Log::info('✅ FOLLOWUP SMS SENT', [
                    'lead_id' => $lead->id,
                    'phone' => $lead->phone,
                    'followup_id' => $this->followup->id,
                ]);

            } catch (\Exception $e) {
                Log::error('❌ FOLLOWUP SMS FAILED', [
                    'lead_id' => $lead->id,
                    'phone' => $lead->phone,
                    'followup_id' => $this->followup->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        // Email via SendGrid
        $sendgridIntegration = TenantAgentIntegration::where('tenant_agent_id', $tenantAgent->id)
            ->where('provider', 'sendgrid')
            ->first();

        if ($sendgridIntegration && $lead->email) {
            try {
                $template = \App\Models\TenantEmailTemplate::where('tenant_id', $lead->tenant_id)
                    ->where('type', 'followup')
                    ->first();

                $defaultSubject = "Follow-up";
                $defaultBody = "Hi {first_name}, we are following up on your interest in our services.";

                $subject = $template ? $template->subject : $defaultSubject;
                $body = $template ? $template->message : $defaultBody;

                $subject = str_replace('{status}', $lead->status, $subject);
                $body = str_replace('{first_name}', $lead->first_name, $body);
                $body = str_replace('{status}', $lead->status, $body);
                $body = str_replace('{note}', $this->followup->note ?? '', $body);

                $fromEmail = $sendgridIntegration->from_email
                    ?? 'no-reply@yourdefault.com';
                $fullName = $lead->first_name . ' ' . ($lead->last_name ?? '');

                $response = Http::withoutVerifying()
                    ->withHeaders(['Authorization' => 'Bearer ' . ($sendgridIntegration->key ?? env('SENDGRID_API_KEY'))])
                    ->post('https://api.sendgrid.com/v3/mail/send', [
                        'personalizations' => [
                            [
                                'to' => [['email' => $lead->email, 'name' => $fullName]],
                                'subject' => $subject,
                            ]
                        ],
                        'from' => ['email' => $fromEmail, 'name' => $tenant->company ?? 'Your Company'],
                        'content' => [
                            ['type' => 'text/plain', 'value' => $body]
                        ]
                    ]);

                if ($response->successful()) {
                    Log::info('✅ FOLLOWUP EMAIL SENT', [
                        'lead_id' => $lead->id,
                        'email' => $lead->email,
                        'followup_id' => $this->followup->id,
                    ]);
                } else {
                    Log::error('❌ FOLLOWUP EMAIL FAILED - SendGrid API Error', [
                        'lead_id' => $lead->id,
                        'email' => $lead->email,
                        'followup_id' => $this->followup->id,
                        'status_code' => $response->status(),
                        'error_body' => $response->body(),
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('❌ FOLLOWUP EMAIL FAILED - Exception', [
                    'lead_id' => $lead->id,
                    'email' => $lead->email,
                    'followup_id' => $this->followup->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        $this->followup->update(['done' => true]);
    }
}