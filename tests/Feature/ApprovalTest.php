<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_requesters_see_only_their_own_requests(): void
    {
        $one = User::factory()->create(); $two = User::factory()->create();
        $this->actingAs($one)->post(route('approvals.store'), ['title'=>'Purchase raw material', 'category'=>'Purchasing'])->assertRedirect();
        $this->actingAs($two)->get(route('approvals.index'))->assertOk()->assertDontSee('Purchase raw material');
        $this->actingAs($one)->get(route('approvals.index'))->assertOk()->assertSee('Purchase raw material');
    }

    public function test_unauthorized_user_cannot_decide_and_requester_cannot_self_approve(): void
    {
        $requester = User::factory()->create(); $other = User::factory()->create();
        Permission::findOrCreate('administration.update', 'web');
        $requester->givePermissionTo('administration.update');
        $approval = ApprovalRequest::create(['title'=>'Approve purchase', 'category'=>'Purchasing', 'requested_by'=>$requester->id]);
        $this->actingAs($other)->patch(route('approvals.decide', $approval), ['decision'=>'approved'])->assertForbidden();
        $this->actingAs($requester)->patch(route('approvals.decide', $approval), ['decision'=>'approved'])->assertSessionHasErrors('approval');
        $this->assertSame('pending', $approval->fresh()->status);
    }

    public function test_authorized_approver_can_decide_only_once(): void
    {
        $requester = User::factory()->create(); $approver = User::factory()->create();
        Permission::findOrCreate('administration.update', 'web');
        $approver->givePermissionTo('administration.update');
        $approval = ApprovalRequest::create(['title'=>'Test approval', 'category'=>'General', 'requested_by'=>$requester->id]);
        $this->actingAs($approver)->patch(route('approvals.decide', $approval), ['decision'=>'approved'])->assertRedirect();
        $this->assertSame('approved', $approval->fresh()->status);
        $this->actingAs($approver)->patch(route('approvals.decide', $approval), ['decision'=>'rejected'])->assertSessionHasErrors('approval');
        $this->assertSame('approved', $approval->fresh()->status);
        $this->assertSame(1, $requester->notifications()->count());
    }
}
