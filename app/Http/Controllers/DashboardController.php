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
    $tenantId = $helperTenant ? $helperTenant->id : null;

    $serviceTypeId = $request->query('service_type_id'); // NEW FILTER

    $today = Carbon::today();
    $weekStart = Carbon::now()->startOfWeek();

    // Leads
    $leadQuery = Lead::where('tenant_id', $tenantId);
    if ($serviceTypeId) {
        $leadQuery->where('service_type_id', $serviceTypeId);
    }

    // Appointments
    $apptQuery = Appointment::where('tenant_id', $tenantId);
    if ($serviceTypeId) {
        $apptQuery->where('service_type_id', $serviceTypeId);
    }

    // Jobs
    $jobQuery = CrmJob::where('tenant_id', $tenantId);
    if ($serviceTypeId) {
        // Jobs store service type name, not ID -> compare name
        $serviceName = \App\Models\ServiceType::where('id', $serviceTypeId)->value('name');
        $jobQuery->where('service_type', $serviceName);
    }

    return response()->json([
        'data' => [
            'leads' => [
                'today' => $leadQuery->clone()->whereDate('created_at', $today)->count(),
                'week' => $leadQuery->clone()->where('created_at', '>=', $weekStart)->count(),
            ],
            'appointments' => [
                'today' => $apptQuery->clone()->whereDate('start_time', $today)->count(),
                'week' => $apptQuery->clone()->where('start_time', '>=', $weekStart)->count(),
            ],
            'jobs' => [
                'today' => $jobQuery->clone()->whereDate('start_date', $today)->count(),
                'week' => $jobQuery->clone()->where('start_date', '>=', $weekStart)->count(),
            ],
        ]
    ]);
}


    public function summary(Request $request)
{
    $helperTenant = Helper::tenant();
    $tenantId = $helperTenant ? $helperTenant->id : null;

    $serviceTypeId = $request->query('service_type_id'); // NEW

    $range = $request->query('range', 7);
    $from = Carbon::now()->subDays($range)->startOfDay();

    // Leads
    $leadQuery = Lead::select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as count'))
        ->where('tenant_id', $tenantId)
        ->where('created_at', '>=', $from);

    if ($serviceTypeId) {
        $leadQuery->where('service_type_id', $serviceTypeId);
    }

    $leads = $leadQuery->groupBy('date')->get();

    // Appointments
    $apptQuery = Appointment::select(DB::raw('DATE(start_time) as date'), DB::raw('COUNT(*) as count'))
        ->where('tenant_id', $tenantId)
        ->where('start_time', '>=', $from);

    if ($serviceTypeId) {
        $apptQuery->where('service_type_id', $serviceTypeId);
    }

    $appointments = $apptQuery->groupBy('date')->get();

    // Jobs
    $jobQuery = CrmJob::select(DB::raw('DATE(start_date) as date'), DB::raw('COUNT(*) as count'))
        ->where('tenant_id', $tenantId)
        ->where('start_date', '>=', $from);

    if ($serviceTypeId) {
        $serviceName = \App\Models\ServiceType::where('id', $serviceTypeId)->value('name');
        $jobQuery->where('service_type', $serviceName);
    }

    $jobs = $jobQuery->groupBy('date')->get();

    return response()->json([
        'data' => [
            'leads' => $leads,
            'appointments' => $appointments,
            'jobs' => $jobs,
        ]
    ]);
}

}
