<?php

namespace App\Http\Controllers;

use App\Models\Consultation;

class AnamnesisVersionController extends Controller
{
    public function index(string $consultationId)
    {
        $consultation = Consultation::findOrFail($consultationId);

        $versions = $consultation->anamnesisVersions()
            ->with('author')
            ->orderByDesc('version')
            ->get()
            ->map(function ($version) {
                return [
                    'id' => (string) $version->id,
                    'version' => (int) $version->version,
                    'anamnesis' => $version->anamnesis,
                    'summary' => $version->summary,
                    'transcription' => $version->transcription,
                    'metadata' => $version->metadata,
                    'author' => $version->author ? [
                        'id' => (string) $version->author->id,
                        'name' => $version->author->name,
                    ] : null,
                    'createdAt' => optional($version->created_at)->toIso8601String(),
                ];
            })
            ->values();

        return response()->json(['data' => $versions]);
    }
}
