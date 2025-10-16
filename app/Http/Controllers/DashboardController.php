<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Lead;
use App\Models\Appointment;
use App\Models\CrmJob;
use App\Helper;
use Carbon\Carbon;
use DB;

class DashboardController extends Controller
{
    public function stats(Request $request)
    {
        $helperTenant = Helper::tenant();
        $leadTenantId = $helperTenant ? $helperTenant->id : null; // Leads ke liye helper
        $appointmentTenantId = $request->user()->tenant_id;       // Appointments ke liye user tenant_id

        $today = Carbon::today();
        $weekStart = Carbon::now()->startOfWeek();

        return response()->json([
            'data' => [
                'leads' => [
                    'today' => $leadTenantId
                        ? Lead::where('tenant_id', $leadTenantId)->whereDate('created_at', $today)->count()
                        : 0,
                    'week' => $leadTenantId
                        ? Lead::where('tenant_id', $leadTenantId)->where('created_at', '>=', $weekStart)->count()
                        : 0,
                ],
                'appointments' => [
                    'today' => Appointment::where('tenant_id', $appointmentTenantId)->whereDate('start_time', $today)->count(),
                    'week' => Appointment::where('tenant_id', $appointmentTenantId)->where('start_time', '>=', $weekStart)->count(),
                ],
                'jobs' => [
                    'today' => CrmJob::where('tenant_id', $appointmentTenantId)->whereDate('start_date', $today)->count(),
                    'week' => CrmJob::where('tenant_id', $appointmentTenantId)->where('start_date', '>=', $weekStart)->count(),
                ],
            ]
        ]);
    }

    public function summary(Request $request)
    {
        $helperTenant = Helper::tenant();
        $leadTenantId = $helperTenant ? $helperTenant->id : null; // Leads ke liye helper
        $appointmentTenantId = $request->user()->tenant_id;       // Appointments ke liye user tenant_id

        $range = $request->query('range', 7);
        $from = Carbon::now()->subDays($range)->startOfDay();

        // Leads (Helper-based)
        $leads = $leadTenantId
            ? Lead::select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as count'))
                ->where('tenant_id', $leadTenantId)
                ->where('created_at', '>=', $from)
                ->groupBy('date')
                ->get()
            : collect([]);

        // Appointments (User tenant-based)
        $appointments = Appointment::select(DB::raw('DATE(start_time) as date'), DB::raw('COUNT(*) as count'))
            ->where('tenant_id', $appointmentTenantId)
            ->where('start_time', '>=', $from)
            ->groupBy('date')
            ->get();

        // Jobs (User tenant-based)
        $jobs = CrmJob::select(DB::raw('DATE(start_date) as date'), DB::raw('COUNT(*) as count'))
            ->where('tenant_id', $appointmentTenantId)
            ->where('start_date', '>=', $from)
            ->groupBy('date')
            ->get();

        return response()->json([
            'data' => [
                'leads' => $leads,
                'appointments' => $appointments,
                'jobs' => $jobs,
            ]
        ]);
    }
}
