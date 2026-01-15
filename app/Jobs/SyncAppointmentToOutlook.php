<?php

namespace App\Jobs;

use App\Models\Appointment;
use App\Models\TenantAgent;
use App\Models\TenantAgentIntegration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncAppointmentToOutlook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected Appointment $appointment;
    protected bool $isUpdate;

    public function __construct(Appointment $appointment, bool $isUpdate = false)
    {
        $this->appointment = $appointment;
        $this->isUpdate = $isUpdate;
    }

    public function handle(): void
    {
        $appointment = $this->appointment;

        $tenantAgent = TenantAgent::where('tenant_id', $appointment->tenant_id)->first();
        if (!$tenantAgent) {
            Log::warning('Outlook Sync: TenantAgent not found');
            return;
        }

        $integration = TenantAgentIntegration::where('tenant_agent_id', $tenantAgent->id)
            ->where('provider', 'outlook')
            ->first();

        if (!$integration) {
            Log::info('Outlook Sync: Outlook not connected');
            return;
        }

        $accessToken = $this->getAccessToken($integration);
        if (!$accessToken) {
            Log::error('Outlook Sync: Failed to obtain access token');
            return;
        }

        $eventPayload = [
            'subject' => $appointment->title,
            'body' => [
                'contentType' => 'HTML',
                'content' => $appointment->description ?? '',
            ],
            'start' => [
                'dateTime' => $appointment->start_time->toIso8601String(),
                'timeZone' => 'UTC',
            ],
            'end' => [
                'dateTime' => $appointment->end_time->toIso8601String(),
                'timeZone' => 'UTC',
            ],
        ];

        try {
            if ($this->isUpdate && $appointment->outlook_event_id) {
                // 🔁 Update event
                Http::withToken($accessToken)
                    ->patch(
                        "https://graph.microsoft.com/v1.0/me/events/{$appointment->outlook_event_id}",
                        $eventPayload
                    );
            } else {
                // ➕ Create event
                Log::info('Outlook Sync: Attempting to create event', ['payload' => $eventPayload]);
                $response = Http::withToken($accessToken)
                    ->post('https://graph.microsoft.com/v1.0/me/events', $eventPayload);

                if ($response->successful()) {
                    $appointment->update([
                        'outlook_event_id' => $response->json('id'),
                    ]);
                    Log::info('Outlook Calendar synced successfully', ['appointment_id' => $appointment->id]);
                } else {
                    Log::error('Outlook Sync: Create event failed', [
                        'status' => $response->status(),
                        'response' => $response->body()
                    ]);
                }
            }

        } catch (\Exception $e) {
            Log::error('Outlook Sync failed', [
                'appointment_id' => $appointment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function getAccessToken(TenantAgentIntegration $integration): ?string
    {
        if (!$integration->secret) {
            return $integration->key;
        }

        $response = Http::asForm()->post(
            'https://login.microsoftonline.com/common/oauth2/v2.0/token',
            [
                'client_id' => env('OUTLOOK_CLIENT_ID'),
                'client_secret' => env('OUTLOOK_CLIENT_SECRET'),
                'grant_type' => 'refresh_token',
                'refresh_token' => $integration->secret,
                'scope' => 'offline_access Calendars.ReadWrite User.Read',
            ]
        );

        if (!$response->successful()) {
            return null;
        }

        $token = $response->json();

        $integration->update([
            'key' => $token['access_token'],
            'meta' => json_encode([
                'expires_in' => $token['expires_in'],
                'updated_at' => now(),
            ]),
        ]);

        return $token['access_token'];
    }
}
