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
            $client = new Client($twilioIntegration->key, $twilioIntegration->secret, null, null);
            $numbers = $client->incomingPhoneNumbers->read();
            $fromNumber = $numbers[0]->phoneNumber ?? env('TWILIO_PHONE');

            $template = \App\Models\TenantSmsTemplate::where('tenant_id', $appointment->tenant_id)
                ->where('type', 'reminder')
                ->first();

            $defaultBody = "Reminder: Your appointment with {company_name} is in 24 hours on {date_time}. Title: {appointment_title}.";

            $body = $template ? $template->message : $defaultBody;

            $body = str_replace('{first_name}', $lead->first_name, $body);
            $body = str_replace('{last_name}', $lead->last_name ?? '', $body);
            $body = str_replace('{service_type}', $lead->service_type_name ?? 'our services', $body);
            // Deduce timezone from country or default to UTC
            $visitorTz = $this->getLeadTimezone($lead);
            $body = str_replace('{date_time}', Carbon::parse($appointment->start_time, 'UTC')->setTimezone($visitorTz)->format('M d, Y h:i A'), $body);
            $body = str_replace('{appointment_title}', $appointment->title, $body);
            $body = str_replace('{company_name}', $tenant->company ?? '', $body);
            $body = str_replace('{company_domain}', $tenant->domain ?? '', $body);
            $body = str_replace('{company_phone_number}', $tenant->phone ?? '', $body);
            $body = str_replace('{company_phone}', $tenant->phone ?? '', $body);

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
            $defaultBody = "Hi {first_name},\n\nThis is a friendly reminder that your appointment with {company_name} is in 24 hours on {date_time}.\nTitle: {appointment_title}.\n\nSee you soon!";

            $subject = $template ? $template->subject : $defaultSubject;
            $body = $template ? $template->message : $defaultBody;

            // Variables for replacement
            $firstName = $lead->first_name;
            // Deduce timezone from country or default to UTC
            $visitorTz = $this->getLeadTimezone($lead);
            $dateTime = Carbon::parse($appointment->start_time, 'UTC')->setTimezone($visitorTz)->format('M d, Y h:i A');
            $appointmentTitle = $appointment->title;
            $companyName = $tenant->company ?? '';
            $companyDomain = $tenant->domain ?? '';
            $companyPhone = $tenant->phone ?? '';

            // Replacement in body
            $body = str_replace('{first_name}', $firstName, $body);
            $body = str_replace('{date_time}', $dateTime, $body);
            $body = str_replace('{appointment_title}', $appointmentTitle, $body);
            $body = str_replace('{company_name}', $companyName, $body);
            $body = str_replace('{company_domain}', $companyDomain, $body);
            $body = str_replace('{company_phone_number}', $companyPhone, $body);
            $body = str_replace('{company_phone}', $companyPhone, $body);

            // Replacement in subject
            $subject = str_replace('{first_name}', $firstName, $subject);
            $subject = str_replace('{date_time}', $dateTime, $subject);
            $subject = str_replace('{appointment_title}', $appointmentTitle, $subject);
            $subject = str_replace('{company_name}', $companyName, $subject);
            $subject = str_replace('{company_domain}', $companyDomain, $subject);
            $subject = str_replace('{company_phone_number}', $companyPhone, $subject);
            $subject = str_replace('{company_phone}', $companyPhone, $subject);

            $fromEmail = $sendgridIntegration->from_email ?? 'no-reply@yourdefault.com';
            $fullName = $lead->first_name . ' ' . ($lead->last_name ?? '');

            $response = Http::withHeaders(['Authorization' => 'Bearer ' . ($sendgridIntegration->key ?? env('SENDGRID_API_KEY'))])
                ->post('https://api.sendgrid.com/v3/mail/send', [
                    'personalizations' => [
                        [
                            'to' => [['email' => $lead->email, 'name' => $fullName]],
                            'subject' => $subject,
                        ]
                    ],
                    'from' => ['email' => $fromEmail, 'name' => $companyName],
                    'content' => [
                        ['type' => 'text/html', 'value' => view('emails.layout', [
                            'subject' => $subject,
                            'body' => $body,
                            'company_name' => $tenant->company ?? 'Our Company',
                            'company_domain' => $tenant->domain ?? '',
                            'company_phone' => $tenant->phone ?? ''
                        ])->render()]
                    ],
                    'tracking_settings' => [
                        'click_tracking' => ['enable' => false, 'enable_text' => false],
                        'open_tracking'  => ['enable' => false]
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

private function getLeadTimezone($lead): string
{
    // Simple country-to-timezone mapping for common countries
    $countryTimezones = [
        'Pakistan' => 'Asia/Karachi',
        'United States' => 'America/New_York',
        'USA' => 'America/New_York',
        'United Kingdom' => 'Europe/London',
        'UK' => 'Europe/London',
        'Canada' => 'America/Toronto',
        'Australia' => 'Australia/Sydney',
        'India' => 'Asia/Kolkata',
    ];

    return $countryTimezones[$lead->country] ?? 'UTC';
}
}