<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('oops_core_modules', function (Blueprint $table) {
            $table->string('slug')->primary()->comment('Unique module identifier (ex: user, store, status)');
            $table->boolean('enabled')->default(true)->comment('Is the module enabled?');
            $table->string('version')->default('1.0.0')->comment('Module version');
            $table->enum('source', ['cli', 'store', 'api'])->default('cli')->comment('How was this module installed?');
            $table->json('meta')->nullable()->comment('Module metadata (extensible)');
            $table->timestamp('installed_at')->nullable()->default(DB::raw('CURRENT_TIMESTAMP'))->comment('Installation timestamp');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('oops_core_modules');
    }
};
