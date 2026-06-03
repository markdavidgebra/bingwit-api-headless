<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\FishCatch;

class CatchAdminController extends Controller
{
    // DELETE ANY CATCH (admin moderation)
    public function destroy($id)
    {
        $catch = FishCatch::findOrFail($id);

        // Spatie deletes associated media when the model is removed.
        $catch->delete();

        return response()->json(['message' => 'Catch deleted.']);
    }
}
