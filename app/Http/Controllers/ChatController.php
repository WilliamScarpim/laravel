<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ChatController extends Controller
{
    public function respond(Request $request)
    {
        $data = $request->validate([
            'messages' => ['required', 'array'],
            'context' => ['nullable', 'array'],
        ]);

        $lastUserMessage = collect($data['messages'])
            ->where('role', 'user')
            ->pluck('content')
            ->last();

        $patientName = $data['context']['patient']['name'] ?? 'o paciente';

        return response()->json([
            'reply' => "Entendi a pergunta sobre \"{$lastUserMessage}\". Com base no contexto de {$patientName}, sugiro revisar sinais de alerta e orientar retorno em caso de piora.",
        ]);
    }
}
