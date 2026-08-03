<?php

namespace App\Services;

use App\Models\MasterOrder;
use App\Models\Notification;
use App\Models\OrderItem;
use App\Models\Supplier;
use App\Models\SupplierInventory;
use App\Models\SupplierOrder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AllocationEngine
{
    // =========================================================
    // allocate()
    // =========================================================
    public function allocate(MasterOrder $order): MasterOrder
    {
        $branch = $order->pharmacyBranch()->with('zones')->first();

        $orderItems = OrderItem::where('master_order_id', $order->id)
            ->whereNull('supplier_order_id')
            ->with('drug')
            ->get();

        if ($orderItems->isEmpty()) {
            throw new \Exception('Cannot allocate an order with no items.');
        }

        $branchZoneIds     = $branch->zones->pluck('id')->toArray();
        $eligibleSuppliers = $this->getEligibleSuppliers($branchZoneIds, $order);

        if ($eligibleSuppliers->isEmpty()) {
            throw new \Exception('No suppliers found serving your zone. Please contact support.');
        }

        $drugIds   = $orderItems->pluck('drug_id')->filter()->unique()->toArray();
        $inventory = $this->loadInventory($eligibleSuppliers->pluck('id')->toArray(), $drugIds);

        // ── Run the allocation strategy ───────────────────────
        $allocation = $order->order_mode === 'specific_supplier'
            ? $this->allocateSpecificSupplier($orderItems, $inventory, $eligibleSuppliers->first())
            : $this->allocateBestDiscount($orderItems, $inventory, $eligibleSuppliers);

        // ── Validate min_order_value per supplier ─────────────
        // This runs AFTER the split is computed but BEFORE
        // any DB writes. If a supplier's total falls below
        // their minimum we handle it here.
        $allocation = $this->enforceMinOrderValues(
            $allocation,
            $eligibleSuppliers,
            $inventory,
            $orderItems,
            $order->order_mode
        );

        // Persist in transaction
        DB::transaction(function () use ($order, $allocation) {
            $totalValue = 0;

            foreach ($allocation['supplier_splits'] as $supplierId => $items) {
                if (empty($items)) continue;

                $subtotal     = round(collect($items)->sum('line_total'), 2);
                $commPct      = 1.00;
                $commValue    = round($subtotal * $commPct / 100, 2);
                $soNumber     = $order->order_number . '-S' . $supplierId;

                $supplierOrder = SupplierOrder::create([
                    'master_order_id'  => $order->id,
                    'supplier_id'      => $supplierId,
                    'order_number'     => $soNumber,
                    'status'           => 'pending',
                    'subtotal'         => $subtotal,
                    'commission_pct'   => $commPct,
                    'commission_value' => $commValue,
                ]);

                foreach ($items as $item) {
                    \App\Models\OrderItem::create([
                        'master_order_id'    => $order->id,
                        'supplier_order_id'  => $supplierOrder->id,
                        'drug_id'            => $item['drug_id'],
                        'drug_name_raw'      => $item['drug_name'],
                        'quantity_requested' => $item['quantity_requested'],
                        'quantity_confirmed' => 0,
                        'unit_price'         => $item['unit_price'],
                        'discount_pct'       => $item['discount_pct'],
                        'line_total'         => $item['line_total'],
                        'status'             => 'pending',
                    ]);
                }

                $totalValue += $subtotal;
                $this->notifySupplier($supplierId, $supplierOrder);
            }

            $order->update([
                'status'      => 'pending_supplier_confirmation',
                'total_value' => $totalValue,
            ]);
        });

        return $order->fresh(['supplierOrders.orderItems', 'supplierOrders.supplier']);
    }

    // =========================================================
    // PRIVATE — Enforce min_order_value per supplier
    //
    // Three possible outcomes per supplier:
    //
    //   A) Subtotal >= min_order_value → keep as is
    //
    //   B) Subtotal < min_order_value AND best_discount mode
    //      → try to redistribute those items to another eligible
    //        supplier that meets minimum. If a supplier can fill
    //        the gap, move the items there.
    //
    //   C) Subtotal < min_order_value AND specific_supplier mode
    //      OR no other supplier can absorb the items
    //      → throw exception with clear message so pharmacy
    //        knows they need to add more items or choose
    //        a different supplier.
    // =========================================================
    private function enforceMinOrderValues(
        array      $allocation,
        Collection $suppliers,
        array      $inventory,
        Collection $orderItems,
        string     $mode
    ): array {
        $supplierMap   = $suppliers->keyBy('id');
        $belowMinimum  = [];

        // Pass 1: identify which suppliers are below their minimum
        foreach ($allocation['supplier_splits'] as $supplierId => $items) {
            if (empty($items)) continue;

            $supplier = $supplierMap->get($supplierId);
            if (! $supplier) continue;

            $subtotal = collect($items)->sum('line_total');
            $minValue = (float)$supplier->min_order_value;

            if ($minValue > 0 && $subtotal < $minValue) {
                $belowMinimum[$supplierId] = [
                    'supplier_name' => $supplier->name,
                    'subtotal'      => $subtotal,
                    'min_required'  => $minValue,
                    'shortfall'     => round($minValue - $subtotal, 2),
                    'items'         => $items,
                ];
            }
        }

        if (empty($belowMinimum)) {
            return $allocation; // All good — no changes needed
        }

        // Pass 2: In specific_supplier mode — no redistribution
        // possible. Throw immediately with clear message.
        if ($mode === 'specific_supplier') {
            $messages = [];
            foreach ($belowMinimum as $supplierId => $info) {
                $messages[] = "Your order total (EGP " . number_format($info['subtotal'], 2) . ") " .
                              "is below {$info['supplier_name']}'s minimum order value of EGP " .
                              number_format($info['min_required'], 2) . ". " .
                              "Please add EGP " . number_format($info['shortfall'], 2) . " more to your order.";
            }
            throw new \Exception(implode(' | ', $messages));
        }

        // Pass 3: In best_discount mode — try to redistribute
        // items from below-minimum suppliers to other suppliers
        foreach ($belowMinimum as $supplierId => $info) {
            $itemsToRedistribute = $info['items'];
            $redistributed       = false;

            // Try each item — find another supplier who has it
            $newItems = [];
            foreach ($itemsToRedistribute as $item) {
                $drugId     = $item['drug_id'];
                $foundAlt   = false;

                // Look for another supplier who has stock of this drug
                // and is NOT below minimum after receiving this item
                foreach ($allocation['supplier_splits'] as $altSupplierId => $altItems) {
                    if ($altSupplierId == $supplierId) continue;

                    $altSupplier    = $supplierMap->get($altSupplierId);
                    $altInvRow      = $inventory[$altSupplierId][$drugId] ?? null;

                    if (! $altInvRow || $altInvRow->quantity_available < $item['quantity_requested']) {
                        continue;
                    }

                    // Compute line total at this alternative supplier's price
                    $newLineTotal = round(
                        $item['quantity_requested']
                        * $altInvRow->unit_price
                        * (1 - $altInvRow->discount_pct / 100),
                        2
                    );

                    // Move item to alternative supplier
                    $allocation['supplier_splits'][$altSupplierId][] = [
                        'drug_id'            => $drugId,
                        'drug_name'          => $item['drug_name'],
                        'quantity_requested' => $item['quantity_requested'],
                        'unit_price'         => $altInvRow->unit_price,
                        'discount_pct'       => $altInvRow->discount_pct,
                        'line_total'         => $newLineTotal,
                    ];

                    $foundAlt      = true;
                    $redistributed = true;
                    break;
                }

                if (! $foundAlt) {
                    // Cannot redistribute this item — keep original
                    $newItems[] = $item;
                }
            }

            if ($redistributed && empty($newItems)) {
                // All items redistributed — remove this supplier entirely
                unset($allocation['supplier_splits'][$supplierId]);
            } else {
                // Partial or no redistribution — still below minimum
                // Re-check new subtotal after partial redistribution
                $newSubtotal = collect($newItems)->sum('line_total');
                $minRequired = $info['min_required'];

                if ($newSubtotal < $minRequired) {
                    $supplier = $supplierMap->get($supplierId);
                    throw new \Exception(
                        "Cannot reach {$info['supplier_name']}'s minimum order value of EGP " .
                        number_format($minRequired, 2) . ". " .
                        "Current allocated total: EGP " . number_format($newSubtotal, 2) . ". " .
                        "Please add more items to your order or choose a different supplier."
                    );
                }

                $allocation['supplier_splits'][$supplierId] = $newItems;
            }
        }

        return $allocation;
    }

    // =========================================================
    // PRIVATE — Get eligible suppliers
    // =========================================================
    private function getEligibleSuppliers(array $branchZoneIds, MasterOrder $order): Collection
    {
        $query = Supplier::with('zones')
            ->where('is_active', 1)
            ->where('approval_status', 'approved')
            ->whereHas('zones', function ($q) use ($branchZoneIds) {
                $q->whereIn('zones.id', $branchZoneIds);
            });

        if ($order->order_mode === 'specific_supplier' && $order->notes) {
            $supplierId = (int)$order->notes;
            if ($supplierId) $query->where('id', $supplierId);
        }

        return $query->get();
    }

    // =========================================================
    // PRIVATE — Load inventory matrix
    // =========================================================
    private function loadInventory(array $supplierIds, array $drugIds): array
    {
        $rows = SupplierInventory::whereIn('supplier_id', $supplierIds)
            ->whereIn('drug_id', $drugIds)
            ->where('quantity_available', '>', 0)
            ->get();

        $matrix = [];
        foreach ($rows as $row) {
            $matrix[$row->supplier_id][$row->drug_id] = $row;
        }
        return $matrix;
    }

    // =========================================================
    // PRIVATE — Best discount allocation
    // =========================================================
    private function allocateBestDiscount(
        Collection $orderItems,
        array      $inventory,
        Collection $suppliers
    ): array {
        $splits = [];

        foreach ($orderItems as $orderItem) {
            $drugId        = $orderItem->drug_id;
            $remainingQty  = $orderItem->quantity_requested;
            $drugName      = $orderItem->drug?->trade_name ?? $orderItem->drug_name_raw ?? '';

            if (! $drugId) continue;

            $availableSuppliers = [];
            foreach ($suppliers as $supplier) {
                $invRow = $inventory[$supplier->id][$drugId] ?? null;
                if ($invRow && $invRow->quantity_available > 0) {
                    $availableSuppliers[] = [
                        'supplier_id'     => $supplier->id,
                        'quantity'        => $invRow->quantity_available,
                        'unit_price'      => $invRow->unit_price,
                        'discount_pct'    => $invRow->discount_pct,
                        'effective_price' => $invRow->unit_price * (1 - $invRow->discount_pct / 100),
                    ];
                }
            }

            if (empty($availableSuppliers)) continue;

            usort($availableSuppliers, fn($a, $b) => $a['effective_price'] <=> $b['effective_price']);

            foreach ($availableSuppliers as $sup) {
                if ($remainingQty <= 0) break;

                $allocatedQty = min($remainingQty, $sup['quantity']);
                $lineTotal    = round($allocatedQty * $sup['unit_price'] * (1 - $sup['discount_pct'] / 100), 2);

                $splits[$sup['supplier_id']][] = [
                    'drug_id'            => $drugId,
                    'drug_name'          => $drugName,
                    'quantity_requested' => $allocatedQty,
                    'unit_price'         => $sup['unit_price'],
                    'discount_pct'       => $sup['discount_pct'],
                    'line_total'         => $lineTotal,
                ];

                $remainingQty -= $allocatedQty;
            }
        }

        return ['supplier_splits' => $splits];
    }

    // =========================================================
    // PRIVATE — Specific supplier allocation
    // =========================================================
    private function allocateSpecificSupplier(
        Collection $orderItems,
        array      $inventory,
        Supplier   $supplier
    ): array {
        $splits = [];

        foreach ($orderItems as $orderItem) {
            $drugId   = $orderItem->drug_id;
            $drugName = $orderItem->drug?->trade_name ?? $orderItem->drug_name_raw ?? '';

            if (! $drugId) continue;

            $invRow    = $inventory[$supplier->id][$drugId] ?? null;
            $unitPrice = $invRow?->unit_price  ?? 0;
            $discPct   = $invRow?->discount_pct ?? 0;
            $lineTotal = round($orderItem->quantity_requested * $unitPrice * (1 - $discPct / 100), 2);

            $splits[$supplier->id][] = [
                'drug_id'            => $drugId,
                'drug_name'          => $drugName,
                'quantity_requested' => $orderItem->quantity_requested,
                'unit_price'         => $unitPrice,
                'discount_pct'       => $discPct,
                'line_total'         => $lineTotal,
            ];
        }

        return ['supplier_splits' => $splits];
    }

    // =========================================================
    // PRIVATE — Notify supplier
    // =========================================================
    private function notifySupplier(int $supplierId, SupplierOrder $so): void
    {
        $supplier = Supplier::with('user')->find($supplierId);
        if (! $supplier?->user) return;

        Notification::create([
            'user_id'         => $supplier->user->id,
            'title'           => 'New order received',
            'body'            => "You have a new order {$so->order_number} waiting for your confirmation.",
            'type'            => 'new_order',
            'channel'         => 'push',
            'notifiable_type' => 'SupplierOrder',
            'notifiable_id'   => $so->id,
            'is_read'         => 0,
        ]);
    }
}
