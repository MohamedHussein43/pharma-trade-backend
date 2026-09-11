<?php

namespace App\Services;

use App\Models\MasterOrder;
use App\Models\Notification;
use App\Models\SupplierOrder;
use App\Models\User;

class NotificationService
{
    public function __construct(
        private FcmService      $fcm,
        private WhatsAppService $whatsapp,
    ) {}

    // =========================================================
    // Central method — creates DB row + sends FCM + WhatsApp
    // All notification calls across the app should use this
    // instead of Notification::create() directly
    // =========================================================
    public function send(
        int    $userId,
        string $title,
        string $body,
        string $type,
        string $notifiableType,
        int    $notifiableId,
        array  $extra = []   // optional: whatsapp_order for supplier new order
    ): Notification {

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

        // Send FCM push
        $this->fcm->sendToUser($notification);

        // Send WhatsApp for new order to supplier
        if ($type === 'new_order' && isset($extra['supplier_order'])) {
            $this->whatsapp->sendNewOrderToSupplier(
                $extra['supplier_order'],
                $notification
            );
        }

        // Send WhatsApp status updates to pharmacy
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
    // Shorthand methods for each notification event
    // =========================================================

    public function newOrderForSupplier(SupplierOrder $so): void
    {
        $supplier = $so->supplier()->with('user')->first();
        if (! $supplier?->user) return;

        $this->send(
            userId:          $supplier->user->id,
            title:           'طلب جديد',
            body:            "لديك طلب جديد {$so->order_number} بانتظار تأكيدك.",
            type:            'new_order',
            notifiableType:  'SupplierOrder',
            notifiableId:    $so->id,
            extra:           ['supplier_order' => $so],
        );
    }

    public function orderConfirmedForPharmacy(SupplierOrder $so, bool $hasShortage): void
    {
        $masterOrder = MasterOrder::with('pharmacyBranch.user')->find($so->master_order_id);
        if (! $masterOrder?->pharmacyBranch?->user) return;

        $user        = $masterOrder->pharmacyBranch->user;
        $supplierName = $so->supplier->name ?? '';
        $type        = $hasShortage ? 'shortage_reported' : 'order_confirmed';
        $title       = $hasShortage ? 'تم الإبلاغ عن نقص جزئي' : 'تم تأكيد الطلب';
        $body        = $hasShortage
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
        $supplierName = $so->supplier->name ?? '';

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
        $supplierName = $so->supplier->name ?? '';

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
        // Notify pharmacy
        $this->send(
            userId:         $pharmacyUser->id,
            title:          'تم تأكيد الاستلام',
            body:           "لقد أكدت استلام طلب {$order->order_number}. تم إغلاق الطلب.",
            type:           'order_delivered',
            notifiableType: 'MasterOrder',
            notifiableId:   $order->id,
        );

        // Notify each supplier
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
