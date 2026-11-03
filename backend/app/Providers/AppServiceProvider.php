<?php

namespace App\Providers;

use App\Models\User;
use App\Support\MorphMap;
use App\Support\Permissions\PermissionCatalog;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

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
        Relation::enforceMorphMap(MorphMap::all());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
        Password::defaults(fn () => Password::min(10)->letters()->mixedCase()->numbers());

        // Every "<module>.<action>" permission key is a Gate ability, enforced on the backend.
        Gate::before(function (?Authenticatable $user, string $ability) {
            if (! PermissionCatalog::exists($ability)) {
                return null;
            }

            return $user instanceof User && $user->hasPermission($ability);
        });

        ResetPassword::createUrlUsing(function ($user, string $token) {
            return rtrim((string) config('promofy.frontend_url'), '/')
                .'/'.($user->locale ?? config('promofy.default_locale'))
                .'/reset-password?token='.$token.'&email='.urlencode($user->getEmailForPasswordReset());
        });

        $this->configureRateLimiting();
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(240)->by($request->user()?->getAuthIdentifier() ?: $request->ip()));

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(10)->by(Str::lower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(40)->by($request->ip()),
        ]);

        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(6)->by(
            ($request->hasSession() ? $request->session()->get('two_factor.id') : null) ?: $request->ip()
        ));

        // Public forms (register, contact, quote requests…): strict per-IP limit against spam.
        RateLimiter::for('forms', fn (Request $request) => Limit::perMinute(8)->by($request->ip()));
    }
}
