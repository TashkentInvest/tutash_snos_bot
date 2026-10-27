<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * Configure the rate limiters for the application.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Mini App: mobil operatorlarda ko'p xodim bitta umumiy IP (CGNAT) orqali chiqadi, shuning uchun limit
        // Telegram foydalanuvchisi bo'yicha. Imzo controllerda tekshiriladi; soxta id bilan aylanib o'tishga qarshi — umumiy IP limiti.
        foreach (['tg-app' => 60, 'tg-state' => 30, 'tg-location' => 20, 'tg-media' => 40, 'tg-video' => 10] as $name => $perUser) {
            RateLimiter::for($name, fn (Request $request) => [
                Limit::perMinute($perUser)->by($name.':u:'.(self::telegramUserId($request) ?? 'ip:'.$request->ip())),
                Limit::perMinute($perUser * 20)->by($name.':ip:'.$request->ip()),
            ]);
        }
    }

    /** initData ichidagi Telegram foydalanuvchi id'si (faqat limit kaliti uchun; imzo bu yerda tekshirilmaydi). */
    private static function telegramUserId(Request $request): ?int
    {
        parse_str((string) $request->input('initData'), $data);
        $user = json_decode((string) ($data['user'] ?? ''), true);

        return is_array($user) && isset($user['id']) ? (int) $user['id'] : null;
    }
}
