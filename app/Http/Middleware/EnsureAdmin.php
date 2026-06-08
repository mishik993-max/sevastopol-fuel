<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $password = (string) config('admin.password');

        if ($password === '') {
            return response()->json(['message' => 'Админка не настроена (ADMIN_PASSWORD в .env)'], 503);
        }

        $token = (string) $request->header('X-Admin-Token', '');

        if ($token === '' || ! hash_equals($password, $token)) {
            return response()->json(['message' => 'Неверный пароль администратора'], 401);
        }

        return $next($request);
    }
}
