<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    use HasFactory;

   protected $fillable = [
    'user_id',
    'employee_id',
    'biometric_emp_id',
    'employee_code',
    'phone',
    'date_of_birth',
    'gender',
    'branch_id',
    'department_id',
    'designation_id',
    'staff_tier_id',
    'shift_id',
    'attendance_policy_id',
    'date_of_joining',
    'employment_type',
    'address_line_1',
    'address_line_2',
    'base_salary',
    'city',
    'state',
    'country',
    'postal_code',
    'emergency_contact_name',
    'emergency_contact_relationship',
    'emergency_contact_relationship_other', // FIX #3
    'emergency_contact_number',
    'payment_method',                       // FIX #2
    'bank_name',
    'account_holder_name',
    'account_number',
    'bank_identifier_code',
    'bank_branch',
    'tax_payer_id',
    'tpin',
    'napsa_number',
    'nhima_number',
    'employee_status',
    'created_by',
    'exempt_from_napsa',
    'exempt_from_nhima',
    'exempt_from_sdl',
    'exempt_from_paye',
    'title',
    'first_name',
    'middle_name',
    'last_name',
    'nationality',
    'marital_status',
    'nrc',
    'passport_no',
    'permit_no',
    // track-a/11: senior/junior payroll tier — guards EmployeeController,
    // PayrollRunController, and ZambiaReportController against the
    // manage-senior-payroll / manage-junior-payroll permissions.
    'staff_tier',
];

protected $casts = [
    'staff_tier' => 'string',
];

    /**
     * track-a/11: tier-scope used by EmployeeController, PayrollRunController,
     * and ZambiaReportController. Returns `null` when the current user is
     * unrestricted (superadmin, company role, or has neither tier permission
     * set on their role — preserves pre-Track-A behaviour for existing
     * tenants). Otherwise returns the explicit allow-list of tiers.
     */
    public static function allowedPayrollTiersForCurrentUser(): ?array
    {
        $user = auth()->user();
        if (! $user) {
            return ['__none__'];
        }
        if ($user->hasRole(['superadmin', 'company'])) {
            return null;
        }

        $tiers = [];
        try {
            if ($user->hasPermissionTo('manage-senior-payroll')) {
                $tiers[] = 'senior';
            }
            if ($user->hasPermissionTo('manage-junior-payroll')) {
                $tiers[] = 'junior';
            }
        } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist $e) {
            return null;
        }

        return empty($tiers) ? null : $tiers;
    }

    public function scopeForCurrentPayrollTiers($query)
    {
        $tiers = self::allowedPayrollTiersForCurrentUser();
        if ($tiers === null) {
            return $query;
        }
        return $query->whereIn('staff_tier', $tiers);
    }

    /**
     * Get the branch that the employee belongs to.
     */
    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Get the department that the employee belongs to.
     */
    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Get the designation that the employee has.
     */
    public function designation()
    {
        return $this->belongsTo(Designation::class);
    }

    /**
     * Get the shift that the employee belongs to.
     */
    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * Get the attendance policy that the employee has.
     */
    public function attendancePolicy()
    {
        return $this->belongsTo(AttendancePolicy::class);
    }

    /**
     * Get the user associated with the employee.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the user who created the employee.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the employee's documents.
     */
    public function documents()
    {
        return $this->hasMany(EmployeeDocument::class, 'employee_id', 'user_id');
    }

    /**
     * Generate a unique employee ID for the current company.
     *
     * The prefix and zero-padding are configurable per company via the
     * 'employee_id_prefix' / 'employee_id_padding' settings, so each tenant can
     * use its own format (e.g. "BIL0001") instead of a shared generic "EMP000001"
     * that collides across companies. The sequence is scoped to the whole company
     * (not the individual sub-user creating the record), and the result is checked
     * against the global unique index to guarantee it is free.
     */
    public static function generateEmployeeId()
    {
        $prefix  = trim((string) getSetting('employee_id_prefix', 'EMP'));
        $padding = (int) getSetting('employee_id_padding', 6);
        if ($padding < 1) {
            $padding = 6;
        }

        $companyUserIds = getCompanyAndUsersId();

        // Highest existing sequence for this company under the current prefix.
        $existing = self::whereIn('created_by', $companyUserIds)
            ->where('employee_id', 'like', $prefix . '%')
            ->pluck('employee_id');

        $max = 0;
        foreach ($existing as $eid) {
            $num = (int) substr((string) $eid, strlen($prefix));
            if ($num > $max) {
                $max = $num;
            }
        }
        $nextId = $max + 1;

        // Guarantee global uniqueness (employee_id has a global unique index).
        do {
            $candidate = $prefix . str_pad((string) $nextId, $padding, '0', STR_PAD_LEFT);
            $exists = self::where('employee_id', $candidate)->exists();
            $nextId++;
        } while ($exists);

        return $candidate;
    }
}
