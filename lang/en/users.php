<?php

return [
    'title'        => 'Users',
    'sub'          => 'The people who can sign in. Give each a role per store.',
    'crumb_parent' => 'Configure',

    'actions' => [
        'new'         => 'New user',
        'edit'        => 'Edit',
        'save'        => 'Save changes',
        'create'      => 'Create user',
        'delete'      => 'Delete',
        'cancel'      => 'Cancel',
        'discard'     => 'Discard',
        'activate'    => 'Activate',
        'deactivate'  => 'Deactivate',
        'force_logout' => 'Sign out everywhere',
        'add_store'   => 'Add store access',
        'remove'      => 'Remove',
        'export'      => 'Export',
        'export_csv'  => 'CSV',
        'export_xlsx' => 'Excel (XLSX)',
    ],

    'create' => ['title' => 'New user', 'sub' => 'Create an account and grant store access.'],
    'edit'   => ['title' => 'Edit user', 'sub' => 'Update profile, access, and status.'],

    'form' => [
        'profile'      => 'Profile',
        'profile_sub'  => 'Who this person is.',
        'access'       => 'Store access',
        'access_sub'   => 'Which stores they can work in, and their role in each.',
        'security'     => 'Security',
        'security_sub' => 'Sign-in and account status.',
    ],

    'fields' => [
        'name'        => 'Name',
        'name_ph'     => 'Full name',
        'email'       => 'Email',
        'email_ph'    => 'name@example.com',
        'phone'       => 'Phone',
        'locale'      => 'Locale',
        'locale_ph'   => 'en',
        'password'    => 'Password',
        'password_ph' => 'At least 8 characters',
        'password_edit_help' => 'Leave blank to keep the current password.',
        'password_mode'      => 'Initial password',
        'password_mode_set'  => 'Set a password now',
        'password_mode_link' => 'Send a setup link (random password for now)',
        'is_active'        => 'Active',
        'is_active_help'   => 'Inactive users can’t sign in.',
        'is_super_admin'      => 'Super admin',
        'is_super_admin_help' => 'Bypasses every permission. Grant sparingly.',
        'store'       => 'Store',
        'role'        => 'Role',
    ],

    'list' => [
        'title'       => 'All users (:count)',
        'search_ph'   => 'Search users…',
        'no_stores'   => 'No store access',
        'empty_title' => 'No users yet',
        'empty_sub'   => 'Create your first user to grant access.',
    ],

    'columns' => [
        'user'    => 'User',
        'access'  => 'Store access',
        'status'  => 'Status',
        'actions' => 'Actions',
    ],

    'badges' => [
        'super_admin' => 'Super admin',
        'active'      => 'Active',
        'inactive'    => 'Inactive',
        'you'         => 'You',
    ],

    'delete' => [
        'title'   => 'Delete “:name”?',
        'message' => 'The account is removed and can no longer sign in. Historical records are preserved.',
    ],

    // Admin force-logout (SEC #40).
    'force_logout' => [
        'title'   => 'Sign “:name” out of every device?',
        'message' => 'They will have to sign in again everywhere. Their account stays active and their password is unchanged.',
    ],

    'flash' => [
        'created'             => 'User “:name” created.',
        'created_with_link'   => 'User “:name” created — a setup link has been emailed so they can set their password.',
        'created_link_failed' => 'User “:name” created, but the setup link couldn’t be emailed. Check Settings → Email, then use “Forgot password”, or edit the user to set a password.',
        'updated'     => 'User “:name” updated.',
        'deleted'     => 'User “:name” deleted.',
        'activated'   => 'User “:name” activated.',
        'deactivated' => 'User “:name” deactivated.',
        'force_logged_out' => '{0}“:name” was not signed in anywhere.|{1}Signed “:name” out of 1 device.|[2,*]Signed “:name” out of :count devices.',
    ],

    'errors' => [
        'self'             => 'You can’t deactivate or delete your own account.',
        'last_super_admin' => 'Can’t remove “:name” — they’re the last active super admin.',
        'sessions_unsupported' => 'Signing users out needs the database session driver. Set SESSION_DRIVER=database in your .env file.',
    ],

    'validation' => [
        'stores_required' => 'Assign the user to at least one store.',
        'store_required'  => 'Please select a store for each access row.',
        'role_required'   => 'Please select a role for each access row.',
        'role_beyond_own_privileges' => 'You can’t assign the “:role” role — it holds permissions you don’t have yourself.',
    ],
];
