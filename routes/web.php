<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'name' => 'UT_API',
        'status' => 'running',
        'message' => 'API backend for docente management',
    ]);
});
