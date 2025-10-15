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

        if (!$tenant || !$tenant->google_access_token) return;

        $client = new Client();
        $client->setAccessToken($tenant->google_access_token);
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
    }
}

