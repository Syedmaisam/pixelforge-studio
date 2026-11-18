<?php

namespace App\Http\Controllers;

use App\Models\CreditTransaction;
use App\Services\CreditLedger;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * "Buy credits": a fake purchase that simply adds credits. No real payment.
 */
class CreditController extends Controller
{
    public function store(Request $request, CreditLedger $ledger): RedirectResponse
    {
        $input = $request->validate([
            'dollars' => ['required', 'integer', 'in:5,10,25'],
        ]);

        $dollars = (int) $input['dollars'];

        $ledger->addCredits(
            $request->user(),
            Money::dollars($dollars),
            CreditTransaction::PURCHASE,
            "Bought \${$dollars} of credits (demo purchase)",
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => "Added \${$dollars}.00 of credits."]);

        return back();
    }
}
