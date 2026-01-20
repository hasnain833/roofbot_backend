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

        if ($twilioIntegration) {
            try {
                $client = new Client($twilioIntegration->key, $twilioIntegration->secret, null, null);
                $numbers = $client->incomingPhoneNumbers->read();
                $fromNumber = $numbers[0]->phoneNumber ?? env('TWILIO_PHONE');
            } catch (\Exception $e) {
                $fromNumber = $tenant->phone ?? env('TWILIO_PHONE');
            }
        } else {
            $fromNumber = $tenant->phone ?? env('TWILIO_PHONE');
        }

        if ($twilioIntegration && $lead->phone) {
            try {
                $client = new Client($twilioIntegration->key, $twilioIntegration->secret, null, null);

                $template = \App\Models\TenantSmsTemplate::where('tenant_id', $lead->tenant_id)
                    ->where('type', 'followup')
                    ->first();

                $defaultBody = "Hi {first_name}, {company_name} here following up. Are you still interested in our services? Reply here or call {company_phone} any time!";

                $body = $template ? $template->message : $defaultBody;

                $body = str_replace('{first_name}', $lead->first_name, $body);
                $body = str_replace('{last_name}', $lead->last_name ?? '', $body);
                $body = str_replace('{service_type}', $lead->service_type_name ?? 'our services', $body);
                $body = str_replace('{status}', $lead->status, $body);
                $body = str_replace('{note}', $this->followup->note ?? '', $body);
                $body = str_replace('{company_name}', $tenant->company ?? '', $body);
                $body = str_replace('{company_domain}', $tenant->domain ?? '', $body);
                $body = str_replace('{company_phone_number}', $fromNumber, $body);
                $body = str_replace('{company_phone}', $fromNumber, $body);

                // Date Time support in case it's in the template
                $lastAppointment = \App\Models\Appointment::where('lead_id', $lead->id)->latest()->first();
                if ($lastAppointment) {
                    $visitorTz = $this->getLeadTimezone($lead);
                    $dateTimeFormatted = Carbon::parse($lastAppointment->start_time, 'UTC')->setTimezone($visitorTz)->format('M d, Y h:i A');
                    $body = str_replace('{date_time}', $dateTimeFormatted, $body);
                    $body = str_replace('{appointment_title}', $lastAppointment->title, $body);
                } else {
                    $body = str_replace('{date_time}', '', $body);
                    $body = str_replace('{appointment_title}', '', $body);
                }

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

                $defaultSubject = "Following up from {company_name}";
                $defaultBody = "Hi {first_name},\n\n{company_name} here. We are following up on your interest in {service_type}.\n\nDo you have any further questions? You can reach us by replying to this email or calling {company_phone}.\n\nBest,\n{company_name}\n{company_domain}";

                $subject = $template ? $template->subject : $defaultSubject;
                $body = $template ? $template->message : $defaultBody;

                // Variables for replacement
                $firstName = $lead->first_name;
                $status = $lead->status;
                $note = $this->followup->note ?? '';
                $companyName = $tenant->company ?? '';
                $companyDomain = $tenant->domain ?? '';
                $companyPhone = $tenant->phone ?? '';

                // Replacement in body
                $body = str_replace('{first_name}', $firstName, $body);
                $body = str_replace('{status}', $status, $body);
                $body = str_replace('{note}', $note, $body);
                $body = str_replace('{company_name}', $companyName, $body);
                $body = str_replace('{company_domain}', $companyDomain, $body);
                $body = str_replace('{company_phone_number}', $fromNumber ?? '', $body);
                $body = str_replace('{company_phone}', $fromNumber ?? '', $body);

                // Replacement in subject
                $subject = str_replace('{first_name}', $firstName, $subject);
                $subject = str_replace('{status}', $status, $subject);
                $subject = str_replace('{note}', $note, $subject);
                $subject = str_replace('{company_name}', $companyName, $subject);
                $subject = str_replace('{company_domain}', $companyDomain, $subject);
                $subject = str_replace('{company_phone_number}', $fromNumber ?? '', $subject);
                $subject = str_replace('{company_phone}', $fromNumber ?? '', $subject);

                $fromEmail = $sendgridIntegration->from_email
                    ?? 'no-reply@yourdefault.com';
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
                                'company_phone' => $fromNumber ?? ''
                            ])->render()]
                        ],
                        'tracking_settings' => [
                            'click_tracking' => ['enable' => false, 'enable_text' => false],
                            'open_tracking'  => ['enable' => false]
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