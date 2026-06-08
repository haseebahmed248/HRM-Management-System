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
     * Generate unique employee ID
     */
    public static function generateEmployeeId()
    {
        $creatorId = creatorId();
        $last = self::where('created_by', $creatorId)
            ->orderBy('id', 'desc')
            ->value('employee_id');

        $nextId = $last ? ((int) substr($last, 3)) + 1 : 1;

        return 'EMP' . str_pad($nextId, 6, '0', STR_PAD_LEFT);
    }
}
