<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->unique()->constrained('customers')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Never erase the only distinction between portal users and staff.
        if (DB::table('users')->whereNotNull('customer_id')->exists()) {
            throw new RuntimeException('Remove or archive hotel portal accounts before rolling back hotel authorization.');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->dropUnique(['customer_id']);
            $table->dropColumn('customer_id');
        });
    }
};
