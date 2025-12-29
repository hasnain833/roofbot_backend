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

        // SMS via Twilio
        $twilioIntegration = TenantAgentIntegration::where('tenant_agent_id', $tenantAgent->id)
            ->where('provider', 'twilio')
            ->first();

        if ($twilioIntegration && $lead->phone) {
            try {
                $client = new Client($twilioIntegration->key, $twilioIntegration->secret);
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

                Log::info('Followup SMS sent', ['lead_id' => $lead->id]);
            } catch (\Exception $e) {
                Log::error('Followup SMS failed: ' . $e->getMessage());
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

                $email = new Mail();
                $fromEmail = $sendgridIntegration->from_email
                    ?? 'no-reply@yourdefault.com';

                $email->setFrom(
                    $fromEmail,
                    $tenant->company ?? 'Your Company'
                );
                $email->setSubject($subject);
                $email->addTo($lead->email, $lead->first_name . ' ' . ($lead->last_name ?? ''));
                $email->addContent("text/plain", $body);

                $sendgrid = new SendGrid($sendgridIntegration->key ?? env('SENDGRID_API_KEY'));
                $sendgrid->send($email);

                Log::info('Followup email sent', ['lead_id' => $lead->id]);
            } catch (\Exception $e) {
                Log::error('Followup email failed: ' . $e->getMessage());
            }
        }

        $this->followup->update(['done' => true]);
    }
}