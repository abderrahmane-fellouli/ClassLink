<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index de performance sur les colonnes étrangères interrogées seules
 * (PostgreSQL n'indexe pas les clés étrangères automatiquement, contrairement
 * aux habitudes prises sous SQLite en développement).
 *
 * Aucune contrainte ni colonne modifiée : la migration est purement additive
 * et réversible. `assessment_candidates` n'est pas touché : son unique
 * (assessment_id, student_id) préfixe déjà les recherches.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memberships', fn (Blueprint $t) => $t->index('student_id', 'memberships_student_id_index'));
        Schema::table('school_enrollments', fn (Blueprint $t) => $t->index('student_id', 'school_enrollments_student_id_index'));
        Schema::table('class_delegates', fn (Blueprint $t) => $t->index('student_id', 'class_delegates_student_id_index'));
        Schema::table('module_access_grants', fn (Blueprint $t) => $t->index('student_id', 'module_access_grants_student_id_index'));
        Schema::table('grade_entries', fn (Blueprint $t) => $t->index('student_id', 'grade_entries_student_id_index'));
        Schema::table('submissions', fn (Blueprint $t) => $t->index('student_id', 'submissions_student_id_index'));
        Schema::table('attempts', fn (Blueprint $t) => $t->index('student_id', 'attempts_student_id_index'));
        Schema::table('notifications', fn (Blueprint $t) => $t->index(['user_id', 'created_at'], 'notifications_user_id_created_at_index'));
        Schema::table('audit_logs', fn (Blueprint $t) => $t->index('created_at', 'audit_logs_created_at_index'));
        Schema::table('school_reports', fn (Blueprint $t) => $t->index('reported_by', 'school_reports_reported_by_index'));
    }

    public function down(): void
    {
        // Défensif : SchoolUpgradeTest reconstruit un schéma "legacy" en
        // supprimant/recréant des tables pendant que l'enregistrement de
        // migration de ce fichier reste présent ; l'index peut déjà manquer
        // au moment du rollback. La correspondance se fait par NOM pour ne
        // jamais supprimer un index créé par une autre migration.
        $drops = [
            'memberships' => 'memberships_student_id_index',
            'school_enrollments' => 'school_enrollments_student_id_index',
            'class_delegates' => 'class_delegates_student_id_index',
            'module_access_grants' => 'module_access_grants_student_id_index',
            'grade_entries' => 'grade_entries_student_id_index',
            'submissions' => 'submissions_student_id_index',
            'attempts' => 'attempts_student_id_index',
            'notifications' => 'notifications_user_id_created_at_index',
            'audit_logs' => 'audit_logs_created_at_index',
            'school_reports' => 'school_reports_reported_by_index',
        ];
        foreach ($drops as $table => $index) {
            if (! Schema::hasTable($table) || ! Schema::hasIndex($table, $index)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($index) {
                $t->dropIndex($index);
            });
        }
    }
};