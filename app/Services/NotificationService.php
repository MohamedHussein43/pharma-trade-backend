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

    public function registrationApproved(int $userId, string $entityType): void
    {
        $this->send(
            userId:         $userId,
            title:          'تم قبول طلبك',
            body:           'تم مراجعة طلب تسجيلك والموافقة عليه. يمكنك الآن تسجيل الدخول.',
            type:           'registration_approved',
            notifiableType: 'User',
            notifiableId:   $userId,
        );
    }

    public function registrationDeclined(int $userId, string $reason): void
    {
        $this->send(
            userId:         $userId,
            title:          'تم رفض طلبك',
            body:           "تم رفض طلب تسجيلك. السبب: {$reason}",
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
