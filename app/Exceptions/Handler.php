<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<\Throwable>, \Psr\Log\LogLevel::*>
     */
    protected $levels = [
        //
    ];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<\Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        // "Page expired" (419) odatda jurnalga yozilmaydi — sababini aniqlash uchun qisqa izoh yoziladi
        // (CSRF, Livewire release token yoki Livewire yutib yuborgan TypeError — LivewireUpdateController).
        $this->renderable(function (Throwable $e, $request) {
            $status = $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? $e->getStatusCode() : null;
            if ($e instanceof \Illuminate\Session\TokenMismatchException || $status === 419) {
                $components = collect($request->input('components', []))->map(fn ($c) => [
                    'name' => json_decode($c['snapshot'] ?? '{}', true)['memo']['name'] ?? null,
                    'calls' => collect($c['calls'] ?? [])->pluck('method')->all(),
                ])->all();
                \Illuminate\Support\Facades\Log::warning('419 page expired', [
                    'exception' => class_basename($e), 'message' => \Illuminate\Support\Str::limit($e->getMessage(), 200),
                    'path' => $request->path(), 'user' => $request->user()?->id, 'has_session_cookie' => $request->hasCookie(config('session.cookie')),
                    'token_header' => (bool) $request->header('X-CSRF-TOKEN'), 'token_body' => (bool) $request->input('_token'),
                    'session_token_matches' => $request->hasSession() && hash_equals((string) $request->session()->token(), (string) ($request->input('_token') ?? $request->header('X-CSRF-TOKEN'))),
                    'components' => $components,
                ]);
            }

            return null;
        });
    }
}
