<?php

return [

    'title' => 'Restore from backup',
    'sub'   => 'Replace all current data with the contents of a backup. This cannot be undone.',
    'crumb' => 'Restore',

    'steps' => [
        'source'  => 'Choose a backup',
        'review'  => 'Review',
        'confirm' => 'Confirm',
    ],

    'source' => [
        'existing_title' => 'Restore from an existing backup',
        'existing_sub'   => 'Pick one of the backups stored on this server.',
        'empty'          => 'No backups on this server yet. Run a backup first, or upload a backup file.',
        'upload_title'   => 'Upload a backup file',
        'upload_sub'     => 'Choose a .zip backup created by this app.',
        'choose_file'    => 'Choose file',
        'col_created'    => 'Created',
        'col_version'    => 'Version',
        'col_size'       => 'Size',
        'col_type'       => 'Type',
        'continue'       => 'Continue',
    ],

    'review' => [
        'title'        => 'Review this backup',
        'created'      => 'Backup taken',
        'app_version'  => 'App version',
        'schema'       => 'Database structure',
        'company'      => 'Company',
        'size'         => 'Size',
        'contents'     => 'Contents',
        'compatible'   => 'This backup is compatible with your install.',
        'incompatible' => 'This backup cannot be restored on this install.',
        'back'         => 'Back',
        'continue'     => 'Continue',
    ],

    'confirm' => [
        'title'       => 'Confirm restore',
        'warning'     => 'Restoring permanently replaces all current data and uploaded files with the contents of this backup. This cannot be undone.',
        'pre_restore' => 'Back up current data first (recommended)',
        'type_prompt' => 'Type :word to confirm',
        'word'        => 'RESTORE',
        'back'        => 'Back',
        'start'       => 'Start restore',
    ],

    'running' => [
        'title'  => 'Restoring…',
        'do_not' => 'Do not close or refresh this tab.',
    ],

    'done' => [
        'title' => 'Restore complete',
        'sub'   => 'Your data has been restored. Please sign in again.',
        'login' => 'Go to sign in',
    ],

    'counts' => [
        'products'  => 'Products',
        'customers' => 'Customers',
        'sales'     => 'Sales',
        'users'     => 'Users',
    ],

    /*
     |--------------------------------------------------------------------------
     | Restore — validation errors
     |--------------------------------------------------------------------------
     | Shown to the operator when a backup can't be restored. The version /
     | checksum / format checks all fire BEFORE the live database is touched,
     | so the operator can safely pick a different file.
     */
    'errors' => [
        'archive_missing'    => 'The backup archive could not be found.',
        'archive_unreadable' => 'The backup archive could not be opened. It may be corrupt or incomplete.',
        'manifest_missing'   => 'This file is not a valid backup — its manifest is missing.',
        'unsupported_format' => 'This backup was created in a format this version cannot restore.',
        // SEC #20 — our own dumps only ever contain DROP/CREATE/INSERT.
        'unsafe_dump'        => 'This backup contains a :statement statement, which our backups never produce. It was not created by this application and will not be restored.',
        'dump_missing'       => 'The backup is missing its database dump and cannot be restored.',
        'checksum_mismatch'  => 'The backup is corrupt: its database dump failed the integrity check.',
        'newer_app_version'  => 'This backup is from a newer version (:backup) than this install (:current). Update this install first, then restore.',
        'newer_schema'       => 'This backup expects a newer database structure than this install supports. Update this install first, then restore.',
        'no_source'          => 'Choose an existing backup or upload a backup file.',
        'session_expired'    => 'This restore session expired. Please choose the backup again.',
        // SEC #20 — provenance. Raised when an UPLOADED archive can't be shown
        // to be one this install produced and the operator hasn't ticked the
        // "I know where this file came from" box.
        'unverified_unsigned' => 'This backup carries no signature, so we cannot confirm this install created it. Restoring it will replace all of your data with whatever it contains. Tick the confirmation box if you are certain of where this file came from.',
        'unverified_invalid'  => 'This backup was not signed by this install — it is either from a different installation or it has been modified. Restoring it will replace all of your data with whatever it contains. Tick the confirmation box if you are certain of where this file came from.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Restore — provenance (SEC #20)
    |--------------------------------------------------------------------------
    | Shown on the review step. A backup this install made is signed with a key
    | the archive does not contain, so we can tell our own file from one
    | somebody handed the admin. Restoring another install's backup is a
    | legitimate migration, so an unconfirmed archive asks for confirmation
    | rather than being refused.
    */
    'provenance' => [
        'unsigned' => 'This file carries no signature, so we cannot confirm this install created it.',
        'invalid'  => 'This file was not signed by this install — it is from a different installation, or it has been modified since it was made.',
        'confirm'  => 'I know where this file came from and I accept the risk of restoring it.',
        'label'    => 'Source',
        'verified' => 'Created by this installation',
    ],

];
