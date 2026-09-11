<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\SupplierOrder;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    private string $token;
    private string $fromNumberId;
    private string $apiUrl;

    public function __construct()
    {
        $this->token        = config('services.whatsapp.token', '');
        $this->fromNumberId = config('services.whatsapp.from_number_id', '');
        $this->apiUrl       = "https://graph.facebook.com/v19.0/{$this->fromNumberId}/messages";
    }

    // =========================================================
    // Send new order notification to supplier via WhatsApp
    //
    // Sends a structured message with order details:
    //   - Order number
    //   - Number of drug lines
    //   - Total subtotal
    //   - Pharmacy branch name + phone
    // =========================================================
    public function sendNewOrderToSupplier(SupplierOrder $supplierOrder, Notification $notification): bool
    {
        if (empty($this->token) || empty($this->fromNumberId)) {
            Log::warning('WhatsApp: API credentials not configured.');
            return false;
        }

        $supplier = $supplierOrder->supplier()->with('user')->first();
        if (! $supplier?->user) return false;

        $phone = $this->formatPhone($supplier->user->phone);
        if (! $phone) {
            Log::warning("WhatsApp: Invalid phone for supplier user {$supplier->user->id}");
            return false;
        }

        $branch     = $supplierOrder->masterOrder?->pharmacyBranch;
        $itemsCount = $supplierOrder->orderItems()->count();

        $message = "🔔 *طلب جديد — {$supplierOrder->order_number}*\n\n" .
                   "📦 عدد الأصناف: {$itemsCount}\n" .
                   "💰 إجمالي الطلب: EGP " . number_format($supplierOrder->subtotal, 2) . "\n" .
                   "🏥 الصيدلية: " . ($branch?->name ?? 'غير محدد') . "\n" .
                   "📞 هاتف الصيدلية: " . ($branch?->phone ?? 'غير محدد') . "\n\n" .
                   "يرجى فتح التطبيق لتأكيد الطلب.";

        return $this->sendTextMessage($phone, $message, $notification);
    }

    // =========================================================
    // Send order status update to pharmacy via WhatsApp
    // =========================================================
    public function sendOrderStatusToPharmacy(
        string       $status,
        string       $orderNumber,
        string       $supplierName,
        User         $pharmacyUser,
        Notification $notification
    ): bool {
        if (empty($this->token) || empty($this->fromNumberId)) return false;

        $phone = $this->formatPhone($pharmacyUser->phone);
        if (! $phone) return false;

        $statusMessages = [
            'confirmed'          => "✅ تم تأكيد طلبك من {$supplierName}",
            'partially_available'=> "⚠️ تم تأكيد جزء من طلبك من {$supplierName} — يوجد نواقص",
            'shipped'            => "🚚 تم شحن طلبك من {$supplierName}",
            'delivered'          => "📦 تم توصيل طلبك من {$supplierName}",
            'delivery_confirmed' => "✅ تم تأكيد استلام طلبك — الطلب مغلق",
        ];

        $statusText = $statusMessages[$status] ?? "تم تحديث حالة طلبك";
        $message    = "🔔 *تحديث الطلب — {$orderNumber}*\n\n{$statusText}\n\nافتح التطبيق لمزيد من التفاصيل.";

        return $this->sendTextMessage($phone, $message, $notification);
    }

    // =========================================================
    // PRIVATE — Send text message via Meta Graph API
    // =========================================================
    private function sendTextMessage(string $to, string $body, Notification $notification): bool
    {
        if (! \App\Models\PlatformSetting::isWhatsAppEnabled()) {
            return false;
        }

        if (empty($this->token) || empty($this->fromNumberId)) {
            Log::warning('WhatsApp: API credentials not configured.');
            return false;
        }
        
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'text',
            'text'              => [
                'preview_url' => false,
                'body'        => $body,
            ],
        ];

        try {
            $response = Http::withToken($this->token)
                ->timeout(10)
                ->post($this->apiUrl, $payload);

            if ($response->successful()) {
                $notification->update([
                    'whatsapp_sent'    => 1,
                    'whatsapp_sent_at' => now(),
                    'whatsapp_error'   => null,
                ]);
                return true;
            }

            $error = $response->body();
            Log::error("WhatsApp send failed for notification {$notification->id}: {$error}");
            $notification->update(['whatsapp_error' => substr($error, 0, 500)]);
            return false;

        } catch (\Exception $e) {
            Log::error("WhatsApp exception: " . $e->getMessage());
            $notification->update(['whatsapp_error' => $e->getMessage()]);
            return false;
        }
    }

    // =========================================================
    // PRIVATE — Format phone to international format
    // Egyptian numbers: 010/011/012/015 → +20...
    // =========================================================
    private function formatPhone(string $phone): ?string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);

        if (str_starts_with($phone, '0')) {
            $phone = '2' . $phone; // Egypt country code
        }

        if (! str_starts_with($phone, '20')) {
            $phone = '20' . $phone;
        }

        // Validate Egyptian mobile (12 digits starting with 201)
        if (strlen($phone) === 12 && str_starts_with($phone, '20')) {
            return $phone;
        }

        return null;
    }
}
