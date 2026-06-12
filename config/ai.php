<?php

return [
    'api_key' => env('TIMEWEB_AI_API_KEY'),
    'base_uri' => env('TIMEWEB_AI_BASE_URI', 'https://api.timeweb.ai/v1'),
    'model' => env('TIMEWEB_AI_MODEL', 'gemini/gemini-2.5-flash-lite'),
];
