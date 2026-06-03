<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TournamentPost extends Model
{
    protected $fillable = [
        'tournament_id',
        'admin_id',
        'title',
        'body',
        'cross_post_to_feed',
    ];

    protected $casts = [
        'cross_post_to_feed' => 'boolean',
    ];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function admin()
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }

    /**
     * Scope: only posts that should leak into the global feed.
     */
    public function scopeAnnouncements($query)
    {
        return $query->where('cross_post_to_feed', true);
    }
}
