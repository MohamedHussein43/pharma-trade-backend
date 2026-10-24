<?php

namespace App\Services;

use App\Models\MasterOrder;
use App\Models\Notification;
use App\Models\SupplierOrder;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    public function __construct(
        private FcmService      $fcm,
        private WhatsAppService $whatsapp,
    ) {}

    // =========================================================
    // Central send method
    // Creates DB row FIRST then fires FCM immediately
    // =========================================================
    public function send(
        int    $userId,
        string $title,
        string $body,
        string $type,
        string $notifiableType,
        int    $notifiableId,
        array  $extra = []
    ): Notification {

        // ── Step 1: Create DB row ─────────────────────────────
        $notification = Notification::create([
            'user_id'         => $userId,
            'title'           => $title,
            'body'            => $body,
            'type'            => $type,
            'channel'         => 'push',
            'notifiable_type' => $notifiableType,
            'notifiable_id'   => $notifiableId,
            'is_read'         => 0,
            'fcm_sent'        => 0,
            'whatsapp_sent'   => 0,
        ]);

        Log::info("NotificationService: Created notification #{$notification->id} for user {$userId} — type={$type}");

        // ── Step 2: Check user has device token ───────────────
        $user = User::find($userId);
        if (! $user) {
            Log::warning("NotificationService: User {$userId} not found — skipping FCM");
        } elseif (empty($user->device_token)) {
            Log::warning("NotificationService: User {$userId} has no device_token — skipping FCM. Ask Flutter dev to call PATCH /profile/device-token after login.");
        } else {
            Log::info("NotificationService: Sending FCM to user {$userId}, token prefix: " . substr($user->device_token, 0, 20));

            // ── Step 3: Fire FCM ──────────────────────────────
            $fcmResult = $this->fcm->sendToUser($notification);

            Log::info("NotificationService: FCM result for notification #{$notification->id} = " . ($fcmResult ? 'SUCCESS' : 'FAILED'));
        }

        // ── Step 4: WhatsApp for new orders to supplier ───────
        if ($type === 'new_order' && isset($extra['supplier_order'])) {
            $this->whatsapp->sendNewOrderToSupplier(
                $extra['supplier_order'],
                $notification
            );
        }

        // ── Step 5: WhatsApp status updates to pharmacy ───────
        if (isset($extra['pharmacy_user']) && isset($extra['order_number'])) {
            $this->whatsapp->sendOrderStatusToPharmacy(
                $type,
                $extra['order_number'],
                $extra['supplier_name'] ?? '',
                $extra['pharmacy_user'],
                $notification
            );
        }

        return $notification;
    }

    // =========================================================
    // Shorthand methods
    // =========================================================

    public function newOrderForSupplier(SupplierOrder $so): void
    {
        $supplier = $so->supplier()->with('user')->first();
        if (! $supplier?->user) {
            Log::warning("NotificationService::newOrderForSupplier — supplier has no user for SO #{$so->id}");
            return;
        }

        $this->send(
            userId:         $supplier->user->id,
            title:          'طلب جديد',
            body:           "لديك طلب جديد {$so->order_number} بانتظار تأكيدك.",
            type:           'new_order',
            notifiableType: 'SupplierOrder',
            notifiableId:   $so->id,
            extra:          ['supplier_order' => $so],
        );
    }

    public function orderConfirmedForPharmacy(SupplierOrder $so, bool $hasShortage): void
    {
        $masterOrder = MasterOrder::with('pharmacyBranch.user')->find($so->master_order_id);
        if (! $masterOrder?->pharmacyBranch?->user) {
            Log::warning("NotificationService::orderConfirmedForPharmacy — no pharmacy user for MO #{$so->master_order_id}");
            return;
        }

        $user         = $masterOrder->pharmacyBranch->user;
        $supplierName = $so->supplier?->name ?? '';
        $type         = $hasShortage ? 'shortage_reported' : 'order_confirmed';
        $title        = $hasShortage ? 'تم الإبلاغ عن نقص جزئي' : 'تم تأكيد الطلب';
        $body         = $hasShortage
            ? "قام {$supplierName} بتأكيد جزء من طلب {$so->order_number} مع وجود نواقص."
            : "قام {$supplierName} بتأكيد طلب {$so->order_number} بالكامل.";

        $this->send(
            userId:         $user->id,
            title:          $title,
            body:           $body,
            type:           $type,
            notifiableType: 'MasterOrder',
            notifiableId:   $so->master_order_id,
            extra:          [
                'pharmacy_user' => $user,
                'order_number'  => $so->order_number,
                'supplier_name' => $supplierName,
            ],
        );
    }

    public function orderShippedForPharmacy(SupplierOrder $so): void
    {
        $masterOrder = MasterOrder::with('pharmacyBranch.user')->find($so->master_order_id);
        if (! $masterOrder?->pharmacyBranch?->user) return;

        $user         = $masterOrder->pharmacyBranch->user;
        $supplierName = $so->supplier?->name ?? '';

        $this->send(
            userId:         $user->id,
            title:          'تم شحن الطلب',
            body:           "تم شحن طلبك {$so->order_number} من {$supplierName}.",
            type:           'order_shipped',
            notifiableType: 'SupplierOrder',
            notifiableId:   $so->id,
            extra:          [
                'pharmacy_user' => $user,
                'order_number'  => $so->order_number,
                'supplier_name' => $supplierName,
            ],
        );
    }

    public function orderDeliveredForPharmacy(SupplierOrder $so): void
    {
        $masterOrder = MasterOrder::with('pharmacyBranch.user')->find($so->master_order_id);
        if (! $masterOrder?->pharmacyBranch?->user) return;

        $user         = $masterOrder->pharmacyBranch->user;
        $supplierName = $so->supplier?->name ?? '';

        $this->send(
            userId:         $user->id,
            title:          'تم توصيل الطلب',
            body:           "تم توصيل طلبك {$so->order_number} من {$supplierName}.",
            type:           'order_delivered',
            notifiableType: 'SupplierOrder',
            notifiableId:   $so->id,
            extra:          [
                'pharmacy_user' => $user,
                'order_number'  => $so->order_number,
                'supplier_name' => $supplierName,
            ],
        );
    }

    public function deliveryConfirmedForAll(MasterOrder $order, User $pharmacyUser): void
    {
        $this->send(
            userId:         $pharmacyUser->id,
            title:          'تم تأكيد الاستلام',
            body:           "لقد أكدت استلام طلب {$order->order_number}. تم إغلاق الطلب.",
            type:           'order_delivered',
            notifiableType: 'MasterOrder',
            notifiableId:   $order->id,
        );

        foreach ($order->supplierOrders as $so) {
            if (! $so->supplier?->user) continue;

            $this->send(
                userId:         $so->supplier->user->id,
                title:          'تأكيد الاستلام من الصيدلية',
                body:           "أكدت الصيدلية استلام طلب {$so->order_number}. تم إغلاق الطلب.",
                type:           'order_delivered',
                notifiableType: 'SupplierOrder',
                notifiableId:   $so->id,
                extra:          ['supplier_order' => $so],
            );
        }
    }

    public function registrationApproved(int $userId, ?string $entityType = null): void
    {
        switch ($entityType) {
            case 'zone_update':
                $title = 'تم قبول طلب تحديث المناطق';
                $body  = 'تمت الموافقة على طلب تحديث المناطق الخاص بك. تم تحديث مناطق التوصيل الخاصة بك.';
                break;

            case 'licence_update':
                $title = 'تم قبول طلب تحديث الرخصة';
                $body  = 'تمت الموافقة على طلب تحديث الرخصة الخاص بك. تم تحديث بيانات الرخصة.';
                break;

            case 'pharmacy':
                $title = 'تم قبول طلب التسجيل';
                $body  = 'تمت الموافقة على طلب تسجيل الصيدلية. يمكنك الآن تسجيل الدخول والبدء.';
                break;

            case 'supplier':
                $title = 'تم قبول طلب التسجيل';
                $body  = 'تمت الموافقة على طلب تسجيل شركتك. يمكنك الآن تسجيل الدخول والبدء.';
                break;

            default:
                $title = 'تم قبول طلبك';
                $body  = 'تمت مراجعة طلبك والموافقة عليه.';
                break;
        }

        $this->send(
            userId:         $userId,
            title:          $title,
            body:           $body,
            type:           'registration_approved',
            notifiableType: 'User',
            notifiableId:   $userId,
        );
    }

    public function registrationDeclined(int $userId, string $reason, ?string $entityType = null): void
    {
        switch ($entityType) {
            case 'zone_update':
                $title = 'تم رفض طلب تحديث المناطق';
                $body  = "تم رفض طلب تحديث المناطق. السبب: {$reason}";
                break;

            case 'licence_update':
                $title = 'تم رفض طلب تحديث الرخصة';
                $body  = "تم رفض طلب تحديث الرخصة. السبب: {$reason}";
                break;

            case 'pharmacy':
            case 'supplier':
                $title = 'تم رفض طلب التسجيل';
                $body  = "تم رفض طلب تسجيلك. السبب: {$reason}";
                break;

            default:
                $title = 'تم رفض طلبك';
                $body  = "تم رفض طلبك. السبب: {$reason}";
                break;
        }

        $this->send(
            userId:         $userId,
            title:          $title,
            body:           $body,
            type:           'registration_declined',
            notifiableType: 'User',
            notifiableId:   $userId,
        );
    }

    public function inventoryUploadComplete(int $userId, int $logId, int $success, int $failed): void
    {
        $body = "تم رفع {$success} دواء بنجاح.";
        if ($failed > 0) $body .= " {$failed} صف به أخطاء.";

        $this->send(
            userId:         $userId,
            title:          'اكتمل رفع المخزون',
            body:           $body,
            type:           'general',
            notifiableType: 'InventoryUploadLog',
            notifiableId:   $logId,
        );
    }

    public function shortageAcceptedByPharmacy(SupplierOrder $supplierOrder): void
    {
        $supplier = $supplierOrder->supplier;
        if (! $supplier?->user?->device_token) return;

        $this->send(
            userId: $supplier->user->id,
            title: 'تأكيد الطلب مجدداً',
            body: "قبلت الصيدلية الكميات المتاحة للطلب #{$supplierOrder->order_number}. يرجى تأكيد الطلب مجدداً.",
            type:'shortage_resolved',
            notifiableType: 'SupplierOrder',
            notifiableId:   $supplierOrder->id,
            extra:
            [
                'supplier_order_id' => (string) $supplierOrder->id,
                'master_order_id'   => (string) $supplierOrder->master_order_id,
            ]
        );
    }

    public function shortageCancelledByPharmacy(SupplierOrder $supplierOrder): void
    {
        $supplier = $supplierOrder->supplier;
        if (! $supplier?->user?->device_token) return;

        $this->send(
            userId: $supplier->user->id,
            title: 'إلغاء الطلب',
            body: "ألغت الصيدلية الطلب #{$supplierOrder->order_number} بسبب نقص المخزون.",
            type:'order_cancelled',
            notifiableType: 'SupplierOrder',
            notifiableId:   $supplierOrder->id,
            extra:
            [
                'supplier_order_id' => (string) $supplierOrder->id,
                'master_order_id'   => (string) $supplierOrder->master_order_id,
            ]
        );
    }

    public function shortageReportedBySupplier(MasterOrder $masterOrder): void
    {
        $masterOrder->loadMissing('pharmacyBranch.user');
        $branch = $masterOrder->pharmacyBranch;
        Log::info('shortageReportedBySupplier debug', [
        'master_order_id'  => $masterOrder->id,
        'branch'           => $branch?->id,
        'branch_user'      => $branch?->user?->id,
        'device_token'     => $branch?->user?->device_token ? 'SET' : 'NULL',
    ]);
        if (! $branch || ! $branch->user) return;
        if (! $branch->user->device_token)  return;

        $this->send(
            userId:         $branch->user->id,
            title:          'نقص في مخزون الطلب',
            body:           "أفاد المورد بوجود نقص في بعض أصناف الطلب #{$masterOrder->id}. يرجى مراجعة الطلب واتخاذ القرار المناسب.",
            type:           'shortage_reported',
            notifiableType: 'MasterOrder',
            notifiableId:   $masterOrder->id,
            extra: [
                'master_order_id' => (string) $masterOrder->id,
            ]
        );
    }
