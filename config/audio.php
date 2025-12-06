<?php

return [
    'debug_log' => (bool) env('AUDIO_DEBUG_LOG', false),

    // Força split mesmo em áudios curtos
    'force_split' => (bool) env('AI_FORCE_SPLIT', false),

    // Intervalo mínimo entre cortes por silêncio (segundos)
    'split_min_interval' => (int) env('AI_SPLIT_DURATION', 300),

    // Nível de silêncio e duração mínima para detectar (ex.: "-25dB" / segundos)
    'silence_threshold' => env('AI_SILENCE_DB', '-25dB'),
    'silence_duration' => (float) env('AI_SILENCE_DURATION', 3.0),

    // Duração máxima de cada segmento quando não há silêncio (segundos)
    'max_segment_seconds' => (int) env('AI_MAX_SEGMENT_SECONDS', 60),
];
