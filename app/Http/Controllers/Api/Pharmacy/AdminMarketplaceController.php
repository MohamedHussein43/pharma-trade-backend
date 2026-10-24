<?php

namespace App\Http\Controllers\Api\Pharmacy;

use App\Http\Controllers\Controller;
use App\Models\PharmacyCollection;
use App\Models\PharmacyOrder;
use App\Services\PharmacyOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminMarketplaceController extends Controller
{
    public function __construct(
        private readonly PharmacyOrderService $orderService,
    ) {}

    /**
     * GET /admin/pharmacy/marketplace/orders
     * All P2P orders with filters.
     */
    public function orders(Request $request): JsonResponse
    {
        $orders = PharmacyOrder::with('collection', 'buyerBranch', 'sellerBranch')
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->input('buyer_branch_id'), fn ($q, $id) => $q->where('buyer_branch_id', $id))
            ->when($request->input('seller_branch_id'), fn ($q, $id) => $q->where('seller_branch_id', $id))
            ->orderByDesc('created_at')
            ->paginate(50);

        return response()->json($orders);
    }

    /**
     * GET /admin/pharmacy/marketplace/orders/{order}
     */
    public function showOrder(PharmacyOrder $order): JsonResponse
    {
        return response()->json($order->load('collection.items.drug', 'buyerBranch', 'sellerBranch'));
    }

    /**
     * POST /admin/pharmacy/marketplace/orders/{order}/force-cancel
     * Admin overrides to cancel any non-terminal order.
     */
    public function forceCancel(PharmacyOrder $order): JsonResponse
    {
        if ($order->isTerminal()) {
            return response()->json(['message' => 'الطلب في حالة نهائية ولا يمكن إلغاؤه.'], 422);
        }

        try {
            $order = $this->orderService->cancelOrder($order, 'admin');
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($order);
    }

    /**
     * GET /admin/pharmacy/marketplace/collections
     * All collections with optional status filter.
     */
    public function collections(Request $request): JsonResponse
    {
        $collections = PharmacyCollection::with('sellerBranch')
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->input('zone_id'), fn ($q, $id) => $q->where('zone_id', $id))
            ->orderByDesc('created_at')
            ->paginate(50);

        return response()->json($collections);
    }

    /**
     * POST /admin/pharmacy/marketplace/collections/{collection}/suspend
     * Suspend a collection (e.g. compliance issue).
     */
    public function suspendCollection(PharmacyCollection $collection): JsonResponse
    {
        if (in_array($collection->status, [
            PharmacyCollection::STATUS_COMPLETED,
            PharmacyCollection::STATUS_SUSPENDED,
        ])) {
            return response()->json(['message' => 'لا يمكن تعليق هذه المجموعة.'], 422);
        }

        $collection->update(['status' => PharmacyCollection::STATUS_SUSPENDED]);

        return response()->json($collection->fresh());
    }
}
