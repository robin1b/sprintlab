<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Courses\CourseLibrary;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // The owner(s) of the app, listed by GitHub id in the config.
        Gate::define('admin', fn (User $user): bool => in_array(
            $user->github_id,
            (array) config('sprintlab.admin_github_ids'),
            true,
        ));

        // `{course}` in a route is a course file; an unknown slug is a 404.
        Route::bind('course', fn (string $slug) => $this->app->make(CourseLibrary::class)->find($slug) ?? abort(404));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        // Live, every link starts with APP_URL and https, whatever proxy or
        // folder the request came through.
        if (app()->isProduction()) {
            URL::forceHttps();
            URL::forceRootUrl((string) config('app.url'));
        }
    }
}
