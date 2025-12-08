<?php

namespace App\Services;

use App\Models\Document;

class DocumentVersionService
{
    public function record(Document $document, ?string $userId = null): void
    {
        $latest = $document->versions()->orderByDesc('version')->first();
        $nextVersion = ($latest?->version ?? 0) + 1;

        $hasChanges = ! $latest
            || $latest->title !== $document->title
            || trim((string) $latest->content) !== trim((string) $document->content);

        if (! $hasChanges && $nextVersion > 1) {
            return;
        }

        $document->versions()->create([
            'consultation_id' => $document->consultation_id,
            'user_id' => $userId,
            'version' => $nextVersion,
            'type' => $document->type,
            'title' => $document->title,
            'content' => $document->content,
        ]);
    }
}
