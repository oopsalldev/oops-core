<?php

namespace App\Modules\OopsCore\Controller;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\File;

class OopsCoreController extends Controller
{
    // Servisler property olarak
    protected $installer;
    protected $registry;

    public function __construct()
    {
        $this->installer = new \App\Modules\OopsCore\Service\ModuleInstaller();
        $this->registry = new \App\Modules\OopsCore\Service\ModuleRegistry();
    }

    /**
     * Modül kurulumu başlatır.
     */
    public function install(Request $request)
    {
        $slug = $request->input('slug');
        $license = $request->input('license_key');
        try {
            $result = $this->installer->install($slug, $license);
            return $this->successResponse('Module installed.', $result);
        } catch (\Throwable $e) {
            Log::error('Module install failed', [
                'error' => $e->getMessage(),
                'slug' => $slug,
                'license' => $license,
            ]);
            return $this->errorResponse('Module installation failed.', [
                'error_code' => 'OOPSCORE_INSTALL_01',
                'short' => 'Installation error',
                'details' => $e->getMessage(),
                'reason' => 'An exception occurred while installing the module.',
                'recommendation' => 'Check module slug and license validity.',
                'context' => compact('slug', 'license')
            ]);
        }
    }
    /**
     * OopsCore ana paneli: Kurulu ve yüklenebilir market modüllerini döndürür.
     */
    public function index()
    {
        $localModules = $this->registry::all();
        $marketModules = [];
        $token = env('OOPSCORE_MARKET_TOKEN');
        $marketUrl = $this->marketUrl();
        if ($token && $marketUrl) {
            $res = Http::withToken($token)->get($marketUrl . '/modules');
            if ($res->ok()) {
                $marketModules = $res->json('modules') ?? [];
            }
        }
        return inertia('oops/core/index', [
            'modules' => $localModules,
            'marketModules' => $marketModules,
        ]);
    }

        /**
     * composer.lock dosyasından OopsCore versiyonunu döndürür.
     */
    private function getOopsCoreVersion(): string
    {
        $fallback = env('OOPSCORE_VERSION', 'dev');

        // 1. composer.lock'tan bulmaya çalış
        $lockFile = base_path('composer.lock');
        if (file_exists($lockFile)) {
            $composer = json_decode(file_get_contents($lockFile), true);
            foreach ($composer['packages'] ?? [] as $package) {
                if ($package['name'] === 'oopsall/oopscore') {
                    return $package['version'] ?? $fallback;
                }
            }
        }

        // 2. composer.json içinden oku (geliştirme ortamı)
        $oopsCoreComposer = base_path('app/Modules/OopsCore/composer.json');
        if (file_exists($oopsCoreComposer)) {
            $composer = json_decode(file_get_contents($oopsCoreComposer), true);
            return $composer['version'] ?? $fallback;
        }

        return $fallback;
    }

    /**
     * Modül aktif/pasif durumunu değiştirir.
     */
    public function toggle(Request $request)
    {
        $slug = $request->input('slug');
        try {
            $result = $this->installer->toggle($slug);
            return $this->successResponse('Module toggled.', $result);
        } catch (\Throwable $e) {
            Log::error('Module toggle failed', [
                'error' => $e->getMessage(),
                'slug' => $slug,
            ]);
            return $this->errorResponse('Module toggle failed.', [
                'error_code' => 'OOPSCORE_TOGGLE_01',
                'short' => 'Toggle error',
                'details' => $e->getMessage(),
                'reason' => 'An exception occurred while toggling the module.',
                'recommendation' => 'Check if the module slug is correct and installed.',
                'context' => compact('slug')
            ]);
        }
    }

    /**
     * Modülü indirir.
     */
    public function download(Request $request)
    {
        $slug = $request->input('slug');
        $license = $request->input('license');
        try {
            $result = $this->installer->download($slug, $license);
            return $this->successResponse('Module downloaded.', $result);
        } catch (\Throwable $e) {
            Log::error('Module download failed', [
                'error' => $e->getMessage(),
                'slug' => $slug,
                'license' => $license,
            ]);
            return $this->errorResponse('Module download failed.', [
                'error_code' => 'OOPSCORE_DOWNLOAD_01',
                'short' => 'Download error',
                'details' => $e->getMessage(),
                'reason' => 'An exception occurred while downloading the module.',
                'recommendation' => 'Ensure the license key and slug are valid.',
                'context' => compact('slug', 'license')
            ]);
        }
    }

