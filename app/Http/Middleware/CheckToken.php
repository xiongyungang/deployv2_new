<?php

namespace App\Http\Middleware;

use Closure;
use Lcobucci\JWT\Parser;

class CheckToken
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  \Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $token = $request->bearerToken();
        if (!$token) {
            return response()->json(['ret' => -1, 'msg' => 'token is required']);
        }

        $token = (new Parser())->parse((string)$token);
        $appkey = $token->getClaim('appkey');
        if (!$appkey) {
            return response()->json(['ret' => -1, 'msg' => 'token data must contain appkey']);
        }
        //获取请求回调地址
        $url = $request->header('callback-url');
        if (!$url) {
            $url = "";
        }
        $request->merge(['appkey' => $appkey, 'callback_url' => $url]);


        return $next($request);
    }
}
