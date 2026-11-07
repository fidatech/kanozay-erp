@extends('layouts.kanozay')
@section('content')
<style>
.doc-workspace .heading{margin-bottom:18px}.doc-workspace .panel{padding:20px;border-radius:12px;border:1px solid #e0e8f3;margin-bottom:16px}
.doc-workspace .doc-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.doc-workspace label{display:grid;gap:6px;font-size:12px;font-weight:700;color:#385676}
.doc-workspace label.span{grid-column:1/-1}.doc-workspace input,.doc-workspace textarea,.doc-workspace select{box-sizing:border-box;min-width:0;width:100%;border:1px solid #d8e3f0;border-radius:8px;padding:11px;font-size:12px}
.doc-workspace form.doc-form{display:grid;grid-template-columns:1fr 1fr;gap:13px;align-items:end}
.doc-workspace form.doc-form button{justify-self:start}.doc-workspace .doc-note{color:#67809c;font-size:12px;line-height:1.7}
.doc-workspace .doc-table{max-width:100%;overflow:auto;border:1px solid #e2eaf3;border-radius:10px}
.doc-workspace table{min-width:850px;margin:0;font-size:11px}.doc-workspace th{padding:12px;background:#f2f7fc;color:#3b5776}.doc-workspace td{padding:12px;vertical-align:top}
.doc-workspace td .doc-actions{display:flex;gap:7px;flex-wrap:wrap;align-items:center}.doc-workspace td .doc-actions a{background:#eef5ff;color:#17579a;border-radius:6px;padding:7px 9px;text-decoration:none;font-weight:700}
.doc-workspace td button{border:1px solid #c9dbee;background:#eef5ff;color:#135a9a;padding:8px 10px;border-radius:7px;cursor:pointer}
.doc-workspace td .reject{background:#fff2f2;border-color:#efcdcd;color:#a33333}
.doc-workspace .doc-alert{padding:13px;margin-bottom:14px;border-radius:8px;background:#f0faef;color:#236c46}
@media(max-width:800px){.doc-workspace .doc-grid,.doc-workspace form.doc-form{grid-template-columns:1fr}}
</style>
<div class="doc-workspace">
<div class="heading"><div><h1><i class="fa-solid fa-folder-open"></i> {{ config('erp_pages.documents.pages.'.$page) }}</h1><p>Document Management / Controlled private document storage</p></div><a class="btn btn-light" href="{{ route('workspace.page',['documents','library','export'=>'csv']) }}"><i class="fa-solid fa-file-csv"></i> Export register</a></div>
@if(session('success'))<div class="doc-alert">{{ session('success') }}</div>@endif
@if($errors->any())<div class="panel" style="border-color:#efc6c6;color:#a22">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
@if($page==='categories')
@canany(['documents.create','administration.update'])
<section class="panel"><h2>Create document category</h2><form class="doc-form" method="POST" action="{{ route('documents.categories.store') }}">@csrf
<label>Code<input name="code" required maxlength="32" placeholder="LEGAL"></label>
<label>Category name<input name="name" required maxlength="120"></label>
<label class="span">Description<textarea name="description" maxlength="2000" rows="2"></textarea></label>
<button class="btn" type="submit">Create category</button></form></section>
@endcanany
<section class="panel"><h2>Document categories</h2>
<div class="doc-table"><table><thead><tr><th>Code</th><th>Name</th><th>Description</th><th>Active</th></tr></thead><tbody>
@forelse($categories as $category)
<tr><td>{{ $category->code }}</td><td>{{ $category->name }}</td><td>{{ $category->description }}</td><td>{{ $category->is_active?'Active':'Inactive' }}</td></tr>
@empty<tr><td colspan="4">No categories configured.</td></tr>@endforelse
</tbody></table></div></section>
@elseif($page==='approvals')
<section class="panel"><h2>Independent document approval queue</h2>
<p class="doc-note">A document's uploader cannot approve their own request. Approval applies to a specific version and resets when a revised file or document metadata is submitted.</p>
<div class="doc-table"><table><thead><tr><th>Document</th><th>Version</th><th>Requested by</th><th>Status</th><th>File</th><th>Decision</th></tr></thead><tbody>
@forelse($approvals as $approval)
<tr><td>{{ $approval->number }}<br>{{ $approval->title }}</td><td>{{ $approval->version }}</td><td>{{ $approval->requested_by_name }}</td><td>{{ ucfirst($approval->status) }}</td>
<td><a href="{{ route('documents.download',[$approval->managed_document_id,$approval->version]) }}">Download for review</a></td><td>
@if($approval->status==='pending' && $approval->version===$approval->current_version)
@canany(['documents.update','administration.update'])
<form method="POST" action="{{ route('documents.approvals.decide',[$approval->managed_document_id,$approval->id]) }}">@csrf
<input type="text" name="decision_note" placeholder="Decision note" maxlength="1500">
<button name="decision" value="approved">Approve</button><button class="reject" name="decision" value="rejected">Reject</button></form>
@endcanany
@else <span class="doc-note">Decision recorded</span>@endif
</td></tr>
@empty<tr><td colspan="6">No approval requests found.</td></tr>@endforelse
</tbody></table></div></section>
@elseif($page==='versions')
<section class="panel"><h2>Document version history</h2>
<div class="doc-table"><table><thead><tr><th>Document</th><th>Version</th><th>Original filename</th><th>Checksum</th><th>Change note</th><th>Download</th></tr></thead><tbody>
@forelse($versions as $version)
<tr><td>{{ $version->number }}<br>{{ $version->title }}</td><td>v{{ $version->version }}</td><td>{{ $version->original_name }}</td><td title="{{ $version->sha256 }}">{{ substr($version->sha256,0,18) }}...</td><td>{{ $version->change_note ?: 'Initial upload' }}</td>
<td><a href="{{ route('documents.download',[$version->managed_document_id,$version->version]) }}">Private download</a></td></tr>
@empty<tr><td colspan="6">No document versions have been uploaded.</td></tr>@endforelse
</tbody></table></div></section>
@else
@if(in_array($page,['library','templates'],true))
@canany(['documents.create','administration.update'])
<section class="panel"><h2>Upload a controlled document</h2>
<form class="doc-form" method="POST" enctype="multipart/form-data" action="{{ route('documents.upload') }}">@csrf
<label>Title<input name="title" required maxlength="180" value="{{ old('title') }}" placeholder="Document title"></label>
<label>Category<select name="document_category_id"><option value="">Uncategorized</option>
@foreach($categories as $category)@if($category->is_active)<option value="{{ $category->id }}" @selected(old('document_category_id')==$category->id)>{{ $category->name }}</option>@endif @endforeach
</select></label>
<label>Type<select name="type"><option value="document" @selected($page!=='templates')>Document</option><option value="template" @selected($page==='templates')>Reusable template</option></select></label>
<label>Private file (20 MB maximum)<input type="file" name="file" required accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.png,.jpg,.jpeg"></label>
<label>Expiration date<input type="date" name="expires_on" value="{{ old('expires_on') }}"></label>
<label>Retention until<input type="date" name="retention_until" value="{{ old('retention_until') }}"></label>
<label class="span">Description<textarea name="notes" maxlength="5000">{{ old('notes') }}</textarea></label>
<button class="btn" type="submit"><i class="fa-solid fa-upload"></i> Upload private document</button></form>
<p class="doc-note">Files are saved on Laravel's private local disk, outside the public web folder. Access requires authentication and document permissions. Publishing a document requires independent approval.</p>
</section>
@endcanany
@endif
<section class="panel">
<h2>Stored documents</h2>
<form method="GET" action="{{ route('workspace.page',['documents',$page]) }}" style="display:flex;gap:9px;flex-wrap:wrap;margin-bottom:14px">
<input type="search" name="search" maxlength="100" value="{{ $filters['search']??'' }}" placeholder="Search title or reference" style="max-width:320px">
<select name="category_id" style="max-width:260px"><option value="">All categories</option>
@foreach($categories as $category)<option value="{{ $category->id }}" @selected(($filters['category_id']??null)==$category->id)>{{ $category->name }}</option>@endforeach</select>
<button class="btn" type="submit">Filter</button>
<a class="btn btn-light" href="{{ route('workspace.page',['documents',$page]) }}">Reset</a>
</form>
<div class="doc-table"><table><thead><tr><th>Reference</th><th>Title</th><th>Category</th><th>Type</th><th>Version</th><th>Status</th><th>Expiration</th><th>Retention</th><th>Actions</th></tr></thead><tbody>
@forelse($records as $document)
<tr><td><strong>{{ $document->number }}</strong></td><td>{{ $document->title }}</td><td>{{ $document->category_name??'—' }}</td><td>{{ ucfirst($document->type) }}</td><td>{{ $document->current_version }}</td><td><span class="status">{{ str_replace('_',' ',ucfirst($document->status)) }}</span></td>
<td>{{ $document->expires_on?:'—' }}</td><td>{{ $document->retention_until?:'—' }}</td>
<td><div class="doc-actions">
<a href="{{ route('documents.download',$document->id) }}">Download</a>
@canany(['documents.update','administration.update'])
@if(in_array($document->status,['draft','rejected'],true))
<form method="POST" action="{{ route('documents.approvals.request',$document->id) }}">@csrf<button type="submit">Request approval</button></form>
@endif
<details><summary>Upload new version</summary><form method="POST" action="{{ route('documents.replace',$document->id) }}" enctype="multipart/form-data">@csrf<input type="file" name="file" required><input name="change_note" placeholder="Required change note" required minlength="5" maxlength="1500"><button type="submit">Upload revision</button></form></details>
<details><summary>Edit metadata</summary><form method="POST" action="{{ route('documents.metadata',$document->id) }}">@csrf @method('PATCH')
<input name="title" required maxlength="180" value="{{ $document->title }}" placeholder="Title">
<select name="document_category_id"><option value="">No category</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected($category->id==$document->document_category_id)>{{ $category->name }}</option>@endforeach</select>
<input type="date" name="expires_on" value="{{ $document->expires_on }}">
<input type="date" name="retention_until" value="{{ $document->retention_until }}">
<input name="notes" value="{{ $document->notes }}" maxlength="5000" placeholder="Description">
<button type="submit">Save and reset approval</button>
</form></details>
@endcanany
@canany(['documents.delete','administration.update'])
<form method="POST" action="{{ route('documents.archive',$document->id) }}" onsubmit="return confirm('Archive this document? Files and revisions are retained.')">@csrf @method('DELETE')<button type="submit" class="reject">Archive</button></form>
@endcanany
</div></td></tr>
@empty<tr><td colspan="9" style="padding:30px;text-align:center">No documents match these filters.</td></tr>@endforelse
</tbody></table></div>{{ $records->links() }}
@if($page==='expirations')<p class="doc-note">These documents have expiration dates, including past expiration dates. Check each date and renew the affected document manually.</p>@endif
@if($page==='retention')<p class="doc-note">Documents with an active retention date cannot be archived until the hold expires. Uploaded files are preserved even after a document is archived.</p>@endif
</section>
@endif
</div>
@endsection
