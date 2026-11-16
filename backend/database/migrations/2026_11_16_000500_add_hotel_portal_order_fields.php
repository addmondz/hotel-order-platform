<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('source', 16)->default('staff');
            $table->text('portal_notes')->nullable();
            $table->index(['customer_id', 'status', 'issued_at']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['customer_id', 'status', 'issued_at']);
            $table->dropColumn(['source', 'portal_notes']);
        });
    }
};
