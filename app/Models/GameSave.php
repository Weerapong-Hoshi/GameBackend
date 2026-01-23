<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GameSave extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'data',
        'pity_count'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}