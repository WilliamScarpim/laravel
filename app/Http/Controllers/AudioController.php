<?php

namespace App\Http\Controllers;

use App\Models\Consultation;
use App\Services\AudioProcessorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class AudioController extends Controller
{
    public function __construct(private readonly AudioProcessorService $audioProcessor)
    {
    }

    public function process(Request $request)
    {
        $validated = $request->validate([
            'audio' => ['required', 'file'],
            'type' => ['nullable', 'string'],
            'consultation_id' => ['required', 'string', 'exists:consultations,id'],
        ]);

        $type = $validated['type'] ?? 'main';
        $path = $request->file('audio')->store("tmp/uploads/{$type}", 'local');

        $this->log('🎤 Áudio recebido', [
            'path' => $path,
            'type' => $type,
            'consultation_id' => $validated['consultation_id'],
        ]);

        $result = $this->audioProcessor->process($path);
        $this->attachAudioToConsultation($validated['consultation_id'], $type, $path, $result);

        return response()->json([
            'audio' => $result,
            'message' => 'Áudio processado com sucesso',
        ]);
    }

    private function attachAudioToConsultation(string $consultationId, string $type, string $originalPath, array $processed): void
    {
        $consultation = Consultation::find($consultationId);
        if (! $consultation) {
            return;
        }

        $files = $consultation->audio_files ?? [];
        $files[] = [
            'type' => $type,
            'original' => $originalPath,
            'optimized' => $processed['source']['path'] ?? null,
            'segments' => $processed['segments'] ?? [],
            'recorded_at' => now()->toIso8601String(),
        ];

        $consultation->audio_files = $files;
        $consultation->save();
    }

    private function log(string $message, array $context = []): void
    {
        if (config('audio.debug_log')) {
            logger()->info($message, $context);
        }
    }

    public function testFiles()
    {
        $dir = storage_path('app/public/audio-test');

        if (! is_dir($dir)) {
            return response()->json(['files' => []]);
        }

        $files = collect(File::allFiles($dir))
            ->filter(fn ($f) => preg_match('/\.(wav|mp3|ogg|m4a|webm)$/i', $f->getFilename()))
            ->map(function ($f) {
                return [
                    'name' => $f->getFilename(),
                    'size' => $f->getSize(),
                    'updated_at' => $f->getMTime(),
                ];
            })
            ->values()
            ->all();

        return response()->json(['files' => $files]);
    }

    public function getTestFile(string $file)
    {
        $safeName = basename($file);
        $path = "audio-test/{$safeName}";
        if (!Storage::disk('public')->exists($path)) {
            return response()->json(['message' => 'Arquivo não encontrado'], 404);
        }

        return response()->file(Storage::disk('public')->path($path));
    }
}
