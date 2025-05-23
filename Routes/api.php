<?php

use Illuminate\Support\Facades\Route;
use App\Modules\OopsCore\Controller\OopsCoreController;

/**
 * OopsCore API Routes
 * Tüm temel modül yönetimi fonksiyonları için REST API endpointleri.
 * Kritik işlemlere rate limit & auth örnekleri eklendi.
 */

Route::prefix('oops-core')->middleware(['api'])->group(function () {
    // Status - Herkese açık (sistemin sağlığını ölçmek için)
    Route::get('/status', [OopsCoreController::class, 'status'])->name('oops-core.status');

    Route::get('/status/proxy', function () {
        try {
            $res = \Illuminate\Support\Facades\Http::timeout(5)->get('https://oops.zone/api/status');
            return response()->json($res->json(), $res->status());
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'unreachable',
                'message' => 'Could not fetch status from oops.zone',
                'error' => $e->getMessage(),
            ], 500);
        }
    })->name('oops-core.status.proxy');

    // Modül yükle/kur (rate limit eklenmiş örnek, istersen auth:api ekleyebilirsin)
    Route::post('/install', [OopsCoreController::class, 'install'])
        ->middleware('throttle:10,1') // 10 request / dakika
        ->name('oops-core.install');

    // Modül aktif/pasif et (rate limit örnekli)
    Route::post('/toggle', [OopsCoreController::class, 'toggle'])
        ->middleware('throttle:20,1')
        ->name('oops-core.toggle');

    // Lisans doğrulama (kısıtlama örneği)
    Route::post('/validate-license', [OopsCoreController::class, 'validateLicense'])
        ->middleware('throttle:30,1')
        ->name('oops-core.validate-license');

    // Diagnostik endpointler - sadece local, test veya debug modunda erişim!
    if (
        config('app.debug')
        || app()->environment('local', 'testing')
        || (request()->ip() === '127.0.0.1')
    ) {
        Route::get('/diagnostics/iconify', function () {
            $exists = file_exists(base_path('node_modules/@iconify/react/package.json'));
            return response()->json(['status' => $exists ? 'ok' : 'missing'], $exists ? 200 : 404);
        })->name('oops-core.diagnostics.iconify');

        Route::get('/diagnostics/module-dir', function () {
            return response()->json([
                'exists' => is_dir(base_path('app/Modules')),
            ]);
        })->name('oops-core.diagnostics.module-dir');

        Route::get('/diagnostics/tailwind', function () {
            $exists = file_exists(base_path('node_modules/tailwindcss/package.json'));
            return response()->json(['status' => $exists ? 'ok' : 'missing'], $exists ? 200 : 404);
        })->name('oops-core.diagnostics.tailwind');

        // Ekstra: DB bağlantısı testi
        Route::get('/diagnostics/db', function () {
            try {
                \DB::connection()->getPdo();
                return response()->json(['status' => 'ok']);
            } catch (\Throwable $e) {
                return response()->json(['status' => 'db-error', 'error' => $e->getMessage()], 500);
            }
        })->name('oops-core.diagnostics.db');
    }
});

Route::post('/oops-core/update-core', [\App\Modules\OopsCore\Controller\OopsCoreController::class, 'updateCore']);