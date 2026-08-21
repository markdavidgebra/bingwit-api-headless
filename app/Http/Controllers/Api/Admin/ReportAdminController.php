<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContentReport;
use App\Models\FishCatch;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReportAdminController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->query('status', 'open');

        $query = ContentReport::with([
            'reporter:id,name,email',
            'reportedUser:id,name,email',
            'reviewer:id,name',
        ])->latest();

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        return response()->json($query->paginate(30));
    }

    public function update(Request $request, $id)
    {
        $report = ContentReport::findOrFail($id);

        $data = $request->validate([
            'status' => ['required', Rule::in([
                ContentReport::STATUS_OPEN,
                ContentReport::STATUS_REVIEWED,
                ContentReport::STATUS_DISMISSED,
                ContentReport::STATUS_ACTIONED,
            ])],
            'admin_note' => 'nullable|string|max:2000',
            'delete_catch' => 'nullable|boolean',
        ]);

        if (! empty($data['delete_catch'])
            && $report->reportable_type === ContentReport::TYPE_CATCH) {
            $catch = FishCatch::find($report->reportable_id);
            $catch?->delete();
            $data['status'] = ContentReport::STATUS_ACTIONED;
        }

        $report->update([
            'status' => $data['status'],
            'admin_note' => $data['admin_note'] ?? $report->admin_note,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return response()->json([
            'message' => 'Report updated.',
            'report' => $report->fresh(['reporter:id,name', 'reportedUser:id,name', 'reviewer:id,name']),
        ]);
    }
}
