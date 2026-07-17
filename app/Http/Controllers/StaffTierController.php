<?php

namespace App\Http\Controllers;

use App\Models\StaffTier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class StaffTierController extends Controller
{
    public function index(Request $request)
    {
        if (Auth::user()->can('manage-staff-tiers')) {
            $query = StaffTier::with(['creator'])->where(function ($q) {
                if (Auth::user()->can('manage-any-staff-tiers')) {
                    $q->whereIn('created_by', getCompanyAndUsersId());
                } elseif (Auth::user()->can('manage-own-staff-tiers')) {
                    $q->where('created_by', Auth::id());
                } else {
                    $q->whereRaw('1 = 0');
                }
            });

            if ($request->has('search') && !empty($request->search)) {
                $query->where(function ($q) use ($request) {
                    $q->where('name', 'like', '%' . $request->search . '%')
                        ->orWhere('description', 'like', '%' . $request->search . '%');
                });
            }

            if ($request->has('status') && !empty($request->status) && $request->status !== 'all') {
                $query->where('status', $request->status);
            }

            $sortField = $request->get('sort_field', 'created_at');
            $sortDirection = $request->get('sort_direction', 'desc');

            $allowedSortFields = ['name', 'created_at', 'id'];
            if (!in_array($sortField, $allowedSortFields)) {
                $sortField = 'created_at';
            }

            $query->orderBy($sortField, $sortDirection);

            $staffTiers = $query->paginate($request->per_page ?? 10);

            return Inertia::render('hr/staff-tiers/index', [
                'staffTiers' => $staffTiers,
                'filters' => $request->all(['search', 'status', 'sort_field', 'sort_direction', 'per_page']),
            ]);
        } else {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }
    }

    public function store(Request $request)
    {
        if (Auth::user()->can('create-staff-tiers')) {
            try {
                $validated = $request->validate([
                    'name' => 'required|string|max:255',
                    'description' => 'nullable|string',
                    'status' => 'nullable|in:active,inactive',
                ]);

                $validated['created_by'] = creatorId();

                $exists = StaffTier::where('name', $validated['name'])
                    ->whereIn('created_by', getCompanyAndUsersId())
                    ->exists();

                if ($exists) {
                    return redirect()->back()->with('error', __('Staff tier with this name already exists.'));
                }

                StaffTier::create($validated);

                return redirect()->back()->with('success', __('Staff tier created successfully'));
            } catch (\Exception $e) {
                return redirect()->back()->with('error', $e->getMessage() ?: __('Failed to create staff tier'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }
    }

    public function update(Request $request, $staffTierId)
    {
        if (Auth::user()->can('edit-staff-tiers')) {
            $staffTier = StaffTier::where('id', $staffTierId)
                ->whereIn('created_by', getCompanyAndUsersId())
                ->first();

            if ($staffTier) {
                try {
                    $validated = $request->validate([
                        'name' => 'required|string|max:255',
                        'description' => 'nullable|string',
                        'status' => 'nullable|in:active,inactive',
                    ]);

                    $exists = StaffTier::where('name', $validated['name'])
                        ->whereIn('created_by', getCompanyAndUsersId())
                        ->where('id', '!=', $staffTierId)
                        ->exists();

                    if ($exists) {
                        return redirect()->back()->with('error', __('Staff tier with this name already exists.'));
                    }

                    $staffTier->update($validated);

                    return redirect()->back()->with('success', __('Staff tier updated successfully'));
                } catch (\Exception $e) {
                    return redirect()->back()->with('error', $e->getMessage() ?: __('Failed to update staff tier'));
                }
            } else {
                return redirect()->back()->with('error', __('Staff Tier Not Found.'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }
    }

    public function destroy($staffTierId)
    {
        if (Auth::user()->can('delete-staff-tiers')) {
            $staffTier = StaffTier::where('id', $staffTierId)
                ->whereIn('created_by', getCompanyAndUsersId())
                ->first();

            if ($staffTier) {
                try {
                    $staffTier->delete();
                    return redirect()->back()->with('success', __('Staff tier deleted successfully'));
                } catch (\Exception $e) {
                    return redirect()->back()->with('error', $e->getMessage() ?: __('Failed to delete staff tier'));
                }
            } else {
                return redirect()->back()->with('error', __('Staff Tier Not Found.'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }
    }

    public function toggleStatus($staffTierId)
    {
        if (Auth::user()->can('toggle-status-staff-tiers')) {
            $staffTier = StaffTier::where('id', $staffTierId)
                ->whereIn('created_by', getCompanyAndUsersId())
                ->first();

            if ($staffTier) {
                try {
                    $staffTier->status = $staffTier->status === 'active' ? 'inactive' : 'active';
                    $staffTier->save();
                    return redirect()->back()->with('success', __('Staff tier status updated successfully'));
                } catch (\Exception $e) {
                    return redirect()->back()->with('error', $e->getMessage() ?: __('Failed to update staff tier status'));
                }
            } else {
                return redirect()->back()->with('error', __('Staff Tier Not Found.'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }
    }
}
