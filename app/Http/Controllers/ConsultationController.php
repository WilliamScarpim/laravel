<?php

namespace App\Http\Controllers;

use App\Models\Consultation;
use App\Models\ConsultationJob;
use App\Models\Patient;
use App\Services\AnamnesisVersionService;
use App\Services\ConsultationAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ConsultationController extends Controller
{
    public function __construct(
        private readonly ConsultationAuditService $auditService,
        private readonly AnamnesisVersionService $versionService
    ) {
    }

    public function index(Request $request)
    {
        $query = Consultation::with(['patient', 'doctor', 'pendingJob', 'latestJob'])
            ->withCount('anamnesisVersions')
            ->withMax('anamnesisVersions as anamnesis_version', 'version')
            ->orderByDesc('date')
            ->orderByDesc('created_at');

        $perPage = max(5, min(100, (int) $request->query('perPage', 20)));
        $page = max(1, (int) $request->query('page', 1));

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('summary', 'like', "%{$search}%")
                    ->orWhereHas('patient', function ($qp) use ($search) {
                        $qp->where('name', 'like', "%{$search}%")
                           ->orWhere('cpf', 'like', "%{$search}%");
                    });
            });
        }

        if ($from = $request->query('dateFrom')) {
            $query->whereDate('date', '>=', $from);
        }
        if ($to = $request->query('dateTo')) {
            $query->whereDate('date', '<=', $to);
        }

        $consultations = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => $consultations
                ->getCollection()
                ->map(fn ($c) => $this->transform($c))
                ->values(),
            'meta' => [
                'current_page' => $consultations->currentPage(),
                'per_page' => $consultations->perPage(),
                'total' => $consultations->total(),
                'last_page' => $consultations->lastPage(),
            ],
        ]);
    }

    public function show(string $id)
    {
        $consultation = Consultation::with([
            'patient',
            'doctor',
            'documents',
            'pendingJob',
            'latestJob',
        ])
            ->withCount('anamnesisVersions')
            ->withMax('anamnesisVersions as anamnesis_version', 'version')
            ->find($id);

        if (! $consultation) {
            return response()->json(['message' => 'Consulta não encontrada'], 404);
        }

        return response()->json(['data' => $this->transform($consultation)]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'consultationId' => ['nullable', 'string'],
            'patientId' => ['required', 'string', 'exists:patients,id'],
            'transcription' => ['nullable', 'string'],
            'anamnesis' => ['nullable', 'string'],
            'summary' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'currentStep' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
            'audioFiles' => ['nullable', 'array'],
        ]);

        $patient = Patient::findOrFail($data['patientId']);
        $status = $data['status'] ?? 'transcription';
        $step = $data['currentStep'] ?? 'transcription';

        if (! empty($data['consultationId'])) {
            $consultation = Consultation::findOrFail($data['consultationId']);
            $original = $consultation->getOriginal();
            $consultation->fill([
                'patient_id' => $patient->id,
                'doctor_id' => $request->user()->id,
                'transcription' => $data['transcription'] ?? $consultation->transcription,
                'anamnesis' => $data['anamnesis'] ?? $consultation->anamnesis,
                'summary' => $data['summary'] ?? $consultation->summary,
                'status' => $status ?? $consultation->status,
                'current_step' => $step ?? $consultation->current_step,
                'metadata' => $data['metadata'] ?? $consultation->metadata,
                'audio_files' => $data['audioFiles'] ?? $consultation->audio_files,
            ]);
            $this->syncCompletionTimestamp($consultation);

            $changedFields = array_keys($consultation->getDirty());
            if (! empty($changedFields)) {
                $consultation->save();
                $this->auditService->record($consultation, $original, (string) $request->user()->id);
                if (in_array('anamnesis', $changedFields, true)) {
                    $this->versionService->record($consultation, (string) $request->user()->id, $consultation->metadata ?? []);
                }
            }
        } else {
            $completionTimestamp = $status === 'completed' ? now() : null;

            $consultation = Consultation::create([
                'patient_id' => $patient->id,
                'doctor_id' => $request->user()->id,
                'date' => Carbon::now()->toDateString(),
                'transcription' => $data['transcription'] ?? null,
                'anamnesis' => $data['anamnesis'] ?? null,
                'summary' => $data['summary'] ?? null,
                'status' => $status,
                'current_step' => $step,
                'metadata' => $data['metadata'] ?? null,
                'audio_files' => $data['audioFiles'] ?? null,
                'completed_at' => $completionTimestamp,
            ]);
            if (! empty($data['anamnesis'])) {
                $this->versionService->record($consultation, (string) $request->user()->id, $consultation->metadata ?? []);
            }
        }

        $consultation->load(['patient', 'doctor']);

        return response()->json(['data' => $this->transform($consultation)], 201);
    }

    public function update(Request $request, string $id)
    {
        $consultation = Consultation::findOrFail($id);
        $data = $request->validate([
            'patientId' => ['nullable', 'string', 'exists:patients,id'],
            'transcription' => ['nullable', 'string'],
            'anamnesis' => ['nullable', 'string'],
            'summary' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'currentStep' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
            'audioFiles' => ['nullable', 'array'],
        ]);

        if (! empty($data['patientId'])) {
            $consultation->patient_id = $data['patientId'];
        }

        $original = $consultation->getOriginal();

        foreach (['transcription', 'anamnesis', 'summary', 'status', 'currentStep', 'metadata', 'audioFiles'] as $field) {
            $snake = $field === 'currentStep' ? 'current_step' : $field;
            if (array_key_exists($field, $data)) {
                $consultation->{$snake} = $data[$field];
            }
        }
        $this->syncCompletionTimestamp($consultation);

        $changedFields = array_keys($consultation->getDirty());
        $consultation->save();

        if (! empty($changedFields)) {
            $this->auditService->record($consultation, $original, (string) $request->user()->id);
            if (in_array('anamnesis', $changedFields, true)) {
                $this->versionService->record($consultation, (string) $request->user()->id, $consultation->metadata ?? []);
            }
        }

        $consultation->load(['patient', 'doctor']);

        return response()->json(['data' => $this->transform($consultation)]);
    }

    public function complete(string $id)
    {
        $consultation = Consultation::findOrFail($id);
        $original = $consultation->getOriginal();
        $consultation->status = 'completed';
        $consultation->current_step = 'complete';
        if (! $consultation->completed_at) {
            $consultation->completed_at = Carbon::now();
        }
        $this->cleanupAudio($consultation);
        $consultation->save();

        $this->auditService->record($consultation, $original, (string) $request->user()->id, 'complete');

        $consultation->load(['patient', 'doctor']);

        return response()->json(['data' => $this->transform($consultation)]);
    }

    public function transform(Consultation $consultation): array
    {
        return [
            'id' => (string) $consultation->id,
            'patientId' => (string) $consultation->patient_id,
            'patient' => $consultation->patient ? [
                'id' => (string) $consultation->patient->id,
                'cpf' => $consultation->patient->cpf,
                'name' => $consultation->patient->name,
                'birthDate' => optional($consultation->patient->birth_date)->format('Y-m-d'),
                'email' => $consultation->patient->email,
            ] : null,
            'doctorId' => (string) $consultation->doctor_id,
            'doctor' => $consultation->doctor ? [
                'id' => (string) $consultation->doctor->id,
                'name' => $consultation->doctor->name,
                'crm' => $consultation->doctor->crm,
                'specialty' => $consultation->doctor->specialty,
            ] : null,
            'date' => optional($consultation->date)->format('Y-m-d'),
            'transcription' => $consultation->transcription,
            'anamnesis' => $consultation->anamnesis,
            'summary' => $consultation->summary,
            'status' => $consultation->status,
            'currentStep' => $consultation->current_step,
            'metadata' => $consultation->metadata,
            'audioFiles' => $consultation->audio_files,
            'createdAt' => optional($consultation->created_at)->toIso8601String(),
            'updatedAt' => optional($consultation->updated_at)->toIso8601String(),
            'completedAt' => optional($consultation->completed_at)->toIso8601String(),
            'processing' => $this->transformProcessing($consultation),
            'anamnesisVersion' => [
                'current' => (int) ($consultation->anamnesis_version ?? 0),
                'total' => (int) ($consultation->anamnesis_versions_count ?? 0),
            ],
        ];
    }

    private function transformProcessing(Consultation $consultation): ?array
    {
        /** @var ConsultationJob|null $job */
        $job = $consultation->pendingJob ?? $consultation->latestJob;

        if (! $job) {
            return null;
        }

        return [
            'jobId' => (string) $job->id,
            'status' => $job->status,
            'step' => $job->current_step,
            'progress' => (int) $job->progress,
            'queuePosition' => (int) $job->queue_position,
            'updatedAt' => optional($job->updated_at)->toIso8601String(),
        ];
    }

    private function cleanupAudio(Consultation $consultation): void
    {
        $files = $consultation->audio_files ?? [];
        foreach ($files as $entry) {
            $paths = [];
            if (! empty($entry['original'])) {
                $paths[] = $entry['original'];
            }
            if (! empty($entry['optimized'])) {
                $paths[] = $entry['optimized'];
            }
            if (! empty($entry['segments']) && is_array($entry['segments'])) {
                foreach ($entry['segments'] as $seg) {
                    if (! empty($seg['path'])) {
                        $paths[] = $seg['path'];
                    }
                }
            }

            foreach ($paths as $path) {
                try {
                    \Storage::disk('local')->delete($path);
                } catch (\Throwable $e) {
                    // ignore
                }
            }
        }

        $consultation->audio_files = null;
        $metadata = $consultation->metadata ?? [];
        unset($metadata['audioSegments']);
        $consultation->metadata = $metadata;
    }

    private function syncCompletionTimestamp(Consultation $consultation): void
    {
        if ($consultation->status === 'completed') {
            if (! $consultation->completed_at) {
                $consultation->completed_at = Carbon::now();
            }
        } else {
            $consultation->completed_at = null;
        }
    }
}
