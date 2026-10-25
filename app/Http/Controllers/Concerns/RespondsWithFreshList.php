<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The ONE `{ok, message, id, list_html, rows}` envelope behind every
 * master-detail settings screen's AJAX refresh (code-review #34). It was
 * copy-pasted as a private freshListJson() across ~14 admin controllers;
 * each controller now keeps only its query + row mapper and hands the
 * pieces here, so an envelope change touches one file.
 */
trait RespondsWithFreshList
{
    /**
     * @param array<string, mixed> $viewData  data for the `_list` partial
     *                                        (keeps each partial's own
     *                                        variable name, e.g. `brands`)
     * @param mixed                $rows      JSON-ready row payload
     */
    /**
     * The `?selected=` / `?new=` resolution every master-detail index()
     * repeats (code-review #36).
     *
     * @return array{0: mixed, 1: bool, 2: int} [$selected, $isNew, $selectedId]
     */
    private function resolveSelection(Request $request, Collection $rows): array
    {
        $selectedId = $request->integer('selected');
        $isNew      = $request->boolean('new');
        $selected   = $selectedId ? $rows->firstWhere('id', $selectedId) : null;

        return [$selected, $isNew, $selectedId];
    }

    /**
     * The save-path fork every master-detail store()/update() repeats
     * (code-review #36): AJAX gets the fresh-list envelope, a plain form
     * post redirects back with the row selected and a success flash.
     */
    private function savedListResponse(
        Request $request,
        ?int $id,
        string $message,
        string $indexRoute,
    ): JsonResponse|RedirectResponse {
        if ($request->wantsJson()) {
            return $this->freshListJson($id, $message);
        }

        return redirect()
            ->route($indexRoute, ['selected' => $id])
            ->with('success', $message);
    }

    private function freshListEnvelope(
        ?int $selectedId,
        string $message,
        string $partial,
        array $viewData,
        mixed $rows,
    ): JsonResponse {
        return response()->json([
            'ok'        => true,
            'message'   => $message,
            'id'        => $selectedId,
            'list_html' => view($partial, $viewData)->render(),
            'rows'      => $rows,
        ]);
    }
}
