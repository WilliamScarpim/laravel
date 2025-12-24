<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessTranscriptionJob;
use App\Models\Consultation;
use App\Models\ConsultationJob;
use App\Services\ConsultationJobService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TranscriptionController extends Controller
{
    public function __construct(
        private readonly ConsultationJobService $jobService
    ) {
    }

    public function transcribe(Request $request)
    {
        if ($request->user()?->isCompany()) {
            abort(403, 'Empresas não podem enviar gravações.');
        }

        $validated = $request->validate([
            'audio' => ['required', 'file'],
            'consultation_id' => ['required', 'string', 'exists:consultations,id'],
            'type' => ['nullable', 'string', 'in:main,additional'],
            'notes' => ['nullable', 'string'],
        ]);

        $consultation = Consultation::findOrFail($validated['consultation_id']);
        $type = $validated['type'] ?? 'main';
        $path = $request->file('audio')->store("tmp/uploads/{$type}", 'local');

        $job = $this->jobService->create($type, $consultation, (string) $request->user()->id, [
            'upload_size' => $request->file('audio')->getSize(),
            'original_path' => $path,
            'notes' => $validated['notes'] ?? null,
        ]);

        ProcessTranscriptionJob::dispatch(
            (string) $job->id,
            $path,
            $type,
            (string) $consultation->id,
            (string) $request->user()->id,
            $validated['notes'] ?? null
        );

        return response()->json([
            'job' => $this->jobService->serialize($job),
            'message' => 'Áudio recebido. O processamento será realizado na fila de background.',
        ], 202);
    }

    public function status(string $jobId)
    {
        $job = ConsultationJob::with('consultation')->findOrFail($jobId);

        return response()->json([
            'job' => $this->jobService->serialize($job, true),
        ]);
    }

    public function retry(Request $request, string $jobId)
    {
        $job = ConsultationJob::findOrFail($jobId);

        if (! $job->consultation_id) {
            return response()->json(['message' => 'Consulta não vinculada ao processamento.'], 422);
        }

        $consultation = Consultation::findOrFail($job->consultation_id);
        $audioPath = $job->meta['original_path'] ?? null;

        if (! $audioPath || ! Storage::disk('local')->exists($audioPath)) {
            return response()->json(['message' => 'Áudio original indisponível para nova tentativa.'], 422);
        }

        $notes = $job->meta['notes'] ?? null;

        $newJob = $this->jobService->create(
            $job->type,
            $consultation,
            (string) ($request->user()->id ?? $job->user_id ?? $consultation->doctor_id),
            [
                'original_path' => $audioPath,
                'notes' => $notes,
                'retry_of' => (string) $job->id,
            ]
        );

        ProcessTranscriptionJob::dispatch(
            (string) $newJob->id,
            $audioPath,
            $job->type,
            (string) $consultation->id,
            (string) ($request->user()->id ?? $job->user_id ?? $consultation->doctor_id),
            $notes
        );

        return response()->json([
            'job' => $this->jobService->serialize($newJob),
            'message' => 'Processamento reiniciado.',
        ], 202);
    }
}
