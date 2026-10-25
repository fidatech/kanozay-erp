<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    protected $fillable = ['actor_id', 'action', 'subject_type', 'subject_id', 'description', 'details'];
    protected $casts = ['details' => 'array'];
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_id'); }
    public static function record(string $action, string $subjectType, ?int $subjectId, string $description, array $details = []): void
    {
        $log = self::create(['actor_id' => auth()->id(), 'action' => $action, 'subject_type' => $subjectType, 'subject_id' => $subjectId, 'description' => $description, 'details' => $details]);
        if (in_array($subjectType, ['user', 'role'], true)) {
            User::role('Super Administrator')->where('id', '!=', auth()->id())->each(function (User $recipient) use ($description, $action) {
                $recipient->notify(new \App\Notifications\AdministrativeChange('Administration update', $description.' ('.$action.')'));
            });
        }
    }
}
