<?php

namespace App\Http\Controllers;

use App\Models\ServiceRequest;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * A small, organisation-scoped Service Request summary for the
     * authenticated operator's landing page. Counts only — no charting
     * library, no cross-organisation data.
     */
    public function __invoke(Request $request): Response
    {
        $counts = ServiceRequest::query()
            ->where('organisation_id', $request->user()->organisation_id)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $inProgress = $counts->only(['queued', 'processing', 'retrying'])->sum();
        $needsAttention = $counts->only(['failed', 'uncertain'])->sum();

        return Inertia::render('Dashboard', [
            'summary' => [
                'total' => $counts->sum(),
                'completed' => $counts->get('completed', 0),
                'inProgress' => $inProgress,
                'needsAttention' => $needsAttention,
            ],
        ]);
    }
}
