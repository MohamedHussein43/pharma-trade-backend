<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\LicenceImage;
use App\Models\RegistrationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class LicenceImageController extends Controller
{
    // =========================================================
    // GET /api/v1/admin/licences/{image}/view
    // Named route: admin.licence.view
    // Middleware: auth:sanctum + active.user + role:admin
    //
    // Serves the licence image file securely.
    // Uses a signed route URL — expires after 60 minutes.
    // The file is stored in the private disk, never public.
    // =========================================================
    public function view(Request $request, int $image)
    {
        // Validate the signed URL has not been tampered with
        //hussein check
        if (! $request->hasValidSignature()) {
            abort(403, 'Invalid or expired image link.');
        }

        $licenceImage = LicenceImage::find($image);

        if (! $licenceImage) {
            abort(404, 'Image not found.');
        }

        if (! Storage::disk('private')->exists($licenceImage->file_path)) {
            abort(404, 'Image file not found on disk.');
        }

        return Storage::disk('private')->response(
            $licenceImage->file_path,
            $licenceImage->file_name,
            ['Content-Type' => $licenceImage->mime_type]
        );
    }

        public function dataUrl(Request $request, int $registrationRequestId)
    {
        $query = LicenceImage::where('registration_request_id', $registrationRequestId);

        // Optional: get specific image by ID
        if ($request->filled('image_id')) {
            $query->where('id', (int)$request->image_id);
        } else {
            $query->where('is_primary', 1);
        }

        $licenceImage = $query->first()
            ?? LicenceImage::where('registration_request_id', $registrationRequestId)->first();

        if (! $licenceImage) {
            return response()->json(['message' => 'No licence image found.'], 404);
        }

        $mimeType = $licenceImage->mime_type ?? 'image/jpeg';

        // ── From DB (Railway + local) ─────────────────────────
        if (! empty($licenceImage->file_content)) {
            return response()->json([
                'message' => 'Licence image retrieved.',
                'data'    => [
                    'id'        => $licenceImage->id,
                    'file_name' => $licenceImage->file_name,
                    'mime_type' => $mimeType,
                    'size_kb'   => $licenceImage->file_size_kb,
                    'is_primary'=> $licenceImage->is_primary,
                    'data_url'  => "data:{$mimeType};base64,{$licenceImage->file_content}",
                ],
            ]);
        }

        // ── Fallback: read from disk (local only) ─────────────
        $content  = null;
        $filePath = $licenceImage->file_path;

        foreach ([
            fn() => \Illuminate\Support\Facades\Storage::disk('private')->exists($filePath)
                ? \Illuminate\Support\Facades\Storage::disk('private')->get($filePath) : null,
            fn() => file_exists(storage_path('app/private/' . $filePath))
                ? file_get_contents(storage_path('app/private/' . $filePath)) : null,
            fn() => file_exists(storage_path('app/' . $filePath))
                ? file_get_contents(storage_path('app/' . $filePath)) : null,
        ] as $attempt) {
            $content = $attempt();
            if ($content) break;
        }

        if (! $content) {
            return response()->json(['message' => 'Image not found on server.'], 404);
        }

        return response()->json([
            'message' => 'Licence image retrieved.',
            'data'    => [
                'id'        => $licenceImage->id,
                'file_name' => $licenceImage->file_name,
                'mime_type' => $mimeType,
                'size_kb'   => $licenceImage->file_size_kb,
                'is_primary'=> $licenceImage->is_primary,
                'data_url'  => "data:{$mimeType};base64," . base64_encode($content),
            ],
        ]);
    }


       // =========================================================
    // GET /api/v1/admin/registration-requests/{id}/licence-image
    // Returns the licence image for a registration request
    // Works on both local and Railway
    // =========================================================
    public function show(Request $request, int $id)
    {
        $req = RegistrationRequest::find($id);

        if (! $req) {
            return response()->json(['message' => 'Registration request not found.'], 404);
        }

        // ── Try to find image from meta JSON ──────────────────
        $meta      = is_array($req->meta) ? $req->meta : json_decode($req->meta, true);
        $imagePath = $meta['licence_image']
            ?? $meta['licence_image_path']
            ?? $meta['image_path']
            ?? $req->licence_image
            ?? null;

        Log::info("LicenceImage: Request #{$id}, stored path: " . ($imagePath ?? 'NULL'));

        if (empty($imagePath)) {
            return response()->json(['message' => 'No licence image found for this request.'], 404);
        }

        // ── Try multiple storage locations ────────────────────

        // Option 1: stored as base64 in DB directly
        if (str_starts_with($imagePath, 'data:image') || $this->isBase64($imagePath)) {
            Log::info("LicenceImage: Serving from base64 stored in DB");
            return $this->serveBase64($imagePath);
        }

        // Option 2: stored in storage/app/private
        if (Storage::disk('private')->exists($imagePath)) {
            Log::info("LicenceImage: Serving from private disk");
            $file     = Storage::disk('private')->get($imagePath);
            $mimeType = Storage::disk('private')->mimeType($imagePath);
            return response($file, 200)->header('Content-Type', $mimeType);
        }

        // Option 3: stored in storage/app/public
        if (Storage::disk('public')->exists($imagePath)) {
            Log::info("LicenceImage: Serving from public disk");
            $file     = Storage::disk('public')->get($imagePath);
            $mimeType = Storage::disk('public')->mimeType($imagePath);
            return response($file, 200)->header('Content-Type', $mimeType);
        }

        // Option 4: stored as full absolute path
        if (file_exists($imagePath)) {
            Log::info("LicenceImage: Serving from absolute path");
            $mimeType = mime_content_type($imagePath);
            return response()->file($imagePath, ['Content-Type' => $mimeType]);
        }

        // Option 5: strip leading slash and try again
        $stripped = ltrim($imagePath, '/');
        $fullPath = storage_path('app/' . $stripped);
        if (file_exists($fullPath)) {
            Log::info("LicenceImage: Serving from storage_path: {$fullPath}");
            $mimeType = mime_content_type($fullPath);
            return response()->file($fullPath, ['Content-Type' => $mimeType]);
        }

        Log::error("LicenceImage: Image not found anywhere", [
            'request_id'  => $id,
            'stored_path' => $imagePath,
            'tried'       => [
                'private: ' . $imagePath,
                'public: '  . $imagePath,
                'absolute: ' . $imagePath,
                'storage_path: ' . $fullPath,
            ],
        ]);

        return response()->json([
            'message'     => 'Licence image file not found on server.',
            'stored_path' => $imagePath,
        ], 404);
    }
    private function isBase64(string $str): bool
    {
        if (strlen($str) < 100) return false;
        return (bool)preg_match('/^[A-Za-z0-9+\/]+=*$/', substr($str, 0, 100));
    }

    private function serveBase64(string $base64): \Illuminate\Http\Response
    {
        if (str_starts_with($base64, 'data:')) {
            [$meta, $data] = explode(',', $base64, 2);
            preg_match('/data:([^;]+)/', $meta, $matches);
            $mimeType = $matches[1] ?? 'image/jpeg';
            $content  = base64_decode($data);
        } else {
            $mimeType = $this->detectMimeFromBase64($base64);
            $content  = base64_decode($base64);
        }

        return response($content, 200)->header('Content-Type', $mimeType);
    }

    private function detectMimeFromBase64(string $base64): string
    {
        $bytes = base64_decode(substr($base64, 0, 16));
        if (str_starts_with($bytes, "\xFF\xD8\xFF")) return 'image/jpeg';
        if (str_starts_with($bytes, "\x89PNG"))      return 'image/png';
        if (str_starts_with($bytes, "GIF"))           return 'image/gif';
        if (str_starts_with($bytes, "\x25PDF"))       return 'application/pdf';
        return 'image/jpeg';
    }
}
