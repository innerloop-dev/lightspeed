<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
 * The demo signs you in as a throwaway user so that channel authorization has a
 * real session to work with.
 *
 * ONE BROWSER IS ONE PERSON, and that is not a limitation of the demo so much as
 * a fact about sessions. This route used to call User::create() and Auth::login()
 * on EVERY request, which read like "a per-tab user" and was nothing of the sort:
 * a session lives in one cookie, the cookie is shared by every tab in the
 * browser, and logging in a second user overwrites the first. Two tabs did not
 * become two people. The second tab silently took the first tab's session over,
 * and the first tab went on rendering the id it was printed with while its
 * /broadcasting/auth requests were being answered for somebody else. Observed in
 * a browser: a tab showing "user 2" was refused a grant for its own
 * private-inbox.2 and issued one for private-inbox.3, then received a message
 * addressed to user 3 only. That is cross-user data exposure, and it is exactly
 * the shape of bug this pattern would put into an application that copied it.
 *
 * So: create a user only when this session does not already have one. To be two
 * people, be two sessions. A private/incognito window is the quickest one; a
 * second browser or a second browser profile works the same way.
 */

/*
 * A closure rather than a named function, because a routes file can be loaded
 * more than once in a long-lived worker and a second `function` declaration is a
 * fatal error.
 */
$demoUser = function (string $prefix): User {
    // Auth::user() is the whole guard. Without it, every request mints an
    // identity and reassigns the session's, including the plain page refresh
    // that the other tab is not expecting.
    if ($existing = Auth::user()) {
        return $existing;
    }

    $user = User::create([
        'name' => $prefix.' '.fake()->firstName(),
        'email' => uniqid(strtolower($prefix), true).'@example.test',
        'password' => bcrypt(str()->random()),
    ]);

    Auth::login($user);

    return $user;
};

Route::get('/', function () use ($demoUser) {
    $user = $demoUser('Guest');

    return view('welcome', [
        'userId' => $user->id,
        'appKey' => config('lightspeed.reverb_compat.app_key'),
        'port' => config('lightspeed.server.port'),
    ]);
});

/*
 * The same demo, driven by real Laravel Echo instead of raw pusher-js. The
 * client config is resources/js/echo.js, which is the snippet in
 * docs/configuration.md verbatim, so this page is what proves the documented
 * path works rather than merely reading like it should.
 *
 * Same session, so the same person: opening /echo next to / does not hand you a
 * second identity, and it no longer takes the first tab's away either.
 */

Route::get('/echo', function () use ($demoUser) {
    $user = $demoUser('Echo');

    return view('echo-page', ['userId' => $user->id]);
});
