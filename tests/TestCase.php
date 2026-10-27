<?php

namespace Tests;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Sign somebody in, past the one-time policy notice.
     *
     * Every signed-in page is behind that notice until the account has agreed
     * to it, which is the point of it -- but it is an onboarding step, not the
     * subject of the four hundred tests that follow it. Without this, each one
     * would have to click through a policy page to reach the thing it is
     * actually about, and a failure there would read as a failure of whatever
     * the test was checking.
     *
     * The notice itself is tested in PolicyMustBeAcceptedTest, which signs in
     * with actingAsWithoutPolicy() below and so meets it properly.
     */
    public function actingAs(Authenticatable $user, $guard = null)
    {
        if ($user instanceof User && ! $user->hasAcceptedPolicy()) {
            $user->forceFill([
                'policy_accepted_at' => now(),
                'policy_version' => User::POLICY_VERSION,
            ])->save();
        }

        return parent::actingAs($user, $guard);
    }

    /**
     * Sign somebody in as they really arrive: having agreed to nothing.
     */
    protected function actingAsWithoutPolicy(User $user, $guard = null)
    {
        return parent::actingAs($user, $guard);
    }

    /**
     * A valid sub-category for a category, for tests that do not care which.
     *
     * Filing requires one wherever the category offers a list, which is
     * every category but "Others". Most tests here are about routing,
     * visibility or notification and only need the form to be accepted --
     * so they ask for any valid answer rather than naming one, which also
     * means they do not have to be edited when a list changes.
     */
    protected function aSubcategoryFor(?string $category): ?string
    {
        return \App\Models\Concern::subcategoriesFor($category)[0] ?? null;
    }
}
