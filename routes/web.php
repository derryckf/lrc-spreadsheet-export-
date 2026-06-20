<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PipelineController;

Route::get('/', [PipelineController::class, 'index'])->name('dashboard');

// AJAX endpoints for each phase
Route::post('/run/parse', [PipelineController::class, 'runParse']);
Route::post('/run/resolve', [PipelineController::class, 'runResolve']);
Route::post('/run/inject', [PipelineController::class, 'runInject']);
Route::post('/run/process', [PipelineController::class, 'runProcess']);
Route::post('/run/export', [PipelineController::class, 'runExport']);
Route::post('/run/import', [PipelineController::class, 'runImport']);

// Status lookup
Route::get('/events', [PipelineController::class, 'events']);
Route::get('/event/{id}/entries', [PipelineController::class, 'eventEntries']);

// API: searchable events + auto-detect
Route::get('/api/events', [PipelineController::class, 'events']);
Route::post('/api/events/auto-detect', [PipelineController::class, 'autoDetectEvents']);
