<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * A setting the office can change from the admin pages, rather than from a
 * hosting panel and a redeploy.
 *
 * Each row is one named value. The environment variable that used to hold
 * it stays as the default, so a deploy with no rows behaves exactly as it
 * did before; a row, once written, wins.
 */
class Setting extends Model
{
    /** The test-account sign-in dropdown on the login page. */
    public const DEMO_LOGIN = 'demo_login';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    /**
     * Every setting, read once per request.
     *
     * A static memo rather than the cache: there are a handful of rows, the
     * login page reads one of them, and a cached copy that outlives a write
     * is a switch somebody flicked that did not move. Correctness is worth
     * more here than the query.
     */
    private static ?array $memo = null;

    private static function rows(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        // The table arrives in a migration, and code is live before
        // migrations finish on some deploys. A missing table must not take
        // the SIGN-IN PAGE down with it -- every setting simply falls back
        // to its default until the table is there.
        try {
            if (! Schema::hasTable('settings')) {
                return [];
            }

            return self::$memo = static::query()->pluck('value', 'key')->all();
        } catch (Throwable) {
            return [];
        }
    }

    /** Forget the memo, so the next read sees what was just written. */
    public static function forget(): void
    {
        self::$memo = null;
    }

    /**
     * A yes/no setting, with the value to use when nobody has set one.
     */
    public static function flag(string $key, bool $default = false): bool
    {
        $value = self::rows()[$key] ?? null;

        return $value === null ? $default : $value === '1';
    }

    /** Write a yes/no setting and make the next read see it. */
    public static function putFlag(string $key, bool $on): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $on ? '1' : '0']);

        self::forget();
    }
}
