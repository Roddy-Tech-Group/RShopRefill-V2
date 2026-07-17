<?php

namespace App\Domain\Shared\Services;

use Illuminate\Support\Manager;
use App\Domain\Shared\Contracts\ICardProvider;
use App\Domain\Shared\Contracts\IFXProvider;
use App\Domain\Shared\Contracts\IWalletProvider;
use InvalidArgumentException;

class ProviderManager extends Manager
{
    /**
     * Get the default driver name.
     */
    public function getDefaultDriver(): string
    {
        return $this->config->get('financial.default_provider', 'maplerad');
    }

    /**
     * Get a specific card provider instance.
     */
    public function card(string $driver = null): ICardProvider
    {
        $instance = $this->driver($driver);

        if (!$instance instanceof ICardProvider) {
            $name = $driver ?: $this->getDefaultDriver();
            throw new InvalidArgumentException("Driver [{$name}] does not implement ICardProvider.");
        }

        return $instance;
    }

    /**
     * Get a specific wallet provider instance.
     */
    public function wallet(string $driver = null): IWalletProvider
    {
        $instance = $this->driver($driver);

        if (!$instance instanceof IWalletProvider) {
            $name = $driver ?: $this->getDefaultDriver();
            throw new InvalidArgumentException("Driver [{$name}] does not implement IWalletProvider.");
        }

        return $instance;
    }

    /**
     * Get a specific FX provider instance.
     */
    public function fx(string $driver = null): IFXProvider
    {
        $instance = $this->driver($driver);

        if (!$instance instanceof IFXProvider) {
            $name = $driver ?: $this->getDefaultDriver();
            throw new InvalidArgumentException("Driver [{$name}] does not implement IFXProvider.");
        }

        return $instance;
    }

    protected function createMapleradDriver()
    {
        return new class implements ICardProvider, IWalletProvider, IFXProvider {
            private $cardAdapter;
            private $walletAdapter;
            private $fxAdapter;
            private $breaker;

            public function __construct() {
                $this->cardAdapter = new \App\Domain\Shared\Adapters\Maplerad\MapleradCardAdapter();
                $this->walletAdapter = new \App\Domain\Shared\Adapters\Maplerad\MapleradWalletAdapter();
                $this->fxAdapter = new \App\Domain\Shared\Adapters\Maplerad\MapleradFXAdapter();
                $this->breaker = new \App\Domain\Shared\Services\CircuitBreaker('maplerad', 5, 60);
            }

            public function createVirtualCard(array $data): array { return $this->breaker->execute(fn() => $this->cardAdapter->createVirtualCard($data)); }
            public function fundCard(string $cardId, int $amount, string $currency): array { return $this->breaker->execute(fn() => $this->cardAdapter->fundCard($cardId, $amount, $currency)); }
            public function freezeCard(string $cardId): bool { return $this->breaker->execute(fn() => $this->cardAdapter->freezeCard($cardId)); }
            public function unfreezeCard(string $cardId): bool { return $this->breaker->execute(fn() => $this->cardAdapter->unfreezeCard($cardId)); }
            public function terminateCard(string $cardId): bool { return $this->breaker->execute(fn() => $this->cardAdapter->terminateCard($cardId)); }
            public function getCardTransactions(string $cardId, array $filters = []): array { return $this->breaker->execute(fn() => $this->cardAdapter->getCardTransactions($cardId, $filters)); }

            public function createWallet(array $customerData): array { return $this->breaker->execute(fn() => $this->walletAdapter->createWallet($customerData)); }
            public function getBalance(string $currency, ?string $walletId = null): array { return $this->breaker->execute(fn() => $this->walletAdapter->getBalance($currency, $walletId)); }
            public function withdraw(int $amount, string $currency, array $bankDetails): array { return $this->breaker->execute(fn() => $this->walletAdapter->withdraw($amount, $currency, $bankDetails)); }

            public function getQuote(string $sourceCurrency, string $destinationCurrency, int $amount): array { return $this->breaker->execute(fn() => $this->fxAdapter->getQuote($sourceCurrency, $destinationCurrency, $amount)); }
            public function executeExchange(string $quoteId): array { return $this->breaker->execute(fn() => $this->fxAdapter->executeExchange($quoteId)); }
        };
    }

    /**
     * Create the Flutterwave driver instance.
     */
    protected function createFlutterwaveDriver()
    {
        return new class implements IWalletProvider {
            private $walletAdapter;
            private $breaker;

            public function __construct() {
                $this->walletAdapter = new \App\Domain\Shared\Adapters\Flutterwave\FlutterwaveWalletAdapter();
                $this->breaker = new \App\Domain\Shared\Services\CircuitBreaker('flutterwave', 5, 60);
            }

            public function createWallet(array $customerData): array { return $this->breaker->execute(fn() => $this->walletAdapter->createWallet($customerData)); }
            public function getBalance(string $currency, ?string $walletId = null): array { return $this->breaker->execute(fn() => $this->walletAdapter->getBalance($currency, $walletId)); }
            public function withdraw(int $amount, string $currency, array $bankDetails): array { return $this->breaker->execute(fn() => $this->walletAdapter->withdraw($amount, $currency, $bankDetails)); }
        };
    }
}
