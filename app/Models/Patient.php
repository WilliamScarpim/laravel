<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Patient extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'cpf',
        'name',
        'birth_date',
        'email',
    ];

    public function consultations(): HasMany
    {
        return $this->hasMany(Consultation::class);
    }
}
