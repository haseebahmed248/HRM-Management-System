<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Auth;

class AuditLog extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'category',
        'event',
        'subject',
        'description',
        'changes',
        'auditable_type',
        'auditable_id',
        'performed_by',
        'performed_by_name',
        'created_by',
    ];

    protected $casts = [
        'changes' => 'array',
    ];

    public function performer()
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    /**
     * Item 8 - record an audit-trail entry. Best-effort: never let an audit
     * failure break the underlying operation.
     *
     * @param  string       $category     employee | employee_deleted | system
     * @param  string       $event        created | updated | deleted | system_change
     * @param  string|null  $subject      human label (employee name, "System Settings")
     * @param  string|null  $description  summary of what changed
     * @param  array|null   $changes      ['field' => ['old' => x, 'new' => y]]
     * @param  object|null  $auditable    the model the change is about (for type/id)
     */
    public static function record(
        string $category,
        string $event,
        ?string $subject = null,
        ?string $description = null,
        ?array $changes = null,
        $auditable = null
    ): void {
        try {
            $user = Auth::user();

            static::create([
                'category'          => $category,
                'event'             => $event,
                'subject'           => $subject,
                'description'       => $description,
                'changes'           => $changes,
                'auditable_type'    => $auditable ? class_basename($auditable) : null,
                'auditable_id'      => $auditable->id ?? null,
                'performed_by'      => $user?->id,
                'performed_by_name' => $user?->name,
                'created_by'        => function_exists('creatorId') ? (creatorId() ?: ($user?->id)) : $user?->id,
            ]);
        } catch (\Throwable $e) {
            // Auditing must never break the real operation.
            \Log::warning('Audit log failed: ' . $e->getMessage());
        }
    }
}
