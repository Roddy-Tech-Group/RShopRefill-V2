<?php

namespace App\Domain\Auth\Services;

use App\Domain\Communication\Services\PreferenceManager;
use App\Models\User;
use App\Models\WalletFunding;
use Illuminate\Support\Carbon;

/**
 * The "give me a copy of my data" right (NDPA s.34). Assembles everything we
 * hold about a customer that is theirs to see, in plain JSON. Secrets and
 * internal risk material are deliberately absent: password hashes, PIN
 * hashes, two-factor secrets, fraud scores and admin notes are ours, not
 * theirs, and would be a security hole in a downloadable file.
 */
class DataExportService
{
    /**
     * @return array<string, mixed>
     */
    public function build(User $user): array
    {
        return [
            'generated_at' => now()->toIso8601String(),
            'account' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $this->stamp($user->email_verified_at),
                'phone' => $user->phone,
                'phone_verified_at' => $this->stamp($user->phone_verified_at),
                'gender' => $user->gender,
                'country' => $user->country,
                'display_currency' => $user->display_currency,
                'theme' => $user->theme,
                'signed_in_with_google' => $user->google_id !== null,
                'kyc_status' => $user->kyc_status,
                'referral_code' => $user->referral_code,
                'created_at' => $this->stamp($user->created_at),
                'closure_requested_at' => $this->stamp($user->closure_requested_at),
            ],
            'communication_preferences' => $this->communicationPreferences($user),
            'wallets' => $user->wallets()->get()->map(fn ($wallet) => [
                'currency' => is_object($wallet->currency) ? $wallet->currency->value : $wallet->currency,
                'balance' => (string) $wallet->balance,
                'locked_balance' => (string) $wallet->locked_balance,
                'created_at' => $this->stamp($wallet->created_at),
            ])->all(),
            'wallet_transactions' => $user->walletTransactions()->orderBy('created_at')->get()->map(fn ($tx) => [
                'reference' => $tx->reference,
                'type' => is_object($tx->type) ? $tx->type->value : $tx->type,
                'currency' => is_object($tx->currency) ? $tx->currency->value : $tx->currency,
                'amount' => (string) $tx->amount,
                'description' => $tx->description,
                'created_at' => $this->stamp($tx->created_at),
            ])->all(),
            'wallet_fundings' => $user->hasMany(WalletFunding::class)->orderBy('created_at')->get()->map(fn ($funding) => [
                'reference' => $funding->reference,
                'gateway' => $funding->gateway,
                'currency' => $funding->currency,
                'amount' => (string) $funding->amount,
                'status' => is_object($funding->status) ? $funding->status->value : $funding->status,
                'created_at' => $this->stamp($funding->created_at),
            ])->all(),
            'orders' => $user->orders()->with('items')->orderBy('created_at')->get()->map(fn ($order) => [
                'order_number' => $order->order_number,
                'status' => is_object($order->order_status) ? $order->order_status->value : $order->order_status,
                'payment_status' => is_object($order->payment_status) ? $order->payment_status->value : $order->payment_status,
                'payment_method' => $order->payment_method,
                'currency' => $order->settlement_currency,
                'total' => (string) $order->total_amount,
                'placed_at' => $this->stamp($order->placed_at ?? $order->created_at),
                'items' => $order->items->map(fn ($item) => [
                    'name' => $item->product_name ?? $item->name ?? null,
                    'quantity' => $item->quantity ?? 1,
                    'total' => isset($item->total_amount) ? (string) $item->total_amount : null,
                ])->all(),
            ])->all(),
            'identity_verifications' => $user->kycVerifications()->get()->map(fn ($v) => [
                'provider' => $v->provider,
                'status' => $v->status,
                'decided_at' => $this->stamp($v->decided_at),
                'created_at' => $this->stamp($v->created_at),
            ])->all(),
            'trusted_devices' => $user->trustedDevices()->get()->map(fn ($device) => [
                'device_name' => $device->device_name,
                'browser' => $device->browser,
                'os' => $device->os,
                'last_seen_at' => $this->stamp($device->last_seen_at),
            ])->all(),
        ];
    }

    /**
     * The channel and topic switches as the customer sees them in settings,
     * with the platform defaults filled in for anything never touched.
     *
     * @return array<string, bool>
     */
    private function communicationPreferences(User $user): array
    {
        $prefs = app(PreferenceManager::class)->getPreferences($user);

        $labels = [
            'email_enabled' => 'Email notifications',
            'push_enabled' => 'In-app and push notifications',
            'sms_enabled' => 'SMS notifications',
            'whatsapp_enabled' => 'WhatsApp notifications',
            'wallet_email' => 'Wallet updates by email',
            'wallet_push' => 'Wallet updates in-app',
            'orders_email' => 'Order updates by email',
            'orders_push' => 'Order updates in-app',
            'transactions_email' => 'Transaction receipts by email',
            'transactions_push' => 'Transaction receipts in-app',
            'product_updates_email' => 'Product news by email',
            'product_updates_push' => 'Product news in-app',
            'marketing_email' => 'Offers and promotions by email',
            'marketing_push' => 'Offers and promotions in-app',
        ];

        $out = [];

        foreach ($labels as $key => $label) {
            $out[$label] = (bool) ($prefs->{$key} ?? false);
        }

        return $out;
    }

    private function stamp(mixed $value): ?string
    {
        return $value instanceof Carbon ? $value->toIso8601String() : ($value ? (string) $value : null);
    }
}
