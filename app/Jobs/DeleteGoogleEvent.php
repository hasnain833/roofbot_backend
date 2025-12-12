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

class DeleteGoogleEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $appointment;

    public function __construct(Appointment $appointment)
    {
        $this->appointment = $appointment;
    }

    public function handle(): void
    {
        $appointment = $this->appointment;
        $tenantId = $appointment->tenant_id;

        if (!$appointment->google_event_id) {
            \Log::info('No Google event to delete (missing google_event_id)', [
                'appointment_id' => $appointment->id,
            ]);
            return;
        }

        // Get TenantAgent
        $tenantAgent = TenantAgent::where('tenant_id', $tenantId)->first();
        if (!$tenantAgent) {
            \Log::warning('DeleteGoogleEvent: TenantAgent missing for tenant ID: ' . $tenantId);
            return;
        }

        // Get Google integration
        $integration = TenantAgentIntegration::where('tenant_agent_id', $tenantAgent->id)
            ->where('provider', 'google')
            ->first();

        if (!$integration || !$integration->key) {
            \Log::warning('DeleteGoogleEvent: Google access token missing', [
                'tenant_agent_id' => $tenantAgent->id,
            ]);
            return;
        }

        try {
            $client = new Client();
            $client->setAccessToken($integration->key);

            // Refresh if expired
            if ($client->isAccessTokenExpired() && $integration->secret) {
                $client->fetchAccessTokenWithRefreshToken($integration->secret);
                $newToken = $client->getAccessToken();

                $integration->update([
                    'key' => $newToken['access_token'] ?? $newToken,
                ]);
            }

            $service = new Calendar($client);
            $service->events->delete('primary', $appointment->google_event_id);

            \Log::info('Google Calendar event deleted successfully', [
                'appointment_id' => $appointment->id,
                'google_event_id' => $appointment->google_event_id,
            ]);

            // Optional: Clear the ID after deletion
            $appointment->update(['google_event_id' => null]);

        } catch (\Exception $e) {
            \Log::error('Failed to delete Google Calendar event', [
                'appointment_id' => $appointment->id,
                'google_event_id' => $appointment->google_event_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}