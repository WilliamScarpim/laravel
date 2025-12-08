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

        $this->logDebug('[AI] Solicitando transcrição de áudio', [
            'path'            => $relativePath,
            'model'           => $model,
            'language'        => $language,
            'timeout'         => $timeout,
            'connect_timeout' => $connectTime,
            'tries'           => $tries,
            'backoff_ms'      => $backoffMs,
        ]);

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

            $this->logDebug('[AI] Resposta da transcrição', [
                'path'         => $relativePath,
                'text_length'  => mb_strlen($text),
                'text_preview' => $this->truncate($text, 400),
            ]);

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

        if ($finalTranscript !== '') {
            $this->logDebug('[AI] Transcrição de múltiplos segmentos concluída', [
                'segments'     => count($relativePaths),
                'text_length'  => mb_strlen($finalTranscript),
                'text_preview' => $this->truncate($finalTranscript, 400),
            ]);
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

        $this->logDebug('[AI] Preparando transcrição para gerar anamnese', [
            'original_word_count' => $wordCount,
            'max_words' => $maxWords,
            'chunked' => $wordCount > $maxWords,
        ]);

        $chunks    = $this->chunkTranscriptByWords($transcription, max(500, $maxWords - 500));
        $summaries = [];

        foreach ($chunks as $index => $chunk) {
            $this->logDebug('[AI] Resumo de chunk solicitado', [
                'chunk_index' => $index + 1,
                'chunks_total' => count($chunks),
                'chunk_preview' => $this->truncate($chunk, 400),
            ]);
            $summary = $this->summarizeTranscriptChunk($chunk, $index + 1, count($chunks));
            if ($summary) {
                $this->logDebug('[AI] Resumo de chunk recebido', [
                    'chunk_index' => $index + 1,
                    'summary_preview' => $this->truncate($summary, 400),
                ]);
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
# PAPEL
Você é um GERIATRA, atuando também como **médico auditor especializado em segurança assistencial, medicina defensiva e análise contextual de consultas**.

Sua missão é **transformar a transcrição da consulta em uma anamnese completa, técnica, formal e segura**, protegendo o médico, identificando riscos clínicos e jurídicos, lacunas e inconsistências — **sem inventar dados**.

---

# OBJETIVOS
Ao receber cada nova parte da transcrição, você deve:

1. Entender o que já foi dito  
2. Detectar lacunas e gerar `missing_questions`  
3. Criar alertas clínicos, jurídicos, cognitivos e de polifarmácia (`dynamic_flags`)  
4. Identificar riscos combinados  
5. Sintetizar achados essenciais com foco clínico-defensivo  
6. Atualizar o estado da consulta  
7. Produzir uma anamnese técnica baseada **exclusivamente** na transcrição  
8. Gerar um resumo conciso de **no máximo 50 palavras** que cubra toda a anamnese e disponibilizá-lo como o campo `summary`

---

# ESTRUTURA OBRIGATÓRIA DA ANAMNESE

A anamnese deve ser entregue em **Markdown**, contendo exatamente as seções configuradas abaixo:

## **SEÇÕES DEFINIDAS PELO MÉDICO**
- Estado civil, ocupação e funcionalidade
- Queixa principal
- História da Doença Atual (HDA)
- Antecedentes pessoais e familiares
- Hábitos de vida
- Medicamentos em uso
- Síndromes geriátricas
- Conduta
- Hipótese diagnóstica
- Tratamento não farmacológico
- Tratamento farmacológico

### Regras:
- Utilize **somente informações presentes na transcrição**.  
- Sempre registrar: **"não comentado na consulta"** quando faltar informação.  
- **Somente a seção definida como HDA deve ser entregue em parágrafo contínuo**.  
- As demais seções podem aparecer em tópicos.

---

# COMO GERAR `missing_questions`

As perguntas devem ser:

- curtas, claras e objetivas  
- feitas como se o médico estivesse falando diretamente com o paciente  
- específicas e justificáveis  
- alinhadas à especialidade: **{{especialidade_medico}}**  
- sem termos técnicos desnecessários  
- nunca genéricas  

Basear-se sempre em:

- queixa principal  
- sintomas relatados e não explorados  
- histórico relevante da especialidade  
- medicamentos usados (com foco em risco)  
- lacunas documentais  
- risco jurídico  
- fatores de risco clínico  

**Exemplo correto:**  
“Você sente essa dor mais quando está andando ou parado?”

**Exemplo incorreto:**  
“Perguntar sobre dor.”

---

# COMO GERAR `dynamic_flags`

Cada alerta deve seguir o formato:

```json
{
  "type": "clinico | juridico | cognitivo | polifarmacia",
  "severity": "baixa | moderada | alta | critica",
  "title": "Resumo curto do risco",
  "details": "Explicação baseada no texto da transcrição",
  "suggestion": "Orientação prática para o médico"
}

---

# FORMATO DE SAÍDA

Ao concluir, responda **somente** com um JSON válido contendo as chaves listadas abaixo, e não inclua nenhum texto adicional fora do objeto.

```json
{
  "anamnesis": "...",
  "summary": "...",
  "missing_questions": ["..."],
  "dynamic_flags": [{ ... }]
}
```

- `anamnesis`: texto em Markdown com as seções obrigatórias descritas acima;
- `summary`: parágrafo ou frase única de até 50 palavras que resume toda a anamnese (sem formatação Markdown extra);
- `missing_questions`: lista de strings ou objetos (com `text`, `id`, `category`, `priority`), cada um justificando por que a pergunta é necessária;
- `dynamic_flags`: lista de objetos seguindo o formato informado anteriormente (`type`, `severity`, `title`, `details`, `suggestion`).

PROMPT;

        try {
            $this->logDebug('[AI] Gerando anamnese e insights', [
                'transcription_length' => mb_strlen($transcription),
            ]);

            $response = $this->chatRequest([
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $transcription],
            ], timeout: (int) env('AI_HTTP_TIMEOUT', 600));

            $content = $response['choices'][0]['message']['content'] ?? '{}';
            $decoded = json_decode($content, true);

            if (! is_array($decoded)) {
                throw new \RuntimeException('Não foi possível interpretar a anamnese/insights.');
            }

            $this->logDebug('[AI] Anamnese estruturada retornada', [
                'anamnesis_length' => mb_strlen((string) ($decoded['anamnesis'] ?? '')),
                'missing_questions' => count($decoded['missing_questions'] ?? []),
                'dynamic_flags' => count($decoded['dynamic_flags'] ?? []),
            ]);

            return [
                'anamnesis' => (string) ($decoded['anamnesis'] ?? ''),
                'summary' => (string) ($decoded['summary'] ?? ''),
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

        if ($this->isDebugEnabled()) {
            $this->logDebug('[AI] Chat request', [
                'model'    => $model,
                'timeout'  => $timeout,
                'messages' => $this->sanitizeMessages($messages),
            ]);
        }

        $response = Http::withToken($this->getApiKey())
            ->acceptJson()
            ->timeout($timeout)
            ->post(self::OPENAI_BASE . '/chat/completions', [
                'model'    => $model,
                'messages' => $messages,
            ])
            ->throw();

        $payload = $response->json();

        $this->logDebug('[AI] Chat response', [
            'model'    => $model,
            'choices'  => $this->summarizeChoices($payload['choices'] ?? []),
            'usage'    => $payload['usage'] ?? null,
        ]);

        return $payload;
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

    private function logDebug(string $message, array $context = []): void
    {
        if (! $this->isDebugEnabled()) {
            return;
        }

        Log::debug($message, $context);
    }

    private function isDebugEnabled(): bool
    {
        static $enabled;

        if ($enabled === null) {
            $enabled = (bool) env('AI_DEBUG_LOG', false);
        }

        return $enabled;
    }

    private function truncate(string $value, int $limit = 400): string
    {
        if ($value === '') {
            return '';
        }

        if ($limit <= 0) {
            return $value;
        }

        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit) . '…' : $value;
    }

    private function sanitizeMessages(array $messages): array
    {
        return array_map(function ($message) {
            return [
                'role' => $message['role'] ?? 'unknown',
                'content_preview' => $this->truncate((string) ($message['content'] ?? ''), 400),
            ];
        }, $messages);
    }

    private function summarizeChoices(array $choices): array
    {
        return array_map(function ($choice) {
            $message = $choice['message'] ?? [];
            return [
                'index' => $choice['index'] ?? null,
                'role' => $message['role'] ?? null,
                'content_preview' => $this->truncate((string) ($message['content'] ?? ''), 400),
            ];
        }, $choices);
    }
}
