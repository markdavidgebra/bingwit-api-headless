<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AccountDeletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountDeletionRequestController extends Controller
{
    public function __construct(private AccountDeletionService $deletions) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'account_identifier' => ['nullable', 'string', 'max:64'],
            'confirm' => ['accepted'],
        ]);

        $this->deletions->request(
            $data['email'],
            $data['account_identifier'] ?? null
        );

        return response()->json([
            'message' => AccountDeletionService::PUBLIC_MESSAGE,
        ]);
    }
}
