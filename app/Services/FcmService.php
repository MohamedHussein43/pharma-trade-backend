<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FcmService
{
    private string $credentialsPath;
    private string $projectId;
    private ?string $accessToken = null;

    public function __construct()
    {
        $this->credentialsPath = config('services.fcm.credentials_path', '');
        $this->projectId       = config('services.fcm.project_id', '');
    }

    // =========================================================
    // Send push notification to a single user
    // =========================================================
    public function sendToUser(Notification $notification): bool
    {
        if (! PlatformSetting::isFcmEnabled()) {
            Log::info('FCM: Skipped — FCM disabled in platform settings.');
            return false;
        }

        if (empty($this->credentialsPath) || empty($this->projectId)) {
            Log::warning('FCM: FCM_CREDENTIALS_PATH or FCM_PROJECT_ID missing in .env');
            $notification->update(['fcm_error' => 'Missing FCM credentials in .env']);
            return false;
        }

        $user = User::find($notification->user_id);

        if (! $user) {
            Log::warning("FCM: User {$notification->user_id} not found.");
            $notification->update(['fcm_error' => 'User not found']);
            return false;
        }

        if (empty($user->device_token)) {
            Log::info("FCM: User {$notification->user_id} has no device token — skipping.");
            $notification->update(['fcm_error' => 'No device token']);
            return false;
        }

        $token = $this->getAccessToken();

        if (! $token) {
            Log::error('FCM: Could not obtain OAuth2 access token.');
            $notification->update(['fcm_error' => 'Failed to obtain OAuth2 token']);
            return false;
        }

        $url = "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send";

        $payload = [
            'message' => [
                'token'        => $user->device_token,
                'notification' => [
                    'title' => $notification->title,
                    'body'  => $notification->body,
                ],
                'data' => [
                    'notification_id' => (string)$notification->id,
                    'type'            => (string)$notification->type,
                    'notifiable_type' => (string)$notification->notifiable_type,
                    'notifiable_id'   => (string)$notification->notifiable_id,
                    'click_action'    => 'FLUTTER_NOTIFICATION_CLICK',
                ],
                'android' => [
                    'priority'     => 'high',
                    'notification' => [
                        'sound'        => 'default',
                        'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                    ],
                ],
                'apns' => [
                    'payload' => [
                        'aps' => [
                            'sound' => 'default',
                            'badge' => $this->getUnreadCount($notification->user_id),
                        ],
                    ],
                ],
            ],
        ];

        try {
            Log::info("FCM: Sending to user {$notification->user_id}, token prefix: " .
                substr($user->device_token, 0, 20));

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ])
            ->timeout(15)
            ->post($url, $payload);

            $responseBody = $response->body();
            $responseJson = $response->json();

            Log::info("FCM: Response status {$response->status()}: {$responseBody}");

            if ($response->successful()) {
                $notification->update([
                    'fcm_sent'    => 1,
                    'fcm_sent_at' => now(),
                    'fcm_error'   => null,
                ]);
                Log::info("FCM: Successfully sent notification {$notification->id}");
                return true;
            }

            // Extract error details from response
            $errorMessage = $responseJson['error']['message']
                ?? $responseJson['error']['status']
                ?? $responseBody;

            $errorCode = $responseJson['error']['details'][0]['errorCode']
                ?? $responseJson['error']['status']
                ?? '';

            Log::error("FCM: Send failed [{$errorCode}]: {$errorMessage}");

            $notification->update([
                'fcm_sent'  => 0,
                'fcm_error' => substr("{$errorCode}: {$errorMessage}", 0, 500),
            ]);

            // Token is invalid — clear it
            if (in_array($errorCode, ['UNREGISTERED', 'INVALID_ARGUMENT'])) {
                $user->update(['device_token' => null]);
                Log::info("FCM: Cleared invalid token for user {$user->id}");
            }

            return false;

        } catch (\Exception $e) {
            $errorMsg = $e->getMessage();
            Log::error("FCM: Exception — {$errorMsg}");
            $notification->update(['fcm_error' => substr($errorMsg, 0, 500)]);
            return false;
        }
    }

    // =========================================================
    // PRIVATE — Get OAuth2 token using URL-safe base64
    // =========================================================
    private function getAccessToken(): ?string
    {
        if ($this->accessToken) {
            return $this->accessToken;
        }

        if (! file_exists($this->credentialsPath)) {
            Log::error("FCM: Credentials file not found: {$this->credentialsPath}");
            return null;
        }

        try {
            $credentials = json_decode(file_get_contents($this->credentialsPath), true);

            if (empty($credentials['private_key']) || empty($credentials['client_email'])) {
                Log::error('FCM: Invalid credentials — missing private_key or client_email.');
                return null;
            }

            $now = time();

            // URL-safe base64 encoding required by Google JWT spec
            $header = $this->base64UrlEncode(json_encode([
                'alg' => 'RS256',
                'typ' => 'JWT',
            ]));

            $claim = $this->base64UrlEncode(json_encode([
                'iss'   => $credentials['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud'   => 'https://oauth2.googleapis.com/token',
                'iat'   => $now,
                'exp'   => $now + 3600,
            ]));

            $toSign = "{$header}.{$claim}";

            openssl_sign($toSign, $signature, $credentials['private_key'], 'SHA256');

            $jwt = "{$toSign}." . $this->base64UrlEncode($signature);

            $response = Http::asForm()
                ->timeout(10)
                ->post('https://oauth2.googleapis.com/token', [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion'  => $jwt,
                ]);

            if ($response->successful()) {
                $this->accessToken = $response->json('access_token');
                return $this->accessToken;
            }

            Log::error('FCM: OAuth2 token request failed: ' . $response->body());
            return null;

        } catch (\Exception $e) {
            Log::error('FCM: Exception in getAccessToken: ' . $e->getMessage());
            return null;
        }
    }

    // =========================================================
    // PRIVATE — URL-safe base64 encoding (RFC 4648)
    // Standard base64_encode uses + and / which are invalid in JWT
    // =========================================================
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function getUnreadCount(int $userId): int
    {
        return Notification::where('user_id', $userId)
            ->where('is_read', 0)
            ->count();
    }
}
