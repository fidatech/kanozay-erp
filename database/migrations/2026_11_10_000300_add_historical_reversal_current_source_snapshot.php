<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up():void
    {
        Schema::table('finance_historical_source_reversal_requests',
            function(Blueprint $t){
                $t->char('current_source_fingerprint',64)
                    ->nullable()->after('source_fingerprint');
            });
    }
    public function down():void
    {
        Schema::table('finance_historical_source_reversal_requests',
            fn(Blueprint $t)=>$t->dropColumn('current_source_fingerprint'));
    }
};
