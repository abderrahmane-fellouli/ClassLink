<?php

namespace Tests;

use App\Models\User;
use App\Services\SchoolSetupService;
use Illuminate\Support\Facades\DB;

trait SchoolFixture
{
    protected function schoolFixture(): array
    {
        $admin = $this->admin();
        $year = DB::table('academic_years')->insertGetId(['name' => 'Synthetic year', 'starts_on' => now()->subMonth()->toDateString(), 'ends_on' => now()->addYear()->toDateString(), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $setup = app(SchoolSetupService::class);
        $group = $setup->createGroup($admin, ['academic_year_id' => $year, 'official_code' => 'SYN-1', 'name' => 'Synthetic group', 'filiere' => 'Synthetic stream', 'level' => '2']);
        $teachers = [$this->teacher(['role_locked' => true]), $this->teacher(['role_locked' => true])];
        $offerings = [];
        foreach ($teachers as $i => $teacher) {
            $module = DB::table('school_modules')->insertGetId(['code' => 'SYN-M'.$i, 'name' => 'Synthetic module '.$i, 'created_at' => now(), 'updated_at' => now()]);
            $offering = DB::table('module_offerings')->insertGetId(['classroom_id' => $group->id, 'module_id' => $module, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            $setup->assign($admin, $offering, $teacher);
            $offerings[] = $offering;
        }
        $students = User::factory()->count(2)->create(['display_name' => 'Synthetic duplicate name']);
        foreach ($students as $student) {
            $setup->enroll($admin, $group, $student);
        }

        return [$admin, $group, $teachers, $offerings, $students];
    }
}
