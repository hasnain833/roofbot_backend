<?php

namespace App\Jobs;

use App\Models\Appointment;
use App\Models\TenantAgent;
use App\Models\TenantAgentIntegration;
use Google\Client;
use Google\Service\Calendar;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncAppointmentToGoogle implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $appointment;
    protected $isUpdate;

    public function __construct(Appointment $appointment, $isUpdate = false)
    {
        $this->appointment = $appointment;
        $this->isUpdate = $isUpdate;
    }

    public function handle(): void
    {
        $appointment = $this->appointment;
        $tenantId = $appointment->tenant_id;

        // Get TenantAgent for this tenant
        $tenantAgent = TenantAgent::where('tenant_id', $tenantId)->first();
        if (!$tenantAgent) {
            \Log::warning('TenantAgent missing for tenant ID: ' . $tenantId);
            return;
        }

        // Get Google integration
        $integration = TenantAgentIntegration::where('tenant_agent_id', $tenantAgent->id)
            ->where('provider', 'google')
            ->first();

        if (!$integration || !$integration->key) {
            \Log::warning('Google integration or access token missing for tenant agent ID: ' . $tenantAgent->id);
            return;
        }

        try {
            $client = new Client();
            $client->setAccessToken($integration->key);

            // Refresh token if expired
            if ($client->isAccessTokenExpired() && $integration->secret) {
                $client->fetchAccessTokenWithRefreshToken($integration->secret);
                $newAccessToken = $client->getAccessToken();

                // Update integration with new access token
                $integration->update([
                    'key' => $newAccessToken['access_token'],
                    'meta' => json_encode([
                        'expires_in' => $newAccessToken['expires_in'] ?? null,
                        'updated_at' => now()->toDateTimeString(),
                    ]),
                ]);
            }

            $service = new Calendar($client);

            $eventData = [
                'summary' => $appointment->title,
                'description' => $appointment->description ?? '',
                'start' => ['dateTime' => $appointment->start_time->toRfc3339String(), 'timeZone' => 'Asia/Karachi'],
                'end' => ['dateTime' => $appointment->end_time->toRfc3339String(), 'timeZone' => 'Asia/Karachi'],
            ];

            if ($this->isUpdate && $appointment->google_event_id) {
                $service->events->update('primary', $appointment->google_event_id, new Calendar\Event($eventData));
            } else {
                $event = $service->events->insert('primary', new Calendar\Event($eventData));
                $appointment->update(['google_event_id' => $event->id]);
            }

            \Log::info('Google Calendar sync successful for appointment ID: ' . $appointment->id);

        } catch (\Exception $e) {
            \Log::error('Google Calendar Sync Failed for appointment ID: ' . $appointment->id . ' - ' . $e->getMessage());
        }
    }
}