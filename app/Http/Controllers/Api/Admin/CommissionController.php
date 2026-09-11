<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Commission;
use App\Models\Supplier;
use App\Models\SupplierOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommissionController extends Controller
{
    // =========================================================
    // GET /api/v1/admin/commissions
    // Admin views all commissions with filters
    //
    // Query params:
    //   ?supplier_id=  — filter by supplier
    //   ?month=        — filter by month (1-12)
    //   ?year=         — filter by year (e.g. 2024)
    //   ?status=       — pending / confirmed / paid / cancelled
    //   ?per_page=     — default 30
    // =========================================================
    public function index(Request $request): JsonResponse
    {
        $query = Commission::with([
            'supplier:id,name',
            'pharmacyBranch:id,name',
        ]);

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', (int)$request->supplier_id);
        }

        if ($request->filled('month')) {
            $query->where('period_month', (int)$request->month);
        }

        if ($request->filled('year')) {
            $query->where('period_year', (int)$request->year);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $commissions = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 30));

        // Summary totals for the filtered result
        $totals = Commission::when($request->filled('supplier_id'), fn($q) => $q->where('supplier_id', $request->supplier_id))
            ->when($request->filled('month'),       fn($q) => $q->where('period_month', $request->month))
            ->when($request->filled('year'),        fn($q) => $q->where('period_year', $request->year))
            ->when($request->filled('status'),      fn($q) => $q->where('status', $request->status))
            ->selectRaw('
                COUNT(*)                                        as total_orders,
                SUM(subtotal)                                   as total_subtotal,
                SUM(commission_value)                           as total_commission,
                SUM(CASE WHEN status = "paid" THEN commission_value ELSE 0 END) as total_paid,
                SUM(CASE WHEN status = "pending" THEN commission_value ELSE 0 END) as total_pending
            ')
            ->first();

        return response()->json([
            'message' => 'Commissions retrieved successfully.',
            'data'    => [
                'commissions' => $commissions,
                'summary'     => [
                    'total_orders'     => $totals->total_orders,
                    'total_subtotal'   => round($totals->total_subtotal ?? 0, 2),
                    'total_commission' => round($totals->total_commission ?? 0, 2),
                    'total_paid'       => round($totals->total_paid ?? 0, 2),
                    'total_pending'    => round($totals->total_pending ?? 0, 2),
                ],
            ],
        ], 200);
    }

    // =========================================================
    // GET /api/v1/admin/commissions/by-supplier
    // Commission summary grouped by supplier
    // Great for knowing who owes what this month
    // =========================================================
    public function bySupplier(Request $request): JsonResponse
    {
        $request->validate([
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'year'  => ['required', 'integer', 'min:2024'],
        ]);

        $results = Commission::with('supplier:id,name')
            ->where('period_month', $request->month)
            ->where('period_year',  $request->year)
            ->select('supplier_id',
                DB::raw('COUNT(*) as orders_count'),
                DB::raw('SUM(subtotal) as total_subtotal'),
                DB::raw('SUM(commission_value) as total_commission'),
                DB::raw('SUM(CASE WHEN status = "paid" THEN commission_value ELSE 0 END) as paid_amount'),
                DB::raw('SUM(CASE WHEN status = "pending" THEN commission_value ELSE 0 END) as pending_amount'),
                DB::raw('MAX(status) as latest_status')
            )
            ->groupBy('supplier_id')
            ->orderByDesc('total_commission')
            ->get()
            ->map(fn($row) => [
                'supplier_id'      => $row->supplier_id,
                'supplier_name'    => $row->supplier->name ?? 'Unknown',
                'orders_count'     => $row->orders_count,
                'total_subtotal'   => round($row->total_subtotal, 2),
                'total_commission' => round($row->total_commission, 2),
                'paid_amount'      => round($row->paid_amount, 2),
                'pending_amount'   => round($row->pending_amount, 2),
            ]);

        $grandTotal = round($results->sum('total_commission'), 2);
        $totalPaid  = round($results->sum('paid_amount'), 2);

        return response()->json([
            'message' => 'Commission summary by supplier retrieved.',
            'data'    => [
                'period'      => ['month' => $request->month, 'year' => $request->year],
                'suppliers'   => $results,
                'grand_total' => $grandTotal,
                'total_paid'  => $totalPaid,
                'outstanding' => round($grandTotal - $totalPaid, 2),
            ],
        ], 200);
    }

    // =========================================================
    // GET /api/v1/admin/commissions/by-period
    // Monthly commission totals for a year
    // Shows growth month by month
    // =========================================================
    public function byPeriod(Request $request): JsonResponse
    {
        $year = (int)$request->get('year', date('Y'));

        $monthly = Commission::where('period_year', $year)
            ->select('period_month',
                DB::raw('COUNT(*) as orders_count'),
                DB::raw('SUM(subtotal) as total_subtotal'),
                DB::raw('SUM(commission_value) as total_commission')
            )
            ->groupBy('period_month')
            ->orderBy('period_month')
            ->get()
            ->keyBy('period_month');

        // Build all 12 months even if some have no data
        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $row      = $monthly->get($m);
            $months[] = [
                'month'            => $m,
                'month_name'       => date('F', mktime(0, 0, 0, $m, 1)),
                'orders_count'     => $row?->orders_count    ?? 0,
                'total_subtotal'   => round($row?->total_subtotal   ?? 0, 2),
                'total_commission' => round($row?->total_commission ?? 0, 2),
            ];
        }

        return response()->json([
            'message' => 'Commission by period retrieved.',
            'data'    => [
                'year'   => $year,
                'months' => $months,
                'annual_total' => round(array_sum(array_column($months, 'total_commission')), 2),
            ],
        ], 200);
    }

    // =========================================================
    // PATCH /api/v1/admin/commissions/{id}/mark-paid
    // Admin marks a commission as paid
    // =========================================================
    public function markPaid(Request $request, int $id): JsonResponse
    {
        $commission = Commission::find($id);

        if (! $commission) {
            return response()->json(['message' => 'Commission not found.'], 404);
        }

        if ($commission->status === 'paid') {
            return response()->json(['message' => 'Commission already marked as paid.'], 422);
        }

        $commission->update([
            'status'  => 'paid',
            'paid_at' => now(),
            'notes'   => $request->notes ?? null,
        ]);

        return response()->json([
            'message' => 'Commission marked as paid.',
            'data'    => [
                'id'               => $commission->id,
                'order_number'     => $commission->order_number,
                'commission_value' => $commission->commission_value,
                'status'           => 'paid',
                'paid_at'          => $commission->paid_at,
            ],
        ], 200);
    }

    // =========================================================
    // PATCH /api/v1/admin/commissions/bulk-mark-paid
    // Mark multiple commissions paid at once
    // (e.g. at end of month settle with a supplier)
    // =========================================================
    public function bulkMarkPaid(Request $request): JsonResponse
    {
        $request->validate([
            'commission_ids'   => ['required', 'array', 'min:1'],
            'commission_ids.*' => ['integer', 'exists:commissions,id'],
            'notes'            => ['nullable', 'string', 'max:500'],
        ]);

        $updated = Commission::whereIn('id', $request->commission_ids)
            ->where('status', 'pending')
            ->update([
                'status'  => 'paid',
                'paid_at' => now(),
                'notes'   => $request->notes,
            ]);

        return response()->json([
            'message' => "{$updated} commissions marked as paid.",
            'data'    => ['updated_count' => $updated],
        ], 200);
    }

    // =========================================================
    // GET /api/v1/admin/commissions/order/{order_id}
    // All commissions for a specific master order
    // =========================================================
    public function byOrder(Request $request, int $orderId): JsonResponse
    {
        $commissions = Commission::with('supplier:id,name')
            ->where('master_order_id', $orderId)
            ->get()
            ->map(fn($c) => [
                'id'               => $c->id,
                'supplier'         => $c->supplier->name ?? '',
                'order_number'     => $c->order_number,
                'subtotal'         => $c->subtotal,
                'commission_pct'   => $c->commission_pct,
                'commission_value' => $c->commission_value,
                'status'           => $c->status,
                'paid_at'          => $c->paid_at,
            ]);

        return response()->json([
            'message' => 'Order commissions retrieved.',
            'data'    => [
                'commissions'      => $commissions,
                'total_commission' => round($commissions->sum('commission_value'), 2),
            ],
        ], 200);
    }
}