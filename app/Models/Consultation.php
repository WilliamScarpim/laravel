<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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

    public function anamnesisVersions(): HasMany
    {
        return $this->hasMany(AnamnesisVersion::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(ConsultationAuditLog::class);
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(ConsultationJob::class);
    }

    public function pendingJob(): HasOne
    {
        return $this->hasOne(ConsultationJob::class)
            ->whereIn('status', ['queued', 'processing'])
            ->ofMany('created_at', 'max');
    }

    public function latestJob(): HasOne
    {
        return $this->hasOne(ConsultationJob::class)->latestOfMany();
    }
}
