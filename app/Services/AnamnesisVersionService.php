<?php

namespace App\Services;

use App\Models\Consultation;

class AnamnesisVersionService
{
    public function record(Consultation $consultation, ?string $userId, ?array $metadata = null): void
    {
        $currentAnamnesis = trim((string) $consultation->anamnesis);
        if ($currentAnamnesis === '') {
            return;
        }

        $latest = $consultation->anamnesisVersions()
            ->orderByDesc('version')
            ->first();

        if ($latest && trim((string) $latest->anamnesis) === $currentAnamnesis) {
            return;
        }

        $nextVersion = ($latest?->version ?? 0) + 1;

        $consultation->anamnesisVersions()->create([
            'user_id' => $userId,
            'version' => $nextVersion,
            'anamnesis' => $currentAnamnesis,
            'transcription' => $consultation->transcription,
            'summary' => $consultation->summary,
            'metadata' => $metadata ?? ($consultation->metadata ?? []),
        ]);
    }
}
