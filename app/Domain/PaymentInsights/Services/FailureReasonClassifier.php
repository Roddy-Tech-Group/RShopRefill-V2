<?php

namespace App\Domain\PaymentInsights\Services;

use App\Domain\PaymentInsights\Enums\FailureCategory;
use App\Domain\PaymentInsights\Enums\FailureStage;

class FailureReasonClassifier
{
    /**
     * Enumerated codes emitted by the browser telemetry module
     * (resources/js/payment-telemetry.js). Exact matches — checked before
     * the prose keyword fallback so each keeps its own reason code instead
     * of collapsing into a generic bucket.
     *
     * @var array<string, array{0: FailureCategory, 1: string}>
     */
    private const CLIENT_CODES = [
        'popup_closed' => [FailureCategory::Customer, 'user_cancelled'],
        'page_abandoned' => [FailureCategory::Customer, 'user_cancelled'],
        'popup_blocked' => [FailureCategory::Frontend, 'popup_blocked'],
        'sdk_load_failed' => [FailureCategory::Frontend, 'sdk_load_failed'],
        'ad_blocker' => [FailureCategory::Frontend, 'ad_blocker'],
        'js_exception' => [FailureCategory::Frontend, 'js_exception'],
        'network_offline' => [FailureCategory::Frontend, 'network_offline'],
        'network_timeout' => [FailureCategory::Frontend, 'network_timeout'],
        'slow_network' => [FailureCategory::Frontend, 'slow_network'],
    ];

    /**
     * Classifies a failure reason into a Category and Stage.
     * Returns an array with ['category' => FailureCategory, 'stage' => FailureStage, 'code' => string]
     */
    public function classify(string $provider, string $rawReason, string $sessionStatus): array
    {
        $normalized = strtolower(trim($rawReason));
        $stage = $this->inferStage($sessionStatus);

        // Enumerated client telemetry codes carry their own classification.
        if (isset(self::CLIENT_CODES[$normalized])) {
            [$category, $code] = self::CLIENT_CODES[$normalized];

            return [
                'category' => $category,
                'stage' => $stage,
                'code' => $code,
            ];
        }

        // Categorize based on keywords and patterns
        if (str_contains($normalized, 'insufficient funds') || str_contains($normalized, 'do not honor') || str_contains($normalized, 'declined')) {
            return [
                'category' => FailureCategory::Customer,
                'stage' => $stage,
                'code' => 'bank_declined',
            ];
        }

        if (str_contains($normalized, 'closed modal') || str_contains($normalized, 'user cancelled') || str_contains($normalized, 'changed mind') || str_contains($normalized, 'abandoned')) {
            return [
                'category' => FailureCategory::Customer,
                'stage' => $stage,
                'code' => 'user_cancelled',
            ];
        }

        // System markers outrank the provider bucket: 'orphaned_timeout' is an
        // internal sweeper verdict, not a gateway timeout.
        if (str_contains($normalized, 'orphaned_timeout') || str_contains($normalized, 'internal server error') || str_contains($normalized, 'system')) {
            return [
                'category' => FailureCategory::System,
                'stage' => $stage,
                'code' => 'system_error',
            ];
        }

        if (str_contains($normalized, 'timeout') || str_contains($normalized, 'gateway unavailable') || str_contains($normalized, 'provider error')) {
            return [
                'category' => FailureCategory::Provider,
                'stage' => $stage,
                'code' => 'provider_timeout',
            ];
        }

        // Frontend telemetry vocabulary: everything the browser tracker can
        // report about its own environment (blocked/failed popups, SDK load
        // failures, JS exceptions, connectivity loss).
        $frontendMarkers = ['ad blocker', 'js exception', 'javascript', 'popup', 'network issue', 'sdk', 'offline', 'browser refresh', 'slow network'];
        foreach ($frontendMarkers as $marker) {
            if (str_contains($normalized, $marker)) {
                return [
                    'category' => FailureCategory::Frontend,
                    'stage' => $stage,
                    'code' => 'frontend_issue',
                ];
            }
        }

        // Default fallback
        return [
            'category' => FailureCategory::System,
            'stage' => $stage,
            'code' => 'unknown_error',
        ];
    }

    private function inferStage(string $sessionStatus): FailureStage
    {
        return match ($sessionStatus) {
            'pending', 'awaiting_method' => FailureStage::Initialization,
            'awaiting_customer_action' => FailureStage::Authentication,
            'processing' => FailureStage::Processing,
            'awaiting_transfer', 'awaiting_confirmation' => FailureStage::Confirmation,
            default => FailureStage::Processing,
        };
    }
}
