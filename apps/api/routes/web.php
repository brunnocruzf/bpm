<?php

use Illuminate\Support\Facades\Route;

// Esta aplicação é somente API; o SPA (apps/web) é servido separadamente.
Route::get('/', fn () => response()->json(['service' => 'BPM TMSA API', 'api' => '/api/v1', 'openapi' => '/api/v1/openapi.yaml']));
