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
        $tenantId = $lead->tenant_id;

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
                $body = "Followup for {$lead->first_name}: Status is {$lead->status}. {$this->followup->note}";

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
                $email = new Mail();
                $email->setFrom(env('MAIL_FROM_ADDRESS', 'no-reply@example.com'), env('MAIL_FROM_NAME', $lead->tenant->company));
                $email->setSubject("Followup Reminder: Lead Status {$lead->status}");
                $email->addTo($lead->email, "{$lead->first_name} {$lead->last_name}");
                $email->addContent("text/plain", "Hi {$lead->first_name}, followup for your lead (status: {$lead->status}). {$this->followup->note}"); 

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