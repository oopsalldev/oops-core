<?php

namespace App\Modules\OopsCore\Bootstrap;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

class OopsCoreModuleLoader
{
    // Sabit olarak kullanılacak provider yolu
    public const DEFAULT_PROVIDER = 'Providers\\ModuleServiceProvider';

    /**
     * Aktif modülleri yükler.
     *
     * @param Application $app
     * @return void
     */
    public static function loadEnabledModules(Application $app)
    {
        $modulesPath = base_path('app/Modules');
        $enabledModules = [];
        $devMode = env('OOPS_MODULE_DEV_MODE', false);

        // Geliştirme moduysa sadece dizinleri yükle
        if ($devMode) {
            $enabledModules = array_map(
                fn($dir) => strtolower(trim(basename($dir))),
                File::directories($modulesPath)
            );
            Log::info("OOPS_MODULE_DEV_MODE enabled: all modules will be loaded.");
        } else {
            // Production - DB'den yüklenenler
            if (Schema::hasTable('oops_core_modules')) {
                $enabledModules = DB::table('oops_core_modules')
                    ->where('enabled', true)
                    ->pluck('slug')
                    ->map(fn($slug) => strtolower(trim($slug)))
                    ->toArray();
                if (empty($enabledModules)) {
                    Log::info("No enabled modules found in oops_core_modules.");
                }
            }
        }

        $knownModuleDirs = File::directories($modulesPath);
        $knownSlugs = array_map(fn($dir) => strtolower(trim(basename($dir))), $knownModuleDirs);

        // DB'de olup dizinde olmayanları logla
        foreach ($enabledModules as $dbSlug) {
            if (!in_array($dbSlug, $knownSlugs)) {
                Log::warning("Module enabled in database but missing on filesystem: {$dbSlug}");
            }
        }

        $loaded = [];

        foreach ($knownModuleDirs as $moduleDir) {
            $slug = strtolower(trim(basename($moduleDir)));
            if (!in_array($slug, $enabledModules)) {
                continue;
            }
            if (in_array($slug, $loaded)) {
                continue;
            }

            // Daha esnek: Modül adının ilk harfi büyük olabilir (class_exists büyük/küçük harfe duyarlı!)
            $moduleName = basename($moduleDir);
            $providerClass = "App\\Modules\\{$moduleName}\\" . self::DEFAULT_PROVIDER;

            if (!class_exists($providerClass)) {
                Log::warning("Provider class not found for module: {$slug} ({$providerClass})");
                continue;
            }

            try {
                $app->register($providerClass);
                $loaded[] = $slug;
                Log::info("Module successfully loaded: {$slug}");
                if (env('OOPS_DEBUG', false)) {
                    echo "Module loaded: {$slug}\n";
                }
            } catch (\Throwable $e) {
                Log::error("Module failed to load: {$slug}. Reason: {$e->getMessage()}");
            }
        }
    }
}