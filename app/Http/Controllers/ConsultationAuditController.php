<?php

namespace App\Http\Controllers;

use App\Models\Consultation;

class ConsultationAuditController extends Controller
{
    public function index(string $consultationId)
    {
        $consultation = Consultation::findOrFail($consultationId);

        $logs = $consultation->auditLogs()
            ->with('author')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(function ($log) {
                return [
                    'id' => (string) $log->id,
                    'action' => $log->action,
                    'changes' => $log->changes,
                    'author' => $log->author ? [
                        'id' => (string) $log->author->id,
                        'name' => $log->author->name,
                    ] : null,
                    'createdAt' => optional($log->created_at)->toIso8601String(),
                ];
            })
            ->values();

        return response()->json(['data' => $logs]);
    }
}