    /**
     * Lisans anahtarını kaydeder.
     */
    public function saveLicense(Request $request)
    {
        $slug = $request->input('slug');
        $license = $request->input('license');
        try {
            $result = $this->installer->saveLicense($slug, $license);
            return $this->successResponse('License saved.', $result);
        } catch (\Throwable $e) {
            Log::error('License save failed', [
                'error' => $e->getMessage(),
                'slug' => $slug,
                'license' => $license,
            ]);
            return $this->errorResponse('License save failed.', [
                'error_code' => 'OOPSCORE_LICENSE_01',
                'short' => 'License save error',
                'details' => $e->getMessage(),
                'reason' => 'An exception occurred while saving the license key.',
                'recommendation' => 'Ensure the license key and module slug are valid.',
                'context' => compact('slug', 'license')
            ]);
        }
    }

    /**
     * Modül ve market durumunu kontrol eder.
     */
    public function status()
    {
        try {
            $modules = $this->registry::all();
            $token = env('OOPSCORE_MARKET_TOKEN');
            $ping = false;
            if ($token) {
                try {
                    $res = Http::withToken($token)->get($this->marketUrl() . '/verify-token');
                    $ping = $res->ok();
                } catch (\Throwable $e) {
                    $ping = false;
                }
            }

            // Replace store status with GitHub release fetch
            try {
                $github = Http::timeout(5)
                    ->accept('application/vnd.github+json')
                    ->get('https://api.github.com/repos/oopsallbusiness/oopscore/releases/latest');

                $githubVersion = null;
                if ($github->ok()) {
                    $githubVersion = ltrim($github->json('tag_name') ?? '', 'v');
                }

                $storeStatus = [
                    'latest_core_version' => $githubVersion,
                    'source' => 'GitHub',
                    'status' => $githubVersion ? 'ok' : 'unknown',
                ];
            } catch (\Throwable $e) {
                $storeStatus = [
                    'status' => 'unreachable',
                    'latest_core_version' => null,
                    'source' => 'GitHub',
                ];
            }

            return $this->successResponse('OopsCore is alive.', [
                'token_present' => !empty($token),
                'market_reachable' => $ping,
                'laravel_version' => app()->version(),
                'core_version' => $this->getOopsCoreVersion(),
                'module_count' => count($modules),
                'modules' => $modules,
                'store_status' => $storeStatus,
            ]);
        } catch (\Throwable $e) {
            Log::error('Status check failed', ['error' => $e->getMessage()]);
            return $this->errorResponse('OopsCore status check failed.', [
                'error_code' => 'OOPSCORE_STATUS_01',
                'short' => 'Status exception',
                'details' => $e->getMessage(),
                'reason' => 'An exception occurred while checking the status of modules and market connection.',
                'recommendation' => 'Check internet connection and token configuration.',
                'context' => []
            ]);
        }
    }

    /**
     * Market kaydı yapar ve .env dosyasını günceller.
     */
    public function register(Request $request)
    {
        $request->validate([
            'domain' => 'required|string|max:255',
            'email' => 'required|email|max:255',
        ]);
        $domain = $request->domain;
        $email = $request->email;

        try {
            $response = Http::post($this->registerUrl(), [
                'domain' => $domain,
                'email' => $email,
            ]);

            if ($response->failed()) {
                Log::error('OopsCore registration failed', [
                    'response' => $response->body(),
                    'domain' => $domain,
                    'email' => $email,
                ]);
                return $this->errorResponse('OopsStore registration failed.', [
                    'error_code' => 'OOPSCORE_REGISTER_01',
                    'short' => 'Store response failure',
                    'details' => $response->body(),
                    'reason' => 'OopsStore API responded with failure during registration.',
                    'recommendation' => 'Ensure the registration endpoint is reachable and returns a valid token.',
                    'context' => compact('domain', 'email')
                ]);
            }

            $data = $response->json();

            if (!isset($data['token'])) {
                Log::error('OopsCore registration failed: token missing', [
                    'response' => $response->body(),
                    'domain' => $domain,
                    'email' => $email,
                ]);
                return $this->errorResponse('OopsStore registration failed.', [
                    'error_code' => 'OOPSCORE_REGISTER_02',
                    'short' => 'Missing token',
                    'details' => $response->body(),
                    'reason' => 'Registration succeeded but token was missing in the response.',
                    'recommendation' => 'Ensure OopsStore includes a token in the registration response.',
                    'context' => compact('domain', 'email')
                ]);
            }

            // .env backup
            $envPath = base_path('.env');
            if (file_exists($envPath)) {
                File::copy($envPath, $envPath . '.bak');
            }

            return $this->successResponse('OopsStore kaydı tamamlandı', ['token' => $data['token']]);
        } catch (\Throwable $e) {
            Log::error('Register endpoint failed', [
                'error' => $e->getMessage(),
                'domain' => $domain,
                'email' => $email,
            ]);
            return $this->errorResponse('OopsStore registration error.', [
                'error_code' => 'OOPSCORE_REGISTER_03',
                'short' => 'Registration exception',
                'details' => $e->getMessage(),
                'reason' => 'An exception occurred while registering the module to OopsStore.',
                'recommendation' => 'Check connection and payload to OopsStore.',
                'context' => compact('domain', 'email')
            ]);
        }
    }

