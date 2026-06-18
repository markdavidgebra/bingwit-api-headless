<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // One reaction per user per catch — keep love when both exist.
        DB::statement("
            DELETE likes FROM likes
            INNER JOIN likes AS other
                ON likes.user_id = other.user_id
               AND likes.catch_id = other.catch_id
               AND likes.type = 'like'
               AND other.type = 'love'
        ");
    }

    public function down(): void
    {
        // Cannot restore removed duplicate reactions.
    }
};
