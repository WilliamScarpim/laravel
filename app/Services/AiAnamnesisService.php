<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AiAnamnesisService
{
    private const OPENAI_BASE = 'https://api.openai.com/v1';

    public function transcribeRelativePath(string $relativePath): string
    {
        $disk = Storage::disk('local');

        if (! $disk->exists($relativePath)) {
            Log::error('[AI] Arquivo para transcrição não encontrado', [
                'path' => $relativePath,
            ]);

            throw new \RuntimeException('Arquivo não encontrado para transcrição.');
        }

        $text = $this->transcribeOne($relativePath);

        if ($text === null || trim($text) === '') {
            throw new \RuntimeException('Falha ao transcrever o áudio.');
        }

        return trim($text);
    }

    public function transcribeOne(string $relativePath): ?string
    {
        $absolutePath = Storage::disk('local')->path($relativePath);

        if (! is_file($absolutePath)) {
            Log::error('[AI] Caminho de áudio inválido para transcrição', [
                'relative' => $relativePath,
                'absolute' => $absolutePath,
            ]);

            return null;
        }

        $apiKey      = $this->getApiKey();
        $model       = env('AI_TRANSCRIBE_MODEL', 'whisper-1');
        $language    = env('AI_LANGUAGE', 'pt');
        $timeout     = (int) env('AI_HTTP_TIMEOUT', 90);
        $connectTime = (int) env('AI_HTTP_CONNECT_TIMEOUT', 10);
        $tries       = max(1, (int) env('AI_HTTP_TRIES', 3));
        $backoffMs   = (int) env('AI_HTTP_BACKOFF_MS', 1500);

        $handle = fopen($absolutePath, 'r');
        if ($handle === false) {
            Log::error('[AI] Não foi possível abrir o arquivo para transcrição', [
                'absolute' => $absolutePath,
            ]);

            return null;
        }

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout($timeout)
                ->connectTimeout($connectTime)
                ->retry(
                    max($tries - 1, 0),
                    $backoffMs,
                    fn ($exception) => $this->shouldRetryRequest($exception),
                    throw: true
                )
                ->asMultipart()
                ->attach('file', $handle, basename($absolutePath))
                ->post(self::OPENAI_BASE . '/audio/transcriptions', [
                    'model'           => $model,
                    'language'        => $language,
                    'response_format' => 'json',
                ])
                ->throw();

            $payload = $response->json();
            $text    = is_array($payload) ? ($payload['text'] ?? '') : '';

            return trim((string) $text) ?: null;
        } catch (ConnectionException|RequestException $exception) {
            Log::error('[AI] Falha HTTP ao transcrever áudio', [
                'message' => $exception->getMessage(),
                'code'    => $exception->getCode(),
            ]);

            return null;
        } catch (\Throwable $exception) {
            Log::error('[AI] Erro inesperado ao transcrever áudio', [
                'message' => $exception->getMessage(),
            ]);

            return null;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    public function transcribeMany(array $relativePaths): ?string
    {
        $finalTranscript = '';

        foreach ($relativePaths as $index => $path) {
            if (! is_string($path) || $path === '') {
                continue;
            }

            $partial = $this->transcribeOne($path);
            if ($partial && trim($partial) !== '') {
                $finalTranscript .= ($finalTranscript ? "\n\n" : '') . trim($partial);
            } else {
                Log::warning('[AI] Segmento ignorado ao transcrever lista', [
                    'segment' => $index + 1,
                    'path'    => $path,
                ]);
            }
        }

        return $finalTranscript !== '' ? $finalTranscript : null;
    }

    public function prepareTranscriptForAnamnesis(string $transcription): string
    {
        $maxWords  = (int) env('AI_MAX_TRANSCRIPT_WORDS', 4500);
        $wordCount = str_word_count($transcription);

        if ($wordCount <= $maxWords) {
            return $transcription;
        }

        $chunks    = $this->chunkTranscriptByWords($transcription, max(500, $maxWords - 500));
        $summaries = [];

        foreach ($chunks as $index => $chunk) {
            $summary = $this->summarizeTranscriptChunk($chunk, $index + 1, count($chunks));
            if ($summary) {
                $summaries[] = $summary;
            }
        }

        return $summaries ? implode("\n\n", $summaries) : $transcription;
    }

    /**
     * Gera anamnese e já retorna perguntas e alertas na mesma resposta.
     */
    public function buildAnamnesisAndInsights(string $transcription): ?array
    {
        $systemPrompt = <<<PROMPT
## Papel
Você é um médico geriatra experiente. Converta a transcrição em uma anamnese estruturada e traga, na mesma resposta, perguntas sugeridas e alertas.

## Instruções
- Use apenas informações presentes no texto.
- Mencione ausências como "não comentado".
- Anamnese em Markdown, seções: Queixa principal, HDA, Antecedentes, Hábitos, Medicamentos, Síndromes geriátricas, Conduta, Hipótese diagnóstica, Tratamento não farmacológico, Tratamento farmacológico.
- Gere perguntas específicas que o médico deve fazer a seguir.
- Gere alertas (flags) com type (clinico|juridico|cognitivo|polifarmacia), severity (baixa|moderada|alta|critica), title, details, suggestion.

## Formato de saída (JSON)
{
  "anamnesis": "texto markdown",
  "missing_questions": ["pergunta 1", "pergunta 2"],
  "dynamic_flags": [
    {
      "type": "clinico|juridico|cognitivo|polifarmacia",
      "severity": "baixa|moderada|alta|critica",
      "title": "resumo do risco",
      "details": "explicação",
      "suggestion": "ação prática"
    }
  ]
}
PROMPT;

        try {
            $response = $this->chatRequest([
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $transcription],
            ], timeout: (int) env('AI_HTTP_TIMEOUT', 600));

            $content = $response['choices'][0]['message']['content'] ?? '{}';
            $decoded = json_decode($content, true);

            if (! is_array($decoded)) {
                throw new \RuntimeException('Não foi possível interpretar a anamnese/insights.');
            }

            return [
                'anamnesis' => (string) ($decoded['anamnesis'] ?? ''),
                'missing_questions' => array_values(array_filter($decoded['missing_questions'] ?? [])),
                'dynamic_flags' => array_values(array_filter($decoded['dynamic_flags'] ?? [])),
            ];
        } catch (\Throwable $exception) {
            Log::error('[AI] Erro ao gerar anamnese e insights', [
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    public function runClinicalChat(string $anamnesis, string $message, array $history = []): string
    {
        $systemPrompt = <<<PROMPT
Você é um especialista clínico que auxilia médicos a interpretar uma anamnese geriátrica. Use linguagem direta, baseada em evidências e sempre referencie o texto recebido. Quando fizer recomendações, explique a justificativa clínica em poucas frases.
PROMPT;

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => "Contexto clínico atual:\n" . trim($anamnesis)],
        ];

        foreach ($history as $entry) {
            $role = $entry['role'] ?? null;
            $content = isset($entry['content']) ? trim((string) $entry['content']) : null;

            if (! in_array($role, ['user', 'assistant'], true) || $content === null || $content === '') {
                continue;
            }

            $messages[] = [
                'role'    => $role,
                'content' => $content,
            ];
        }

        $messages[] = ['role' => 'user', 'content' => trim($message)];

        try {
            $response = $this->chatRequest($messages);
            $content  = $response['choices'][0]['message']['content'] ?? '';

            if (! is_string($content) || trim($content) === '') {
                throw new \RuntimeException('A resposta da IA veio vazia.');
            }

            return trim($content);
        } catch (\Throwable $exception) {
            Log::error('[AI] Falha no chat clínico', [
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    private function chunkTranscriptByWords(string $text, int $maxWords): array
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $text));
        if ($normalized === '') {
            return [];
        }

        $words  = explode(' ', $normalized);
        $chunks = [];
        $buffer = [];

        foreach ($words as $word) {
            $buffer[] = $word;
            if (count($buffer) >= $maxWords) {
                $chunks[] = implode(' ', $buffer);
                $buffer   = [];
            }
        }

        if (! empty($buffer)) {
            $chunks[] = implode(' ', $buffer);
        }

        return $chunks;
    }

    private function summarizeTranscriptChunk(string $chunk, int $index, int $total): ?string
    {
        $prompt = <<<PROMPT
Você receberá o trecho {$index} de {$total} de uma transcrição longa. Resuma-o em um parágrafo claro, mantendo dados clínicos importantes e referências temporais.
PROMPT;

        try {
            $response = $this->chatRequest([
                ['role' => 'system', 'content' => $prompt],
                ['role' => 'user', 'content' => $chunk],
            ]);

            $content = $response['choices'][0]['message']['content'] ?? '';

            return trim((string) $content) ?: null;
        } catch (\Throwable $exception) {
            Log::warning('[AI] Não foi possível resumir parte da transcrição', [
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function chatRequest(array $messages, ?string $model = null, ?int $timeout = null): array
    {
        $model   = $model ?? env('AI_COMPLETION_MODEL', 'gpt-5-nano');
        $timeout = $timeout ?? (int) env('AI_HTTP_TIMEOUT', 120);

        $response = Http::withToken($this->getApiKey())
            ->acceptJson()
            ->timeout($timeout)
            ->post(self::OPENAI_BASE . '/chat/completions', [
                'model'    => $model,
                'messages' => $messages,
            ])
            ->throw();

        return $response->json();
    }

    private function shouldRetryRequest(\Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if ($exception instanceof RequestException) {
            $status = optional($exception->response)->status();
            return in_array($status, [408, 425, 429, 500, 502, 503, 504], true);
        }

        return false;
    }

    private function getApiKey(): string
    {
        $key = env('OPENAI_API_KEY');

        if (! $key) {
            throw new \RuntimeException('OPENAI_API_KEY não configurada.');
        }

        return $key;
    }
}

