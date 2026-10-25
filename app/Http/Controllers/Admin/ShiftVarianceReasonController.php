<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\RespondsWithFreshList;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ShiftVarianceReasonRequest;
use App\Models\Shift;
use App\Models\ShiftVarianceReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Variance-reasons admin — picklist the cashier picks from on the
 * close-shift form. Same inline master-detail surface as Return Reasons
 * (small enough that CRUD lives inline; no separate Action classes).
 */
class ShiftVarianceReasonController extends Controller
{
    use RespondsWithFreshList;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', ShiftVarianceReason::class);

        $rows       = ShiftVarianceReason::query()->ordered()->get();
        [$selected, $isNew, $selectedId] = $this->resolveSelection($request, $rows);

        return view('admin.shift-variance-reasons.index', [
            'rows'     => $rows,
            'selected' => $selected,
            'isNew'    => $isNew,
        ]);
    }

    public function store(ShiftVarianceReasonRequest $request): RedirectResponse|JsonResponse
    {
        $this->authorize('create', ShiftVarianceReason::class);

        $row     = ShiftVarianceReason::create($request->persistedAttributes());
        $message = __('shift_variance_reasons.flash.created', ['name' => $row->name]);

        return $this->savedListResponse($request, $row->id, $message, 'admin.shift-variance-reasons.index');
    }

    public function update(ShiftVarianceReasonRequest $request, ShiftVarianceReason $shiftVarianceReason): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $shiftVarianceReason);

        $shiftVarianceReason->update($request->persistedAttributes());
        $message = __('shift_variance_reasons.flash.updated', ['name' => $shiftVarianceReason->name]);

        return $this->savedListResponse($request, $shiftVarianceReason->id, $message, 'admin.shift-variance-reasons.index');
    }

    public function destroy(Request $request, ShiftVarianceReason $shiftVarianceReason): RedirectResponse|JsonResponse
    {
        $this->authorize('delete', $shiftVarianceReason);
        $name = $shiftVarianceReason->name;

        // Block delete if any closed shift references the code — keeps
        // historical Z-reports legible. Operators can deactivate instead.
        $count = Shift::query()->where('variance_reason', $shiftVarianceReason->code)->count();
        if ($count > 0) {
            $msg = __('shift_variance_reasons.errors.in_use', ['count' => $count, 'name' => $name]);
            if ($request->wantsJson()) {
                return response()->json(['ok' => false, 'message' => $msg], 422);
            }
            return redirect()->route('admin.shift-variance-reasons.index', ['selected' => $shiftVarianceReason->id])->with('error', $msg);
        }

        $shiftVarianceReason->delete();
        $message = __('shift_variance_reasons.flash.deleted', ['name' => $name]);

        if ($request->wantsJson()) {
            return $this->freshListJson(null, $message);
        }
        return redirect()->route('admin.shift-variance-reasons.index')->with('success', $message);
    }

    private function freshListJson(?int $selectedId, string $message): JsonResponse
    {
        $rows = ShiftVarianceReason::query()->ordered()->get();

        $rowsForJs = $rows->map(fn (ShiftVarianceReason $r) => [
            'id'         => $r->id,
            'code'       => $r->code,
            'name'       => $r->name,
            'sort_order' => (int) $r->sort_order,
            'is_active'  => (bool) $r->is_active,
            'updated_at' => $r->updated_at?->diffForHumans(),
        ])->values();

        return $this->freshListEnvelope($selectedId, $message, 'admin.shift-variance-reasons._list', ['rows' => $rows], $rowsForJs);
    }
}
