<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\LegacyAccountsWorkbookReviewService;
use Illuminate\Http\Request;

class LegacyAccountsWorkbookReviewController extends Controller
{
    public function __invoke(Request $request, LegacyAccountsWorkbookReviewService $reviewer)
    {
        abort_unless($request->user()->can('administration.update'), 403);
        $data = $request->validate(['file' => ['required', 'file', 'mimes:xls,xlsx', 'max:4096']]);

        return view('admin.accounts.workbook-review', [
            'review' => $reviewer->review($data['file']->getRealPath()),
        ]);
    }
}
