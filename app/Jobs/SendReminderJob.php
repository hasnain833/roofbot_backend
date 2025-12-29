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

    // SMS via Twilio
    $twilioIntegration = TenantAgentIntegration::where('tenant_agent_id', $tenantAgent->id)
        ->where('provider', 'twilio')
        ->first();

    if ($twilioIntegration && $lead->phone) {
        try {
            $client = new Client($twilioIntegration->key, $twilioIntegration->secret);
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

            Log::info('Reminder SMS sent', ['appointment_id' => $appointment->id]);
        } catch (\Exception $e) {
            Log::error('Reminder SMS failed: ' . $e->getMessage());
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

            Log::info('Reminder email sent', ['appointment_id' => $appointment->id]);
        } catch (\Exception $e) {
            Log::error('Reminder email failed: ' . $e->getMessage());
        }
    }

    $this->reminder->update(['done' => true]);
}
}