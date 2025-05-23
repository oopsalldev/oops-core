<?php

namespace App\Modules\OopsCore\Service;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ModuleInstaller
{
    /**
     * Marketten modülü indirip kurar, modül bilgilerini oops_core_modules tablosuna kaydeder.
     * meta: Marketten gelen modül datası (json)
     * source: api/cli/store vs. (env ile kontrol edilir)
     */
    public function install(string $slug, ?string $license = null)
    {
        if (!preg_match('/^[a-z0-9\-]+$/', $slug)) {
            return response()->json(['status' => 'error', 'message' => 'Invalid module slug.'], 400);
        }

        $marketUrl = $this->getMarketUrl();
        if (!filter_var($marketUrl, FILTER_VALIDATE_URL)) {
            return response()->json(['status' => 'error', 'message' => 'Invalid market URL.'], 400);
        }
        $token = env('OOPSCORE_MARKET_TOKEN');
        $market = Http::withToken($token)->get($marketUrl)->json();

        if (!$market || !is_array($market)) {
            return response()->json(['status' => 'error', 'message' => 'Failed to retrieve market data.'], 500);
        }

        $module = collect($market)->firstWhere('slug', $slug);

        if (!$module || !isset($module['repository'])) {
            return response()->json(['status' => 'error', 'message' => 'Module or repository not found.'], 404);
        }

        if (!empty($module['requires_license']) && empty($license)) {
            return response()->json(['status' => 'error', 'message' => 'License required.'], 403);
        }

        $targetPath = base_path("app/Modules/{$slug}");

        if (file_exists($targetPath)) {
            return response()->json(['status' => 'error', 'message' => 'Module is already installed.'], 409);
        }

        // Download and extract zip instead of git clone
        $tempZip = storage_path("app/modules/temp-{$slug}.zip");
        $response = Http::withToken($token)->get($module['repository']);

        if ($response->failed()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to download module.'
            ], 500);
        }

        file_put_contents($tempZip, $response->body());

        $zip = new \ZipArchive();
        if ($zip->open($tempZip) === true) {
            $extractPath = base_path("app/Modules/{$slug}");
            mkdir($extractPath, 0755, true);
            $zip->extractTo($extractPath);
            $zip->close();
            // copy zip to storage for download()
            copy($tempZip, storage_path("app/modules/{$slug}.zip"));
            unlink($tempZip);
        } else {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to open ZIP file.'
            ], 500);
        }

        $installScript = base_path("app/Modules/{$slug}/install.php");
        if (file_exists($installScript)) {
            try {
                require $installScript;
            } catch (\Throwable $e) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Install script error',
                    'error' => $e->getMessage(),
                ], 500);
            }
        }

        $moduleVersion = $module['version'] ?? 'unknown';
        $metaData = json_encode($module); // marketten gelen tüm data

        Http::withToken($token)->post($this->getMarketUrl() . '/installed', [
            'slug' => $slug,
            'version' => $moduleVersion,
        ]);

        Http::withToken($token)->post($this->getMarketUrl() . '/sync-modules', [
            [
                'slug' => $slug,
                'version' => $moduleVersion,
                'enabled' => true,
            ]
        ]);

        // Log local installation
        file_put_contents(
            storage_path("logs/module-installs.log"),
            now() . " - {$slug} installed\n",
            FILE_APPEND
        );

        $source = env('OOPSCORE_INSTALL_SOURCE', 'api');

        DB::table('oops_core_modules')->updateOrInsert([
            'slug' => $slug,
        ], [
            'enabled' => true,
            'version' => $moduleVersion,
            'source' => $source,
            'installed_at' => now(),
            'updated_at' => now(),
            'meta' => $metaData,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => "Module '{$slug}' was installed successfully.",
            'path' => $targetPath
        ]);
    }

    public function toggle(string $slug)
    {
        if (!preg_match('/^[a-z0-9\-]+$/', $slug)) {
            return response()->json(['status' => 'error', 'message' => 'Invalid module slug.'], 400);
        }

        $newState = !DB::table('oops_core_modules')->where('slug', $slug)->value('enabled');
        DB::table('oops_core_modules')->updateOrInsert(
            ['slug' => $slug],
            ['enabled' => $newState, 'updated_at' => now()]
        );

        // TODO: In OopsCore ModuleLoader, skip loading modules where enabled === false

        return response()->json([
            'status' => 'success',
            'message' => "Module state updated.",
            'enabled' => $newState
        ]);
    }

    public function saveLicense(string $slug, string $license)
    {
        if (!preg_match('/^[a-z0-9\-]+$/', $slug)) {
            return response()->json(['status' => 'error', 'message' => 'Invalid module slug.'], 400);
        }

        if (strlen($license) < 10) {
            return response()->json(['status' => 'error', 'message' => 'License key appears invalid.'], 422);
        }

        $licensePath = storage_path("app/licenses/{$slug}.key");
        file_put_contents($licensePath, $license);

        return response()->json([
            'status' => 'success',
            'message' => 'License saved successfully.',
            'slug' => $slug
        ]);
    }

    public function download(string $slug, string $license)
    {
        if (!preg_match('/^[a-z0-9\-]+$/', $slug)) {
            return response()->json(['status' => 'error', 'message' => 'Invalid module slug.'], 400);
        }

        $token = env('OOPSCORE_MARKET_TOKEN');
        $response = Http::withToken($token)->post($this->getMarketUrl() . '/verify-license', [
            'slug' => $slug,
            'license' => $license,
        ]);

        if ($response->failed() || !($response->json('valid') ?? false)) {
            Log::warning("License validation failed for {$slug}");
            return response()->json(['error' => 'License validation failed.'], 403);
        }

        $zipPath = storage_path("app/modules/{$slug}.zip");
        if (!file_exists($zipPath)) {
            return response()->json(['error' => 'Module file not found.'], 404);
        }
        Log::info("Module downloaded: {$slug} | token: {$token} | ip: " . request()->ip());
        return response()->download($zipPath);
    }

    protected function getMarketUrl(): string
    {
        return env('VITE_OOPSCORE_MARKET_API', 'https://oopsall.dev/api');
    }
}