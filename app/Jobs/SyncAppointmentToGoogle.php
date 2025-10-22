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
        $tenant = $appointment->tenant;

        if (!$tenant || !$tenant->google_access_token) {
            \Log::warning('Google token missing for tenant ID: ' . $tenant->id ?? 'unknown');
            return;
        }

        try {
            $client = new Client();
            $client->setAccessToken($tenant->google_access_token);

            // ✅ Refresh token if expired
            if ($client->isAccessTokenExpired() && $tenant->google_refresh_token) {
                $client->fetchAccessTokenWithRefreshToken($tenant->google_refresh_token);
                $tenant->update(['google_access_token' => $client->getAccessToken()]);
            }

            $service = new Calendar($client);

            $eventData = [
                'summary' => $appointment->title,
                'description' => $appointment->description ?? '',
                'start' => ['dateTime' => $appointment->start_time->toRfc3339String(), 'timeZone' => 'UTC'],
                'end' => ['dateTime' => $appointment->end_time->toRfc3339String(), 'timeZone' => 'UTC'],
            ];

            if ($this->isUpdate && $appointment->google_event_id) {
                $service->events->update('primary', $appointment->google_event_id, new Calendar\Event($eventData));
            } else {
                $event = $service->events->insert('primary', new Calendar\Event($eventData));
                $appointment->update(['google_event_id' => $event->id]);
            }

        } catch (\Exception $e) {
            \Log::error('Google Calendar Sync Failed: ' . $e->getMessage());
        }
    }
}
