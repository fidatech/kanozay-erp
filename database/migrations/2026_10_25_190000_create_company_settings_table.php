<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('company_settings', function (Blueprint $table) {
            $table->id();
            $table->string('name', 180);
            $table->string('legal_name', 180)->nullable();
            $table->string('registration_number', 100)->nullable();
            $table->string('tax_number', 100)->nullable();
            $table->string('email', 180)->nullable();
            $table->string('phone', 60)->nullable();
            $table->string('website', 180)->nullable();
            $table->text('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('country', 100)->default('Afghanistan');
            $table->string('timezone', 80)->default('Asia/Kabul');
            $table->string('default_locale', 5)->default('en');
            $table->string('date_format', 20)->default('gregorian');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('company_settings'); }
};
