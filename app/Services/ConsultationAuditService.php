<?php

namespace App\Services;

use App\Models\Consultation;
use App\Models\ConsultationAuditLog;
use App\Models\Document;
use Illuminate\Support\Arr;

class ConsultationAuditService
{
    /**
     * Registra alteraÇõÇæes na consulta com valores anteriores e atuais.
     */
    public function record(
        Consultation $consultation,
        array $original,
        ?string $userId,
        string $action = 'update',
        ?array $fields = null
    ): void {
        $fields = $fields ?? array_keys($consultation->getChanges());

        $entries = [];
        foreach ($fields as $field) {
            $before = Arr::get($original, $field);
            $after = $consultation->getAttribute($field);

            if ($this->normalize($before) === $this->normalize($after)) {
                continue;
            }

            $entries[] = [
                'field' => $field,
                'before' => $before,
                'after' => $after,
            ];
        }

        if (empty($entries)) {
            return;
        }

        ConsultationAuditLog::create([
            'consultation_id' => $consultation->id,
            'user_id' => $userId,
            'action' => $action,
            'changes' => $entries,
        ]);
    }

    /**
     * Registra alterações em documentos associados a uma consulta.
     */
    public function recordDocument(Document $document, ?string $userId, string $action, array $changes): void
    {
        if (empty($changes)) {
            return;
        }

        $entries = array_map(function ($change) use ($document) {
            $field = $change['field'] ?? 'document';

            return [
                'field' => sprintf(
                    '%s.document.%s.%s',
                    $field,
                    (string) $document->type,
                    (string) $document->id
                ),
                'before' => $change['before'] ?? null,
                'after' => $change['after'] ?? null,
            ];
        }, $changes);

        ConsultationAuditLog::create([
            'consultation_id' => $document->consultation_id,
            'user_id' => $userId,
            'action' => $action,
            'changes' => $entries,
        ]);
    }

    private function normalize(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        if (is_array($value)) {
            ksort($value);

            return array_map(fn ($v) => $this->normalize($v), $value);
        }

        return $value;
    }
}
