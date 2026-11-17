<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index()
    {
        $users = User::with('homeroomClasses')->orderByRaw("CASE role WHEN 'admin' THEN 0 WHEN 'kepsek' THEN 1 WHEN 'tu' THEN 2 ELSE 3 END")->orderBy('name')->get();

        return view('users.index', ['users' => $users]);
    }

    public function create()
    {
        return view('users.form', ['user' => new User(['role' => 'guru', 'is_active' => true])]);
    }

    public function store(Request $request)
    {
        $user = User::create($this->validated($request));

        return redirect()->route('users.index')->with('ok', 'Akun '.$user->username.' dibuat.');
    }

    public function edit(User $user)
    {
        return view('users.form', ['user' => $user]);
    }

    public function update(Request $request, User $user)
    {
        $data = $this->validated($request, $user);
        if ($user->is($request->user())) {
            unset($data['role'], $data['is_active']); // jangan mengunci diri sendiri
        }
        if (empty($data['password'])) {
            unset($data['password']);
        }
        $user->update($data);

        return redirect()->route('users.index')->with('ok', 'Akun disimpan.');
    }

    public function destroy(Request $request, User $user)
    {
        abort_if($user->is($request->user()), 422, 'Tidak bisa menghapus akun sendiri.');
        $user->delete();

        return back()->with('ok', 'Akun dihapus.');
    }

    private function validated(Request $request, ?User $user = null): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'username' => ['required', 'alpha_dash', 'max:40', Rule::unique('users', 'username')->ignore($user?->id)],
            'role' => ['required', Rule::in(array_keys(User::ROLES))],
            'nip' => 'nullable|string|max:30',
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:8'],
        ]);
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
