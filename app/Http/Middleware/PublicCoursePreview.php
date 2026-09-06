<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Nightwatch\Facades\Nightwatch;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class PublicCoursePreview
{
    public static function locale(Request $request): void
    {
        $locale = $request->hasSession() ? $request->session()->get('preview_locale') : null;
        app()->setLocale(in_array($locale, ['en', 'pt_BR'], true) ? $locale : 'pt_BR');
    }

    public function handle(Request $request, Closure $next): Response
    {
        Nightwatch::dontSample();
        self::locale($request);
        try {
            $playback = $request->routeIs('course-preview.playback');
            $key = 'course-preview:'.($playback ? 'playback:' : 'read:').hash('sha256', $request->ip());
            abort_if(RateLimiter::tooManyAttempts($key, $playback ? 30 : 60), 429);
            RateLimiter::hit($key, 60);
            $response = $next($request);
        } catch (Throwable $exception) {
            // Capability-bearing request paths must never enter generic exception logging.
            $response = self::error($request, $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 503);
        }

        return self::headers($response);
    }

    public static function headers(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    public static function error(Request $request, int $status): Response
    {
        $message = match ($status) {
            410 => __('This preview has ended.'),
            409 => __('This video is not available yet.'),
            429 => __('Too many requests. Please try again shortly.'),
            419 => __('Please reload this preview and try again.'),
            503, 500 => __('Preview temporarily unavailable. Please try again.'),
            default => __('This preview is unavailable.'),
        };

        return $request->expectsJson()
            ? response()->json(['error' => match ($status) {
                410 => 'preview_ended', 409 => 'media_unavailable', 429 => 'rate_limited', 419 => 'session_expired', 503, 500 => 'temporarily_unavailable', default => 'preview_unavailable'
            }, 'message' => $message], $status)
            : response()->view('course-preview.unavailable', ['message' => $message, 'token' => preg_match('/^[a-f0-9]{64}$/D', (string) $request->route('token')) ? $request->route('token') : null], $status);
    }
}
