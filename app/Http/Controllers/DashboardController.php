<?php

namespace App\Http\Controllers;

use App\Models\Concern;
use App\Models\Feedback;
use App\Models\Section;
use App\Models\User;
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

        // Both administrator tiers. A Staff Admin covering while the System
        // Admin is away needs the same view of the queue.
        // hasAnyRole, not the primary role alone: somebody who covers
        // Staff Admin as a second hat opens the same page.
        if (! $user->hasAnyRole(['System Admin', 'Staff Admin'])) {
            abort(403, 'The dashboard is available to administrator accounts only.');
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
        // WHAT NEEDS SOMEBODY TODAY
        // ------------------------------------------------------------------
        // The counts above describe what has been filed; these describe what
        // is stuck.
        //
        // An unassigned open concern is one routing could not place: the role
        // that should take it has nobody in it, or the only holder is the
        // person the concern is about. The office still SEES it -- each one
        // has a standing view of its own categories -- but nobody owns it, so
        // nobody is answerable for it and the student has no handler on their
        // timeline.
        $unassignedOpen = (clone $analyticsBase)
            ->whereNull('assigned_to')
            ->whereNotIn('status', Concern::TERMINAL_STATUSES)
            ->count();

        // Staff waiting to be given the role they asked for at sign-up. Until
        // an admin decides, they hold Faculty/Staff and receive nothing.
        $pendingRoleRequests = User::whereNotNull('requested_role_id')->count();

        // Classes with nobody advising them. Their Academic, Physical, Safety
        // and Others concerns fall past the adviser tier to an instructor of
        // the college, which works but is not what the student expects.
        $term = Section::currentTerm();
        $classesThisTerm = Section::where('school_year', $term['school_year'])
            ->where('semester', $term['semester'])
            ->count();
        $classesWithoutAdviser = Section::where('school_year', $term['school_year'])
            ->where('semester', $term['semester'])
            ->whereNull('adviser_id')
            ->count();

        // ------------------------------------------------------------------
        // REFERRALS AND ESCALATIONS
        // ------------------------------------------------------------------
        // A referral is recorded on the concern itself -- status 'referred'
        // and the office it went to -- so these read from there rather than
        // from the referrals table.
        $referredOpen = (clone $analyticsBase)->where('status', 'referred')->count();

        // Every concern that was EVER referred, not only those still in
        // flight. "How often do Administrative concerns end up with Records?"
        // is a question about the year, and counting only the ones open right
        // now answered it with whatever happened to be on a desk this morning.
        $referralsByOffice = (clone $analyticsBase)
            ->whereNotNull('referred_to')
            ->select('referred_to', DB::raw('count(*) as count'))
            ->groupBy('referred_to')
            ->orderByDesc('count')
            ->pluck('count', 'referred_to')
            ->all();

        // Split by WHO was named, because an adviser is not "staff" in the
        // sense this system uses the word: the second picker on the form
        // offers deans, chairs, counsellors and offices, while the person a
        // student most often names is the teacher who advises their class.
        // Counting them as one number read as a stranger's complaint when it
        // was usually about their own adviser.
        $teachingRoles = ['Instructor', 'Adviser'];

        $aboutTeacherCount = (clone $analyticsBase)
            ->whereHas('aboutStaff.role', fn ($q) => $q->whereIn('name', $teachingRoles))
            ->count();

        $aboutOfficerCount = (clone $analyticsBase)
            ->whereHas('aboutStaff.role', fn ($q) => $q->whereNotIn('name', $teachingRoles))
            ->count();

        // Concerns that named a member of staff, and concerns where the
        // student asked not to reach their class adviser. Both mean somebody
        // senior is holding the case, and both are worth watching as a rate
        // rather than reading one by one.
        $aboutStaffCount = (clone $analyticsBase)->whereNotNull('about_staff_id')->count();
        $adviserBypassed = (clone $analyticsBase)->where('skip_adviser', true)->count();

        // There was an average time-to-resolve here. It was removed: an
        // average over every resolved concern hides the one that matters. Two
        // cases settled in an hour and one left for three days average out to
        // something comfortable, and the number gives nobody anything to do.
        // If a speed figure is wanted later, the useful one is the oldest
        // concern still waiting -- that names somebody who is waiting now.

        // ------------------------------------------------------------------
        // RECENT CONCERNS (per-record access controlled by visibleTo)
        // ------------------------------------------------------------------
        $recentConcerns = Concern::query()
            ->visibleTo($user)
            ->with('user')
            ->latest()
            ->limit(8)
            ->get();

        // ------------------------------------------------------------------
        // TIMELINE (per-record access controlled by visibleTo)
        // ------------------------------------------------------------------
        // A fortnight of the queue as a Gantt chart: one row per concern, a
        // bar from the day it was filed to the day it was settled, or to
        // today while it is still open. The numbers above say how much and
        // what kind; this says how long, which is the thing a list of counts
        // cannot show -- a bar that reaches today from two weeks ago is a
        // case nobody has closed, and it looks like one.
        $timelineStart = now()->copy()->subDays(13)->startOfDay();
        $timelineEnd = now()->copy()->endOfDay();

        $timelineDays = [];

        for ($day = $timelineStart->copy(); $day <= $timelineEnd; $day->addDay()) {
            $timelineDays[] = $day->copy();
        }

        // Institution-wide, like every other panel here and for the same
        // reason: only the two administrator tiers reach this page, and the
        // numbers above them already count the whole queue. Scoping this one
        // to what an administrator may personally OPEN would have left it
        // permanently empty -- their standing window is the Administrative
        // category alone, which is why Recent Concerns below shows them
        // nothing. A row is only made clickable where they can actually open
        // it; the rest are plain text, so nothing here promises a page that
        // then refuses them.
        $timelineRows = Concern::query()
            // Anything that overlaps the window: filed inside it, or filed
            // earlier and still running through it.
            ->where(function ($q) use ($timelineStart) {
                $q->where('created_at', '>=', $timelineStart)
                  ->orWhere(function ($open) use ($timelineStart) {
                      $open->where('created_at', '<', $timelineStart)
                           ->where(function ($w) use ($timelineStart) {
                               $w->whereNull('resolved_at')
                                 ->orWhere('resolved_at', '>=', $timelineStart);
                           });
                  });
            })
            ->latest('id')
            ->limit(10)
            ->get()
            ->pipe(function ($concerns) use ($user) {
                // One query for the lot, rather than a visibility check per row.
                $openable = Concern::query()
                    ->visibleTo($user)
                    ->whereIn('id', $concerns->pluck('id'))
                    ->pluck('id')
                    ->flip();

                return $concerns->map(fn (Concern $c) => tap($c, function ($row) use ($openable) {
                    $row->setAttribute('viewer_can_open', $openable->has($row->id));
                }));
            })
            ->map(function (Concern $concern) use ($timelineStart, $timelineDays) {
                $days = count($timelineDays);

                // Clamped to the window at both ends, so a case that started
                // before it still shows the part that falls inside.
                $from = max(0, $timelineStart->diffInDays($concern->created_at, false));
                $settled = $concern->resolved_at ?: now();
                $to = min($days - 1, (int) floor($timelineStart->diffInDays($settled, false)));

                $from = (int) floor(min($from, $days - 1));
                $to = max($from, $to);

                return [
                    'concern' => $concern,
                    // 1-based, because CSS grid columns are.
                    'column' => $from + 1,
                    'span' => ($to - $from) + 1,
                    'open' => ! in_array($concern->status, Concern::TERMINAL_STATUSES, true),
                    'canOpen' => (bool) $concern->getAttribute('viewer_can_open'),
                ];
            });

        return view('dashboard', compact(
            'role',
            'timelineDays',
            'timelineRows',
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
            'unassignedOpen',
            'pendingRoleRequests',
            'classesThisTerm',
            'classesWithoutAdviser',
            'referredOpen',
            'referralsByOffice',
            'aboutStaffCount',
            'aboutTeacherCount',
            'aboutOfficerCount',
            'adviserBypassed',
            'recentConcerns'
        ));
    }
}