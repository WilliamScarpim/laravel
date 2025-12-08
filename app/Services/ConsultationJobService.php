<?php

namespace App\Services;

use App\Models\Consultation;
use App\Models\ConsultationJob;
use Illuminate\Support\Collection;

class ConsultationJobService
{
    public function create(string $type, ?Consultation $consultation, ?string $userId, array $meta = []): ConsultationJob
    {
        $job = ConsultationJob::create([
            'consultation_id' => $consultation?->id,
            'user_id' => $userId,
            'type' => $type,
            'status' => 'queued',
            'current_step' => 'upload',
            'progress' => 5,
            'queue_position' => 0,
            'meta' => $meta,
        ]);

        return $this->syncQueuePosition($job);
    }

    public function update(
        ConsultationJob $job,
        string $status,
        ?string $step = null,
        ?int $progress = null,
        array $meta = []
    ): ConsultationJob {
        $job->status = $status;
        if ($step !== null) {
            $job->current_step = $step;
        }
        if ($progress !== null) {
            $job->progress = (int) max(0, min(100, $progress));
        }
        if (! empty($meta)) {
            $currentMeta = $job->meta ?? [];
            $job->meta = array_merge($currentMeta, $meta);
        }

        $job->save();

        return $this->syncQueuePosition($job);
    }

    public function serialize(ConsultationJob $job, bool $withConsultation = false): array
    {
        $payload = [
            'id' => (string) $job->id,
            'consultationId' => $job->consultation_id ? (string) $job->consultation_id : null,
            'type' => $job->type,
            'status' => $job->status,
            'step' => $job->current_step,
            'progress' => (int) $job->progress,
            'queuePosition' => (int) $job->queue_position,
            'meta' => $job->meta,
            'updatedAt' => optional($job->updated_at)->toIso8601String(),
            'createdAt' => optional($job->created_at)->toIso8601String(),
        ];

        if ($withConsultation && $job->relationLoaded('consultation') && $job->consultation) {
            $payload['consultation'] = [
                'id' => (string) $job->consultation->id,
                'status' => $job->consultation->status,
                'currentStep' => $job->consultation->current_step,
            ];
        }

        return $payload;
    }

    public function pendingQueue(): Collection
    {
        return ConsultationJob::query()
            ->where('status', 'queued')
            ->orderBy('created_at')
            ->get();
    }

    private function syncQueuePosition(ConsultationJob $job): ConsultationJob
    {
        if ($job->status !== 'queued') {
            if ($job->queue_position !== 0) {
                $job->queue_position = 0;
                $job->save();
            }

            return $job;
        }

        $position = ConsultationJob::query()
            ->where('status', 'queued')
            ->where('created_at', '<=', $job->created_at)
            ->count();

        if ($job->queue_position !== $position) {
            $job->queue_position = $position;
            $job->save();
        }

        return $job->refresh();
    }
}
