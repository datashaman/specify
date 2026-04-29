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
        Schema::create('approval_policies', function (Blueprint $table) {
            $table->id();
            $table->string('scope_type');
            $table->unsignedBigInteger('scope_id');
            $table->unsignedInteger('required_approvals')->default(0);
            $table->boolean('allow_self_approval')->default(false);
            $table->boolean('auto_approve')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['scope_type', 'scope_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('approval_policies');
    }
};
