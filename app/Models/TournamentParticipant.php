<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TournamentParticipant extends Model
{
    protected $fillable = [
        'tournament_id',
        'user_id',
        'status',
        'registered_at',
        'entry_fee_amount',
        'payment_status',
        'payment_method',
        'reference_number',
        'paymongo_checkout_id',
        'paymongo_payment_id',
        'paid_at',
    ];

    protected $casts = [
        'registered_at'    => 'datetime',
        'paid_at'          => 'datetime',
        'entry_fee_amount' => 'decimal:2',
    ];

    public static function newReference(): string
    {
        do {
            $ref = 'TNT-'.strtoupper(Str::random(10));
        } while (static::where('reference_number', $ref)->exists());

        return $ref;
    }

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', ['registered', 'confirmed'])
            ->whereIn('payment_status', ['paid', 'free']);
    }

    public function scopeOccupying(Builder $query): Builder
    {
        return $query->whereIn('status', ['registered', 'confirmed'])
            ->whereIn('payment_status', ['paid', 'free', 'unpaid']);
    }

    public function isSettled(): bool
    {
        return in_array($this->payment_status, ['paid', 'free'], true)
            && in_array($this->status, ['registered', 'confirmed'], true);
    }

    public function markPaid(?string $paymentId = null): void
    {
        if ($this->isSettled() && $this->payment_status === 'paid') {
            return;
        }

        DB::transaction(function () use ($paymentId) {
            $row = static::where('id', $this->id)->lockForUpdate()->first();
            if (! $row || ($row->isSettled() && $row->payment_status === 'paid')) {
                return;
            }

            $row->update([
                'status'             => $row->status === 'withdrawn' ? 'registered' : ($row->status ?: 'registered'),
                'payment_status'     => 'paid',
                'payment_method'     => 'paymongo',
                'paymongo_payment_id'=> $paymentId ?: $row->paymongo_payment_id,
                'paid_at'            => $row->paid_at ?? now(),
                'registered_at'      => $row->registered_at ?? now(),
            ]);
        });

        $this->refresh();
    }

    public function markFree(): void
    {
        $this->update([
            'status'          => in_array($this->status, ['registered', 'confirmed'], true)
                ? $this->status
                : 'registered',
            'payment_status'  => 'free',
            'payment_method'  => $this->payment_method ?: 'complimentary',
            'paid_at'         => $this->paid_at ?? now(),
            'registered_at'   => $this->registered_at ?? now(),
        ]);
    }

    public function markCancelled(): void
    {
        if ($this->isSettled()) {
            return;
        }

        $this->update([
            'payment_status' => 'cancelled',
            'status'         => 'withdrawn',
        ]);
    }
}
