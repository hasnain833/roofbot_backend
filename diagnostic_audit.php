<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Lead;
use App\Models\Appointment;

$leadId = 101;
$lead = Lead::find($leadId);
$appointment = Appointment::where('lead_id', $leadId)->first();

echo "--- LEAD 101 ---\n";
if ($lead) {
    echo "ID: " . $lead->id . "\n";
    echo "First Name: " . $lead->first_name . "\n";
    echo "Service Name: " . ($lead->service_type_name ?? 'NULL') . "\n";
    echo "Service ID: " . ($lead->service_type_id ?? 'NULL') . "\n";
    echo "Email: " . ($lead->email ?? 'NULL') . "\n";
} else {
    echo "Lead 101 NOT FOUND\n";
}

echo "\n--- APPOINTMENT ---\n";
if ($appointment) {
    echo "ID: " . $appointment->id . "\n";
    echo "Title: " . $appointment->title . "\n";
    echo "Start Time (DB): " . $appointment->start_time . "\n";
    echo "Outlook Event ID: " . ($appointment->outlook_event_id ?? 'NULL') . "\n";
    echo "Google Event ID: " . ($appointment->google_event_id ?? 'NULL') . "\n";
} else {
    echo "Appointment for Lead 101 NOT FOUND\n";
}

echo "\n--- APP CONFIG ---\n";
echo "Timezone: " . config('app.timezone') . "\n";
