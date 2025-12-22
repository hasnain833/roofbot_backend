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
        $tenant = Helper::tenant();
        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 403);
        }

        // Frontend sends service_type as the NAME (e.g., "Plumbing")
        $serviceTypeName = $request->query('service_type');

        $today = Carbon::today();
        $weekStart = Carbon::now()->startOfWeek();

        // Base queries
        $leadQuery = Lead::where('tenant_id', $tenant->id);
        $apptQuery = Appointment::where('tenant_id', $tenant->id);
        $jobQuery = CrmJob::where('tenant_id', $tenant->id);

        // QUICK FIX: Filter using string columns instead of relationship
        if ($serviceTypeName) {
            $leadQuery->where('service_type_name', $serviceTypeName);
            $apptQuery->where('service_type', $serviceTypeName);
            $jobQuery->where('service_type', $serviceTypeName);
        }

        return response()->json([
            'data' => [
                'leads' => [
                    'today' => (clone $leadQuery)->whereDate('created_at', $today)->count(),
                    'week'  => (clone $leadQuery)->where('created_at', '>=', $weekStart)->count(),
                ],
                'appointments' => [
                    'today' => (clone $apptQuery)->whereDate('start_time', $today)->count(),
                    'week'  => (clone $apptQuery)->where('start_time', '>=', $weekStart)->count(),
                ],
                'jobs' => [
                    'today' => (clone $jobQuery)->whereDate('start_date', $today)->count(),
                    'week'  => (clone $jobQuery)->where('start_date', '>=', $weekStart)->count(),
                ],
            ]
        ]);
    }

    public function summary(Request $request)
    {
        $tenant = Helper::tenant();
        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 403);
        }

        $tenantId = $tenant->id;
        $serviceTypeName = $request->query('service_type');

        $range = $request->query('range', 7);
        $from = Carbon::now()->subDays($range)->startOfDay();

        // Leads - use string column
        $leadQuery = Lead::select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as count'))
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $from);

        if ($serviceTypeName) {
            $leadQuery->where('service_type_name', $serviceTypeName);
        }

        $leads = $leadQuery->groupBy('date')->orderBy('date')->get();

        // Appointments - use string column
        $apptQuery = Appointment::select(DB::raw('DATE(start_time) as date'), DB::raw('COUNT(*) as count'))
            ->where('tenant_id', $tenantId)
            ->where('start_time', '>=', $from);

        if ($serviceTypeName) {
            $apptQuery->where('service_type', $serviceTypeName);
        }

        $appointments = $apptQuery->groupBy('date')->orderBy('date')->get();

        // Jobs - already using string column
        $jobQuery = CrmJob::select(DB::raw('DATE(start_date) as date'), DB::raw('COUNT(*) as count'))
            ->where('tenant_id', $tenantId)
            ->where('start_date', '>=', $from);

        if ($serviceTypeName) {
            $jobQuery->where('service_type', $serviceTypeName);
        }

        $jobs = $jobQuery->groupBy('date')->orderBy('date')->get();

        return response()->json([
            'data' => [
                'leads' => $leads,
                'appointments' => $appointments,
                'jobs' => $jobs,
            ]
        ]);
    }
}