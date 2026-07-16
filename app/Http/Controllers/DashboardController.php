<?php

namespace App\Http\Controllers;

use App\Models\Concern;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Role-aware dashboard. The aggregate stats (status/urgency/category
 * counts) are institution-wide for staff but own-concerns-only for
 * students, and being counts they never expose who submitted what. The
 * "recent concerns" list is per-record, so it goes through the usual
 * visibleTo() scope like everywhere else.
 */
class DashboardController extends Controller
{
    public function index()
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $role = optional($user->role)->name ?? 'User';

        // ------------------------------------------------------------------
        // ANALYTICS (aggregate, institution-wide, retained forever)
        // ------------------------------------------------------------------
        // Aggregate statistics count EVERY concern ever submitted, resolved or
        // not, so the institution can analyse the most common concerns over
        // time. These are counts only -- they never expose who submitted what,
        // and they are independent of per-record access control.
        //
        // Students only see their OWN aggregate numbers; staff roles see the
        // whole institution for planning.
        $analyticsBase = Concern::query();
        if ($role === 'Student') {
            $analyticsBase->where('user_id', $user->id);
        }

        $trendWindow = now()->subDays(30); // rolling 30-day "trending" window

        $totalConcerns = (clone $analyticsBase)->count();
        $recentTrendCount = (clone $analyticsBase)->where('created_at', '>=', $trendWindow)->count();

        $statusCounts = (clone $analyticsBase)
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')->pluck('count', 'status')->all();

        $urgencyCounts = (clone $analyticsBase)
            ->select('urgency', DB::raw('count(*) as count'))
            ->groupBy('urgency')->pluck('count', 'urgency')->all();

        // Trending categories: rolling 30-day window, ordered most-common first.
        $trendingCategories = (clone $analyticsBase)
            ->where('created_at', '>=', $trendWindow)
            ->select('category', DB::raw('count(*) as count'))
            ->groupBy('category')->orderByDesc('count')->limit(5)
            ->pluck('count', 'category')->all();

        // All-time category totals for annual analysis (never shrinks).
        $categoryTotals = (clone $analyticsBase)
            ->select('category', DB::raw('count(*) as count'))
            ->groupBy('category')->orderByDesc('count')
            ->pluck('count', 'category')->all();

        $trendingDepartments = (clone $analyticsBase)
            ->where('created_at', '>=', $trendWindow)
            ->select('department', DB::raw('count(*) as count'))
            ->groupBy('department')->orderByDesc('count')->limit(5)
            ->pluck('count', 'department')->all();

        // ------------------------------------------------------------------
        // RECENT CONCERNS (per-record access controlled by visibleTo)
        // ------------------------------------------------------------------
        // The clickable "recent" list still respects least-privilege: a user
        // only sees rows they are permitted to open.
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
            'statusCounts',
            'urgencyCounts',
            'trendingCategories',
            'categoryTotals',
            'trendingDepartments',
            'recentConcerns'
        ));
    }
}