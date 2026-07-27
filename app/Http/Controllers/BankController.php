<?php

namespace App\Http\Controllers;

use App\Models\Bank;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class BankController extends Controller
{
    public function index(Request $request)
    {
        if (Auth::user()->can('manage-banks')) {
            $query = Bank::with(['creator'])->where(function ($q) {
                if (Auth::user()->can('manage-any-banks')) {
                    $q->whereIn('created_by', getCompanyAndUsersId());
                } elseif (Auth::user()->can('manage-own-banks')) {
                    $q->where('created_by', Auth::id());
                } else {
                    $q->whereRaw('1 = 0');
                }
            });

            if ($request->has('search') && !empty($request->search)) {
                $query->where(function ($q) use ($request) {
                    $q->where('name', 'like', '%' . $request->search . '%');
                });
            }

            if ($request->has('status') && !empty($request->status) && $request->status !== 'all') {
                $query->where('status', $request->status);
            }

            $sortField = $request->get('sort_field', 'name');
            $sortDirection = $request->get('sort_direction', 'asc');

            $allowedSortFields = ['name', 'created_at', 'id'];
            if (!in_array($sortField, $allowedSortFields)) {
                $sortField = 'name';
            }

            $query->orderBy($sortField, $sortDirection);

            $banks = $query->paginate($request->per_page ?? 10);

            return Inertia::render('hr/banks/index', [
                'banks' => $banks,
                'filters' => $request->all(['search', 'status', 'sort_field', 'sort_direction', 'per_page']),
            ]);
        } else {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }
    }

    public function store(Request $request)
    {
        if (Auth::user()->can('create-banks')) {
            try {
                $validated = $request->validate([
                    'name' => 'required|string|max:255',
                    'status' => 'nullable|in:active,inactive',
                ]);

                $validated['created_by'] = creatorId();
                $validated['status'] = $validated['status'] ?? 'active';

                $exists = Bank::where('name', $validated['name'])
                    ->whereIn('created_by', getCompanyAndUsersId())
                    ->exists();

                if ($exists) {
                    return redirect()->back()->with('error', __('Bank with this name already exists.'));
                }

                Bank::create($validated);

                return redirect()->back()->with('success', __('Bank created successfully'));
            } catch (\Exception $e) {
                return redirect()->back()->with('error', $e->getMessage() ?: __('Failed to create bank'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }
    }

    public function update(Request $request, $bankId)
    {
        if (Auth::user()->can('edit-banks')) {
            $bank = Bank::where('id', $bankId)
                ->whereIn('created_by', getCompanyAndUsersId())
                ->first();

            if ($bank) {
                try {
                    $validated = $request->validate([
                        'name' => 'required|string|max:255',
                        'status' => 'nullable|in:active,inactive',
                    ]);

                    $exists = Bank::where('name', $validated['name'])
                        ->whereIn('created_by', getCompanyAndUsersId())
                        ->where('id', '!=', $bankId)
                        ->exists();

                    if ($exists) {
                        return redirect()->back()->with('error', __('Bank with this name already exists.'));
                    }

                    $bank->update($validated);

                    return redirect()->back()->with('success', __('Bank updated successfully'));
                } catch (\Exception $e) {
                    return redirect()->back()->with('error', $e->getMessage() ?: __('Failed to update bank'));
                }
            } else {
                return redirect()->back()->with('error', __('Bank Not Found.'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }
    }

    public function destroy($bankId)
    {
        if (Auth::user()->can('delete-banks')) {
            $bank = Bank::where('id', $bankId)
                ->whereIn('created_by', getCompanyAndUsersId())
                ->first();

            if ($bank) {
                try {
                    $bank->delete();
                    return redirect()->back()->with('success', __('Bank deleted successfully'));
                } catch (\Exception $e) {
                    return redirect()->back()->with('error', $e->getMessage() ?: __('Failed to delete bank'));
                }
            } else {
                return redirect()->back()->with('error', __('Bank Not Found.'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }
    }

    public function toggleStatus($bankId)
    {
        if (Auth::user()->can('toggle-status-banks')) {
            $bank = Bank::where('id', $bankId)
                ->whereIn('created_by', getCompanyAndUsersId())
                ->first();

            if ($bank) {
                try {
                    $bank->status = $bank->status === 'active' ? 'inactive' : 'active';
                    $bank->save();
                    return redirect()->back()->with('success', __('Bank status updated successfully'));
                } catch (\Exception $e) {
                    return redirect()->back()->with('error', $e->getMessage() ?: __('Failed to update bank status'));
                }
            } else {
                return redirect()->back()->with('error', __('Bank Not Found.'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }
    }
}
