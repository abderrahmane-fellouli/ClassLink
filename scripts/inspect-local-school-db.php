<?php

// Read-only inventory: no Laravel/default .env connection is bootstrapped.
if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('CLI only.');
}
$path = realpath($argv[1] ?? '');
if (! $path || ! is_file($path) || str_starts_with($path, '\\\\') || str_starts_with($path, '//')) {
    throw new RuntimeException('An existing local SQLite file is required.');
}
$db = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('PRAGMA query_only = ON');
$tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
$result = ['database' => $path, 'tables' => $tables, 'counts' => []];
$countsOnly = in_array('--counts-only', $argv, true);
foreach ($tables as $table) {
    $result['counts'][$table] = (int) $db->query('SELECT count(*) FROM "'.str_replace('"', '""', $table).'"')->fetchColumn();
}
if (in_array('users', $tables, true)) {
    $result['roles'] = $db->query('SELECT role,count(*) AS count FROM users GROUP BY role')->fetchAll(PDO::FETCH_ASSOC);
    if (! $countsOnly) {
        $result['admins'] = $db->query("SELECT id,display_name,email,role_locked,is_active FROM users WHERE role='admin'")->fetchAll(PDO::FETCH_ASSOC);
        $result['accounts'] = $db->query('SELECT id,display_name,email,role,role_locked,is_active FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }
}
foreach (['classrooms', 'academic_years', 'school_modules', 'module_offerings', 'teaching_assignments', 'school_enrollments'] as $table) {
    if (! $countsOnly && in_array($table, $tables, true)) {
        $result[$table] = $db->query('SELECT * FROM "'.$table.'"')->fetchAll(PDO::FETCH_ASSOC);
    }
}
if (! $countsOnly && in_array('migrations', $tables, true)) {
    $result['migrations'] = $db->query('SELECT migration FROM migrations ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
}
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
