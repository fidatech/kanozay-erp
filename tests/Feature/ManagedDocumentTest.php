<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ManagedDocumentTest extends TestCase
{
    use RefreshDatabase;

    private function officer():User
    {
        foreach(['documents.view','documents.create','documents.update','documents.delete'] as $permission) {
            Permission::findOrCreate($permission,'web');
        }
        $user=User::factory()->create();
        $user->givePermissionTo(['documents.view','documents.create','documents.update','documents.delete']);
        return $user;
    }

    private function upload(User $user,string $title='Synthetic production SOP'):int
    {
        $this->actingAs($user)->post(route('documents.upload'),[
            'title'=>$title,'type'=>'document',
            'file'=>UploadedFile::fake()->create('document.pdf',15,'application/pdf'),
        ])->assertRedirect(route('workspace.page',['documents','library']));
        return (int)DB::table('managed_documents')->latest('id')->value('id');
    }

    public function test_all_document_pages_display_live_content():void
    {
        $officer=$this->officer();
        foreach(['library','categories','approvals','versions','templates','retention','expirations'] as $page) {
            $this->actingAs($officer)->get(route('workspace.page',['documents',$page]))
                ->assertOk()->assertDontSee('Workflow pending');
        }
    }

    public function test_private_upload_download_and_version_history():void
    {
        Storage::fake('local');
        $user=$this->officer();
        $doc=$this->upload($user);
        $record=DB::table('managed_documents')->find($doc);
        $this->assertSame('DOC-0000001',$record->number);
        $version=DB::table('managed_document_versions')->where('managed_document_id',$doc)->first();
        Storage::disk('local')->assertExists($version->storage_path);
        $this->actingAs($user)->get(route('documents.download',$doc))->assertOk();
        $stranger=User::factory()->create();
        $this->actingAs($stranger)->get(route('documents.download',$doc))->assertForbidden();
        $this->actingAs($user)->post(route('documents.replace',$doc),[
            'file'=>UploadedFile::fake()->create('updated.pdf',12,'application/pdf'),
            'change_note'=>'Updated production procedure after review',
        ])->assertRedirect();
        $this->assertSame(2,DB::table('managed_document_versions')->where('managed_document_id',$doc)->count());
        $this->actingAs($user)->get(route('documents.download.version',[$doc,1]))->assertOk();
    }

    public function test_approval_requires_independent_reviewer_and_current_version():void
    {
        Storage::fake('local');
        $uploader=$this->officer();
        $reviewer=$this->officer();
        $doc=$this->upload($uploader);
        $this->actingAs($uploader)->post(route('documents.approvals.request',$doc))->assertRedirect();
        $approval=DB::table('managed_document_approvals')->where('managed_document_id',$doc)->first();
        $this->actingAs($uploader)->post(route('documents.approvals.decide',[$doc,$approval->id]),[
            'decision'=>'approved',
        ])->assertSessionHasErrors('decision');
        $this->actingAs($reviewer)->post(route('documents.approvals.decide',[$doc,$approval->id]),[
            'decision'=>'approved',
        ])->assertRedirect();
        $this->assertDatabaseHas('managed_documents',['id'=>$doc,'status'=>'approved','approved_by'=>$reviewer->id]);
        $this->actingAs($uploader)->post(route('documents.replace',$doc),[
            'file'=>UploadedFile::fake()->create('updated.pdf',5,'application/pdf'),
            'change_note'=>'Changed the procedure after signoff',
        ])->assertRedirect();
        $this->assertDatabaseHas('managed_documents',['id'=>$doc,'status'=>'draft','current_version'=>2,'approved_by'=>null]);
    }

    public function test_retention_hold_blocks_archival_and_allows_metadata_update():void
    {
        Storage::fake('local');
        $user=$this->officer();
        $doc=$this->upload($user);
        $this->actingAs($user)->patch(route('documents.metadata',$doc),[
            'title'=>'Controlled SOP','retention_until'=>now()->addYear()->toDateString(),
        ])->assertRedirect();
        $this->actingAs($user)->delete(route('documents.archive',$doc))->assertSessionHasErrors('retention_until');
        $this->assertDatabaseHas('managed_documents',['id'=>$doc,'deleted_at'=>null]);
    }
}
