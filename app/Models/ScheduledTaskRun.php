<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The last claimed run-window for one recurring task.
 *
 * Written only by {@see \App\Actions\System\RunDailyTask}. A row left at
 * `status = 'running'` means the process died mid-task (an OOM or a host
 * time-limit kill leaves no exception to catch), which is how the backup
 * runner notices a silent failure a period later.
 */
class ScheduledTaskRun extends Model
{
    protected $fillable = ['task_key', 'period_started_at', 'ran_at', 'status', 'message'];

    protected $casts = [
        'period_started_at' => 'datetime',
        'ran_at'            => 'datetime',
    ];

    public const STATUS_RUNNING = 'running';
    public const STATUS_OK      = 'ok';
    public const STATUS_FAILED  = 'failed';
}