    /**
     * Lisans doğrulama endpointi.
     */
    public function validateLicense(Request $request)
    {
        $token = $request->bearerToken();
        if (!$token) {
            return response()->json(['status' => 'unauthorized', 'message' => 'Bearer token missing'], 401);
        }

        $request->validate([
            'slug' => 'required|string',
            'license_key' => 'required|string',
        ]);
        $slug = $request->slug;
        $licenseKey = $request->license_key;

        try {
            $response = Http::withToken($token)->post(config('oops.license_validate_url'), [
                'slug' => $slug,
                'license_key' => $licenseKey,
            ]);

            if ($response->failed()) {
                Log::error('License validation failed', [
                    'response' => $response->body(),
                    'slug' => $slug,
                    'license_key' => $licenseKey,
                ]);
                return $this->errorResponse('License validation failed.', [
                    'error_code' => 'OOPSCORE_VALIDATE_01',
                    'short' => 'Validation failed',
                    'details' => $response->json(),
                    'reason' => 'OopsStore license validation API responded with failure.',
                    'recommendation' => 'Verify token, slug, and license key.',
                    'context' => compact('slug', 'licenseKey')
                ], 403);
            }

            return $this->successResponse('License is valid.');
        } catch (\Throwable $e) {
            Log::error('License validation exception', [
                'error' => $e->getMessage(),
                'slug' => $slug,
                'license_key' => $licenseKey,
            ]);
            return $this->errorResponse('License validation exception.', [
                'error_code' => 'OOPSCORE_VALIDATE_02',
                'short' => 'Validation error',
                'details' => $e->getMessage(),
                'reason' => 'An exception occurred during license validation.',
                'recommendation' => 'Check token, connectivity, and OopsStore availability.',
                'context' => compact('slug', 'licenseKey')
            ]);
        }
    }

