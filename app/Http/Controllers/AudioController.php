<?php

namespace App\Http\Controllers;

use App\Services\AudioProcessorService;
use Illuminate\Http\Request;
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
            'consultation_id' => ['nullable', 'string'],
        ]);

        $type = $validated['type'] ?? 'main';
        $path = $request->file('audio')->store("tmp/uploads/{$type}", 'local');

        $this->log('🎤 Áudio recebido', [
            'path' => $path,
            'type' => $type,
            'consultation_id' => $validated['consultation_id'] ?? null,
        ]);

        $result = $this->audioProcessor->process($path);

        return response()->json([
            'audio' => $result,
            'message' => 'Áudio processado com sucesso',
        ]);
    }

    private function log(string $message, array $context = []): void
    {
        if (config('audio.debug_log')) {
            logger()->info($message, $context);
        }
    }
}
