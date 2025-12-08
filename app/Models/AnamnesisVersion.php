<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnamnesisVersion extends Model
{
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'consultation_id',
        'user_id',
        'version',
        'anamnesis',
        'transcription',
        'summary',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
