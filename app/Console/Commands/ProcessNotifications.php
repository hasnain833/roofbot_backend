<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Followup;
use App\Models\Reminder;
use App\Jobs\SendFollowupJob;
use App\Jobs\SendReminderJob;
use Carbon\Carbon;

class ProcessNotifications extends Command
{
    protected $signature = 'notifications:process';
    protected $description = 'Process due followups and reminders';

    public function handle()
    {
        // Due followups
        $dueFollowups = Followup::where('done', false)
            ->where('followup_date', '<=', Carbon::now())
            ->get();

        foreach ($dueFollowups as $followup) {
            SendFollowupJob::dispatch($followup);
        }

        // Due reminders
        $dueReminders = Reminder::where('done', false)
            ->where('reminder_date', '<=', Carbon::now())
            ->get();

        foreach ($dueReminders as $reminder) {
            SendReminderJob::dispatch($reminder);
        }

        $this->info('Processed notifications.');
    }
}