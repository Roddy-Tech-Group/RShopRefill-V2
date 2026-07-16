<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Domain\Admin\Services\AdminAuthService;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AdminSecurityQuestionController extends Controller
{
    public function __construct(
        private readonly AdminAuthService $adminAuthService
    ) {}

    public function create(Request $request)
    {
        if (! $request->session()->has('admin_needs_security_question')) {
            return redirect()->route('admin.login');
        }

        $adminId = $request->session()->get('admin_2fa_id');
        $admin = Admin::find($adminId);

        if (! $admin || empty($admin->security_question)) {
            return redirect()->route('admin.login');
        }

        return view('admin.auth.security-question', [
            'question' => $admin->security_question
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (! $request->session()->has('admin_needs_security_question')) {
            return redirect()->route('admin.login');
        }

        $request->validate([
            'security_answer' => ['required', 'string'],
        ]);

        $adminId = $request->session()->get('admin_2fa_id');
        $admin = Admin::find($adminId);

        $answer = trim($request->security_answer);
        $lowerAnswer = strtolower($answer);

        if (! $admin || (! Hash::check($lowerAnswer, $admin->security_answer) && ! Hash::check($answer, $admin->security_answer))) {
            throw ValidationException::withMessages([
                'security_answer' => 'The provided answer is incorrect.',
            ]);
        }

        // Passed! Remove the flag.
        $request->session()->forget('admin_needs_security_question');

        return redirect()->route('admin.2fa.challenge');
    }
}