// ---------------------------------------------------------------------------
// Paste these methods into App\Services\NotificationService
// ---------------------------------------------------------------------------

    // -------------------------------------------------------------------------
    // P2P Marketplace Notifications
    // -------------------------------------------------------------------------

     /**
     * Notify seller branch users that a new order was received.
     */
    public function p2pOrderReceived(\App\Models\PharmacyOrder $order): void
    {
        foreach ($this->branchUsers($order->seller_branch_id) as $user) {
            $this->send(
                userId:         $user->id,
                title:          'طلب جديد في السوق',
                body:           "تم استلام طلب جديد رقم {$order->order_number}.",
                type:           'p2p_order_received',
                notifiableType: 'PharmacyOrder',
                notifiableId:   $order->id,
            );
        }
    }

    /**
     * Notify buyer branch users that their order was accepted.
     */
    public function p2pOrderAccepted(\App\Models\PharmacyOrder $order): void
    {
        foreach ($this->branchUsers($order->buyer_branch_id) as $user) {
            $this->send(
                userId:         $user->id,
                title:          'تم قبول طلبك',
                body:           "تم قبول الطلب رقم {$order->order_number} من قِبَل البائع.",
                type:           'p2p_order_accepted',
                notifiableType: 'PharmacyOrder',
                notifiableId:   $order->id,
            );
        }
    }

    /**
     * Notify buyer branch users that their order was rejected.
     */
    public function p2pOrderRejected(\App\Models\PharmacyOrder $order): void
    {
        foreach ($this->branchUsers($order->buyer_branch_id) as $user) {
            $this->send(
                userId:         $user->id,
                title:          'تم رفض طلبك',
                body:           "تم رفض الطلب رقم {$order->order_number}.",
                type:           'p2p_order_rejected',
                notifiableType: 'PharmacyOrder',
                notifiableId:   $order->id,
            );
        }
    }

    /**
     * Notify buyer branch users that their order is ready for pickup.
     */
    public function p2pOrderReady(\App\Models\PharmacyOrder $order): void
    {
        foreach ($this->branchUsers($order->buyer_branch_id) as $user) {
            $this->send(
                userId:         $user->id,
                title:          'طلبك جاهز للاستلام',
                body:           "الطلب رقم {$order->order_number} جاهز — يمكن لمندوبك استلامه الآن.",
                type:           'p2p_order_ready',
                notifiableType: 'PharmacyOrder',
                notifiableId:   $order->id,
            );
        }
    }

    /**
     * Notify both buyer and seller branch users that the order is completed.
     */
    public function p2pOrderCompleted(\App\Models\PharmacyOrder $order): void
    {
        $allUsers = $this->branchUsers($order->buyer_branch_id)
            ->merge($this->branchUsers($order->seller_branch_id));

        foreach ($allUsers as $user) {
            $this->send(
                userId:         $user->id,
                title:          'اكتمل الطلب',
                body:           "الطلب رقم {$order->order_number} اكتمل بنجاح.",
                type:           'p2p_order_completed',
                notifiableType: 'PharmacyOrder',
                notifiableId:   $order->id,
            );
        }
    }

    /**
     * Notify the other party on cancellation.
     * If buyer cancelled → notify seller branch. If seller cancelled → notify buyer branch.
     */
    public function p2pOrderCancelled(\App\Models\PharmacyOrder $order, string $cancelledByRole): void
    {
        $notifyBranchId = $cancelledByRole === 'buyer'
            ? $order->seller_branch_id
            : $order->buyer_branch_id;

        foreach ($this->branchUsers($notifyBranchId) as $user) {
            $this->send(
                userId:         $user->id,
                title:          'تم إلغاء الطلب',
                body:           "تم إلغاء الطلب رقم {$order->order_number}.",
                type:           'p2p_order_cancelled',
                notifiableType: 'PharmacyOrder',
                notifiableId:   $order->id,
            );
        }
    }

    /**
     * Notify buyers in the same zone(s) when a new collection becomes active.
     */
    public function p2pCollectionAvailable(\App\Models\PharmacyCollection $collection): void
    {
        $zoneIds = $collection->zone_ids ?? [];

        if (empty($zoneIds)) {
            return;
        }

        // Get users of all branches that share any zone with this collection,
        // excluding the seller's own branch.
        $users = \App\Models\User::whereHas('pharmacyBranch', function ($q) use ($collection, $zoneIds) {
            $q->where('id', '!=', $collection->seller_branch_id)
              ->whereHas('zones', fn ($z) => $z->whereIn('zone_id', $zoneIds));
        })->get();

        foreach ($users as $user) {
            $this->send(
                userId:         $user->id,
                title:          'مجموعة أدوية جديدة متاحة',
                body:           "مجموعة \"{$collection->name}\" متاحة الآن في منطقتك.",
                type:           'p2p_collection_available',
                notifiableType: 'PharmacyCollection',
                notifiableId:   $collection->id,
            );
        }
    }

    // ─────────────────────────────────────────────────────────
    // Private helper
    // ─────────────────────────────────────────────────────────

    /**
     * Get all users that belong to a given pharmacy branch.
     * Uses the user_id FK on pharmacy_branches (one branch → one user in your schema).
     */
    private function branchUsers(int $branchId): \Illuminate\Support\Collection
    {
        return \App\Models\User::whereHas('pharmacyBranch', fn ($q) => $q->where('id', $branchId))->get();
    }

}


