<?php 
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // 1️⃣ Add tenant_id as nullable first
        Schema::table('service_types', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
        });

        /**
         * 2️⃣ BACKFILL EXISTING DATA
         * Choose ONE tenant as default (usually first tenant)
         */
        $defaultTenantId = DB::table('tenants')->value('id');

        if ($defaultTenantId) {
            DB::table('service_types')
                ->whereNull('tenant_id')
                ->update(['tenant_id' => $defaultTenantId]);
        }

        // 3️⃣ Make column NOT NULL + add FK
        Schema::table('service_types', function (Blueprint $table) {
            $table->foreign('tenant_id')
                  ->references('id')
                  ->on('tenants')
                  ->cascadeOnDelete();

            $table->unsignedBigInteger('tenant_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('service_types', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
            $table->dropColumn('tenant_id');
        });
    }
};
