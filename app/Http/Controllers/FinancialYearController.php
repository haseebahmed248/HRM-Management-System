<?php

namespace App\Http\Controllers;

use App\Models\FinancialYear;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class FinancialYearController extends Controller
{
    /**
     * Display a listing of financial years.
     */
    public function index(Request $request)
    {
        if (!Auth::user()->can('manage-payroll-settings')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $query = FinancialYear::with(['creator'])
            ->whereIn('created_by', getCompanyAndUsersId());

        // Handle search
        if ($request->has('search') && !empty($request->search)) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%')
                    ->orWhere('notes', 'like', '%' . $request->search . '%');
            });
        }

        // Handle status filter
        if ($request->has('status') && !empty($request->status) && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Handle sorting
        $sortField = $request->get('sort_field', 'start_date');
        $sortDirection = $request->get('sort_direction', 'desc');

        $allowedSortFields = ['name', 'start_date', 'end_date', 'status', 'created_at'];
        if (!in_array($sortField, $allowedSortFields)) {
            $sortField = 'start_date';
        }

        $query->orderBy($sortField, $sortDirection);

        $financialYears = $query->paginate($request->per_page ?? 10);

        return Inertia::render('hr/financial-years/index', [
            'financialYears' => $financialYears,
            'filters' => $request->all(['search', 'status', 'sort_field', 'sort_direction', 'per_page']),
        ]);
    }

    /**
     * Store a newly created financial year.
     */
    public function store(Request $request)
    {
        if (!Auth::user()->can('manage-payroll-settings')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'start_date' => 'required|date',
                'end_date' => 'required|date|after:start_date',
                'status' => 'nullable|in:active,closed',
                'is_current' => 'nullable|boolean',
                'notes' => 'nullable|string|max:1000',
            ]);

            $validated['created_by'] = creatorId();
            $validated['status'] = $validated['status'] ?? 'active';
            $validated['is_current'] = $validated['is_current'] ?? false;

            // Check for overlapping financial years
            $overlap = FinancialYear::whereIn('created_by', getCompanyAndUsersId())
                ->where(function ($q) use ($validated) {
                    $q->whereBetween('start_date', [$validated['start_date'], $validated['end_date']])
                        ->orWhereBetween('end_date', [$validated['start_date'], $validated['end_date']])
                        ->orWhere(function ($q2) use ($validated) {
                            $q2->where('start_date', '<=', $validated['start_date'])
                                ->where('end_date', '>=', $validated['end_date']);
                        });
                })
                ->exists();

            if ($overlap) {
                return redirect()->back()->with('error', __('This financial year overlaps with an existing one.'));
            }

            // Check if name already exists
            $nameExists = FinancialYear::where('name', $validated['name'])
                ->whereIn('created_by', getCompanyAndUsersId())
                ->exists();

            if ($nameExists) {
                return redirect()->back()->with('error', __('A financial year with this name already exists.'));
            }

            $financialYear = FinancialYear::create($validated);

            // If marked as current, unmark others
            if ($financialYear->is_current) {
                $financialYear->markAsCurrent();
            }

            return redirect()->back()->with('success', __('Financial year created successfully.'));
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage() ?: __('Failed to create financial year.'));
        }
    }

    /**
     * Update the specified financial year.
     */
    public function update(Request $request, $id)
    {
        if (!Auth::user()->can('manage-payroll-settings')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $financialYear = FinancialYear::where('id', $id)
            ->whereIn('created_by', getCompanyAndUsersId())
            ->first();

        if (!$financialYear) {
            return redirect()->back()->with('error', __('Financial year not found.'));
        }

        // Prevent editing closed financial years
        if ($financialYear->status === 'closed' && $request->status !== 'active') {
            // Allow reopening, but prevent other edits on closed years
            if ($request->has('start_date') || $request->has('end_date')) {
                return redirect()->back()->with('error', __('Cannot modify dates of a closed financial year.'));
            }
        }

        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'start_date' => 'required|date',
                'end_date' => 'required|date|after:start_date',
                'status' => 'nullable|in:active,closed',
                'is_current' => 'nullable|boolean',
                'notes' => 'nullable|string|max:1000',
            ]);

            // Check for overlapping financial years (excluding current)
            $overlap = FinancialYear::whereIn('created_by', getCompanyAndUsersId())
                ->where('id', '!=', $id)
                ->where(function ($q) use ($validated) {
                    $q->whereBetween('start_date', [$validated['start_date'], $validated['end_date']])
                        ->orWhereBetween('end_date', [$validated['start_date'], $validated['end_date']])
                        ->orWhere(function ($q2) use ($validated) {
                            $q2->where('start_date', '<=', $validated['start_date'])
                                ->where('end_date', '>=', $validated['end_date']);
                        });
                })
                ->exists();

            if ($overlap) {
                return redirect()->back()->with('error', __('This financial year overlaps with an existing one.'));
            }

            // Check if name already exists (excluding current)
            $nameExists = FinancialYear::where('name', $validated['name'])
                ->whereIn('created_by', getCompanyAndUsersId())
                ->where('id', '!=', $id)
                ->exists();

            if ($nameExists) {
                return redirect()->back()->with('error', __('A financial year with this name already exists.'));
            }

            $financialYear->update($validated);

            // If marked as current, unmark others
            if ($financialYear->is_current) {
                $financialYear->markAsCurrent();
            }

            return redirect()->back()->with('success', __('Financial year updated successfully.'));
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage() ?: __('Failed to update financial year.'));
        }
    }

    /**
     * Remove the specified financial year.
     */
    public function destroy($id)
    {
        if (!Auth::user()->can('manage-payroll-settings')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $financialYear = FinancialYear::where('id', $id)
            ->whereIn('created_by', getCompanyAndUsersId())
            ->first();

        if (!$financialYear) {
            return redirect()->back()->with('error', __('Financial year not found.'));
        }

        // Check if any payroll runs are linked to this financial year
        if ($financialYear->payrollRuns()->count() > 0) {
            return redirect()->back()->with('error', __('Cannot delete financial year with linked payroll runs.'));
        }

        try {
            $financialYear->delete();
            return redirect()->back()->with('success', __('Financial year deleted successfully.'));
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage() ?: __('Failed to delete financial year.'));
        }
    }

    /**
     * Mark a financial year as current.
     */
    public function setCurrent($id)
    {
        if (!Auth::user()->can('manage-payroll-settings')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $financialYear = FinancialYear::where('id', $id)
            ->whereIn('created_by', getCompanyAndUsersId())
            ->first();

        if (!$financialYear) {
            return redirect()->back()->with('error', __('Financial year not found.'));
        }

        if ($financialYear->status === 'closed') {
            return redirect()->back()->with('error', __('Cannot set a closed financial year as current.'));
        }

        try {
            $financialYear->markAsCurrent();
            return redirect()->back()->with('success', __('Financial year set as current.'));
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage() ?: __('Failed to set current financial year.'));
        }
    }

    /**
     * Close a financial year.
     */
    public function close($id)
    {
        if (!Auth::user()->can('manage-payroll-settings')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $financialYear = FinancialYear::where('id', $id)
            ->whereIn('created_by', getCompanyAndUsersId())
            ->first();

        if (!$financialYear) {
            return redirect()->back()->with('error', __('Financial year not found.'));
        }

        if ($financialYear->status === 'closed') {
            return redirect()->back()->with('error', __('Financial year is already closed.'));
        }

        try {
            $financialYear->close();
            return redirect()->back()->with('success', __('Financial year closed successfully.'));
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage() ?: __('Failed to close financial year.'));
        }
    }

    /**
     * Reopen a closed financial year.
     */
    public function reopen($id)
    {
        if (!Auth::user()->can('manage-payroll-settings')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $financialYear = FinancialYear::where('id', $id)
            ->whereIn('created_by', getCompanyAndUsersId())
            ->first();

        if (!$financialYear) {
            return redirect()->back()->with('error', __('Financial year not found.'));
        }

        if ($financialYear->status === 'active') {
            return redirect()->back()->with('error', __('Financial year is already active.'));
        }

        try {
            $financialYear->status = 'active';
            $financialYear->save();
            return redirect()->back()->with('success', __('Financial year reopened successfully.'));
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage() ?: __('Failed to reopen financial year.'));
        }
    }
}
