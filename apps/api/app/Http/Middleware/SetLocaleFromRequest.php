<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Picks Arabic or English for API messages from the Accept-Language header.
 * Arabic is the default when the header is missing or names neither language.
 */
class SetLocaleFromRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var list<string> $supported */
        $supported = config('groupbuy.locales');
        $default = $supported[0];

        $locale = $request->hasHeader('Accept-Language')
            ? $this->match($request->getLanguages(), $supported) ?? $default
            : $default;

        App::setLocale($locale);

        $response = $next($request);
        $response->headers->set('Content-Language', $locale);

        return $response;
    }

    /**
     * @param  list<string>  $requested  languages in the client's order of preference
     * @param  list<string>  $supported
     */
    private function match(array $requested, array $supported): ?string
    {
        foreach ($requested as $language) {
            $primary = strtolower(strtok(str_replace('_', '-', $language), '-'));

            if (in_array($primary, $supported, true)) {
                return $primary;
            }
        }

        return null;
    }
}
