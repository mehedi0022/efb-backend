<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return response()->json([
        'success' => true,
        'message' => 'Laravel API backend is running.',
    ]);
});

Route::get('/docs', function () {
    return redirect('/swagger/index.html');
});

Route::get('/openapi', function () {
    return redirect('/openapi.json');
});

Route::fallback(function () {
    return response()->json([
        'success' => false,
        'message' => 'Route not found.',
    ], 404);
});
