<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Apply database delegate-slot enforcement after creating the base table.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_assignment_requests', function (Blueprint $t) {
            $t->unsignedBigInteger('offering_id')->nullable()->change();
            $t->string('requested_group_code', 64)->nullable();
            $t->string('requested_module_code', 64)->nullable();
            $t->string('requested_year', 32)->nullable();
            $t->string('request_key', 64)->nullable()->unique();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE class_delegates ADD CONSTRAINT class_delegates_two_slots CHECK (active_slot IS NULL OR active_slot IN (1, 2))');
        } elseif (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $operation) {
                DB::statement('CREATE TRIGGER class_delegates_slot_'.strtolower($operation)." BEFORE $operation ON class_delegates WHEN NEW.active_slot IS NOT NULL AND NEW.active_slot NOT IN (1,2) BEGIN SELECT RAISE(ABORT, 'Only two delegate slots are permitted'); END");
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE class_delegates DROP CONSTRAINT IF EXISTS class_delegates_two_slots');
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS class_delegates_slot_insert');
            DB::statement('DROP TRIGGER IF EXISTS class_delegates_slot_update');
        }
        Schema::table('school_assignment_requests', function (Blueprint $t) {
            $t->dropUnique(['request_key']);
            $t->dropColumn(['requested_group_code', 'requested_module_code', 'requested_year', 'request_key']);
        });
        // Keep offering_id nullable rather than deleting unmapped institutional reports.
    }
};
