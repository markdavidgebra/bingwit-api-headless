<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TournamentDay extends Model
{
    protected $fillable = [
        'tournament_id',
        'day_date',
        'label',
    ];

    protected $casts = [
        'day_date' => 'date',
    ];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function dayParticipants()
    {
        return $this->hasMany(TournamentDayParticipant::class);
    }

    public function catches()
    {
        return $this->hasMany(FishCatch::class, 'tournament_day_id');
    }

    public function isToday(): bool
    {
        return $this->day_date?->toDateString() === now()->toDateString();
    }
}
