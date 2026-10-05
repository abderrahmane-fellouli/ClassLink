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
            $table->string('microsoft_tenant_id', 36)->nullable();
            $table->string('microsoft_object_id', 36)->nullable();
            $table->timestamp('microsoft_verified_at')->nullable();
            $table->string('role_candidate', 16)->nullable()->index();
            $table->unique(['microsoft_tenant_id', 'microsoft_object_id'], 'users_microsoft_identity_unique');
        });
        DB::transaction(function () {
            // Preserve all records and explicitly approved roles. Prior automatic
            // teacher detection is not approval evidence; require admin review.
            $unapproved = DB::table('users')->where('role', 'teacher')->where('role_locked', false);
            $ids = $unapproved->pluck('id');
            DB::table('personal_access_tokens')->where('tokenable_type', 'App\\Models\\User')
                ->whereIn('tokenable_id', $ids)->delete();
            $unapproved->update(['role' => 'pending', 'role_candidate' => 'teacher']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_microsoft_identity_unique');
            $table->dropIndex(['role_candidate']);
            $table->dropColumn(['microsoft_tenant_id', 'microsoft_object_id', 'microsoft_verified_at', 'role_candidate']);
        });
        // Never restore auto-granted teacher permissions or revoked tokens.
    }
};