    /**
     * .env değerini güvenli şekilde günceller, başarılıysa true döner.
     */
    private function setEnvValue($key, $value): bool
    {
        $envPath = base_path('.env');
        if (!file_exists($envPath)) {
            Log::error('.env file not found');
            return false;
        }
        $env = file_get_contents($envPath);
        $pattern = "/^{$key}=.*/m";
        if (preg_match($pattern, $env)) {
            $env = preg_replace($pattern, "{$key}={$value}", $env);
        } else {
            $env .= PHP_EOL . "{$key}={$value}";
        }
        try {
            file_put_contents($envPath, $env);
            return true;
        } catch (\Throwable $e) {
            Log::error('.env file write failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Yardımcı: Başarılı response çıktısı (her yerde aynı)
     */
    private function successResponse($message, $data = null)
    {
        $resp = ['status' => 'success', 'message' => $message];
        if (!is_null($data)) {
            $resp['data'] = $data;
        }
        return response()->json($resp);
    }

    /**
     * Yardımcı: Hatalı response çıktısı (her yerde aynı)
     */
    private function errorResponse($message, $error = null, $code = 500)
    {
        $resp = ['status' => 'error', 'message' => $message];
        $debug = env('OOPS_DEBUG');
        if ($error instanceof \Throwable) {
            $resp['error'] = $debug
                ? ['msg' => $error->getMessage(), 'trace' => $error->getTraceAsString()]
                : $error->getMessage();
        } else if (!is_null($error)) {
            $resp['error'] = $error;
        }
        return response()->json($resp, $code);
    }

    /**
     * Yardımcı: Market URL dinamikleştir
     */
    private function marketUrl()
    {
        return env('OOPSCORE_MARKET_URL', 'https://oopsall.dev/api');
    }

    /**
     * Yardımcı: Register URL dinamikleştir
     */
    private function registerUrl()
    {
        return env('OOPSCORE_REGISTER_URL', $this->marketUrl() . '/register');
    }

        /**
     * OopsCore kendini günceller (github zip ile).
     */
    public function updateCore(Request $request)
    {
        $url = $request->input('source');
        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
            return $this->errorResponse('OopsCore update failed due to invalid or missing source URL.', [
                'error_code' => 'OOPSCORE_UPDATE_01',
                'short' => 'Invalid source URL',
                'details' => 'The source URL is either missing or not a valid URL format.',
                'reason' => 'The input did not include a valid https URL for the GitHub zip archive.',
                'recommendation' => 'Check if the URL is present and starts with https://',
                'context' => [
                    'input_url' => $url
                ]
            ]);
        }

        $tempPath = storage_path('app/oopscore_update.zip');
        $extractPath = storage_path('app/oopscore_update');

        try {
            $zipData = @file_get_contents($url);
            if (!$zipData) {
                return $this->errorResponse('OopsCore update failed: ZIP download error.', [
                    'error_code' => 'OOPSCORE_UPDATE_02',
                    'short' => 'ZIP download failed',
                    'details' => 'Unable to retrieve the ZIP from the provided URL.',
                    'reason' => 'The URL may be unreachable, incorrect, or returning an error.',
                    'recommendation' => 'Verify that the GitHub URL is reachable and not returning 404.',
                    'context' => [
                        'download_url' => $url
                    ]
                ]);
            }

            if (@file_put_contents($tempPath, $zipData) === false) {
                return $this->errorResponse('OopsCore update failed: Cannot save ZIP file.', [
                    'error_code' => 'OOPSCORE_UPDATE_03',
                    'short' => 'ZIP save failed',
                    'details' => 'ZIP file could not be saved to temporary path.',
                    'reason' => 'Filesystem permission issues or disk space limitations may have prevented saving.',
                    'recommendation' => 'Check storage/app permissions and ensure there is enough free disk space.',
                    'context' => [
                        'path' => $tempPath
                    ]
                ]);
            }
        } catch (\Throwable $e) {
            return $this->errorResponse('OopsCore update failed due to exception while downloading/saving the ZIP.', [
                'error_code' => 'OOPSCORE_UPDATE_04',
                'short' => 'Download or save exception',
                'details' => $e->getMessage(),
                'reason' => 'Exception occurred during file_get_contents or file_put_contents operations.',
                'recommendation' => 'Inspect error message and stack trace for deeper issue.',
                'context' => [
                    'exception' => get_class($e)
                ]
            ]);
        }

        try {
            // Aç
            $zip = new \ZipArchive;
            if ($zip->open($tempPath) === true) {
                $zip->extractTo($extractPath);
                $zip->close();
            } else {
                return $this->errorResponse('OopsCore update failed during ZIP extraction.', [
                    'error_code' => 'OOPSCORE_UPDATE_13',
                    'short' => 'ZIP extraction failed',
                    'details' => 'The ZIP archive could not be extracted.',
                    'reason' => 'The archive may be corrupt or the server lacks permission to extract files.',
                    'recommendation' => 'Ensure the ZIP is valid and the server has appropriate write permissions.',
                    'context' => [
                        'zip_path' => $tempPath
                    ]
                ]);
            }

            // Varsayılan dizin: oopscore-main
            $sourceDir = $extractPath . '/oopscore-main';
            $targetDir = base_path('app/Modules/OopsCore');

            if (!is_dir($sourceDir)) {
                return $this->errorResponse('OopsCore update failed due to missing source directory in ZIP archive.', [
                    'error_code' => 'OOPSCORE_UPDATE_14',
                    'short' => 'Missing extracted folder',
                    'details' => 'Expected folder "oopscore-main" not found inside the ZIP.',
                    'reason' => 'ZIP archive does not contain the expected top-level directory.',
                    'recommendation' => 'Ensure the GitHub ZIP contains a folder named "oopscore-main" at its root.',
                    'context' => [
                        'zip_path' => $tempPath,
                        'expected_folder' => 'oopscore-main'
                    ]
                ]);
            }

            // Eski modülü sil
            \Illuminate\Support\Facades\File::deleteDirectory($targetDir);
            // Yenisini taşı
            \Illuminate\Support\Facades\File::copyDirectory($sourceDir, $targetDir);

            return $this->successResponse('OopsCore updated from GitHub.');
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('OopsCore update failed', ['error' => $e->getMessage()]);
            return $this->errorResponse('OopsCore update failed due to unhandled exception.', [
                'error_code' => 'OOPSCORE_UPDATE_99',
                'short' => 'Unhandled update exception',
                'details' => $e->getMessage(),
                'reason' => 'A non-caught exception occurred after file operations.',
                'recommendation' => 'Check logs for full stack trace. Add more specific catch blocks if needed.',
                'context' => [
                    'exception' => get_class($e)
                ]
            ]);
        }
    }
}