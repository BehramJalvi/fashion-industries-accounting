<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    protected $fillable = [
        'code',
        'name',
        'symbol',
        'is_base',
    ];

    protected function casts(): array
    {
        return [
            'is_base' => 'boolean',
        ];
    }
}
