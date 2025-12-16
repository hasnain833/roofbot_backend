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

class DeleteOutlookEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected Appointment $appointment;

    public function __construct(Appointment $appointment)
    {
        $this->appointment = $appointment;
    }

    public function handle(): void
    {
        $appointment = $this->appointment;

        if (!$appointment->outlook_event_id) {
            Log::info('No Outlook event to delete (missing outlook_event_id)', [
                'appointment_id' => $appointment->id,
            ]);
            return;
        }

        $tenantAgent = TenantAgent::where('tenant_id', $appointment->tenant_id)->first();
        if (!$tenantAgent) {
            Log::warning('DeleteOutlookEvent: TenantAgent not found', [
                'tenant_id' => $appointment->tenant_id,
            ]);
            return;
        }

        $integration = TenantAgentIntegration::where('tenant_agent_id', $tenantAgent->id)
            ->where('provider', 'outlook')
            ->first();

        if (!$integration || !$integration->key) {
            Log::warning('DeleteOutlookEvent: Outlook access token missing', [
                'tenant_agent_id' => $tenantAgent->id,
            ]);
            return;
        }

        $accessToken = $integration->key;

        try {
            $response = Http::withToken($accessToken)
                ->delete("https://graph.microsoft.com/v1.0/me/events/{$appointment->outlook_event_id}");

            if ($response->status() === 401 && $integration->secret) {
                // Refresh token
                $tokenResponse = Http::asForm()->post(
                    'https://login.microsoftonline.com/common/oauth2/v2.0/token',
                    [
                        'client_id' => config('services.outlook.client_id'),
                        'client_secret' => config('services.outlook.client_secret'),
                        'grant_type' => 'refresh_token',
                        'refresh_token' => $integration->secret,
                        'scope' => 'https://graph.microsoft.com/.default',
                    ]
                );

                if ($tokenResponse->successful()) {
                    $newAccessToken = $tokenResponse->json('access_token');

                    $integration->update([
                        'key' => $newAccessToken,
                    ]);

                    // Retry delete
                    Http::withToken($newAccessToken)
                        ->delete("https://graph.microsoft.com/v1.0/me/events/{$appointment->outlook_event_id}");
                }
            }

            Log::info('Outlook Calendar event deleted successfully', [
                'appointment_id' => $appointment->id,
                'outlook_event_id' => $appointment->outlook_event_id,
            ]);

            // Clear stored ID
            $appointment->update(['outlook_event_id' => null]);

        } catch (\Exception $e) {
            Log::error('Failed to delete Outlook Calendar event', [
                'appointment_id' => $appointment->id,
                'outlook_event_id' => $appointment->outlook_event_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
