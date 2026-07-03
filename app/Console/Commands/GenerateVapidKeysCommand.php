<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GenerateVapidKeysCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'webpush:generate-keys {--show : Print keys instead of writing to .env}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate VAPID keys for Web Push notifications';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            $keys = $this->generateVapidKeys();
        } catch (\Throwable $e) {
            $this->error('Failed to generate keys: '.$e->getMessage());
            $this->warn('Ensure the gmp or bcmath PHP extension is enabled.');
            $this->warn('On XAMPP, ensure openssl.cnf is accessible (usually at C:\\xampp\\apache\\conf\\openssl.cnf).');

            return 1;
        }

        if ($this->option('show')) {
            $this->line('<info>VAPID_PUBLIC_KEY=</info>'.$keys['publicKey']);
            $this->line('<info>VAPID_PRIVATE_KEY=</info>'.$keys['privateKey']);

            return 0;
        }

        $this->setEnvKey('VAPID_PUBLIC_KEY', $keys['publicKey']);
        $this->setEnvKey('VAPID_PRIVATE_KEY', $keys['privateKey']);
        $this->setEnvKey('VAPID_SUBJECT', 'mailto:'.config('mail.from.address', 'hello@rshoprefills.com'));

        $this->info('VAPID keys generated and set successfully.');

        return 0;
    }

    /**
     * Generate VAPID-compatible ECDSA P-256 keys using OpenSSL directly.
     *
     * The minishlink/web-push library's VAPID::createVapidKeys() calls
     * JWKFactory::createECKey() which in turn calls openssl_pkey_new()
     * WITHOUT passing the 'config' option. On XAMPP / Windows this fails
     * because PHP can't auto-locate openssl.cnf.
     *
     * This method passes the config path explicitly.
     */
    protected function generateVapidKeys(): array
    {
        $opensslCnf = $this->resolveOpensslConfig();

        $config = [
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ];

        if ($opensslCnf) {
            $config['config'] = $opensslCnf;
        }

        $key = openssl_pkey_new($config);
        if ($key === false) {
            throw new \RuntimeException('Unable to create the EC key: '.openssl_error_string());
        }

        $details = openssl_pkey_get_details($key);
        $x = $details['ec']['x'];
        $y = $details['ec']['y'];
        $d = $details['ec']['d'];

        // Uncompressed public key = 0x04 || x || y, then base64url-encode
        $publicKeyBin = "\x04"
            .str_pad($x, 32, "\x00", STR_PAD_LEFT)
            .str_pad($y, 32, "\x00", STR_PAD_LEFT);

        $publicKey = rtrim(strtr(base64_encode($publicKeyBin), '+/', '-_'), '=');
        $privateKey = rtrim(strtr(base64_encode(str_pad($d, 32, "\x00", STR_PAD_LEFT)), '+/', '-_'), '=');

        return compact('publicKey', 'privateKey');
    }

    protected function setEnvKey(string $key, string $value): void
    {
        $path = app()->environmentFilePath();
        $env = file_get_contents($path);

        if (str_contains($env, $key.'=')) {
            $env = preg_replace("/^{$key}=.*/m", "{$key}={$value}", $env);
        } else {
            $env .= "\n{$key}={$value}";
        }

        file_put_contents($path, $env);
    }

    /**
     * Attempt to locate the openssl.cnf on XAMPP / Windows installs.
     */
    protected function resolveOpensslConfig(): ?string
    {
        if ($existing = getenv('OPENSSL_CONF')) {
            return is_file($existing) ? $existing : null;
        }

        $candidates = [
            'C:\\xampp\\apache\\conf\\openssl.cnf',
            'C:\\xampp\\php\\extras\\ssl\\openssl.cnf',
        ];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
