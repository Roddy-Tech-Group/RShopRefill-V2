<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Domain\Admin\Services\AdminAuthService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AdminLoginController extends Controller
{
    public function __construct(
        private readonly AdminAuthService $adminAuthService,
    ) {}

    /**
     * Display the admin login view.
     */
    public function create()
    {
        return view('admin.auth.login');
    }

    /**
     * Handle an incoming admin authentication request.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $this->adminAuthService->authenticate(
            $request->email,
            $request->password,
            $request->boolean('remember')
        );

        if ($request->session()->has('admin_needs_security_question')) {
            return redirect()->route('admin.security-question');
        }

        return redirect()->route('admin.2fa.challenge');
    }

    /**
     * Destroy an authenticated admin session.
     */
    public function destroy(): RedirectResponse
    {
        $this->adminAuthService->logout();

        return redirect('/');
    }
}
