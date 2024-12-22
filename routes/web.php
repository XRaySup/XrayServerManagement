<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\isegarobotController;
use App\Http\Controllers\xraybot;
use App\Http\Controllers\TelegramWebhookController;

Route::post('/isegarobot/webhook', [isegarobotController::class, 'handleWebhook']);
Route::post('/xraybot/webhook', [xraybot::class, 'handleWebhook']);
Route::post('/telegram/webhook', [TelegramWebhookController::class, 'handle']);

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
    return view('welcome');
});

Route::post('/test-webhook', function () {
    return response()->json(['status' => 'POST request received']);
});
