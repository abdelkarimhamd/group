<?php

// Reverb reads the cache every 5 seconds for `reverb:restart`. A file
// store needs no database; this app has none.
return [
    'default' => env('CACHE_STORE', 'file'),
];
