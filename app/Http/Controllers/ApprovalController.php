<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Notifications\AdministrativeChange;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ApprovalController extends Controller
{
    private function canDecide(Request $request): bool
    {
        return $request->user()->hasRole('Super Administrator') || $request->user()->can('administration.update');
    }

    public function index(Request $request): View
    {
        $canDecide = $this->canDecide($request);
        $approvals = ApprovalRequest::with(['requester', 'decider'])
            ->when(!$canDecide, fn ($query) => $query->where('requested_by', $request->user()->id))
            ->latest()->paginate(20);
        return view('approvals.index', compact('approvals', 'canDecide'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:3000'],
            'category' => ['required', Rule::in(['General', 'Purchasing', 'Inventory', 'Manufacturing', 'Laboratory', 'Sales', 'Finance', 'HR', 'Maintenance'])],
        ]);
        $approval = DB::transaction(function () use ($data, $request) {
            $approval = ApprovalRequest::create($data + ['requested_by' => $request->user()->id]);
            ActivityLog::record('approval.requested', 'approval', $approval->id, 'Submitted approval request', ['category' => $approval->category]);
            return $approval;
        });
        User::where('id', '!=', $request->user()->id)->get()->filter(fn (User $recipient) => $recipient->hasRole('Super Administrator') || $recipient->can('administration.update'))->each(function (User $recipient) use ($approval) {
            $recipient->notify(new AdministrativeChange('Approval requested', $approval->title));
        });
        return redirect()->route('approvals.index')->with('status', 'Approval request submitted.');
    }

    public function decide(Request $request, ApprovalRequest $approval): RedirectResponse
    {
        abort_unless($this->canDecide($request), 403);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'decision_note' => ['nullable', 'string', 'max:3000'],
        ]);
        if ($approval->requested_by === $request->user()->id) return back()->withErrors(['approval' => 'You cannot approve or reject your own request.']);
        $updated = DB::transaction(function () use ($approval, $request, $data) {
            $updated = ApprovalRequest::whereKey($approval->id)->where('status', 'pending')->update([
                'status' => $data['decision'], 'decided_by' => $request->user()->id,
                'decision_note' => $data['decision_note'] ?? null, 'decided_at' => now(),
            ]);
            if ($updated) ActivityLog::record('approval.'.$data['decision'], 'approval', $approval->id, 'Decision recorded for approval request', ['decision' => $data['decision']]);
            return $updated;
        });
        if (!$updated) return back()->withErrors(['approval' => 'This request has already been decided.']);
        $approval->requester->notify(new AdministrativeChange('Approval '.$data['decision'], $approval->title));
        return back()->with('status', 'Approval request '.$data['decision'].'.');
    }
}
