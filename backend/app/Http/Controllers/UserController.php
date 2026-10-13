<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /**
     * Ambil semua user dengan role admin atau guru.
     */
    public function index()
    {
        $users = User::select('id', 'name', 'email', 'role', 'created_at')
            ->whereIn('role', ['admin', 'guru', 'super_admin'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($users);
    }

    /**
     * Buat user admin atau guru baru.
     * Hanya super_admin yang bisa akses (dijaga di route via CheckRole).
     */
    public function store(Request $request)
    {
        $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8|max:100',
            'role'     => ['required', Rule::in(['admin', 'guru'])],
        ]);

        $user = User::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'password' => Hash::make($request->password),
        ]);

        // forceFill karena role ada di $guarded
        $user->forceFill(['role' => $request->role])->save();

        return response()->json([
            'message' => 'User berhasil dibuat.',
            'user'    => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'role'  => $user->role,
            ],
        ], 201);
    }

    /**
     * Hapus user admin atau guru.
     * Tidak bisa hapus diri sendiri atau sesama super_admin.
     */
    public function destroy(Request $request, $id)
    {
        $target = User::findOrFail($id);

        // Tidak boleh hapus diri sendiri
        if ($target->id === Auth::id()) {
            return response()->json(['message' => 'Tidak bisa menghapus akun sendiri.'], 403);
        }

        // Tidak boleh hapus super_admin lain
        if ($target->role === 'super_admin') {
            return response()->json(['message' => 'Tidak bisa menghapus akun super admin.'], 403);
        }

        // Hanya admin dan guru yang boleh dihapus lewat endpoint ini
        if (!in_array($target->role, ['admin', 'guru'])) {
            return response()->json(['message' => 'User ini tidak bisa dihapus lewat endpoint ini.'], 403);
        }

        $target->delete();

        return response()->json(['message' => 'User berhasil dihapus.']);
    }

    /**
     * Mengambil data user yang sedang login.
     */
    public function getUser(Request $request)
    {
        return response()->json($request->user());
    }
}
