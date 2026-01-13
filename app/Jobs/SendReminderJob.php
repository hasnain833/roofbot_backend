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
use Carbon\Carbon;
use App\Helper;
use Twilio\Rest\Client;
use SendGrid;
use SendGrid\Mail\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

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
    $lead = $appointment->lead;
    $tenantAgent = TenantAgent::where('tenant_id', $appointment->tenant_id)->first();
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

            $template = \App\Models\TenantSmsTemplate::where('tenant_id', $appointment->tenant_id)
                ->where('type', 'reminder')
                ->first();

            $defaultBody = "Reminder: Your appointment is in 24 hours on {date_time}. Title: {appointment_title}.";

            $body = $template ? $template->message : $defaultBody;

            $body = str_replace('{first_name}', $lead->first_name, $body);
            $body = str_replace('{date_time}', Carbon::parse($appointment->start_time)->format('M d, Y h:i A'), $body);
            $body = str_replace('{appointment_title}', $appointment->title, $body);

            $client->messages->create($lead->phone, [
                'from' => $fromNumber,
                'body' => $body,
            ]);

            Log::info('✅ REMINDER SMS SENT', [
                'appointment_id' => $appointment->id,
                'lead_id' => $lead->id,
                'phone' => $lead->phone,
                'reminder_id' => $this->reminder->id,
            ]);

        } catch (\Exception $e) {
            Log::error('❌ REMINDER SMS FAILED', [
                'appointment_id' => $appointment->id,
                'lead_id' => $lead->id,
                'phone' => $lead->phone,
                'reminder_id' => $this->reminder->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    $sendgridIntegration = TenantAgentIntegration::where('tenant_agent_id', $tenantAgent->id)
        ->where('provider', 'sendgrid')
        ->first();

    if ($sendgridIntegration && $lead->email) {
        try {
            $template = \App\Models\TenantEmailTemplate::where('tenant_id', $appointment->tenant_id)
                ->where('type', 'reminder')
                ->first();

            $defaultSubject = "Appointment Reminder: 24 Hours Away";
            $defaultBody = "Hi {first_name},\n\nThis is a friendly reminder that your appointment is in 24 hours on {date_time}. Title: {appointment_title}.";

            $subject = $template ? $template->subject : $defaultSubject;
            $body = $template ? $template->message : $defaultBody;

            $body = str_replace('{first_name}', $lead->first_name, $body);
            $body = str_replace('{date_time}', Carbon::parse($appointment->start_time)->format('M d, Y h:i A'), $body);
            $body = str_replace('{appointment_title}', $appointment->title, $body);

            $fromEmail = $sendgridIntegration->from_email ?? 'no-reply@yourdefault.com';
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
                Log::info('✅ REMINDER EMAIL SENT', [
                    'appointment_id' => $appointment->id,
                    'lead_id' => $lead->id,
                    'email' => $lead->email,
                    'reminder_id' => $this->reminder->id,
                ]);
            } else {
                Log::error('❌ REMINDER EMAIL FAILED - SendGrid API Error', [
                    'appointment_id' => $appointment->id,
                    'lead_id' => $lead->id,
                    'email' => $lead->email,
                    'reminder_id' => $this->reminder->id,
                    'status_code' => $response->status(),
                    'error_body' => $response->body(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('❌ REMINDER EMAIL FAILED - Exception', [
                'appointment_id' => $appointment->id,
                'lead_id' => $lead->id,
                'email' => $lead->email,
                'reminder_id' => $this->reminder->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    $this->reminder->update(['done' => true]);
}
}