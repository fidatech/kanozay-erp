<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
class DocumentSequence extends Model {
    protected $fillable=['document_type','prefix','next_number','padding','is_active','updated_by'];
    protected $casts=['is_active'=>'boolean','next_number'=>'integer','padding'=>'integer'];
    public static function issue(string $type): string {
        return DB::transaction(function () use ($type) {
            $sequence=self::where('document_type',$type)->lockForUpdate()->firstOrFail();
            if(!$sequence->is_active) throw new \RuntimeException('Document sequence is disabled.');
            $number=$sequence->prefix.str_pad((string)$sequence->next_number,$sequence->padding,'0',STR_PAD_LEFT);
            $sequence->increment('next_number'); return $number;
        });
    }
}
