<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FishCatch;
use App\Models\GearDonation;
use App\Models\Notification;
use Illuminate\Http\Request;

class GearDonationController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'catch_id'    => 'nullable|exists:catches,id',
            'recipient_id'=> 'required_without:catch_id|exists:users,id',
            'item_name'   => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'condition'   => 'nullable|string|max:40',
        ]);

        $recipientId = $request->recipient_id;
        $catchId     = $request->catch_id;

        if ($catchId) {
            $catch = FishCatch::findOrFail($catchId);
            $recipientId = $catch->user_id;
        }

        if ($recipientId == $request->user()->id) {
            return response()->json(['message' => 'You cannot donate gear to yourself.'], 422);
        }

        $donation = GearDonation::create([
            'donor_id'     => $request->user()->id,
            'recipient_id' => $recipientId,
            'catch_id'     => $catchId,
            'item_name'    => $request->item_name,
            'description'  => $request->description,
            'condition'    => $request->condition ?? 'good',
            'status'       => 'offered',
        ]);

        Notification::create([
            'user_id'        => $recipientId,
            'type'           => 'gear_donation',
            'title'          => $request->user()->name . ' wants to donate gear!',
            'body'           => 'Offered: ' . $donation->item_name,
            'reference_id'   => $donation->id,
            'reference_type' => 'gear_donation',
        ]);

        return response()->json([
            'message'  => 'Gear donation offered!',
            'donation' => $donation->load(['donor:id,name,profile_picture', 'recipient:id,name']),
        ], 201);
    }

    public function myDonations(Request $request)
    {
        $sent = GearDonation::with(['recipient:id,name,profile_picture', 'catch:id,fish_species'])
            ->where('donor_id', $request->user()->id)
            ->latest()
            ->get();

        $received = GearDonation::with(['donor:id,name,profile_picture', 'catch:id,fish_species'])
            ->where('recipient_id', $request->user()->id)
            ->latest()
            ->get();

        return response()->json([
            'sent'     => $sent,
            'received' => $received,
        ]);
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:accepted,declined,completed,cancelled',
        ]);

        $donation = GearDonation::findOrFail($id);
        $user     = $request->user();

        $allowed = match ($request->status) {
            'accepted', 'declined' => $donation->recipient_id === $user->id,
            'cancelled'            => $donation->donor_id === $user->id,
            'completed'            => $donation->recipient_id === $user->id
                && in_array($donation->status, ['accepted', 'offered']),
            default => false,
        };

        if (! $allowed) {
            return response()->json(['message' => 'You cannot update this donation.'], 403);
        }

        $donation->update(['status' => $request->status]);

        return response()->json([
            'message'  => 'Donation updated.',
            'donation' => $donation,
        ]);
    }
}
