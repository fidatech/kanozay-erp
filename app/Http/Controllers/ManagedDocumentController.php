<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ManagedDocumentController extends Controller
{
    private const PAGES = ['library','categories','approvals','versions','templates','retention','expirations'];

    private function check(Request $request, string $action): void
    {
        $admin = $action === 'view' ? 'administration.view' : 'administration.update';
        abort_unless($request->user()?->canAny(['documents.'.$action, $admin]),403);
    }

    public function index(Request $request, string $page)
    {
        abort_unless(in_array($page,self::PAGES,true),404);
        $this->check($request,'view');

        $filters = $request->validate([
            'search'=>['nullable','string','max:100'],
            'category_id'=>['nullable','integer','exists:document_categories,id'],
            'export'=>['nullable',Rule::in(['csv'])],
        ]);
        $categories = DB::table('document_categories')->orderBy('name')->get();
        $documents = DB::table('managed_documents as d')
            ->leftJoin('document_categories as c','c.id','=','d.document_category_id')
            ->leftJoin('users as u','u.id','=','d.created_by')
            ->whereNull('d.deleted_at')
            ->select('d.*','c.name as category_name','u.name as creator_name');

        if($page === 'templates') $documents->where('d.type','template');
        if($page === 'retention') $documents->whereNotNull('d.retention_until');
        if($page === 'expirations') $documents->whereNotNull('d.expires_on');
        if(!empty($filters['category_id'])) $documents->where('d.document_category_id',$filters['category_id']);
        if(!empty($filters['search'])) {
            $term=trim($filters['search']);
            $documents->where(function($q) use ($term) {
                $q->where('d.title','like','%'.$term.'%')->orWhere('d.number','like','%'.$term.'%');
            });
        }

        if(($filters['export']??null)==='csv') {
            return response()->streamDownload(function()use($documents){
                $output=fopen('php://output','w');
                fputcsv($output,['Number','Title','Category','Type','Status','Version','Expiration','Retention Until']);
                foreach((clone $documents)->orderBy('d.id')->cursor() as $doc) {
                    $vals=[$doc->number,$doc->title,$doc->category_name,$doc->type,$doc->status,
                        $doc->current_version,$doc->expires_on,$doc->retention_until];
                    fputcsv($output,array_map(static function($value){
                        $value=(string)($value??'');
                        return preg_match('/^\s*[=+\-@]/u',$value) ? "'".$value:$value;
                    },$vals));
                }
                fclose($output);
            },'kanozay-documents-'.now()->format('Ymd').'.csv',
            ['Content-Type'=>'text/csv; charset=UTF-8']);
        }

        $records = $documents->orderByDesc('d.id')->paginate(25)->withQueryString();
        $approvals = DB::table('managed_document_approvals as a')
            ->join('managed_documents as d','d.id','=','a.managed_document_id')
            ->whereNull('d.deleted_at')
            ->leftJoin('users as u','u.id','=','a.requested_by')
            ->select('a.*','d.number','d.title','d.current_version','u.name as requested_by_name')
            ->orderByRaw("CASE WHEN a.status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('a.id')->limit(100)->get();
        $versions = DB::table('managed_document_versions as v')
            ->join('managed_documents as d','d.id','=','v.managed_document_id')
            ->whereNull('d.deleted_at')
            ->select('v.*','d.number','d.title')->orderByDesc('v.id')->limit(100)->get();

        return view('documents.manage',compact('page','filters','categories','records','approvals','versions'));
    }

    private function uploadRules(): array
    {
        return [
            'file'=>['required','file','max:20480',
                'mimes:pdf,doc,docx,xls,xlsx,csv,txt,png,jpg,jpeg'],
        ];
    }

    public function category(Request $request)
    {
        $this->check($request,'create');
        $request->merge(['code'=>strtoupper(trim((string)$request->input('code')))]);
        $data=$request->validate([
            'code'=>['required','regex:/^[A-Z][A-Z0-9_-]{1,31}$/',Rule::unique('document_categories','code')],
            'name'=>['required','string','max:120'],
            'description'=>['nullable','string','max:2000'],
        ]);
        $id=DB::table('document_categories')->insertGetId($data+[
            'created_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now(),
        ]);
        ActivityLog::record('documents.category.created','document_category',$id,'Document category created.');

        return redirect()->route('workspace.page',['documents','categories'])->with('success','Document category created.');
    }

    public function upload(Request $request)
    {
        $this->check($request,'create');
        $data=$request->validate(array_merge($this->uploadRules(),[
            'title'=>['required','string','max:180'],
            'document_category_id'=>['nullable',Rule::exists('document_categories','id')->where('is_active',true)],
            'type'=>['required',Rule::in(['document','template'])],
            'expires_on'=>['nullable','date'],
            'retention_until'=>['nullable','date'],
            'notes'=>['nullable','string','max:5000'],
        ]));
        $file=$request->file('file');
        $sha=hash_file('sha256',$file->getRealPath());
        $storagePath=$file->store('kanozay/documents/'.Str::uuid(),'local');

        try {
            $docId=DB::transaction(function()use($request,$data,$file,$sha,$storagePath){
                $id=DB::table('managed_documents')->insertGetId([
                    'number'=>'TMP-'.Str::uuid(),'title'=>$data['title'],
                    'document_category_id'=>$data['document_category_id']??null,
                    'type'=>$data['type'],'status'=>'draft','current_version'=>1,
                    'expires_on'=>$data['expires_on']??null,
                    'retention_until'=>$data['retention_until']??null,
                    'notes'=>$data['notes']??null,'created_by'=>$request->user()->id,
                    'created_at'=>now(),'updated_at'=>now(),
                ]);
                DB::table('managed_documents')->where('id',$id)
                    ->update(['number'=>'DOC-'.str_pad((string)$id,7,'0',STR_PAD_LEFT)]);
                DB::table('managed_document_versions')->insert([
                    'managed_document_id'=>$id,'version'=>1,'storage_path'=>$storagePath,
                    'original_name'=>Str::limit($file->getClientOriginalName(),200,''),
                    'mime_type'=>$file->getMimeType()?:'application/octet-stream',
                    'file_size_bytes'=>$file->getSize(),'sha256'=>$sha,
                    'uploaded_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now(),
                ]);
                ActivityLog::record('documents.uploaded','managed_document',$id,'Private document uploaded.');
                return $id;
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($storagePath);
            throw $e;
        }

        return redirect()->route('workspace.page',['documents','library'])
            ->with('success','Document DOC-'.str_pad((string)$docId,7,'0',STR_PAD_LEFT).' stored privately.');
    }

    private function document(int $id)
    {
        $doc=DB::table('managed_documents')->where('id',$id)->whereNull('deleted_at')->first();
        abort_unless($doc,404);
        return $doc;
    }

    public function download(Request $request,int $document,?int $version=null)
    {
        $this->check($request,'view');
        $doc=$this->document($document);
        $wanted=$version??$doc->current_version;
        $row=DB::table('managed_document_versions')->where('managed_document_id',$doc->id)
            ->where('version',$wanted)->first();
        abort_unless($row,404);
        abort_unless(Storage::disk('local')->exists($row->storage_path),404);

        return Storage::disk('local')->download($row->storage_path,$row->original_name);
    }

    public function replace(Request $request,int $document)
    {
        $this->check($request,'update');
        $data=$request->validate(array_merge($this->uploadRules(),[
            'change_note'=>['required','string','min:5','max:1500'],
        ]));
        $file=$request->file('file');
        $sha=hash_file('sha256',$file->getRealPath());
        $storagePath=$file->store('kanozay/documents/'.Str::uuid(),'local');

        try {
            DB::transaction(function()use($request,$document,$data,$file,$sha,$storagePath){
                $doc=DB::table('managed_documents')->where('id',$document)
                    ->whereNull('deleted_at')->lockForUpdate()->first();
                abort_unless($doc,404);
                $newVersion=$doc->current_version+1;
                DB::table('managed_document_versions')->insert([
                    'managed_document_id'=>$doc->id,'version'=>$newVersion,
                    'storage_path'=>$storagePath,
                    'original_name'=>Str::limit($file->getClientOriginalName(),200,''),
                    'mime_type'=>$file->getMimeType()?:'application/octet-stream',
                    'file_size_bytes'=>$file->getSize(),'sha256'=>$sha,
                    'change_note'=>$data['change_note'],'uploaded_by'=>$request->user()->id,
                    'created_at'=>now(),'updated_at'=>now(),
                ]);
                DB::table('managed_documents')->where('id',$doc->id)->update([
                    'current_version'=>$newVersion,'status'=>'draft',
                    'approved_by'=>null,'approved_at'=>null,'updated_at'=>now(),
                ]);
                ActivityLog::record('documents.version.created','managed_document',$doc->id,
                    'Document revision '.$newVersion.' uploaded; approval reset.', ['version'=>$newVersion]);
            });
        } catch(\Throwable $e) {
            Storage::disk('local')->delete($storagePath);
            throw $e;
        }

        return back()->with('success','New private document version uploaded. Re-approval is required.');
    }

    public function requestApproval(Request $request,int $document)
    {
        $this->check($request,'update');
        DB::transaction(function()use($document,$request){
            $doc=DB::table('managed_documents')->whereNull('deleted_at')
                ->where('id',$document)->lockForUpdate()->first();
            abort_unless($doc,404);
            if(!in_array($doc->status,['draft','rejected'],true)){
                throw ValidationException::withMessages(['approval'=>'Only draft or rejected documents can request approval.']);
            }
            $pending=DB::table('managed_document_approvals')->where('managed_document_id',$doc->id)
                ->where('version',$doc->current_version)->where('status','pending')->exists();
            if($pending)throw ValidationException::withMessages(['approval'=>'Approval already requested.']);
            DB::table('managed_document_approvals')->insert([
                'managed_document_id'=>$doc->id,'version'=>$doc->current_version,
                'status'=>'pending','requested_by'=>$request->user()->id,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            DB::table('managed_documents')->where('id',$doc->id)
                ->update(['status'=>'pending_approval','updated_at'=>now()]);
            ActivityLog::record('documents.approval.requested','managed_document',$doc->id,
                'Document approval requested for version '.$doc->current_version);
        });

        return back()->with('success','Document submitted for an independent approval.');
    }

    public function decide(Request $request,int $document,int $approval)
    {
        $this->check($request,'update');
        $data=$request->validate([
            'decision'=>['required',Rule::in(['approved','rejected'])],
            'decision_note'=>['nullable','required_if:decision,rejected','string','max:1500'],
        ]);
        DB::transaction(function()use($request,$document,$approval,$data){
            $doc=DB::table('managed_documents')->where('id',$document)
                ->whereNull('deleted_at')->lockForUpdate()->first();
            abort_unless($doc,404);
            $review=DB::table('managed_document_approvals')
                ->where('id',$approval)->where('managed_document_id',$doc->id)
                ->lockForUpdate()->first();
            abort_unless($review,404);
            if($review->status!=='pending' || $doc->status!=='pending_approval' ||
                $review->version!==$doc->current_version){
                throw ValidationException::withMessages(['decision'=>'This approval request is no longer pending for the current version.']);
            }
            if($review->requested_by===$request->user()->id){
                throw ValidationException::withMessages(['decision'=>'You cannot approve or reject your own request.']);
            }
            DB::table('managed_document_approvals')->where('id',$review->id)->update([
                'status'=>$data['decision'],'decision_note'=>$data['decision_note']??null,
                'reviewed_by'=>$request->user()->id,'reviewed_at'=>now(),'updated_at'=>now(),
            ]);
            DB::table('managed_documents')->where('id',$doc->id)->update([
                'status'=>$data['decision'],
                'approved_by'=>$data['decision']==='approved'?$request->user()->id:null,
                'approved_at'=>$data['decision']==='approved'?now():null,'updated_at'=>now(),
            ]);
            ActivityLog::record('documents.approval.'.$data['decision'],'managed_document',$doc->id,
                'Document version '.$review->version.' '.$data['decision']);
        });

        return back()->with('success','Approval decision recorded.');
    }

    public function metadata(Request $request,int $document)
    {
        $this->check($request,'update');
        $data=$request->validate([
            'title'=>['required','string','max:180'],
            'document_category_id'=>['nullable','exists:document_categories,id'],
            'expires_on'=>['nullable','date'],
            'retention_until'=>['nullable','date'],
            'notes'=>['nullable','string','max:5000'],
        ]);
        DB::transaction(function()use($data,$document) {
            $doc=DB::table('managed_documents')->where('id',$document)
                ->whereNull('deleted_at')->lockForUpdate()->first();
            abort_unless($doc,404);
            DB::table('managed_documents')->where('id',$document)->update([
                'title'=>$data['title'],'document_category_id'=>$data['document_category_id']??null,
                'expires_on'=>$data['expires_on']??null,
                'retention_until'=>$data['retention_until']??null,
                'notes'=>$data['notes']??null,
                'status'=>'draft','approved_by'=>null,'approved_at'=>null,'updated_at'=>now(),
            ]);
            ActivityLog::record('documents.metadata.updated','managed_document',$document,
                'Document metadata changed; approval reset.');
        });

        return back()->with('success','Metadata updated. Re-approval is required.');
    }

    public function archive(Request $request,int $document)
    {
        $this->check($request,'delete');
        DB::transaction(function()use($document){
            $doc=DB::table('managed_documents')->whereNull('deleted_at')
                ->where('id',$document)->lockForUpdate()->first();
            abort_unless($doc,404);
            if($doc->retention_until && $doc->retention_until>=now()->toDateString()){
                throw ValidationException::withMessages(['retention_until'=>'This document remains under an active retention hold.']);
            }
            DB::table('managed_documents')->where('id',$document)->update([
                'deleted_at'=>now(),'updated_at'=>now(),
            ]);
            ActivityLog::record('documents.archived','managed_document',$document,
                'Document archived; private files and version history preserved.');
        });

        return redirect()->route('workspace.page',['documents','library'])
            ->with('success','Document archived. Files and audit history are retained.');
    }
}
