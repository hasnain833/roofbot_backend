<?php

namespace App\Jobs;

use App\Models\Appointment;
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
        $tenant = $appointment->tenant;

        if (!$tenant) {
            \Log::warning('Cannot delete Google event: Tenant missing');
            return;
        }

        if (!$appointment->google_event_id) {
            \Log::warning('Cannot delete Google event: Appointment missing google_event_id', [
                'appointment_id' => $appointment->id,
            ]);
            return;
        }

        if (!$tenant->google_access_token) {
            \Log::warning('Cannot delete Google event: Tenant missing google_access_token', [
                'tenant_id' => $tenant->id,
            ]);
            return;
        }

        try {
            $client = new Client();
            $client->setAccessToken($tenant->google_access_token);

            // Optional refresh token logic
            if ($client->isAccessTokenExpired() && $tenant->google_refresh_token) {
                $client->fetchAccessTokenWithRefreshToken($tenant->google_refresh_token);
                $tenant->update(['google_access_token' => $client->getAccessToken()]);
            }

            $service = new Calendar($client);
            $service->events->delete('primary', $appointment->google_event_id);

            \Log::info("Google event deleted successfully", [
                'appointment_id' => $appointment->id,
                'google_event_id' => $appointment->google_event_id,
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to delete Google event: ' . $e->getMessage(), [
                'appointment_id' => $appointment->id,
                'google_event_id' => $appointment->google_event_id,
            ]);
        }
    }
}
