<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\LicenceImage;
use Illuminate\Http\Request;
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
}
