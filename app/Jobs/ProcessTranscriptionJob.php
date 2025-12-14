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
use Illuminate\Support\Str;
use Throwable;

class ProcessTranscriptionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $tries = 1;

    private const ANAMNESIS_SECTIONS = [
        'Estado civil, ocupação e funcionalidade',
        'Queixa principal',
        'História da Doença Atual (HDA)',
        'História da Doença Atual',
        'Antecedentes pessoais e familiares',
        'Hábitos de vida',
        'Medicamentos em uso',
        'Síndromes geriátricas',
        'Conduta',
        'Hipótese diagnóstica',
        'Tratamento não farmacológico',
        'Tratamento farmacológico',
    ];


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
            $unifiedTranscript = $this->type === 'additional'
                ? $this->appendAdditionalTranscription($consultation, $transcriptWithNotes)
                : $transcriptWithNotes;

            if ($this->type === 'additional') {
                $insights = $aiService->mergeWithExisting(
                    (string) $consultation->anamnesis,
                    $unifiedTranscript
                );
            } else {
                $insights = $aiService->buildAnamnesisAndInsights($unifiedTranscript);
            }

            if (! $insights) {
                throw new \RuntimeException('Falha ao gerar a anamnese/insights.');
            }

            Log::debug('[ConsultationJob] Resposta da IA', [
                'job_id' => $job->id,
                'consultation_id' => $consultation->id,
                'flags' => $insights['dynamic_flags'] ?? null,
                'missing_questions' => $insights['missing_questions'] ?? null,
                'summary_length' => mb_strlen((string) ($insights['summary'] ?? '')),
            ]);

            $anamnesisText = $this->normalizeAnamnesisMarkdown($insights['anamnesis'] ?? null);
            if (! $anamnesisText) {
                $anamnesisText = $this->normalizeAnamnesisMarkdown($consultation->anamnesis ?: $unifiedTranscript);
            }

            if ($notesBlock !== '' && $anamnesisText) {
                $anamnesisText .= "\n\n**Notas complementares do m??dico:**\n{$notesBlock}";
            }

            $metadata = $consultation->metadata ?? [];
            $existingFlags = is_array($metadata['flags'] ?? null) ? $metadata['flags'] : [];
            $existingQuestions = is_array($metadata['missingQuestions'] ?? null) ? $metadata['missingQuestions'] : [];

            $flags = $this->normalizeFlags($insights['dynamic_flags'] ?? []);
            $questions = $this->normalizeQuestions($insights['missing_questions'] ?? []);

            $filteredFlags = array_values(array_filter($flags, static function ($flag) {
                $title = trim((string) ($flag['title'] ?? ''));
                $details = trim((string) ($flag['details'] ?? ''));
                $suggestion = trim((string) ($flag['suggestion'] ?? ''));

                return $title !== '' || $details !== '' || $suggestion !== '';
            }));

            $metadata['flags'] = ! empty($filteredFlags) ? $filteredFlags : $existingFlags;
            $metadata['missingQuestions'] = ! empty($questions) ? $questions : $existingQuestions;

            Log::debug('[ConsultationJob] Alertas normalizados', [
                'job_id' => $job->id,
                'consultation_id' => $consultation->id,
                'normalized_flags' => $metadata['flags'],
                'normalized_missing_questions' => $metadata['missingQuestions'],
            ]);
            $existingSegments = is_array($metadata['audioSegments'] ?? null)
                ? $metadata['audioSegments']
                : [];
            $metadata['audioSegments'] = array_values(array_merge(
                $existingSegments,
                $audio['segments'] ?? []
            ));
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
                'transcription' => $unifiedTranscript,
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
            if (is_string($flag)) {
                $text = trim($flag);

                return [
                    'id' => 'flag_' . ($index + 1),
                    'title' => $text !== '' ? Str::of($text)->limit(80)->value() : 'Alerta',
                    'severity' => 'yellow',
                    'details' => $text,
                    'suggestion' => '',
                    'category' => 'clinical',
                ];
            }

            $title = $flag['title'] ?? $flag['titulo'] ?? $flag['resumo'] ?? '';
            $details = $flag['details'] ?? $flag['detalhes'] ?? $flag['descricao'] ?? '';
            $suggestion = $flag['suggestion'] ?? $flag['sugestao'] ?? $flag['orientacao'] ?? '';
            $type = $flag['type'] ?? $flag['tipo'] ?? null;
            $severity = $flag['severity'] ?? $flag['gravidade'] ?? null;

            $title = trim(is_string($title) ? $title : (string) $title);
            $details = trim(is_string($details) ? $details : (string) $details);
            $suggestion = trim(is_string($suggestion) ? $suggestion : (string) $suggestion);
            $severityKey = Str::ascii(strtolower((string) $severity));
            $typeKey = Str::ascii(strtolower((string) $type));

            return [
                'id' => $flag['id'] ?? 'flag_' . ($index + 1),
                'title' => $title !== '' ? $title : 'Alerta',
                'severity' => $mapSeverity[$severityKey] ?? 'yellow',
                'details' => $details,
                'suggestion' => $suggestion,
                'category' => $mapType[$typeKey] ?? 'clinical',
            ];
        })->values()->all();
    }

    private function normalizeQuestions(array $questions): array
    {
        return collect($questions)->map(function ($question, $index) {
            if (is_string($question)) {
                $text = trim($question);

                return [
                    'id' => 'q_' . ($index + 1),
                    'text' => $text,
                    'category' => 'general',
                    'priority' => 'medium',
                    'isDone' => false,
                ];
            }

            $text = trim((string) ($question['text'] ?? ''));

            return [
                'id' => $question['id'] ?? 'q_' . ($index + 1),
                'text' => $text,
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
    private function normalizeAnamnesisMarkdown(?string $text): string
    {
        $value = trim((string) $text);

        if ($value === '') {
            return '';
        }

        $value = preg_replace("/\r?\n/", "\n", $value);

        foreach (self::ANAMNESIS_SECTIONS as $section) {
            $pattern = sprintf(
                '/(^|\n)\s*(?:#{1,3}\s*)?(?:\*\*|__)?%s(?:\*\*|__)?\s*(?:[:])?/imu',
                preg_quote($section, '/')
            );
            $replacement = "\n\n## {$section}";
            $value = preg_replace($pattern, $replacement, $value);
        }

        return trim(preg_replace("/\n{3,}/", "\n\n", $value));
    }


}
