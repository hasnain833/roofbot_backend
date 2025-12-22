<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\Reminder;
use App\Models\TenantAgentIntegration;
use App\Models\TenantAgent;
use App\Helper;
use Twilio\Rest\Client;
use SendGrid;
use SendGrid\Mail\Mail;
use Illuminate\Support\Facades\Log;

class SendReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $reminder;

    public function __construct(Reminder $reminder)
    {
        $this->reminder = $reminder;
    }

    public function handle()
    {
        $appointment = $this->reminder->appointment;
        $lead = $this->reminder->lead;
        $tenantId = $appointment->tenant_id;

        $tenantAgent = TenantAgent::where('tenant_id', $appointment->tenant_id)->first();

        // SMS via Twilio
        $twilioIntegration = TenantAgentIntegration::where('tenant_agent_id', $tenantAgent->id)
            ->where('provider', 'twilio')
            ->first();

        if ($twilioIntegration && $lead->phone) {
            try {
                $client = new Client($twilioIntegration->key, $twilioIntegration->secret);
                $numbers = $client->incomingPhoneNumbers->read();
                $fromNumber = $numbers[0]->phoneNumber ?? env('TWILIO_PHONE');
                $body = "Reminder: Your appointment is in 24 hours on {$appointment->start_time}. Title: {$appointment->title}."; // Customize

                $client->messages->create($lead->phone, [
                    'from' => $fromNumber,
                    'body' => $body,
                ]);

                Log::info('Reminder SMS sent', ['appointment_id' => $appointment->id]);
            } catch (\Exception $e) {
                Log::error('Reminder SMS failed: ' . $e->getMessage());
            }
        }

        // Email via SendGrid
        $sendgridIntegration = TenantAgentIntegration::where('tenant_agent_id', $tenantAgent->id)
            ->where('provider', 'sendgrid')
            ->first();

        if ($sendgridIntegration && $lead->email) {
            try {
                $email = new Mail();
                $email->setFrom(env('MAIL_FROM_ADDRESS', 'no-reply@example.com'), env('MAIL_FROM_NAME', $appointment->tenant->company));
                $email->setSubject("Appointment Reminder: 24 Hours Away");
                $email->addTo($lead->email, "{$lead->first_name} {$lead->last_name}");
                $email->addContent("text/plain", "Hi {$lead->first_name}, your appointment is in 24 hours on {$appointment->start_time}. Title: {$appointment->title}."); // Customize

                $sendgrid = new SendGrid($sendgridIntegration->key ?? env('SENDGRID_API_KEY'));
                $sendgrid->send($email);

                Log::info('Reminder email sent', ['appointment_id' => $appointment->id]);
            } catch (\Exception $e) {
                Log::error('Reminder email failed: ' . $e->getMessage());
            }
        }

        $this->reminder->update(['done' => true]);
    }
}