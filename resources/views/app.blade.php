<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <meta name="referrer" content="strict-origin-when-cross-origin">
    <meta name="theme-color" content="#0a0807">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="app-base" content="{{ request()->getSchemeAndHttpHost() . request()->getBaseUrl() }}">
    <title>{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <div id="app"></div>
</body>
</html>
