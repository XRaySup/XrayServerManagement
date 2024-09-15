<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\isegarobotController;

Route::post('/isegarobot/webhook', [isegarobotController::class, 'handleWebhook']);

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
