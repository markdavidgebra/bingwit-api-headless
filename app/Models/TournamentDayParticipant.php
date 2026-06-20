<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TournamentDayParticipant extends Model
{
    protected $fillable = [
        'tournament_day_id',
        'user_id',
        'tournament_participant_id',
    ];

    public function tournamentDay()
    {
        return $this->belongsTo(TournamentDay::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function tournamentParticipant()
    {
        return $this->belongsTo(TournamentParticipant::class);
    }
}
