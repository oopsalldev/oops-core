<?php

use Inertia\Inertia;
use Illuminate\Support\Facades\Route;
use App\Modules\OopsCore\Controller\OopsCoreController;

/**
 * OopsCore Web Routes
 * Frontend arayüz için Inertia.js ile çalışan sayfa yönlendirmeleri.
 * Route isimleri ve sağlık kontrolüne örnek rate limit eklendi.
 */

Route::get('/oops-core', [OopsCoreController::class, 'index'])->name('oops-core.index');

Route::get('/oops-core/plugin/{slug}', function ($slug) {
    return Inertia::render('oops/core/plugin-detail');
})->name('oops-core.plugin-detail');

Route::get('/oops-core/health-check', function () {
    return Inertia::render('oops/core/health-check');
})->middleware('throttle:30,1')->name('oops-core.health-check');

Route::get('/oops-core/market', function () {
    return Inertia::render('oops/core/market');
})->name('oops-core.market');