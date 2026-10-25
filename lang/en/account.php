<?php

return [

    'title' => 'Profile & preferences',
    'sub'   => 'Manage your account details, language, and password.',

    'demo' => [
        'locked'          => 'Your profile can’t be changed in demo mode.',
        'password_locked' => 'The password can’t be changed in demo mode.',
        'locked_banner'   => 'Demo mode: your profile, preferences and password are read-only. Your changes won’t be saved.',
    ],

    'sections' => [
        'profile'         => 'Profile',
        'profile_sub'     => 'Your name, contact details, and photo.',
        'preferences'     => 'Preferences',
        'preferences_sub' => 'How the admin behaves for you.',
        'password'        => 'Password',
        'password_sub'    => 'Change the password you sign in with.',
        'sessions'        => 'Signed-in devices',
        'sessions_sub'    => 'Where your account is currently signed in. Sign the others out if you see something you do not recognise.',
    ],

    'fields' => [
        'avatar'             => 'Profile photo',
        'avatar_hint'        => 'Square images look best. Max 8 MB.',
        'name'               => 'Full name',
        'email'              => 'Email',
        'phone'              => 'Phone',
        'language'           => 'Language',
        'language_system'    => 'System default',
        'default_store'      => 'Default store',
        'default_store_none' => 'No default (ask each time)',
        'current_password'   => 'Current password',
        'new_password'       => 'New password',
        'confirm_password'   => 'Confirm new password',
    ],

    'actions' => [
        'save'            => 'Save changes',
        'change_password' => 'Change password',
        'sign_out_others' => 'Sign out other devices',
    ],

    'flash' => [
        'updated'          => 'Profile updated.',
        'updated_email_pending' => 'Profile updated. Check :email and open the link we sent to finish changing your address — until then you keep signing in with your current one.',
        'password_updated' => 'Password changed.',
    ],

    // Two-step email change (SEC #10).
    'email_change' => [
        'pending'   => 'Waiting for confirmation of :email. Open the link we sent there to finish the change; your current address keeps working until you do.',
        'confirmed' => 'Your email address is now :email.',
        'invalid'   => 'That confirmation link is invalid or has expired. Change your email again from your profile to get a new one.',
        'taken'     => 'That email address now belongs to another account, so the change was cancelled.',
        'mail'      => [
            'subject' => 'Confirm your new email address — :app',
            'heading' => 'Confirm your new email address',
            'body'    => 'Hi :name — someone asked to change the email address on your account to :email. Open the link below to confirm it. Until you do, your account keeps using its current address.',
            'cta'     => 'Confirm this address',
            'expiry'  => 'This link stops working in :hours hours.',
            'ignore'  => "If you didn't ask for this, ignore this email — nothing changes, and whoever asked cannot sign in with this address.",
        ],
    ],

    // Active devices (SEC #40).
    'sessions' => [
        'columns' => [
            'device'      => 'Device',
            'ip'          => 'IP address',
            'last_active' => 'Last active',
        ],
        'this_device'      => 'This device',
        'none'             => 'No other devices are signed in.',
        'device'           => ':browser on :platform',
        'unknown_device'   => 'Unknown device',
        'unknown_browser'  => 'Unknown browser',
        'confirm_password' => 'Confirm your password',
        'wrong_password'   => 'That password is not correct.',
        'revoked'          => '{0}No other devices were signed in.|{1}Signed out 1 other device.|[2,*]Signed out :count other devices.',
        'driver_required'  => 'Device tracking needs the database session driver. Set SESSION_DRIVER=database in your .env file to see and manage signed-in devices.',
    ],

    'errors' => [
        'store_not_accessible' => "You don't have access to that store.",
        'locale_invalid'       => 'That language is not available.',
    ],

];
