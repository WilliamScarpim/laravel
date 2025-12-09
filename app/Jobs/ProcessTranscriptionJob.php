<?php

namespace App\Jobs;

use App\Models\Consultation;
use App\Models\ConsultationJob;
use App\Services\AiAnamnesisService;
use App\Services\AnamnesisVersionService;
use App\Services\AudioProcessorService;
use App\Services\ConsultationAuditService;
use App\Services\ConsultationJobService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessTranscriptionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $tries = 1;

    public function __construct(
        public readonly string $consultationJobId,
        public readonly string $audioPath,
        public readonly string $type,
        public readonly ?string $consultationId,
        public readonly ?string $userId,
        public readonly ?string $notes = null
    ) {
    }

    public function handle(
        ConsultationJobService $jobService,
        AudioProcessorService $audioProcessor,
        AiAnamnesisService $aiService,
        ConsultationAuditService $auditService,
        AnamnesisVersionService $versionService
    ): void {
        $job = ConsultationJob::findOrFail($this->consultationJobId);
        $consultation = Consultation::find($this->consultationId);

        if (! $consultation) {
            $jobService->update($job, 'failed', 'validation', 0, [
                'error' => 'Consulta não encontrada para processamento.',
            ]);

            return;
        }

        try {
            $jobService->update($job, 'processing', 'audio_processing', 15, [
                'audio' => [
                    'original' => $this->audioPath,
                ],
            ]);

            $audio = $audioProcessor->process($this->audioPath);
            $segments = $this->collectSegmentPaths($audio);

            $jobService->update($job, 'processing', 'transcription', 40, [
                'audio' => $audio,
            ]);

            $transcription = $aiService->transcribeMany($segments->all());
            if (! $transcription) {
                throw new \RuntimeException('Falha ao transcrever o áudio.');
            }

            $prepared = $aiService->prepareTranscriptForAnamnesis($transcription);

            $jobService->update($job, 'processing', 'anamnesis', 65);

            $notesBlock = $this->notes ? trim($this->notes) : '';
            $transcriptWithNotes = $this->applyNotesToTranscript($prepared, $notesBlock);

            if ($this->type === 'additional') {
                $insights = $aiService->mergeWithExisting(
                    (string) $consultation->anamnesis,
                    $transcriptWithNotes
                );
            } else {
                $insights = $aiService->buildAnamnesisAndInsights($transcriptWithNotes);
            }

            if (! $insights) {
                throw new \RuntimeException('Falha ao gerar a anamnese/insights.');
            }

            $preparedWithNotes = $transcriptWithNotes;
            if ($this->type === 'additional') {
                $preparedWithNotes = $this->appendAdditionalTranscription($consultation, $transcriptWithNotes);
            }

            $anamnesisText = $insights['anamnesis'] ?? null;

            if ($notesBlock !== '' && $anamnesisText) {
                $anamnesisText .= "\n\n**Notas complementares do médico:**\n{$notesBlock}";
            }

            $flags = $this->normalizeFlags($insights['dynamic_flags'] ?? []);
            $questions = $this->normalizeQuestions($insights['missing_questions'] ?? []);

            $metadata = $consultation->metadata ?? [];
            $metadata['flags'] = $flags;
            $metadata['missingQuestions'] = $questions;
            $metadata['audioSegments'] = $audio['segments'] ?? [];
            if ($notesBlock !== '') {
                $history = $metadata['manualNotes'] ?? [];
                $history[] = [
                    'text' => $notesBlock,
                    'jobId' => (string) $job->id,
                    'appliedAt' => now()->toIso8601String(),
                ];
                $metadata['manualNotes'] = $history;
            }

            $audioFiles = $consultation->audio_files ?? [];
            $audioFiles[] = [
                'type' => $this->type,
                'original' => $this->audioPath,
                'optimized' => $audio['source']['path'] ?? null,
                'segments' => $audio['segments'] ?? [],
            ];

            $original = $consultation->getOriginal();

            $consultation->fill([
                'transcription' => $preparedWithNotes,
                'anamnesis' => $anamnesisText,
                'summary' => $insights['summary'] ?? null,
                'status' => 'transcription',
                'current_step' => 'transcription',
                'metadata' => $metadata,
                'audio_files' => $audioFiles,
            ]);

            $consultation->save();

            $auditService->record($consultation, $original, $this->userId, 'job_update');
            $versionService->record($consultation, $this->userId, $metadata);

            $jobService->update($job, 'completed', 'completed', 100, [
                'consultation_id' => (string) $consultation->id,
                'anamnesis_version' => $consultation->anamnesisVersions()->max('version') ?? 1,
            ]);
        } catch (Throwable $exception) {
            Log::error('[ConsultationJob] Falha ao processar áudio', [
                'consultation_id' => $consultation->id ?? null,
                'job_id' => $job->id,
                'error' => $exception->getMessage(),
            ]);

            $jobService->update($job, 'failed', 'failed', $job->progress, [
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    private function collectSegmentPaths(array $audio): Collection
    {
        $segments = collect($audio['segments'] ?? [])
            ->map(fn ($segment) => $segment['path'] ?? null)
            ->filter();

        if ($segments->isEmpty() && isset($audio['source']['path'])) {
            return collect([$audio['source']['path']]);
        }

        return $segments->values();
    }

    private function normalizeFlags(array $flags): array
    {
        $mapSeverity = [
            'critica' => 'red',
            'alta' => 'orange',
            'moderada' => 'yellow',
            'baixa' => 'gray',
            'red' => 'red',
            'orange' => 'orange',
            'yellow' => 'yellow',
            'gray' => 'gray',
        ];

        $mapType = [
            'clinico' => 'clinical',
            'juridico' => 'legal',
            'cognitivo' => 'clinical',
            'polifarmacia' => 'drug',
        ];

        return collect($flags)->map(function ($flag, $index) use ($mapSeverity, $mapType) {
            return [
                'id' => $flag['id'] ?? 'flag_' . ($index + 1),
                'title' => $flag['title'] ?? 'Alerta',
                'severity' => $mapSeverity[strtolower($flag['severity'] ?? '')] ?? 'yellow',
                'details' => $flag['details'] ?? '',
                'suggestion' => $flag['suggestion'] ?? '',
                'category' => $mapType[strtolower($flag['type'] ?? '')] ?? 'clinical',
            ];
        })->values()->all();
    }

    private function normalizeQuestions(array $questions): array
    {
        return collect($questions)->map(function ($question, $index) {
            if (is_string($question)) {
                return [
                    'id' => 'q_' . ($index + 1),
                    'text' => $question,
                    'category' => 'general',
                    'priority' => 'medium',
                    'isDone' => false,
                ];
            }

            return [
                'id' => $question['id'] ?? 'q_' . ($index + 1),
                'text' => $question['text'] ?? '',
                'category' => $question['category'] ?? 'general',
                'priority' => $question['priority'] ?? 'medium',
                'isDone' => $question['isDone'] ?? false,
            ];
        })->filter(fn ($q) => ! empty($q['text']))->values()->all();
    }

    private function applyNotesToTranscript(string $transcript, string $notesBlock): string
    {
        $transcript = trim($transcript);

        if ($notesBlock === '') {
            return $transcript;
        }

        if ($transcript === '') {
            return "## Notas do médico\n{$notesBlock}";
        }

        return "{$transcript}\n\n## Notas do médico\n{$notesBlock}";
    }

    private function appendAdditionalTranscription(Consultation $consultation, string $segment): string
    {
        $current = trim((string) $consultation->transcription);
        $segment = trim($segment);

        if ($current === '') {
            return $segment;
        }

        $header = '### Interação adicional registrada em ' . now()->format('d/m/Y H:i');

        return "{$current}\n\n---\n\n{$header}\n\n{$segment}";
    }
}
