<?php

namespace App\Http\Controllers\Cashier;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\User;
use App\Services\Sales\DiscountApprovalToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Manager approval for an over-threshold discount (Checkout discounts,
 * Slice 2). A manager who holds `sales.discount_above_threshold` for the
 * active store re-authenticates here; on success we hand back a short-
 * lived signed token the cashier includes in the ring-up. The server
 * re-verifies the token in {@see \App\Actions\Sales\CompleteSale}.
 */
class DiscountApprovalController extends Controller
{
    public function approve(Request $request, DiscountApprovalToken $tokens): JsonResponse
    {
        // The requester must be a real cashier ringing up a sale.
        $this->authorize('create', Sale::class);

        $data = $request->validate([
            'manager'  => ['required', 'string', 'max:191'],
            'password' => ['required', 'string'],
            'percent'  => ['nullable', 'numeric', 'between:0,100'],
        ]);

        $storeId = active_store_id();

        $manager = User::query()->where('email', $data['manager'])->first();

        if (! $manager || ! Hash::check($data['password'], (string) $manager->password)) {
            return response()->json(['ok' => false, 'message' => __('sales.discount_approval.invalid')], 422);
        }
        if (! $manager->is_active) {
            return response()->json(['ok' => false, 'message' => __('sales.discount_approval.inactive')], 422);
        }
        if (! $manager->hasPermission('sales.discount_above_threshold', (int) $storeId)) {
            return response()->json(['ok' => false, 'message' => __('sales.discount_approval.not_authorized')], 422);
        }

        $token = $tokens->issue((int) $manager->id, (int) $storeId, (float) ($data['percent'] ?? 100));

        return response()->json([
            'ok'       => true,
            'token'    => $token,
            'approver' => $manager->name,
            'message'  => __('sales.discount_approval.approved', ['name' => $manager->name]),
        ]);
    }
}
