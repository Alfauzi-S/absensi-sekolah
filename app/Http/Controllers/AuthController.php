<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function showLogin()
    {
        if (! User::exists()) {
            return redirect()->route('setup');
        }

        return view('auth.login');
    }

    public function login(Request $request)
    {
        $data = $request->validate(['username' => 'required|string', 'password' => 'required|string']);
        if (! Auth::attempt(['username' => $data['username'], 'password' => $data['password'], 'is_active' => true], $request->boolean('remember'))) {
            return back()->withErrors(['username' => 'Username atau password salah.'])->onlyInput('username');
        }
        $request->session()->regenerate();
        $request->user()->forceFill(['last_login_at' => now()])->save();

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /** Pemasangan pertama: buat akun admin bila belum ada pengguna sama sekali. */
    public function showSetup()
    {
        abort_if(User::exists(), 404);

        return view('auth.setup');
    }

    public function setup(Request $request)
    {
        abort_if(User::exists(), 404);
        $data = $request->validate([
            'school_name' => 'required|string|max:120',
            'name' => 'required|string|max:120',
            'username' => 'required|alpha_dash|max:40',
            'password' => 'required|string|min:8|confirmed',
        ]);
        Setting::put('school_name', $data['school_name']);
        $user = User::create(['name' => $data['name'], 'username' => $data['username'], 'password' => $data['password'], 'role' => 'admin']);
        Auth::login($user);

        return redirect()->route('dashboard')->with('ok', 'Selamat datang! Mulai dengan menambah kelas dan siswa.');
    }
}
