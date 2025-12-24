<?php

namespace App\Http\Controllers;

use App\Models\Consultation;
use App\Models\ConsultationJob;
use App\Models\Patient;
use App\Models\User;
use App\Services\AnamnesisVersionService;
use App\Services\ConsultationAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ConsultationController extends Controller
{
    public function __construct(
        private readonly ConsultationAuditService $auditService,
        private readonly AnamnesisVersionService $versionService
    ) {
    }

    public function index(Request $request)
    {
        $query = Consultation::with(['patient', 'doctor.specialtyRelation', 'pendingJob', 'latestJob', 'company'])
            ->withCount('anamnesisVersions')
            ->withMax('anamnesisVersions as anamnesis_version', 'version')
            ->orderByDesc('date')
            ->orderByDesc('created_at');

        $user = $request->user();
        if ($user && $user->isDoctor()) {
            $query->where('doctor_id', $user->id);
        } elseif ($user && $user->isCompany()) {
            $query->where(function ($q) use ($user) {
                $q->where('company_id', $user->id)
                    ->orWhereHas('doctor', fn ($dq) => $dq->where('company_id', $user->id));
            });
        }

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

    public function show(Request $request, string $id)
    {
        $consultation = Consultation::with([
            'patient',
            'doctor.specialtyRelation',
            'documents',
            'pendingJob',
            'latestJob',
            'company',
        ])
            ->withCount('anamnesisVersions')
            ->withMax('anamnesisVersions as anamnesis_version', 'version')
            ->find($id);

        if (! $consultation) {
            return response()->json(['message' => 'Consulta não encontrada'], 404);
        }

        $this->authorizeConsultationAccess($request->user(), $consultation);

        return response()->json(['data' => $this->transform($consultation)]);
    }

    public function store(Request $request)
    {
        if ($request->user()?->isCompany()) {
            abort(403, 'Empresas nÃ£o podem criar prontuÃ¡rios.');
        }

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
        $companyId = $request->user()?->company_id;

        if (! empty($data['consultationId'])) {
            $consultation = Consultation::findOrFail($data['consultationId']);
            $original = $consultation->getOriginal();
            $consultation->fill([
                'patient_id' => $patient->id,
                'doctor_id' => $request->user()->id,
                'company_id' => $companyId ?? $consultation->company_id,
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
                'company_id' => $companyId,
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

        $consultation->load(['patient', 'doctor', 'company']);

        return response()->json(['data' => $this->transform($consultation)], 201);
    }

    public function update(Request $request, string $id)
    {
        if ($request->user()?->isCompany()) {
            abort(403, 'Empresas nÃ£o podem alterar prontuÃ¡rios.');
        }

        $consultation = Consultation::findOrFail($id);
        $this->authorizeConsultationAccess($request->user(), $consultation);
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

        $consultation->load(['patient', 'doctor', 'company']);

        return response()->json(['data' => $this->transform($consultation)]);
    }

    public function complete(Request $request, string $id)
    {
        if ($request->user()?->isCompany()) {
            abort(403, 'Empresas nÃ£o podem encerrar prontuÃ¡rios.');
        }

        $consultation = Consultation::findOrFail($id);
        $this->authorizeConsultationAccess($request->user(), $consultation);
        $original = $consultation->getOriginal();
        $consultation->status = 'completed';
        $consultation->current_step = 'complete';
        if (! $consultation->completed_at) {
            $consultation->completed_at = Carbon::now();
        }
        $this->cleanupAudio($consultation);
        $consultation->save();

        $this->auditService->record($consultation, $original, (string) $request->user()->id, 'complete');

        $consultation->load(['patient', 'doctor', 'company']);

        return response()->json(['data' => $this->transform($consultation)]);
    }

    public function destroy(Request $request, string $id)
    {
        if ($request->user()?->isCompany()) {
            abort(403, 'Empresas nÃ£o podem remover prontuÃ¡rios.');
        }

        $consultation = Consultation::with(['documents.versions', 'anamnesisVersions', 'auditLogs', 'jobs'])
            ->findOrFail($id);
        $this->authorizeConsultationAccess($request->user(), $consultation);

        if ($consultation->status === 'completed') {
            return response()->json(['message' => 'Consultas concluídas não podem ser removidas.'], 422);
        }

        DB::transaction(function () use ($consultation) {
            foreach ($consultation->documents as $document) {
                $document->versions()->delete();
                $document->delete();
            }

            $consultation->anamnesisVersions()->delete();
            $consultation->auditLogs()->delete();
            $consultation->jobs()->delete();

            $this->cleanupAudio($consultation);

            $consultation->delete();
        });

        return response()->json(['message' => 'Consulta removida com sucesso.']);
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
                'specialty' => $consultation->doctor->specialtyRelation?->name ?? $consultation->doctor->specialty,
                'specialtyId' => $consultation->doctor->specialtyRelation?->id,
            ] : null,
            'company' => $consultation->company ? [
                'id' => (string) $consultation->company->id,
                'name' => $consultation->company->name,
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
        $this->removeAudioArtifacts($files);

        $metadata = $consultation->metadata ?? [];
        if ($consultation->audio_files !== null || ! empty($metadata['audioSegments'] ?? null)) {
            $consultation->audio_files = null;
            unset($metadata['audioSegments']);
            $consultation->metadata = $metadata;
        }
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

    private function removeAudioArtifacts(array $files): void
    {
        if (empty($files)) {
            return;
        }

        $disk = Storage::disk('local');
        $paths = [];
        $directories = [];

        foreach ($files as $entry) {
            if (! empty($entry['original'])) {
                $paths[] = $entry['original'];
            }
            if (! empty($entry['optimized'])) {
                $paths[] = $entry['optimized'];
                $directories[] = dirname($entry['optimized']);
            }
            if (! empty($entry['segments']) && is_array($entry['segments'])) {
                foreach ($entry['segments'] as $segment) {
                    if (! empty($segment['path'])) {
                        $paths[] = $segment['path'];
                        $directories[] = dirname($segment['path']);
                    }
                }
            }
        }

        foreach (array_unique($paths) as $path) {
            try {
                $disk->delete($path);
            } catch (\Throwable $e) {
                // ignore
            }
        }

        foreach (array_unique($directories) as $directory) {
            if (! $directory) {
                continue;
            }

            $normalized = trim(str_replace('\\', '/', $directory), '/');
            if (! Str::startsWith($normalized, ['tmp/optimized', 'tmp/chunks'])) {
                continue;
            }

            try {
                $disk->deleteDirectory($normalized);
            } catch (\Throwable $e) {
                // ignore
            }
        }
    }

    private function authorizeConsultationAccess(?User $user, Consultation $consultation): void
    {
        if (! $user) {
            abort(401);
        }

        if ($user->isDoctor() && (string) $consultation->doctor_id !== (string) $user->id) {
            abort(403, 'Prontuário pertence a outro profissional.');
        }

        if ($user->isCompany() && (string) $consultation->company_id !== (string) $user->id) {
            abort(403, 'Prontuário pertence a outra empresa.');
        }
    }
}
