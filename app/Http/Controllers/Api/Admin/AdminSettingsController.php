<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AdminSettingsController extends Controller
{
    // GET /api/v1/admin/settings
    public function getSettings(Request $request): JsonResponse
    {
        $settings = \App\Models\PlatformSetting::current();

        return response()->json([
            'message' => 'Platform settings retrieved.',
            'data'    => [
                'enable_banned_drug_check' => $settings->enable_banned_drug_check,
                'fcm_enabled'              => $settings->fcm_enabled,
                'whatsapp_enabled'         => $settings->whatsapp_enabled,
            ],
        ], 200);
    }

    // PATCH /api/v1/admin/settings
    public function updateSettings(Request $request): JsonResponse
    {
        $request->validate([
            'enable_banned_drug_check' => ['sometimes', 'boolean'],
            'fcm_enabled'              => ['sometimes', 'boolean'],
            'whatsapp_enabled'         => ['sometimes', 'boolean'],
        ]);

        $settings = \App\Models\PlatformSetting::current();

        $updateData = array_filter([
            'enable_banned_drug_check' => $request->has('enable_banned_drug_check')
                ? (bool)$request->enable_banned_drug_check : null,
            'fcm_enabled'              => $request->has('fcm_enabled')
                ? (bool)$request->fcm_enabled : null,
            'whatsapp_enabled'         => $request->has('whatsapp_enabled')
                ? (bool)$request->whatsapp_enabled : null,
            'updated_by'               => $request->user()->id,
        ], fn($v) => $v !== null);

        $settings->update($updateData);

        return response()->json([
            'message' => 'Settings updated successfully.',
            'data'    => [
                'enable_banned_drug_check' => $settings->fresh()->enable_banned_drug_check,
                'fcm_enabled'              => $settings->fresh()->fcm_enabled,
                'whatsapp_enabled'         => $settings->fresh()->whatsapp_enabled,
            ],
        ], 200);
    }
}
