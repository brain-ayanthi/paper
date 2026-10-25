<?php

namespace App\View\Components\Admin;

use App\Actions\Dashboard\ResolveSetupMenuProgress;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * "Finish store setup" row inside the topbar account menu — a small progress
 * ring plus the done/total count. Clicking it takes the owner back to the
 * dashboard checklist, restoring it if they'd dismissed it.
 *
 * Renders nothing once the essentials are all in place.
 */
class SetupProgressMenu extends Component
{
    /** @var array{percent:int,done:int,total:int}|null */
    public ?array $progress;

    public function __construct(ResolveSetupMenuProgress $resolve)
    {
        $this->progress = $resolve();
    }

    public function shouldRender(): bool
    {
        return $this->progress !== null;
    }

    public function render(): View
    {
        return view('components.admin.setup-progress-menu');
    }
}
