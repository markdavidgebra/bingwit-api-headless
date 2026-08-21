<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccountDeletionRequest;
use App\Services\AccountDeletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccountDeletionAdminController extends Controller
{
    public function __construct(private AccountDeletionService $deletions) {}

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status', 'pending');

        $query = AccountDeletionRequest::query()
            ->with(['user:id,name,email', 'processor:id,name,email'])
            ->latest('requested_at');

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        return response()->json($query->paginate(30));
    }

    public function show($id): JsonResponse
    {
        $item = AccountDeletionRequest::query()
            ->with(['user:id,name,email,location,created_at', 'processor:id,name,email'])
            ->findOrFail($id);

        return response()->json([
            'request' => $item,
            'vendor_warning' => $this->deletions->vendorWarningFor($item),
        ]);
    }

    public function process(Request $request, $id): JsonResponse
    {
        $item = AccountDeletionRequest::query()->findOrFail($id);

        try {
            $this->deletions->process($item, $request->user()?->id);
        } catch (ValidationException $e) {
            throw $e;
        }

        $item->refresh()->load(['user:id,name,email', 'processor:id,name,email']);

        return response()->json([
            'message' => 'Account deletion request processed.',
            'request' => $item,
            'vendor_warning' => $this->deletions->vendorWarningFor($item),
        ]);
    }

    public function reject(Request $request, $id): JsonResponse
    {
        $item = AccountDeletionRequest::query()->findOrFail($id);

        $data = $request->validate([
            'admin_note' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', Rule::in([AccountDeletionRequest::STATUS_REJECTED])],
        ]);

        $this->deletions->reject(
            $item,
            $data['admin_note'] ?? null,
            $request->user()?->id
        );

        return response()->json([
            'message' => 'Account deletion request rejected.',
            'request' => $item->fresh(['user:id,name,email', 'processor:id,name,email']),
        ]);
    }
}
