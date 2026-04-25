<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $u = (string) $request->input('username', '');
        $p = (string) $request->input('password', '');
        $expectedUser = (string) config('bi.auth.user');
        $expectedPass = (string) config('bi.auth.password');

        if (hash_equals($expectedUser, $u) && hash_equals($expectedPass, $p)) {
            $request->session()->regenerate();
            $request->session()->put('bi_logged_in', true);
            return redirect('/');
        }
        return redirect('/login')->with('flash', [
            'type' => 'error',
            'msg'  => 'Invalid username or password.',
        ]);
    }

    public function logout(Request $request)
    {
        $request->session()->invalidate();
        return redirect('/login');
    }
}
