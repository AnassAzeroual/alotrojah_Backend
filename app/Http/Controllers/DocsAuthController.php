<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Session login guarding the Scramble docs (viewApiDocs gate). Separate from
 * the JWT API: only active admins get in, and the docs routes already run the
 * web middleware, so the session is available to the gate.
 */
class DocsAuthController extends Controller
{
    public function showLogin(): View|RedirectResponse
    {
        if (session()->has('docs_admin_id')) {
            return redirect('/docs/api');
        }

        return view('docs.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $credentials['email'])
            ->where('role', 'admin')
            ->where('is_active', true)
            ->first();

        // One generic failure for wrong password / non-admin / deactivated —
        // no enumeration of which emails exist.
        if (! $user || ! Hash::check($credentials['password'], $user->password_hash)) {
            throw ValidationException::withMessages([
                'email' => 'These credentials do not grant docs access.',
            ]);
        }

        $request->session()->regenerate();
        $request->session()->put('docs_admin_id', $user->id);

        return redirect('/docs/api');
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget('docs_admin_id');
        $request->session()->regenerateToken();

        return redirect('/docs/login');
    }
}
