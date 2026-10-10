<?php

namespace App\Models;

use App\Enums\CounselingRequestStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['guidance_id', 'student_id', 'semester', 'message', 'decline_reason', 'guidance_notes', 'status'])]
class CounselingRequest extends Model
{
    protected function casts(): array
    {
        return [
            'status' => CounselingRequestStatus::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function guidance(): BelongsTo
    {
        return $this->belongsTo(User::class, 'guidance_id');
    }

    /** @return BelongsTo<User, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    /** @return HasOne<CounselingSlot, $this> */
    public function slot(): HasOne
    {
        return $this->hasOne(CounselingSlot::class);
    }
}
