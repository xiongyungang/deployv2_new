<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;

class DebugDBLogs
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  \Closure                 $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $response = $next($request);
        if ($response instanceof JsonResponse) {
            $response->setEncodingOptions(JSON_UNESCAPED_UNICODE);
        }
        if (config('app.debug')) {
            \DB::enableQueryLog();

            if ($response instanceof JsonResponse) {
                $data = $response->getData(true);
                $data['query_logs'] = \DB::getQueryLog();
                $response->setData($data);
            }

        }

        return $response;
    }
}
