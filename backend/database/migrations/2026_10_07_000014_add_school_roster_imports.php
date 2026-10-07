<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Stable identifiers/import batches follow the institutional enrollment schema.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->string('school_identifier', 64)->nullable()->unique());
        Schema::create('roster_import_batches', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('classroom_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('roster_version');
            $t->json('rows');
            $t->json('errors');
            $t->timestamp('expires_at');
            $t->timestamp('committed_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roster_import_batches');
        Schema::table('users', function (Blueprint $t) {
            $t->dropUnique(['school_identifier']);
            $t->dropColumn('school_identifier');
        });
    }
};
