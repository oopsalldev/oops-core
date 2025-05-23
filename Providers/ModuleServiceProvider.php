<?php

namespace App\Modules\OopsCore\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * OopsCore modülünün Laravel'e otomatik yüklenmesi ve publish işlemleri için ServiceProvider.
 * 
 * Burada:
 * - Route, migration, config, view ve translation publish
 * - Modüle özel servis/provider binding
 * - Genişleme için örnek placeholderlar
 */
class ModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $modulePath = base_path('app/Modules/OopsCore');

        $this->loadRoutesFrom($modulePath . '/Routes/api.php');
        $this->loadRoutesFrom($modulePath . '/Routes/web.php');

        if (is_dir($modulePath . '/Database/Migrations')) {
            $this->loadMigrationsFrom($modulePath . '/Database/Migrations');
        }

        // Config publish (örnek - ileride config klasörü eklenirse)
        if (is_dir($modulePath . '/Config')) {
            $this->publishes([
                $modulePath . '/Config/oopscore.php' => config_path('oopscore.php'),
            ], 'oopscore-config');
        }

        // View publish (örnek - ileride view klasörü eklenirse)
        if (is_dir($modulePath . '/Resources/views')) {
            $this->loadViewsFrom($modulePath . '/Resources/views', 'oopscore');
            $this->publishes([
                $modulePath . '/Resources/views' => resource_path('views/vendor/oopscore'),
            ], 'oopscore-views');
        }

        // Translations publish (örnek - ileride lang klasörü eklenirse)
        if (is_dir($modulePath . '/Resources/lang')) {
            $this->loadTranslationsFrom($modulePath . '/Resources/lang', 'oopscore');
            $this->publishes([
                $modulePath . '/Resources/lang' => resource_path('lang/vendor/oopscore'),
            ], 'oopscore-lang');
        }
    }

    public function register(): void
    {
        // Örnek: Modül servisleri veya helper'ları binding ile tanımlayabilirsin.
        // $this->app->singleton('oopscore.helper', function ($app) {
        //     return new \App\Modules\OopsCore\Service\Helper();
        // });

        // İleride başka servis/provider eklemek için burası hazır.
    }
}