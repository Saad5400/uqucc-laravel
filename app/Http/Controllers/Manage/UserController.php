<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\StoreUserRequest;
use App\Http\Requests\Manage\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    /**
     * Show the users workspace (users are few — the list is searched client-side).
     */
    public function index(): Response
    {
        return Inertia::render('manage/users/Index', [
            'users' => User::query()
                ->with('roles')
                ->withCount('pages')
                ->orderBy('id')
                ->get()
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => $user->getRoleNames()->values(),
                    'telegram_id' => $user->telegram_id,
                    'verified' => $user->email_verified_at !== null,
                    'username' => $user->username,
                    'url' => $user->url,
                    'avatar' => $user->avatar,
                    'pages_count' => $user->pages_count,
                    'created_at' => $user->created_at?->toISOString(),
                ]),
            'roleOptions' => Role::query()->orderBy('name')->pluck('name'),
        ]);
    }

    /**
     * Create a new user (email starts verified; roles apply only for `assign-roles` holders).
     */
    public function store(StoreUserRequest $request): RedirectResponse
    {
        $user = new User($request->safe()->only(['name', 'email', 'password']));
        $user->email_verified_at = now();
        $user->save();

        if ($request->user()->can('assign-roles')) {
            $user->syncRoles($request->validated('roles', []));
        }

        return back();
    }

    /**
     * Update a user. The password only changes when a new one is sent, and
     * roles are silently ignored unless the acting user holds `assign-roles`.
     */
    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $user->fill($request->safe()->only(['name', 'email', 'username', 'url', 'avatar']));

        if ($request->filled('password')) {
            $user->password = $request->validated('password');
        }

        if ($request->has('verified')) {
            $user->email_verified_at = $request->boolean('verified') ? ($user->email_verified_at ?? now()) : null;
        }

        $user->save();

        if ($request->has('roles') && $request->user()->can('assign-roles')) {
            $user->syncRoles($request->validated('roles', []));
        }

        return back();
    }

    /**
     * Delete a user. Self-deletion is blocked, and authored pages survive —
     * only the author pivot rows are removed (cascade on `page_user.user_id`).
     */
    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($request->user()->is($user)) {
            throw ValidationException::withMessages([
                'user' => 'لا يمكنك حذف حسابك.',
            ]);
        }

        $user->delete();

        return back();
    }
}
