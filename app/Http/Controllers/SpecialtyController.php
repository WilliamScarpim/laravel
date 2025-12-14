<?php

namespace App\Http\Controllers;

use App\Models\Specialty;
use Illuminate\Http\JsonResponse;

class SpecialtyController extends Controller
{
    public function index(): JsonResponse
    {
        $specialties = Specialty::orderBy('name')->get(['id', 'name']);

        return response()->json([
            'data' => $specialties->map(fn ($item) => [
                'id' => (string) $item->id,
                'name' => $item->name,
            ]),
        ]);
    }
}
