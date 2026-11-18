<?php

namespace DemoMode\Models;

use Illuminate\Database\Eloquent\Model;

class DemoSetting extends Model
{
    protected $fillable = ['models', 'users'];

    protected function casts(): array
    {
        return ['models' => 'array', 'users' => 'array'];
    }
}
