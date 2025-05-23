<?php

namespace App\Modules\OopsCore\Console;

use App\Modules\OopsCore\Bootstrap\OopsCoreModuleLoader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class OopsInstallCommand extends Command
{
    protected $signature = 'oops:install {--dev} {--skip-db} {--skip-frontend} {--headless} {--type=full} {--source=}';
    protected $description = 'Performs the initial setup for OopsCore.';

    public function handle()
    {
        $this->info('Starting OopsCore installation...');
        $this->info('Connecting to OopsStore...');

        // Gelişmiş domain ve email validasyonu
        $domain = $this->option('headless') ? config('app.url') : $this->ask('Enter your system domain (e.g., oopsproje1.com)');
        $email = $this->option('headless') ? 'admin@' . parse_url(config('app.url'), PHP_URL_HOST) : $this->ask('Admin email address?');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Invalid email address format.');
            return Command::FAILURE;
        }

        if (!preg_match('/^(https?:\/\/)?([a-z0-9\-.]+\.[a-z]{2,})(:\d+)?(\/.*)?$/i', $domain)) {
            $this->error('Invalid domain format.');
            return Command::FAILURE;
        }

        $notes = 'Created during installation';
        $type = $this->option('type');
        $source = $this->option('source') ?? $this->getOopsCoreVersion();

        $registerUrl = config('oops.register_url') ?? 'https://oopsall.dev/api/register';
        $response = Http::post($registerUrl, [
            'domain' => $domain,
            'email' => $email,
            'notes' => $notes,
            'type' => $type,
            'source' => $source,
        ]);

        if ($response->failed() || !$response->json('token')) {
            $this->error('OopsStore registration failed.');
            $this->line('Status: ' . $response->status());
            $this->line('Response: ' . $response->body());
            return Command::FAILURE;
        }

        $token = $response->json('token');
        $envPath = base_path('.env');
        $envBackupPath = base_path('.env.bak');

        // .env dosyasının yedeğini al
        if (File::exists($envPath)) {
            File::copy($envPath, $envBackupPath);
        } else {
            $this->error('.env file not found.');
            return Command::FAILURE;
        }

        $currentToken = env('OOPSCORE_MARKET_TOKEN');
        if (empty($currentToken)) {
            $this->setEnvValue('OOPSCORE_MARKET_TOKEN', $token);
            $this->setEnvValue('VITE_OOPSCORE_MARKET_TOKEN', $token);
        } else {
            $this->info('Token already exists in .env, skipping token overwrite.');
        }
        $marketUrl = 'https://oopsall.dev/api';
        $this->setEnvValue('OOPSCORE_MARKET_URL', $marketUrl);
        $this->setEnvValue('VITE_OOPSCORE_MARKET_API', $marketUrl);
        $this->setEnvValue('OOPSCORE_VERSION', $source);
        $this->setEnvValue('VITE_OOPSCORE_VERSION', $source);
        $this->setEnvValue('OOPS_DEBUG', 'true');

        if (!$this->option('skip-db')) {
            $this->info('Running OopsCore module migrations...');
            $code = $this->callSilent('migrate', ['--path' => 'app/Modules/OopsCore/Database/Migrations']);
            if ($code === 0) {
                $this->info('Migrations completed successfully.');
            } else {
                $this->warn('Migration process completed with issues.');
                $this->line('Check your migration logs or database settings.');
            }
        }

        $this->info('OopsCore installation completed.');

        if ($this->option('dev')) {
            $this->info("Development only: browser token injection example");
            $this->line("localStorage.setItem('oopscore_token', '{$token}')");
        }

        if (\Artisan::has('install:api')) {
            $this->info('Running API install command...');
            try {
                $this->callSilent('install:api');
            } catch (\Throwable $e) {
                $this->warn('API install command failed: ' . $e->getMessage());
            }
        }

        if (!$this->option('skip-frontend')) {
            $this->info('Checking and installing frontend dependencies...');
            $output = [];
            $errorFlag = false;
            exec('npm install @iconify/react 2>&1', $output, $code1);
            exec('npm install -D tailwindcss postcss autoprefixer 2>&1', $output, $code2);
            exec('npx tailwindcss init -p 2>&1', $output, $code3);
            if ($code1 !== 0 || $code2 !== 0 || $code3 !== 0) {
                $errorFlag = true;
                $this->warn('There were issues installing frontend dependencies:');
            }
            if ($this->option('dev') || $errorFlag) {
                $this->line(implode("\n", $output));
            }
            if (!$errorFlag) {
                $this->info('Frontend dependencies installed.');
            }
        }

        OopsCoreModuleLoader::loadEnabledModules($this->getLaravel());
        $this->info('Enabled modules have been successfully loaded.');

        $this->info('Modules loaded:');
        $allModules = DB::table('oops_core_modules')->get();
        foreach ($allModules as $mod) {
            $statusIcon = $mod->enabled ? '✓' : '✕';
            $this->line("[{$statusIcon}] {$mod->slug}");
        }

        $this->info('OopsCore setup is complete! You are ready to rule your kingdom. 👑');
        $this->line('If you have any issues, restore your .env from .env.bak.');

        return Command::SUCCESS;
    }

    protected function setEnvValue(string $key, string $value): void
    {
        $envPath = base_path('.env');
        $env = File::get($envPath);

        if (Str::contains($env, "{$key}=")) {
            $env = preg_replace("/^{$key}=.*$/m", "{$key}={$value}", $env);
        } else {
            $env .= PHP_EOL . "{$key}={$value}";
        }

        File::put($envPath, $env);
    }
    protected function getOopsCoreVersion(): string
    {
        $composerFile = base_path('app/Modules/OopsCore/composer.json');
        if (!file_exists($composerFile)) {
            return 'unknown';
        }

        $composer = json_decode(file_get_contents($composerFile), true);
        return $composer['version'] ?? 'unknown';
    }
}