<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['guidance_id', 'semester', 'starts_at', 'ends_at', 'counseling_request_id'])]
class CounselingSlot extends Model
{
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function guidance(): BelongsTo
    {
        return $this->belongsTo(User::class, 'guidance_id');
    }

    /** @return BelongsTo<CounselingRequest, $this> */
    public function counselingRequest(): BelongsTo
    {
        return $this->belongsTo(CounselingRequest::class);
    }
}
