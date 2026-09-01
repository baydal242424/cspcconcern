<?php

namespace App\Http\Controllers;

use App\Models\Concern;
use App\Models\Feedback;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Admin-only analytics dashboard (per panel recommendation: "Dashboard is
 * only for admin"). Every other role is redirected to their concern list --
 * see index() below. Because only Admin ever reaches this, the aggregate
 * stats are always institution-wide; there is no per-role scoping to worry
 * about anymore.
 */
class DashboardController extends Controller
{
    /** Rolling window, in days, used for the "trending" comparison. */
    private const TREND_WINDOW_DAYS = 30;

    public function index()
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $role = optional($user->role)->name;

        if ($role !== 'Admin') {
            abort(403, 'The dashboard is available to Admin accounts only.');
        }

        // ------------------------------------------------------------------
        // ANALYTICS (aggregate, institution-wide, retained forever)
        // ------------------------------------------------------------------
        // Aggregate statistics count EVERY concern ever submitted, resolved or
        // not, so the institution can analyse the most common concerns over
        // time. These are counts only -- they never expose who submitted what.
        $analyticsBase = Concern::query();

        $currentWindowStart = now()->subDays(self::TREND_WINDOW_DAYS);
        $previousWindowStart = now()->subDays(self::TREND_WINDOW_DAYS * 2);

        $totalConcerns = (clone $analyticsBase)->count();

        // A "trend" needs two points to compare, not just a raw count: how
        // many concerns came in during the current window vs. the window
        // before it, expressed as a percentage change.
        $recentTrendCount = (clone $analyticsBase)->where('created_at', '>=', $currentWindowStart)->count();
        $previousTrendCount = (clone $analyticsBase)
            ->whereBetween('created_at', [$previousWindowStart, $currentWindowStart])
            ->count();
        // NULL when there is nothing to compare against, which is not the same
        // as no change. A percentage needs a non-zero baseline: going from 0 to
        // 14 is not a 100% rise, it is undefined -- and reporting it as 100%
        // made a first month of data look identical to a doubling from 7. The
        // view shows the two raw counts instead when this is null.
        $trendChangePercent = $previousTrendCount > 0
            ? round((($recentTrendCount - $previousTrendCount) / $previousTrendCount) * 100)
            : null;

        $statusCounts = (clone $analyticsBase)
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')->pluck('count', 'status')->all();

        $urgencyCounts = (clone $analyticsBase)
            ->select('urgency', DB::raw('count(*) as count'))
            ->groupBy('urgency')->pluck('count', 'urgency')->all();

        // Trending categories: current window vs. previous window, so a
        // category can be flagged as rising/falling, not just "most common".
        $currentCategoryCounts = (clone $analyticsBase)
            ->where('created_at', '>=', $currentWindowStart)
            ->select('category', DB::raw('count(*) as count'))
            ->groupBy('category')->pluck('count', 'category')->all();

        $previousCategoryCounts = (clone $analyticsBase)
            ->whereBetween('created_at', [$previousWindowStart, $currentWindowStart])
            ->select('category', DB::raw('count(*) as count'))
            ->groupBy('category')->pluck('count', 'category')->all();

        $trendingCategories = collect($currentCategoryCounts)
            ->map(function ($count, $category) use ($previousCategoryCounts) {
                $previous = $previousCategoryCounts[$category] ?? 0;
                return [
                    'count' => $count,
                    'previous' => $previous,
                    'direction' => $count <=> $previous, // 1 up, 0 flat, -1 down
                ];
            })
            ->sortByDesc('count')
            ->take(5);

        // All-time category totals for annual analysis (never shrinks).
        $categoryTotals = (clone $analyticsBase)
            ->select('category', DB::raw('count(*) as count'))
            ->groupBy('category')->orderByDesc('count')
            ->pluck('count', 'category')->all();

        $trendingDepartments = (clone $analyticsBase)
            ->where('created_at', '>=', $currentWindowStart)
            ->select('department', DB::raw('count(*) as count'))
            ->groupBy('department')->orderByDesc('count')->limit(5)
            ->pluck('count', 'department')->all();

        // Reporter satisfaction, from feedback left on resolved concerns.
        $averageRating = round((float) Feedback::avg('rating'), 1);
        $feedbackCount = Feedback::count();

        // ------------------------------------------------------------------
        // RECENT CONCERNS (per-record access controlled by visibleTo)
        // ------------------------------------------------------------------
        $recentConcerns = Concern::query()
            ->visibleTo($user)
            ->with('user')
            ->latest()
            ->limit(8)
            ->get();

        return view('dashboard', compact(
            'role',
            'totalConcerns',
            'recentTrendCount',
            'previousTrendCount',
            'trendChangePercent',
            'statusCounts',
            'urgencyCounts',
            'trendingCategories',
            'categoryTotals',
            'trendingDepartments',
            'averageRating',
            'feedbackCount',
            'recentConcerns'
        ));
    }
}