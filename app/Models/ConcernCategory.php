<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A kind of concern the college accepts.
 *
 * These were eleven names in a PHP constant. They are rows now so that the
 * office can add a twelfth without a deployment -- see the migration for why
 * that matters.
 *
 * The reads are cached for the life of the request. Every concern list, every
 * filing form and every routing decision asks for this list, and a table of
 * a dozen rows read thirty times in one request is thirty queries for an
 * answer that cannot have changed in between.
 */
class ConcernCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'label',
        'routing_role',
        'subcategories',
        'is_active',
        'is_builtin',
        'sort_order',
    ];

    protected $casts = [
        'subcategories' => 'array',
        'is_active' => 'boolean',
        'is_builtin' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * The per-request cache.
     *
     * Cleared by saved() and deleted() below, so a category added in one
     * request is live in the next -- and, more to the point, so a test that
     * adds one and then reads the list in the same request is not served the
     * list from before it existed.
     *
     * @var \Illuminate\Support\Collection<int, self>|null
     */
    private static $cache = null;

    protected static function booted(): void
    {
        static::saved(fn () => self::forget());
        static::deleted(fn () => self::forget());
    }

    /** Drop the per-request cache. */
    public static function forget(): void
    {
        self::$cache = null;
    }

    /**
     * Every category, in the order the filing form offers them.
     *
     * Named cached() rather than overriding Eloquent's all(): that method
     * is called by the framework and by anything expecting a plain,
     * uncached, unordered read, and quietly changing what it means is the
     * kind of trap that only shows up somewhere unrelated.
     *
     * Falls back to the constants when the table is not there yet -- during
     * the migration that creates it, and in any test that builds a schema
     * without running it. Without the fallback, a half-migrated database
     * would answer "there are no categories", and a system that accepts no
     * category accepts no concern.
     *
     * @return \Illuminate\Support\Collection<int, self>
     */
    public static function cached()
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        try {
            return self::$cache = self::query()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();
        } catch (\Throwable $e) {
            return self::$cache = self::fromTheConstants();
        }
    }

    /** The shipped eleven, as unsaved rows. Used only if the table is absent. */
    private static function fromTheConstants()
    {
        $order = 0;

        return collect(Concern::CATEGORIES)->map(function (string $name) use (&$order) {
            return new self([
                'name' => $name,
                'label' => Concern::CATEGORY_LABELS[$name] ?? $name,
                'routing_role' => \App\Http\Controllers\ConcernController::CATEGORY_ROUTING[$name] ?? null,
                'subcategories' => Concern::SUBCATEGORIES[$name] ?? null,
                'is_active' => $name !== Concern::WEBSITE_FAULT,
                'is_builtin' => true,
                'sort_order' => $order += 10,
            ]);
        });
    }

    /**
     * Every stored name, active or not.
     *
     * This is what the server validates against, and it deliberately
     * includes the retired ones: a concern filed years ago under a category
     * since taken off the form must still load, still filter and still be
     * acted on.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return self::cached()->pluck('name')->all();
    }

    /**
     * The ones the filing form offers.
     *
     * @return list<string>
     */
    public static function offered(): array
    {
        return self::cached()->where('is_active', true)->pluck('name')->values()->all();
    }

    /** One category by its stored name, or null. */
    public static function named(?string $name): ?self
    {
        if ($name === null) {
            return null;
        }

        return self::cached()->firstWhere('name', $name);
    }

    /** What a student reads for this category. */
    public static function labelFor(string $name): string
    {
        return optional(self::named($name))->label ?? $name;
    }

    /**
     * The sub-categories offered under it, which may be none.
     *
     * @return list<string>
     */
    public static function subcategoriesFor(string $name): array
    {
        return optional(self::named($name))->subcategories ?? [];
    }

    /** The role that handles it, or null to let routeConcern() decide. */
    public static function routingFor(string $name): ?string
    {
        return optional(self::named($name))->routing_role;
    }
}