/*
============================================================
DB QUERY — Check what is actually happening
Run in phpMyAdmin after triggering a notification event:
============================================================

-- 1. Check latest notifications with FCM status
SELECT
    id,
    user_id,
    title,
    type,
    fcm_sent,
    fcm_sent_at,
    fcm_error,
    created_at
FROM notifications
ORDER BY id DESC
LIMIT 10;

-- If fcm_sent = 0 AND fcm_error = null:
--   FCM send method is not being reached at all
--   Check AppServiceProvider bindings

-- If fcm_sent = 0 AND fcm_error has a message:
--   FCM tried but failed — read the error

-- If fcm_sent = 1 AND fcm_sent_at filled:
--   FCM sent successfully — problem is in Flutter setup


-- 2. Check user device tokens
SELECT id, name, role, device_token
FROM users
WHERE device_token IS NOT NULL;
-- If empty → Flutter dev never called PATCH /profile/device-token

-- 3. Check platform settings
SELECT fcm_enabled, whatsapp_enabled, enable_banned_drug_check
FROM platform_settings
WHERE id = 1;
-- fcm_enabled must be 1


============================================================
LARAVEL LOG — Most important diagnostic
Check storage/logs/laravel.log for these lines:
============================================================

grep "NotificationService" storage/logs/laravel.log | tail -20
grep "FCM:" storage/logs/laravel.log | tail -20

-- You should see:
-- NotificationService: Created notification #X for user Y
-- NotificationService: Sending FCM to user Y, token prefix: ...
-- FCM: Sending to user Y, token prefix: ...
-- FCM: Response status 200: ...
-- FCM: Successfully sent notification #X

-- If you see "no device_token" → Flutter dev needs to send token
-- If you see "FCM: Response status 400" → token is invalid

============================================================
*/
