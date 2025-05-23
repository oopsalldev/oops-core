<?php

namespace App\Modules\OopsCore\Service;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;

/**
 * OopsCore içindeki tüm modülleri döner.
 * meta, created_at, updated_at, deleted_at gibi ek bilgilerle detaylı analiz yapılabilir.
 */
class ModuleRegistry
{
    /**
     * Tüm modülleri dizi olarak döner.
     * @param bool $withDeleted Soft deleted modüller de dahil olsun mu?
     */
    public static function all(bool $withDeleted = false): array
    {
        $query = DB::table('oops_core_modules');
        if ($withDeleted) {
            $modules = $query->get();
        } else {
            $modules = $query->whereNull('deleted_at')->get();
        }
        $result = [];

        foreach ($modules as $module) {
            $slug = $module->slug;
            $path = base_path("app/Modules/{$slug}");
            $hasLicense = File::exists(storage_path("app/licenses/{$slug}.key"));
            $meta = [];
            if (!empty($module->meta)) {
                $decoded = json_decode($module->meta, true);
                $meta = is_array($decoded) ? $decoded : [];
            }

            $result[] = [
                'slug' => $slug,
                'enabled' => $module->enabled,
                'version' => $module->version,
                'source' => $module->source,
                'installed_at' => $module->installed_at,
                'created_at' => $module->created_at,
                'updated_at' => $module->updated_at,
                'deleted_at' => $module->deleted_at,
                'path' => $path,
                'license' => $hasLicense,
                'folder_exists' => File::isDirectory($path),
                'provider_exists' => class_exists("App\\Modules\\{$slug}\\Providers\\ModuleServiceProvider"),
                'meta' => $meta,
            ];
        }

        return $result;
    }
}