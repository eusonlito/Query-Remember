<?php

return [
    'enabled' => (bool)env('QUERY_REMEMBER_ENABLED', env('CACHE_ENABLED', false)),
    'driver' => env('QUERY_REMEMBER_DRIVER', env('CACHE_DRIVER', 'redis')),
    'ttl' => (int)env('QUERY_REMEMBER_TTL', env('CACHE_TTL', 3600)),
    'tag' => env('QUERY_REMEMBER_TAG', 'database'),
    'prefix' => env('QUERY_REMEMBER_PREFIX', 'database|'),
];
