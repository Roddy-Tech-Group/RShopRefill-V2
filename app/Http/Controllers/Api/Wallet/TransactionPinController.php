<?php

namespace App\Http\Controllers\Api\Wallet;

use App\Domain\Wallet\Services\TransactionPinService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Wallet\ChangeTransactionPinRequest;
use App\Http\Requests\Wallet\ResetTransactionPinRequest;
use App\Http\Requests\Wallet\SetupTransactionPinRequest;
use App\Http\Requests\Wallet\VerifyTransactionPinRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TransactionPinController extends Controller
{
    public function __construct(
        private readonly TransactionPinService $pinService
    ) {}

    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'has_pin' => $user->hasTransactionPin(),
            'is_locked' => $user->isTransactionPinLocked(),
            'locked_until' => $user->transaction_pin_locked_until,
        ]);
    }

    public function setup(SetupTransactionPinRequest $request): JsonResponse
    {
        $this->pinService->setupPin($request->user(), $request->input('pin'));

        return response()->json([
            'message' => 'Transaction PIN successfully configured.',
        ], 201);
    }

    public function verify(VerifyTransactionPinRequest $request): JsonResponse
    {
        $token = $this->pinService->verifyPin($request->user(), $request->input('pin'));

        return response()->json([
            'message' => 'Transaction PIN verified.',
            'auth_token' => $token,
            'expires_in' => 300, // 5 minutes
        ]);
    }

    public function change(ChangeTransactionPinRequest $request): JsonResponse
    {
        $this->pinService->changePin(
            $request->user(),
            $request->input('old_pin'),
            $request->input('new_pin')
        );

        return response()->json([
            'message' => 'Transaction PIN successfully changed.',
        ]);
    }

    public function requestReset(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasTransactionPin()) {
            throw ValidationException::withMessages(['pin' => 'You do not have a transaction PIN set.']);
        }

        // The service enforces the password step-up and the hourly limit.
        $this->pinService->requestReset($user, $request->input('password'));

        return response()->json([
            'message' => 'If you have a PIN configured, a reset link has been sent to your email.',
        ]);
    }

    public function confirmReset(ResetTransactionPinRequest $request): JsonResponse
    {
        $this->pinService->confirmReset(
            $request->user(),
            $request->input('token'),
            $request->input('new_pin')
        );

        return response()->json([
            'message' => 'Transaction PIN successfully reset.',
        ]);
    }
}
