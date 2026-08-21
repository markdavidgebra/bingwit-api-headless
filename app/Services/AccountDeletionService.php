<?php

namespace App\Services;

use App\Models\AccountDeletionRequest;
use App\Models\BoatBooking;
use App\Models\Comment;
use App\Models\DirectMessage;
use App\Models\FishCatch;
use App\Models\FishingSpot;
use App\Models\Follow;
use App\Models\GroupMember;
use App\Models\GroupPost;
use App\Models\Like;
use App\Models\Notification;
use App\Models\ResortReview;
use App\Models\Review;
use App\Models\SavedSpot;
use App\Models\User;
use App\Models\UserBlock;
use App\Models\Vendor;
use App\Models\Wishlist;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class AccountDeletionService
{
    public const PUBLIC_MESSAGE = 'If an account exists for that email, we have received your deletion request. We typically complete requests within 30 days.';

    public const DELETED_EMAIL_SUFFIX = '@deleted.bingwit.invalid';

    /**
     * Record a deletion request without revealing whether the email exists.
     */
    public function request(string $email, ?string $accountIdentifier = null): void
    {
        $email = strtolower(trim($email));

        $user = User::query()->where('email', $email)->first();

        if (! $user || $this->isAnonymized($user)) {
            return;
        }

        $alreadyPending = AccountDeletionRequest::query()
            ->where('status', AccountDeletionRequest::STATUS_PENDING)
            ->where(function ($query) use ($user, $email) {
                $query->where('user_id', $user->id)
                    ->orWhere('email', $email);
            })
            ->exists();

        if ($alreadyPending) {
            return;
        }

        AccountDeletionRequest::query()->create([
            'user_id' => $user->id,
            'email' => $email,
            'account_identifier' => $accountIdentifier ?: null,
            'status' => AccountDeletionRequest::STATUS_PENDING,
            'requested_at' => now(),
        ]);
    }

    /**
     * Erase personal data and anonymize the user row so tournament,
     * booking, and wallet foreign keys remain intact.
     */
    public function process(AccountDeletionRequest $request, ?int $adminId = null): void
    {
        if (! $request->isPending()) {
            throw ValidationException::withMessages([
                'status' => ['Only pending requests can be processed.'],
            ]);
        }

        $request->update([
            'status' => AccountDeletionRequest::STATUS_PROCESSING,
            'processed_by' => $adminId,
        ]);

        DB::transaction(function () use ($request, $adminId) {
            $user = $request->user_id
                ? User::query()->find($request->user_id)
                : User::query()->where('email', $request->email)->first();

            if ($user && ! $this->isAnonymized($user)) {
                $this->erasePersonalData($user);
                $this->revokeAccess($user);
                $this->anonymizeUser($user);
            }

            $request->update([
                'status' => AccountDeletionRequest::STATUS_COMPLETED,
                'processed_at' => now(),
                'processed_by' => $adminId,
            ]);
        });
    }

    public function reject(AccountDeletionRequest $request, ?string $note, ?int $adminId = null): void
    {
        if (! $request->isPending()) {
            throw ValidationException::withMessages([
                'status' => ['Only pending requests can be rejected.'],
            ]);
        }

        $request->update([
            'status' => AccountDeletionRequest::STATUS_REJECTED,
            'admin_note' => $note,
            'processed_at' => now(),
            'processed_by' => $adminId,
        ]);
    }

    public function vendorWarningFor(AccountDeletionRequest $request): ?string
    {
        $email = $request->email;
        if (Vendor::query()->where('email', $email)->exists()) {
            return 'A vendor store exists with this email. Vendor accounts are separate and were not deleted.';
        }

        return null;
    }

    public function isAnonymized(User $user): bool
    {
        return str_ends_with((string) $user->email, self::DELETED_EMAIL_SUFFIX);
    }

    private function erasePersonalData(User $user): void
    {
        Like::withoutEvents(fn () => Like::query()->where('user_id', $user->id)->delete());
        Comment::withoutEvents(fn () => Comment::query()->where('user_id', $user->id)->delete());
        Follow::withoutEvents(function () use ($user) {
            Follow::query()
                ->where('follower_id', $user->id)
                ->orWhere('following_id', $user->id)
                ->delete();
        });

        DirectMessage::query()
            ->where('sender_id', $user->id)
            ->orWhere('recipient_id', $user->id)
            ->delete();

        Notification::query()->where('user_id', $user->id)->delete();
        Wishlist::query()->where('user_id', $user->id)->delete();
        Review::query()->where('user_id', $user->id)->delete();
        ResortReview::query()->where('user_id', $user->id)->delete();
        UserBlock::query()
            ->where('blocker_id', $user->id)
            ->orWhere('blocked_id', $user->id)
            ->delete();
        GroupPost::query()->where('user_id', $user->id)->delete();
        GroupMember::query()->where('user_id', $user->id)->delete();
        SavedSpot::query()->where('user_id', $user->id)->delete();

        FishingSpot::query()
            ->where('user_id', $user->id)
            ->each(function (FishingSpot $spot) {
                $spot->savedBy()->delete();
                $spot->delete();
            });

        FishCatch::query()
            ->where('user_id', $user->id)
            ->each(function (FishCatch $catch) {
                $catch->delete();
            });

        if (method_exists($user, 'clearMediaCollection')) {
            $user->clearMediaCollection('profile_picture');
        }

        BoatBooking::query()
            ->where('user_id', $user->id)
            ->update(['notes' => null]);
    }

    private function revokeAccess(User $user): void
    {
        $user->tokens()->delete();

        if (Schema::hasTable('sessions')) {
            DB::table('sessions')
                ->where('user_id', $user->id)
                ->delete();
        }
    }

    private function anonymizeUser(User $user): void
    {
        $user->forceFill([
            'name' => 'Deleted User',
            'email' => 'deleted_'.$user->id.'_'.time().self::DELETED_EMAIL_SUFFIX,
            'password' => Hash::make(bin2hex(random_bytes(32))),
            'profile_picture' => null,
            'bio' => null,
            'location' => null,
            'fishing_style' => null,
            'fish_points' => 0,
            'stars' => 0,
            'social_provider' => null,
            'social_id' => null,
            'remember_token' => null,
            'email_verified_at' => null,
        ])->save();
    }
}
