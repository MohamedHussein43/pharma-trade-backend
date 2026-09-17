<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FcmService
{
    private string  $credentialsPath;
    private string  $credentialsBase64;
    private string  $projectId;
    private ?string $accessToken = null;

    public function __construct()
    {
        $this->credentialsPath   = config('services.fcm.credentials_path', '');
        $this->credentialsBase64 = config('services.fcm.credentials_base64', '');
        $this->projectId         = config('services.fcm.project_id', '');
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

        // ── FIXED: check project_id only — credentials checked in getAccessToken ──
        if (empty($this->projectId)) {
            Log::warning('FCM: FCM_PROJECT_ID not set in env.');
            $notification->update(['fcm_error' => 'FCM_PROJECT_ID missing']);
            return false;
        }

        $user = User::find($notification->user_id);

        if (! $user) {
            Log::warning("FCM: User {$notification->user_id} not found.");
            $notification->update(['fcm_error' => 'User not found']);
            return false;
        }

        if (empty($user->device_token)) {
            Log::warning("FCM: User {$notification->user_id} has no device token.");
            $notification->update(['fcm_error' => 'No device token stored for this user']);
            return false;
        }

        $token = $this->getAccessToken();

        if (! $token) {
            Log::error('FCM: Could not obtain OAuth2 access token.');
            $notification->update(['fcm_error' => 'Failed to obtain OAuth2 token — check credentials']);
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
            Log::info("FCM: Sending notification #{$notification->id} to user {$notification->user_id}");

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ])
            ->timeout(15)
            ->post($url, $payload);

            $responseBody = $response->body();
            $responseJson = $response->json();

            Log::info("FCM: Response {$response->status()}: {$responseBody}");

            if ($response->successful()) {
                $notification->update([
                    'fcm_sent'    => 1,
                    'fcm_sent_at' => now(),
                    'fcm_error'   => null,
                ]);
                Log::info("FCM: Successfully sent notification #{$notification->id}");
                return true;
            }

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

            if (in_array($errorCode, ['UNREGISTERED', 'INVALID_ARGUMENT'])) {
                $user->update(['device_token' => null]);
                Log::info("FCM: Cleared invalid token for user {$user->id}");
            }

            return false;

        } catch (\Exception $e) {
            Log::error("FCM: Exception — " . $e->getMessage());
            $notification->update(['fcm_error' => substr($e->getMessage(), 0, 500)]);
            return false;
        }
    }

    // =========================================================
    // PRIVATE — Load credentials from base64 OR file path
    //
    // Priority:
    //   1. FIREBASE_CREDENTIALS_BASE64 env var  ← Railway / production
    //   2. FCM_CREDENTIALS_PATH file            ← local / ngrok
    //
    // This means:
    //   - Set FIREBASE_CREDENTIALS_BASE64 on Railway → works on server
    //   - Set FCM_CREDENTIALS_PATH in .env locally → works on XAMPP
    //   - Both can coexist — base64 always wins if set
    // =========================================================
    private function loadCredentials(): ?array
    {
        // ── Option 1: base64 env var (Railway / production) ───
        if (! empty($this->credentialsBase64)) {
            Log::info('FCM: Loading credentials from FIREBASE_CREDENTIALS_BASE64');

            $json        = base64_decode($this->credentialsBase64, true);
            $credentials = $json ? json_decode($json, true) : null;

            if (empty($credentials['private_key']) || empty($credentials['client_email'])) {
                Log::error('FCM: FIREBASE_CREDENTIALS_BASE64 decoded but missing private_key or client_email — check base64 is correct');
                return null;
            }

            Log::info("FCM: Credentials loaded from base64 — client_email: {$credentials['client_email']}");
            return $credentials;
        }

        // ── Option 2: file path (local / ngrok) ───────────────
        if (! empty($this->credentialsPath)) {
            if (! file_exists($this->credentialsPath)) {
                Log::error("FCM: Credentials file not found at path: {$this->credentialsPath}");
                return null;
            }

            Log::info("FCM: Loading credentials from file: {$this->credentialsPath}");
            $credentials = json_decode(file_get_contents($this->credentialsPath), true);

            if (empty($credentials['private_key']) || empty($credentials['client_email'])) {
                Log::error('FCM: Credentials file exists but missing private_key or client_email');
                return null;
            }

            Log::info("FCM: Credentials loaded from file — client_email: {$credentials['client_email']}");
            return $credentials;
        }

        // ── Neither set ────────────────────────────────────────
        Log::error('FCM: No credentials configured. Set FIREBASE_CREDENTIALS_BASE64 (production) or FCM_CREDENTIALS_PATH (local)');
        return null;
    }

    // =========================================================
    // PRIVATE — Get OAuth2 access token
    // =========================================================
    private function getAccessToken(): ?string
    {
        if ($this->accessToken) {
            return $this->accessToken;
        }

        $credentials = $this->loadCredentials();
        if (! $credentials) {
            return null;
        }

        try {
            $now = time();

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

            $response = Http::asForm()->timeout(10)->post(
                'https://oauth2.googleapis.com/token',
                [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion'  => $jwt,
                ]
            );

            if ($response->successful()) {
                $this->accessToken = $response->json('access_token');
                Log::info('FCM: OAuth2 token obtained successfully');
                return $this->accessToken;
            }

            Log::error('FCM: OAuth2 token request failed: ' . $response->body());
            return null;

        } catch (\Exception $e) {
            Log::error('FCM: Exception in getAccessToken: ' . $e->getMessage());
            return null;
        }
    }

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
