<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Users\CreateUser;
use App\Actions\Users\DeactivateUser;
use App\Actions\Users\DeleteUser;
use App\Actions\Users\ExportUsers;
use App\Actions\Users\SetUserStoreRoles;
use App\Actions\Users\UpdateUser;
use App\Exceptions\UserActionDenied;
use App\Http\Controllers\Concerns\RendersDataTableRows;
use App\Http\Controllers\Concerns\ResolvesExportFormat;
use App\Http\Controllers\Concerns\RespondsJsonOrRedirect;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserRequest;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\Auth\SessionRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * User management — list + dedicated create/edit pages. Store/role
 * assignment (a user holds a role per store) is part of the form.
 * Gated by `users.*` permissions; super admins bypass via Gate::before.
 *
 * The "Send a setup link" password option creates the account with a
 * random password and emails the user a password-reset link to set their
 * own (see {@see store()} / {@see sendSetupLink()}).
 *
 * Deferred (separate auth build): MFA, API tokens, and the audit log.
 */
class UserController extends Controller
{
    use ResolvesExportFormat;
    use RespondsJsonOrRedirect;

    use RendersDataTableRows;

    /**
     * Users list — server-paginated. Only the first page renders inline; the
     * component search box (`?q=`), the sortable name header and paging all
     * round-trip to {@see rows()} via the generic `usersIndexPage`
     * (aliased serverTablePage) factory.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', User::class);

        $perPage   = $this->dtPerPage($request);
        $paginator = $this->listQuery($request)->paginate($perPage);

        return view('admin.users.index', [
            'users'      => $paginator->getCollection(),
            'total'      => $paginator->total(),
            'perPage'    => $perPage,
            'totalPages' => max(1, $paginator->lastPage()),
        ]);
    }

    /** One page of user rows as an HTML fragment. See {@see RendersDataTableRows}. */
    public function rows(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $paginator = $this->listQuery($request)
            ->paginate($this->dtPerPage($request), ['*'], 'page', $this->dtPage($request));

        return $this->dtRows($paginator, 'admin.users._rows', 'users');
    }

    /** @return Builder<User> */
    private function listQuery(Request $request): Builder
    {
        $query = User::query()->with(['stores:id,name', 'defaultStore:id,name']);

        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $query->where(fn (Builder $w) => $w
                ->where('name', 'like', "%{$q}%")
                ->orWhere('email', 'like', "%{$q}%"));
        }

        $column = (string) $request->query('sort', '');
        $dir    = strtolower((string) $request->query('dir', 'asc')) === 'desc' ? 'desc' : 'asc';
        $map    = ['name' => 'name', 'id' => 'id'];
        if (isset($map[$column])) {
            return $query->orderBy($map[$column], $dir)->orderBy('id', 'desc');
        }

