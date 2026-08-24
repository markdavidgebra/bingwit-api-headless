<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BoatBooking;
use App\Models\FishingBoat;
use App\Models\Notification;
use App\Models\WalletTransaction;
use App\Services\WalletService;
use Illuminate\Http\Request;

class FishingBoatController extends Controller
{
    public function __construct(private WalletService $wallet)
    {
    }

    /**
     * GET /api/fishing-boats
     */
    public function index(Request $request)
    {
        $query = FishingBoat::with('media')
                            ->withCount([
                                'bookings as active_bookings_count' => function ($q) {
                                    $q->whereIn('status', ['pending', 'confirmed']);
                                },
                            ]);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        } else {
            $query->where('status', 'available');
        }

        $boats = $query->latest('id')->paginate(15);

        $userId = optional($request->user())->id;
        $boats->getCollection()->transform(function ($boat) use ($userId) {
            $boat->has_my_booking = $userId
                ? $boat->hasActiveBookingForUser($userId)
                : false;
            return $boat;
        });

        return response()->json($boats);
    }

    /**
     * GET /api/fishing-boats/{id}
     */
    public function show(Request $request, $id)
    {
        $boat = FishingBoat::with('media')
                           ->withCount([
                               'bookings as active_bookings_count' => function ($q) {
                                   $q->whereIn('status', ['pending', 'confirmed']);
                               },
                           ])
                           ->findOrFail($id);

        $userId = optional($request->user())->id;
        $boat->has_my_booking = $userId ? $boat->hasActiveBookingForUser($userId) : false;

        $myBooking = null;
        if ($userId) {
            $myBooking = BoatBooking::with('boat:id,name,slug')
                                    ->where('fishing_boat_id', $boat->id)
                                    ->where('user_id', $userId)
                                    ->whereIn('status', ['pending', 'confirmed'])
                                    ->latest('trip_at')
                                    ->first();
        }

        return response()->json([
            'boat'       => $boat,
            'my_booking' => $myBooking,
        ]);
    }

    /**
     * GET /api/boat-bookings  (auth) — current user's bookings
     */
    public function myBookings(Request $request)
    {
        $bookings = BoatBooking::with([
                                'boat' => function ($q) {
                                    $q->with('media');
                                },
                            ])
                            ->where('user_id', $request->user()->id)
                            ->latest('trip_at')
                            ->paginate(20);

        return response()->json($bookings);
    }

    /**
     * POST /api/fishing-boats/{id}/book  (auth)
     */
    public function book(Request $request, $id)
    {
        $boat = FishingBoat::findOrFail($id);

        if ($boat->status !== 'available') {
            return response()->json([
                'message' => 'This boat is not available for booking.',
            ], 422);
        }

        $data = $request->validate([
            'trip_at'           => 'required|date|after:now',
            'passengers_count'  => 'required|integer|min:1',
            'notes'             => 'nullable|string|max:1000',
        ]);

        if ($data['passengers_count'] > $boat->capacity) {
            return response()->json([
                'message' => "Maximum capacity for this boat is {$boat->capacity} passengers.",
            ], 422);
        }

        $tripAt = \Carbon\Carbon::parse($data['trip_at']);
        $tripDate = $tripAt->toDateString();

        $bookedPassengers = BoatBooking::where('fishing_boat_id', $boat->id)
                                       ->whereIn('status', ['pending', 'confirmed'])
                                       ->whereDate('trip_at', $tripDate)
                                       ->sum('passengers_count');

        if ($bookedPassengers + $data['passengers_count'] > $boat->capacity) {
            return response()->json([
                'message' => 'Not enough seats left on this date. Try another date or fewer passengers.',
            ], 422);
        }

        $existing = BoatBooking::where('fishing_boat_id', $boat->id)
                               ->where('user_id', $request->user()->id)
                               ->whereIn('status', ['pending', 'confirmed'])
                               ->first();

        if ($existing) {
            return response()->json([
                'message' => 'You already have an active booking for this boat. Cancel it first to book again.',
            ], 422);
        }

        // Flat trip price for the charter (not multiplied by passengers).
        $totalPrice = $boat->trip_price;

        $booking = BoatBooking::create([
            'fishing_boat_id'  => $boat->id,
            'user_id'          => $request->user()->id,
            'trip_at'          => $tripAt,
            'passengers_count' => $data['passengers_count'],
            'notes'            => $data['notes'] ?? null,
            'total_price'      => $totalPrice,
            'status'           => 'pending',
        ]);

        $fpBonus = $this->wallet->setting('stars_boat_booking', '5');
        $alreadyRewarded = WalletTransaction::where('user_id', $request->user()->id)
            ->where('type', 'commercial_boat_booking')
            ->where('reference_type', 'boat_booking')
            ->where('reference_id', $booking->id)
            ->exists();

        $fpGranted = 0;
        if ($fpBonus > 0 && ! $alreadyRewarded) {
            $this->wallet->creditFishPoints(
                $request->user(),
                $fpBonus,
                'commercial_boat_booking',
                'boat_booking',
                (int) $booking->id,
                'Fish Points from boat booking'
            );
            $fpGranted = $fpBonus;

            Notification::create([
                'user_id'        => $request->user()->id,
                'type'           => 'fish_points_gift',
                'title'          => "You earned {$fpBonus} Fish Points!",
                'body'           => 'Thanks for booking a fishing boat. Convert Fish Points to Stars in Rewards to claim items.',
                'reference_id'   => $booking->id,
                'reference_type' => 'boat_booking',
            ]);
        }

        return response()->json([
            'message' => $fpGranted > 0
                ? "Your fishing boat trip has been booked! +{$fpGranted} Fish Points earned."
                : 'Your fishing boat trip has been booked!',
            'booking' => $booking->load('boat'),
            'fish_points_earned' => $fpGranted,
        ], 201);
    }

    /**
     * DELETE /api/boat-bookings/{id}  (auth) — cancel own booking
     */
    public function cancelBooking(Request $request, $id)
    {
        $booking = BoatBooking::where('id', $id)
                              ->where('user_id', $request->user()->id)
                              ->firstOrFail();

        if ($booking->status === 'cancelled') {
            return response()->json(['message' => 'Booking is already cancelled.']);
        }

        if ($booking->status === 'completed') {
            return response()->json([
                'message' => 'Completed trips cannot be cancelled.',
            ], 422);
        }

        if ($booking->trip_at->isPast() && $booking->status === 'confirmed') {
            return response()->json([
                'message' => 'Past confirmed trips cannot be cancelled online. Contact the operator.',
            ], 422);
        }

        $booking->update(['status' => 'cancelled']);

        return response()->json([
            'message' => 'Your booking has been cancelled.',
        ]);
    }
}
