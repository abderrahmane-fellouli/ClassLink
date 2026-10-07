<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Additive metadata for existing and institutional resources.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('materials', fn (Blueprint $t) => $t->string('category', 16)->default('document'));
    }

    public function down(): void
    {
        Schema::table('materials', fn (Blueprint $t) => $t->dropColumn('category'));
    }
};