        return $query->orderBy('name');
    }

    public function create(): View
    {
        $this->authorize('create', User::class);

        return view('admin.users.create', $this->formContext());
    }

    public function store(UserRequest $request, CreateUser $create, SetUserStoreRoles $setRoles): JsonResponse|RedirectResponse
    {
        $this->authorize('create', User::class);

        $isSuperAdmin = $request->boolean('is_super_admin') && $request->user()->can('manageSuperAdmin', User::class);

        $user = ($create)($request->profileData(), $request->input('password'), $isSuperAdmin);
        ($setRoles)($user, $request->storeRoles());

        // "Send a setup link" — the account was created with a random
        // password, so email the user a password-reset link to set their
        // own. Reuses the standard broker (config/auth.php) + the merchant's
        // SMTP (rewritten per-request by ApplyCompanySettings).
        $message = __('users.flash.created', ['name' => $user->name]);
        if ($request->input('password_mode') === 'setup_link') {
            $message = $this->sendSetupLink($user)
                ? __('users.flash.created_with_link', ['name' => $user->name])
                : __('users.flash.created_link_failed', ['name' => $user->name]);
        }

        return $this->jsonOrRedirect($request, $message, route('admin.users.index'));
    }

    /**
     * Email the user a password-setup (reset) link. Returns false — without
     * throwing — if mail isn't configured or delivery fails, so a created
     * user is never lost just because the email couldn't go out.
     */
    private function sendSetupLink(User $user): bool
    {
        try {
            return \Illuminate\Support\Facades\Password::sendResetLink(['email' => $user->email])
                === \Illuminate\Support\Facades\Password::RESET_LINK_SENT;
        } catch (\Throwable) {
            return false;
        }
    }

    public function edit(User $user): View
    {
        $this->authorize('update', $user);

        $assigned = $user->stores()
            ->get(['stores.id'])
            ->map(fn ($s) => ['store_id' => (string) $s->id, 'role_id' => (string) $s->pivot->role_id])
            ->values()
            ->all();

        return view('admin.users.edit', array_merge($this->formContext(), [
            'user'     => $user,
            'assigned' => $assigned,
        ]));
    }

    public function update(UserRequest $request, User $user, UpdateUser $update, SetUserStoreRoles $setRoles): JsonResponse|RedirectResponse
    {
        $this->authorize('update', $user);

        $isSuperAdmin = $request->user()->can('manageSuperAdmin', User::class)
            ? $request->boolean('is_super_admin')
            : null; // not allowed to change → leave as-is

        try {
            ($update)(
                $user,
                $request->profileData(),
                $request->input('password'),
                $isSuperAdmin,
                (int) $request->user()->id,
            );
        } catch (UserActionDenied $e) {
            return $this->jsonOrError(
                $request,
                __('users.errors.'.$e->reason, ['name' => $user->name]),
                route('admin.users.index'),
            );
        }

        // Roles are only writable by someone who actually manages users.
        // UserPolicy::update deliberately lets anyone edit THEMSELVES (profile
        // self-service), and this method used to re-save the posted roles
        // unconditionally — so that self-edit allowance doubled as a way to
        // hand yourself the Admin role (SEC #1 / defect #2). A self-edit by
        // someone without `users.update` now changes profile fields only; the
        // Profile page is the intended route for it and cannot touch roles.
        if ($request->user()->hasPermission('users.update')) {
            ($setRoles)($user, $request->storeRoles());
        }

        return $this->jsonOrRedirect(
            $request,
            __('users.flash.updated', ['name' => $user->name]),
            route('admin.users.index'),
        );
    }

    public function toggle(Request $request, User $user, DeactivateUser $deactivate): JsonResponse|RedirectResponse
    {
        $this->authorize('update', $user);

        $data = $request->validate(['is_active' => ['required', 'boolean']]);

        try {
            ($deactivate)($user, (bool) $data['is_active'], $request->user()->id);
        } catch (UserActionDenied $e) {
            return $this->jsonOrError(
                $request,
                __('users.errors.'.$e->reason, ['name' => $user->name]),
                route('admin.users.index'),
            );
        }

        $key = $data['is_active'] ? 'users.flash.activated' : 'users.flash.deactivated';

        return $this->jsonOrRedirect(
            $request,
            __($key, ['name' => $user->name]),
            route('admin.users.index'),
        );
    }

    /**
     * Force a user off every device they're signed in on (SEC #40).
     *
     * `users.force_logout` has been in the permissions catalog since the first
     * seeder and had nothing behind it — the only way to evict a signed-in
     * account was to change its password. This is what the docs call
     * "Revoke sessions" on the user row.
     *
     * Deliberately separate from deactivation: a manager sending a cashier
     * home from a shared tablet wants them out of that session, not locked out
     * of the job tomorrow.
     */
    public function forceLogout(Request $request, User $user, SessionRegistry $sessions): JsonResponse|RedirectResponse
    {
        $this->authorize('update', $user);
        abort_unless($request->user()?->hasPermission('users.force_logout'), 403);

        if (! $sessions->supported()) {
            return $this->jsonOrError(
                $request,
                __('users.errors.sessions_unsupported'),
                route('admin.users.index'),
            );
        }

        $count = $sessions->revokeOthers($user->id, null);

        do_action('auth.session_revoked', $user, $count);

        return $this->jsonOrRedirect(
            $request,
            trans_choice('users.flash.force_logged_out', $count, ['name' => $user->name, 'count' => $count]),
            route('admin.users.index'),
        );
    }

    public function destroy(Request $request, User $user, DeleteUser $delete): JsonResponse|RedirectResponse
    {
        $this->authorize('delete', $user);

        if (pos_is_demo()) {
            return $this->demoBlocked($request, route('admin.users.index'));
        }

        $name = $user->name;

        try {
            ($delete)($user, $request->user()->id);
        } catch (UserActionDenied $e) {
            return $this->jsonOrError(
                $request,
                __('users.errors.'.$e->reason, ['name' => $name]),
                route('admin.users.index'),
            );
        }

        return $this->jsonOrRedirect(
            $request,
            __('users.flash.deleted', ['name' => $name]),
            route('admin.users.index'),
        );
    }

    public function export(Request $request, ExportUsers $export): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->authorize('viewAny', User::class);

        $format = $this->exportFormat($request);

        return ($export)($format);
    }

    /* ── Helpers ────────────────────────────────────────────────── */

    /** @return array<string, mixed> */
    private function formContext(): array
    {
        return [
            'stores'         => Store::query()->active()->ordered()->get(['id', 'name']),
            'roles'          => Role::query()->ordered()->get(['id', 'name']),
            'canSuperAdmin'  => auth()->user()?->can('manageSuperAdmin', User::class) ?? false,
        ];
    }
}
