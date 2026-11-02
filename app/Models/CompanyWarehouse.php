<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class CompanyWarehouse extends Model {
    protected $fillable = ['company_branch_id','code','name','is_active'];
    protected function casts(): array { return ['is_active'=>'boolean']; }
    public function branch(): BelongsTo { return $this->belongsTo(CompanyBranch::class,'company_branch_id'); }
}
