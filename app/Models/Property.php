<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Property extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'damp_history' => 'boolean',
        'household_vulnerable' => 'boolean',
    ];

    public function reports(): HasMany
    {
        return $this->hasMany(RepairReport::class);
    }
}
