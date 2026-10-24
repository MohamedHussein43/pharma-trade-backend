<?php

namespace App\Http\Controllers\Api\Pharmacy;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pharmacy\PlaceMarketplaceOrderRequest;
use App\Http\Requests\Pharmacy\SetCollectorRequest;
use App\Models\PharmacyCollection;
use App\Models\PharmacyOrder;
use App\Models\PharmacyBranch;
use App\Services\PharmacyMarketplaceService;
use App\Services\PharmacyOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Services\NotificationService;


class MarketplaceOrderController extends BaseMarketplaceController
{
    public function __construct(
        private readonly PharmacyMarketplaceService $marketplaceService,
        private readonly PharmacyOrderService       $orderService,
        private readonly NotificationService $notificationService,
        
    ) {}

    /**
     * GET /pharmacy/marketplace/orders
     * Orders placed by this buyer branch.
     */
    public function index(Request $request): JsonResponse
    {
        $branch = $this->buyerBranch();

        $orders = PharmacyOrder::with('collection.items.drug', 'sellerBranch')
            ->where('buyer_branch_id', $branch->id)
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($orders);
    }

    /**
     * POST /pharmacy/marketplace/orders
     * Place an order for a collection.
     */
    public function store(PlaceMarketplaceOrderRequest $request): JsonResponse
    {
        $branch     = $this->buyerBranch();
        $collection = PharmacyCollection::findOrFail($request->validated()['collection_id']);

        try {
            $order = $this->marketplaceService->placeOrder(
                $collection,
                $branch,
                Auth::id()
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($order->load('collection.items.drug'), 201);
    }

    /**
     * GET /pharmacy/marketplace/orders/{order}
     */
    public function show(PharmacyOrder $order): JsonResponse
    {
        $this->authorizeBuyer($order);

        return response()->json($order->load('collection.items.drug', 'sellerBranch'));
    }

    /**
     * POST /pharmacy/marketplace/orders/{order}/set-collector
     * Buyer provides collector details and advances to pending_seller.
     */
    public function setCollector(SetCollectorRequest $request, PharmacyOrder $order): JsonResponse
    {
        $this->authorizeBuyer($order);

        try {
            $order = $this->orderService->setCollectorAndConfirm(
                $order,
                $request->validated()['collector_name'],
                $request->validated()['collector_phone'],
                'buyer'
            );
            $this->notificationService->p2pOrderReceived($order);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($order);
    }

    /**
     * POST /pharmacy/marketplace/orders/{order}/cancel
     * Buyer cancels their own order.
     */
    public function cancel(PharmacyOrder $order): JsonResponse
    {
        $this->authorizeBuyer($order);

        try {
            $order = $this->orderService->cancelOrder($order, 'buyer');
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($order);
    }

    private function authorizeBuyer(PharmacyOrder $order): void
    {
        $branch = $this->buyerBranch();

        if ($order->buyer_branch_id !== $branch->id) {
            abort(403, 'غير مصرح.');
        }
    }
}
