<?php

namespace App\Http\Controllers\Api\Pharmacy;

use App\Http\Controllers\Controller;
use App\Models\PharmacyOrder;
use App\Models\PharmacyBranch;
use App\Services\PharmacyOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Services\NotificationService;

class MarketplaceSellerOrderController extends BaseMarketplaceController
{
    public function __construct(
        private readonly PharmacyOrderService $orderService,
        private readonly NotificationService $notificationService,
    ) {}

    /**
     * GET /pharmacy/marketplace/seller/orders
     * Orders received by this seller branch.
     */
    public function index(Request $request): JsonResponse
    {
        $branch = $this->sellerBranch();

        $orders = PharmacyOrder::with('collection.items.drug', 'buyerBranch')
            ->where('seller_branch_id', $branch->id)
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($orders);
    }

    /**
     * GET /pharmacy/marketplace/seller/orders/{order}
     */
    public function show(PharmacyOrder $order): JsonResponse
    {
        $this->authorizeSeller($order);

        return response()->json($order->load('collection.items.drug', 'buyerBranch'));
    }

    /**
     * POST /pharmacy/marketplace/seller/orders/{order}/accept
     */
    public function accept(PharmacyOrder $order): JsonResponse
    {
        $this->authorizeSeller($order);

        try {
            $order = $this->orderService->acceptOrder($order, 'seller');
            $this->notificationService->p2pOrderAccepted($order);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($order);
    }

    /**
     * POST /pharmacy/marketplace/seller/orders/{order}/reject
     */
    public function reject(PharmacyOrder $order): JsonResponse
    {
        $this->authorizeSeller($order);

        try {
            $order = $this->orderService->rejectOrder($order, 'seller');
            $this->notificationService->p2pOrderRejected($order);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($order);
    }

    /**
     * POST /pharmacy/marketplace/seller/orders/{order}/ready
     * Mark order as ready for pickup.
     */
    public function markReady(PharmacyOrder $order): JsonResponse
    {
        $this->authorizeSeller($order);

        try {
            $order = $this->orderService->markReadyForPickup($order, 'seller');
            $this->notificationService->p2pOrderReady($order);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($order);
    }

    /**
     * POST /pharmacy/marketplace/seller/orders/{order}/complete
     * Mark order as completed after cash handoff.
     */
    public function complete(PharmacyOrder $order): JsonResponse
    {
        $this->authorizeSeller($order);

        try {
            $order = $this->orderService->completeOrder($order, 'seller');
            $this->notificationService->p2pOrderCompleted($order);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($order);
    }

    /**
     * POST /pharmacy/marketplace/seller/orders/{order}/cancel
     * Seller cancels an accepted order (e.g. stock issue discovered).
     */
    public function cancel(PharmacyOrder $order): JsonResponse
    {
        $this->authorizeSeller($order);

        try {
            $order = $this->orderService->cancelOrder($order, 'seller');
            $this->notificationService->p2pOrderCancelled($order, 'seller');
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($order);
    }

    private function authorizeSeller(PharmacyOrder $order): void
    {
        $branch = $this->sellerBranch();

        if ($order->seller_branch_id !== $branch->id) {
            abort(403, 'غير مصرح.');
        }
    }
}
