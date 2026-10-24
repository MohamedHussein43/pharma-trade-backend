<?php

namespace App\Services;

use App\Models\PharmacyOrder;
use App\Models\PharmacyBranch;
use Illuminate\Support\Facades\DB;

class PharmacyOrderService
{
    public function __construct(
        private readonly PharmacyMarketplaceService $marketplaceService
    ) {}

    /**
     * Transition an order to a new status.
     *
     * @throws \InvalidArgumentException  if the transition is not allowed
     */
    public function transition(
        PharmacyOrder $order,
        string        $newStatus,
        string        $role,          // 'buyer' | 'seller' | 'admin'
        array         $extra = []     // additional attributes to set (e.g. collector info)
    ): PharmacyOrder {
        if (! $order->canTransitionTo($newStatus, $role)) {
            throw new \InvalidArgumentException(
                "لا يمكن تغيير حالة الطلب من [{$order->status}] إلى [{$newStatus}]."
            );
        }

        DB::transaction(function () use ($order, $newStatus, $extra) {
            $attributes = array_merge($extra, ['status' => $newStatus]);

            // Stamp timestamps on specific transitions
            $attributes = array_merge($attributes, $this->timestampsFor($newStatus));

            $order->update($attributes);

            // Side-effects
            if ($order->shouldRestoreCollection()) {
                $this->marketplaceService->restoreCollection($order);
            }

            if ($newStatus === PharmacyOrder::STATUS_COMPLETED) {
                $this->marketplaceService->completeCollection($order);
            }
        });

        return $order->fresh();
    }

    /**
     * Set collector info and advance to pending_seller.
     * Buyer provides collector name + phone, then confirms the order.
     */
    public function setCollectorAndConfirm(
        PharmacyOrder $order,
        string        $collectorName,
        string        $collectorPhone,
        string        $role = 'buyer'
    ): PharmacyOrder {
        return $this->transition(
            $order,
            PharmacyOrder::STATUS_PENDING_SELLER,
            $role,
            [
                'collector_name'          => $collectorName,
                'collector_phone'         => $collectorPhone,
                'collector_address'       => $order->sellerBranch->address,
                'collector_confirmed_at'  => now(),
            ]
        );
    }

    // -------------------------------------------------------------------------
    // Convenience wrappers — thin sugar over transition()
    // -------------------------------------------------------------------------

    public function acceptOrder(PharmacyOrder $order, string $role = 'seller'): PharmacyOrder
    {
        return $this->transition($order, PharmacyOrder::STATUS_ACCEPTED, $role);
    }

    public function rejectOrder(PharmacyOrder $order, string $role = 'seller'): PharmacyOrder
    {
        return $this->transition($order, PharmacyOrder::STATUS_REJECTED, $role);
    }

    public function markReadyForPickup(PharmacyOrder $order, string $role = 'seller'): PharmacyOrder
    {
        return $this->transition($order, PharmacyOrder::STATUS_READY_FOR_PICKUP, $role);
    }

    public function completeOrder(PharmacyOrder $order, string $role = 'seller'): PharmacyOrder
    {
        return $this->transition($order, PharmacyOrder::STATUS_COMPLETED, $role);
    }

    public function cancelOrder(PharmacyOrder $order, string $role): PharmacyOrder
    {
        return $this->transition($order, PharmacyOrder::STATUS_CANCELLED, $role);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function timestampsFor(string $status): array
    {
        return match ($status) {
            PharmacyOrder::STATUS_ACCEPTED         => ['accepted_at'   => now()],
            PharmacyOrder::STATUS_READY_FOR_PICKUP => ['ready_at'      => now()],
            PharmacyOrder::STATUS_COMPLETED        => ['completed_at'  => now()],
            default                                => [],
        };
    }
}
