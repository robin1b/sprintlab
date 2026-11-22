<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\GitHub\Onboarding;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

class GitHubController extends Controller
{
    public function __construct(private readonly Onboarding $onboarding) {}

    /**
     * Send the visitor to GitHub to authorize.
     */
    public function redirect(): SymfonyRedirect
    {
        return Socialite::driver('github')->redirect();
    }

    /**
     * Handle the return from GitHub.
     */
    public function callback(Request $request): RedirectResponse
    {
        if ($request->has('error')) {
            return $this->failed();
        }

        try {
            $githubUser = Socialite::driver('github')->user();
        } catch (Throwable $e) {
            report_if(! $e instanceof \InvalidArgumentException, $e);

            return $this->failed();
        }

        $user = $this->syncUser($githubUser);
        $this->onboarding->linkInstallationsOf($user);

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    private function syncUser(SocialiteUser $githubUser): User
    {
        $username = (string) $githubUser->getNickname();
        $name = $githubUser->getName() ?: $username;
        $email = $githubUser->getEmail();

        $user = User::firstOrNew(['github_id' => $githubUser->getId()]);

        $user->github_username = $username;
        $user->name = $name;
        $user->avatar_url = $githubUser->getAvatar();

        if ($user->email === null && $email && ! $this->emailTakenByOther($email, $user)) {
            $user->email = $email;
        }

        $user->save();

        return $user;
    }

    private function emailTakenByOther(string $email, User $user): bool
    {
        return User::query()
            ->where('email', $email)
            ->when($user->exists, fn ($query) => $query->whereKeyNot($user->getKey()))
            ->exists();
    }

    private function failed(): RedirectResponse
    {
        return to_route('login')->with('error', 'GitHub sign-in was cancelled or failed. Please try again.');
    }
}
