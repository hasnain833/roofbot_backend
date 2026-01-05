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
            $client->setClientId(env('GOOGLE_CLIENT_ID'));
            $client->setClientSecret(env('GOOGLE_CLIENT_SECRET'));
            $client->setAccessType('offline');
            $client->setAccessToken([
                'access_token' => $integration->key,
            'expires_in'   => $meta['expires_in'] ?? 3600,
            'created'      => $meta['created'] ?? time(),
 ]);


            // Refresh token if expired
          if ($client->isAccessTokenExpired() && $integration->secret) {
    $newToken = $client->fetchAccessTokenWithRefreshToken($integration->secret);

    if (!isset($newToken['access_token'])) {
        throw new \Exception('Failed to refresh Google access token');
    }

$meta = json_decode($integration->meta ?? '{}', true);

$integration->update([
    'key' => $newToken['access_token'],
    'meta' => json_encode(array_merge($meta, [
        'expires_in' => $newToken['expires_in'] ?? 3600,
        'created' => time(),
    ])),
]);

}


            $service = new Calendar($client);

            $eventData = [
                'summary' => $appointment->title,
                'description' => $appointment->description ?? '',
                'start' => ['dateTime' => $appointment->start_time->toRfc3339String(), 'timeZone' => 'Europe/London'],
                'end' => ['dateTime' => $appointment->end_time->toRfc3339String(), 'timeZone' => 'Europe/London'],
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