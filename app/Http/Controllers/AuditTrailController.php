<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class AuditTrailController extends Controller
{
    /**
     * Item 8 - Audit Trail Report. A read-only, filterable view of employee
     * changes, employee deletions and system changes for the company.
     */
    public function index(Request $request)
    {
        if (! Auth::user()->can('manage-payroll-runs')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $query = AuditLog::whereIn('created_by', getCompanyAndUsersId());

        if ($request->filled('category') && $request->category !== 'all') {
            $query->where('category', $request->category);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('subject', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('performed_by_name', 'like', "%{$search}%");
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $logs = $query->orderByDesc('created_at')
            ->paginate($request->per_page ?? 20)
            ->withQueryString();

        return Inertia::render('hr/audit-trail/index', [
            'logs'    => $logs,
            'filters' => $request->all(['category', 'search', 'date_from', 'date_to', 'per_page']),
        ]);
    }
}
