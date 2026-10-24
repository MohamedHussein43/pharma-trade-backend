<?php

namespace App\Http\Controllers\Api\Pharmacy;

use App\Http\Controllers\Controller;
use App\Models\PharmacyBranch;
use Illuminate\Support\Facades\Auth;

class BaseMarketplaceController extends Controller
{
    /**
     * Return the PharmacyBranch that belongs to the authenticated user.
     * pharmacy_branches.user_id → users.id
     *
     * Throws ModelNotFoundException (404) if the user has no branch yet.
     */
    protected function currentBranch(): PharmacyBranch
    {
        return PharmacyBranch::where('user_id', Auth::id())->firstOrFail();
    }

    /** Alias used in seller-side controllers — same lookup. */
    protected function sellerBranch(): PharmacyBranch
    {
        return $this->currentBranch();
    }

    /** Alias used in buyer-side controllers — same lookup. */
    protected function buyerBranch(): PharmacyBranch
    {
        return $this->currentBranch();
    }
}
