<?php

namespace App\Http\Controllers\Api;

use App\Domain\Wallet\Resources\WalletTransactionResource;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class UserTransactionController extends Controller
{
    public function index(Request $request)
    {
        $transactions = $request->user()->walletTransactions()
            ->when($request->queryString('currency'), fn ($q, $c) => $q->where('currency', strtoupper($c)))
            ->when($request->queryString('type'), fn ($q, $t) => $q->where('type', $t))
            ->latest()
            ->paginate((int) $request->query('per_page', 15));

        return WalletTransactionResource::collection($transactions);
    }
}
