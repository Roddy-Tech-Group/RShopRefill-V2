<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\Shared\Enums\Currency;
use App\Domain\Wallet\Services\WalletService;
use App\Http\Controllers\Controller;
use App\Http\Resources\V2\WalletResource;
use App\Http\Resources\V2\WalletTransactionResource;
use App\Http\Responses\V2\CursorPage;
use App\Models\WalletTransaction;
use App\Support\ApiV2Error;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Wallet balances and statements.
 *
 * READ ONLY. Adding money to a wallet moves money and lives behind its own
 * endpoint with its own idempotency guard; nothing here changes a balance.
 *
 * Every query is scoped to the caller. A wallet id says nothing about who owns it,
 * so ownership is part of the WHERE clause rather than a check afterwards.
 */
class WalletController extends Controller
{
    public function __construct(private WalletService $wallets) {}

    public function index(Request $request): JsonResponse
    {
        $wallets = $request->user()->wallets()->orderBy('currency')->get();

        return response()->json(['data' => WalletResource::collection($wallets)->resolve()]);
    }

    public function show(Request $request, string $currency): JsonResponse
    {
        $resolved = Currency::tryFrom(strtoupper($currency));

        if ($resolved === null) {
            return ApiV2Error::make('unsupported_currency', 'We do not hold wallets in that currency.', 404);
        }

        // getOrCreate rather than find: a customer who has never held naira still
        // has a naira wallet conceptually, and an empty one is a better answer than
        // a 404 the app has to special-case before it can show a zero.
        $wallet = $this->wallets->getOrCreateWallet($request->user(), $resolved);

        return response()->json(['data' => (new WalletResource($wallet))->resolve()]);
    }

    /**
     * The statement. Every movement across every wallet, newest first, or one
     * currency's when asked.
     */
    public function transactions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'currency' => ['nullable', 'string', 'size:3'],
            'per_page' => ['nullable', 'integer', 'min:1'],
            'cursor' => ['nullable', 'string', 'max:500'],
        ]);

        $transactions = WalletTransaction::where('user_id', $request->user()->id)
            ->when(
                $validated['currency'] ?? null,
                fn ($query, $currency) => $query->where('currency', strtoupper($currency)),
            )
            // The id tiebreaker keeps cursor paging total: two movements in the same
            // second must not be skipped or repeated as the customer scrolls a
            // statement, which is exactly where being off by one is noticed.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate(
                CursorPage::perPage($validated['per_page'] ?? null),
                ['*'],
                'cursor',
                CursorPage::cursor($validated['cursor'] ?? null, ['created_at', 'id']),
            );

        return CursorPage::respond($transactions, WalletTransactionResource::class);
    }
}
