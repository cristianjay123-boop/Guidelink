<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $guidance_id
 * @property string $type
 * @property string $title
 * @property string $body
 * @property Carbon|null $event_at
 * @property string $image_path
 */
#[Fillable(['guidance_id', 'type', 'title', 'body', 'event_at', 'image_path'])]
class GuidanceAnnouncement extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'guidance_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_at' => 'datetime',
        ];
    }
}
