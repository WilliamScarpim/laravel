<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Consultation extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'doctor_id',
        'patient_id',
        'date',
        'summary',
        'anamnesis',
        'transcription',
        'status',
        'current_step',
        'metadata',
        'audio_files',
    ];

    protected $casts = [
        'metadata' => 'array',
        'audio_files' => 'array',
        'date' => 'date',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }
}
