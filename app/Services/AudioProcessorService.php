<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AudioProcessorService
{
    public function process(string $relativePath): array
    {
        $this->log('🎧 Iniciando processamento', ['file' => $relativePath]);

        $reencoded = $this->reencode($relativePath);
        $duration = $this->probeDuration($reencoded);

        $this->log('⏱️ Duração após reencode', ['seconds' => $duration]);

        $segments = $this->smartSplit($reencoded, $duration);

        $this->log('✅ Processamento concluído', ['segments' => $segments]);

        return [
            'source' => [
                'path' => $reencoded,
                'duration' => $duration,
            ],
            'segments' => $segments,
        ];
    }

    private function disk()
    {
        return Storage::disk('local');
    }

    private function log(string $message, array $context = []): void
    {
        if (config('audio.debug_log')) {
            Log::info($message, $context);
        }
    }

    private function run(string $cmd, string $errorMessage): void
    {
        exec($cmd . ' 2>&1', $output, $return);
        if ($return !== 0) {
            Log::error("❌ FFmpeg error: " . implode("\n", $output));
            throw new \RuntimeException($errorMessage);
        }
    }

    private function reencode(string $relativePath): string
    {
        $in = $this->disk()->path($relativePath);
        $outRel = 'tmp/optimized/' . uniqid('aud_', true) . '/audio.ogg';
        $outAbs = $this->disk()->path($outRel);

        $this->disk()->makeDirectory(dirname($outRel));

        $this->log('🎧 Reencode', [
            'input' => $relativePath,
            'output' => $outRel,
            'params' => 'mono, opus 12kbps, voip',
        ]);

        $cmd = sprintf(
            'ffmpeg -y -i %s -vn -ac 1 -c:a libopus -b:a 12k -application voip %s',
            escapeshellarg($in),
            escapeshellarg($outAbs)
        );

        $this->run($cmd, 'Erro ao re-encodar áudio');

        $size = $this->disk()->size($outRel);
        $this->log('🎧 Reencode concluído', ['output' => $outRel, 'size_mb' => round($size / 1024 / 1024, 2)]);

        return $outRel;
    }

    private function probeDuration(string $relativePath): float
    {
        $abs = $this->disk()->path($relativePath);
        $cmd = sprintf(
            'ffprobe -i %s -show_entries format=duration -v quiet -of csv="p=0"',
            escapeshellarg($abs)
        );

        $out = shell_exec($cmd) ?? '0';
        return (float) trim($out);
    }

    private function detectSilences(string $relativePath): array
    {
        $abs = $this->disk()->path($relativePath);
        $threshold = config('audio.silence_threshold', '-25dB');
        $minSilence = config('audio.silence_duration', 3.0);

        $cmd = sprintf(
            'ffmpeg -i %s -af silencedetect=noise=%s:d=%s -f null - 2>&1',
            escapeshellarg($abs),
            escapeshellarg($threshold),
            escapeshellarg((string) $minSilence)
        );

        exec($cmd, $output);

        $silences = [];
        $silenceStart = null;
        foreach ($output as $line) {
            if (preg_match('/silence_start:\s*([\d\.]+)/', $line, $m)) {
                $silenceStart = (float) $m[1];
            }
            if (preg_match('/silence_end:\s*([\d\.]+).*duration:\s*([\d\.]+)/', $line, $m) && $silenceStart !== null) {
                $silences[] = [
                    'start' => $silenceStart,
                    'end' => (float) $m[1],
                    'duration' => (float) $m[2],
                ];
                $silenceStart = null;
            }
        }

        $this->log('🔇 Silêncios detectados', ['count' => count($silences), 'silences' => $silences]);

        return $silences;
    }

    private function smartSplit(string $relativePath, float $totalDuration): array
    {
        $minInterval = (int) config('audio.split_min_interval', 300);
        $maxSegment = (int) config('audio.max_segment_seconds', 60);
        $forceSplit = (bool) config('audio.force_split', false);

        $silences = $this->detectSilences($relativePath);

        $cutPoints = [];
        $lastCut = 0;
        foreach ($silences as $silence) {
            if ($silence['start'] >= $lastCut + $minInterval) {
                $cutPoints[] = $silence['end'];
                $lastCut = $silence['end'];
                $this->log('📍 Corte por silêncio', ['at' => $silence['end']]);
            }
        }

        if (empty($cutPoints) && ($forceSplit || $totalDuration > $maxSegment)) {
            $this->log('⏱️ Nenhum silêncio qualificado. Usando split por duração', ['segment_seconds' => $maxSegment]);
            return $this->splitByDuration($relativePath, $maxSegment);
        }

        if (empty($cutPoints)) {
            $this->log('ℹ️ Sem cortes necessários', ['duration' => $totalDuration]);
            return [[
                'path' => $relativePath,
                'duration' => $totalDuration,
            ]];
        }

        return $this->cutAtPoints($relativePath, $cutPoints);
    }

    private function cutAtPoints(string $relativePath, array $cutPoints): array
    {
        $abs = $this->disk()->path($relativePath);
        $outDirRel = 'tmp/chunks/' . uniqid('split_', true);
        $outDirAbs = $this->disk()->path($outDirRel);

        $this->disk()->makeDirectory($outDirRel);

        $files = [];
        $prev = 0;
        $index = 0;

        foreach ($cutPoints as $cp) {
            $dur = max($cp - $prev, 0.1);
            $out = $outDirAbs . '/chunk_' . str_pad((string) $index, 3, '0', STR_PAD_LEFT) . '.ogg';
            $cmd = sprintf(
                'ffmpeg -y -i %s -ss %s -t %s -c copy %s',
                escapeshellarg($abs),
                escapeshellarg((string) $prev),
                escapeshellarg((string) $dur),
                escapeshellarg($out)
            );
            $this->run($cmd, "Erro ao gerar chunk {$index}");
            $relPath = $outDirRel . '/' . basename($out);
            $duration = $this->probeDuration($relPath);
            $files[] = [
                'path' => $relPath,
                'duration' => $duration,
            ];
            $this->log('⏱️ Chunk gerado', ['file' => $relPath, 'duration' => $duration]);
            $prev = $cp;
            $index++;
        }

        // último pedaço
        $out = $outDirAbs . '/chunk_' . str_pad((string) $index, 3, '0', STR_PAD_LEFT) . '.ogg';
        $cmd = sprintf(
            'ffmpeg -y -i %s -ss %s -c copy %s',
            escapeshellarg($abs),
            escapeshellarg((string) $prev),
            escapeshellarg($out)
        );
        $this->run($cmd, 'Erro ao gerar último chunk');
        $relPath = $outDirRel . '/' . basename($out);
        $duration = $this->probeDuration($relPath);
        $files[] = [
            'path' => $relPath,
            'duration' => $duration,
        ];
        $this->log('⏱️ Chunk gerado', ['file' => $relPath, 'duration' => $duration]);

        return $files;
    }

    private function splitByDuration(string $relativePath, int $segmentSec): array
    {
        $abs = $this->disk()->path($relativePath);
        $outDirRel = 'tmp/chunks/' . uniqid('dur_', true);
        $outDirAbs = $this->disk()->path($outDirRel);

        $this->disk()->makeDirectory($outDirRel);

        $segmentPattern = $outDirAbs . '/chunk_%03d.ogg';

        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = sprintf(
                'powershell -NoProfile -Command "& {ffmpeg -i %s -f segment -segment_time %d -c copy %s}"',
                escapeshellarg($abs),
                $segmentSec,
                escapeshellarg($segmentPattern)
            );
        } else {
            $cmd = sprintf(
                'ffmpeg -i %s -f segment -segment_time %d -c copy %s',
                escapeshellarg($abs),
                $segmentSec,
                escapeshellarg($segmentPattern)
            );
        }

        $this->run($cmd, 'Erro ao dividir por duração');

        $files = glob($outDirAbs . '/chunk_*.ogg') ?: [];

        $segments = [];
        foreach ($files as $f) {
            $rel = $outDirRel . '/' . basename($f);
            $duration = $this->probeDuration($rel);
            $this->log('⏱️ Chunk por duração', ['file' => basename($f), 'duration' => $duration]);
            $segments[] = [
                'path' => $rel,
                'duration' => $duration,
            ];
        }

        return $segments;
    }
}
