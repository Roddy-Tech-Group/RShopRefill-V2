<?php

namespace App\Domain\Admin\Services;

use App\Models\Admin;
use PragmaRX\Google2FA\Google2FA;

class AdminTwoFactorService
{
    public function __construct(
        private readonly Google2FA $google2fa
    ) {}

    /**
     * Generate a new TOTP secret key.
     */
    public function generateSecretKey(): string
    {
        return $this->google2fa->generateSecretKey();
    }

    /**
     * Get the QR code URL for the authenticator app.
     */
    public function getQrCodeUrl(string $company, string $email, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl($company, $email, $secret);
    }

    /**
     * Verify a TOTP code against a secret.
     */
    public function verifyTotp(string $secret, string $code): bool
    {
        return $this->google2fa->verifyKey($secret, $code);
    }

    /**
     * Generate a 6-digit email OTP and save it to the admin model.
     */
    public function generateAndSaveEmailOtp(Admin $admin): string
    {
        // 6 digit secure code
        $otp = (string) random_int(100000, 999999);

        $admin->update([
            'email_otp' => $otp,
            'email_otp_expires_at' => now()->addMinutes(10),
        ]);

        return $otp;
    }

    /**
     * Verify an email OTP.
     */
    public function verifyEmailOtp(Admin $admin, string $code): bool
    {
        if (! $admin->email_otp || ! $admin->email_otp_expires_at) {
            return false;
        }

        if (now()->isAfter($admin->email_otp_expires_at)) {
            return false;
        }

        return hash_equals($admin->email_otp, $code);
    }

    /**
     * Clear the email OTP after successful verification.
     */
    public function clearEmailOtp(Admin $admin): void
    {
        $admin->update([
            'email_otp' => null,
            'email_otp_expires_at' => null,
        ]);
    }
}
