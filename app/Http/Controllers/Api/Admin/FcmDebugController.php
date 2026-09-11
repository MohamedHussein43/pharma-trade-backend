<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FcmDebugController extends Controller
{
    public function debug(Request $request): JsonResponse
    {
        $user             = $request->user();
        $credentialsPath  = config('services.fcm.credentials_path', '');
        $projectId        = config('services.fcm.project_id', '');
        $results          = [];

        // ── Step 1: Config values ─────────────────────────────
        $results['step1_config'] = [
            'credentials_path' => $credentialsPath ?: 'EMPTY',
            'project_id'       => $projectId       ?: 'EMPTY',
            'file_exists'      => file_exists($credentialsPath) ? 'YES' : 'NO',
        ];

        if (! file_exists($credentialsPath)) {
            return response()->json(['message' => 'STOP: credentials file not found', 'data' => $results]);
        }

        // ── Step 2: Read credentials ──────────────────────────
        $credentials = json_decode(file_get_contents($credentialsPath), true);
        $results['step2_credentials'] = [
            'client_email'     => $credentials['client_email']  ?? 'MISSING',
            'has_private_key'  => isset($credentials['private_key']) ? 'YES' : 'NO',
            'file_project_id'  => $credentials['project_id']    ?? 'MISSING',
        ];

        // ── Step 3: Build JWT and get OAuth2 token ────────────
        $now    = time();
        $header = $this->b64($credentials['client_email'] ? json_encode(['alg'=>'RS256','typ'=>'JWT']) : '');
        $header = $this->b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claim  = $this->b64(json_encode([
            'iss'   => $credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]));

        $toSign = "{$header}.{$claim}";
        openssl_sign($toSign, $signature, $credentials['private_key'], 'SHA256');
        $jwt = "{$toSign}." . $this->b64($signature);

        $tokenResponse = Http::asForm()->timeout(15)->post(
            'https://oauth2.googleapis.com/token',
            ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt]
        );

        $results['step3_oauth2'] = [
            'http_status' => $tokenResponse->status(),
            'response'    => $tokenResponse->json() ?? $tokenResponse->body(),
        ];

        if (! $tokenResponse->successful()) {
            return response()->json(['message' => 'STOP: OAuth2 failed', 'data' => $results]);
        }

        $accessToken = $tokenResponse->json('access_token');

        // ── Step 4: Device token check ────────────────────────
        $results['step4_device_token'] = [
            'user_id'      => $user->id,
            'device_token' => $user->device_token ?? 'NULL',
            'token_length' => strlen($user->device_token ?? ''),
        ];

        if (empty($user->device_token)) {
            return response()->json(['message' => 'STOP: No device token for this user', 'data' => $results]);
        }

        // ── Step 5: Send FCM message directly ─────────────────
        $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

        $payload = [
            'message' => [
                'token'        => $user->device_token,
                'notification' => [
                    'title' => 'FCM Direct Test',
                    'body'  => 'Direct test from debug controller - ' . now(),
                ],
                'data' => [
                    'type'            => 'general',
                    'notifiable_type' => 'User',
                    'notifiable_id'   => (string)$user->id,
                ],
                'android' => ['priority' => 'high'],
            ],
        ];

        $fcmResponse = Http::withHeaders([
            'Authorization' => 'Bearer ' . $accessToken,
            'Content-Type'  => 'application/json',
        ])->timeout(15)->post($url, $payload);

        $results['step5_fcm_send'] = [
            'http_status'   => $fcmResponse->status(),
            'response_body' => $fcmResponse->json() ?? $fcmResponse->body(),
            'success'       => $fcmResponse->successful(),
        ];

        // ── Step 6: Test via FcmService class ─────────────────
        $testNotif = Notification::create([
            'user_id'         => $user->id,
            'title'           => 'FcmService Test',
            'body'            => 'Testing via FcmService class - ' . now(),
            'type'            => 'general',
            'channel'         => 'push',
            'notifiable_type' => 'User',
            'notifiable_id'   => $user->id,
            'is_read'         => 0,
            'fcm_sent'        => 0,
        ]);

        $fcmService = app(\App\Services\FcmService::class);
        $sent       = $fcmService->sendToUser($testNotif);
        $testNotif->refresh();

        $results['step6_fcm_service'] = [
            'returned'         => $sent ? 'true' : 'false',
            'notification_id'  => $testNotif->id,
            'fcm_sent'         => $testNotif->fcm_sent,
            'fcm_error'        => $testNotif->fcm_error ?? 'null',
        ];

        // ── Step 7: Check laravel log for FCM lines ───────────
        $logPath  = storage_path('logs/laravel.log');
        $logLines = [];
        if (file_exists($logPath)) {
            $lines = array_reverse(file($logPath));
            foreach ($lines as $line) {
                if (str_contains($line, 'FCM:')) {
                    $logLines[] = trim($line);
                    if (count($logLines) >= 10) break;
                }
            }
        }
        $results['step7_recent_fcm_logs'] = $logLines ?: ['No FCM log lines found'];

        return response()->json([
            'message' => 'Debug complete — check each step',
            'data'    => $results,
        ], 200);
    }

    private function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
