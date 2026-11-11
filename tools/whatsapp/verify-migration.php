<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
umask(0077);
ini_set('display_errors', '0');
ini_set('log_errors', '0');
set_error_handler(static function (): never { throw new ErrorException('Migration verification failed.'); });
try {
    if (!is_file('/seed/application.sqlite') || !is_dir('/smoke')) { throw new RuntimeException(); }
    $backup = new Broadcast\Infrastructure\Runtime\SqliteBackup();
    $copy = $backup->create('/seed/application.sqlite', '/smoke/migration-check');
    $db = new PDO('sqlite:' . $copy, options:[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $before = [];
    foreach ($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT IN ('migrations','sqlite_sequence') ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $columns = array_column($db->query('PRAGMA table_info("' . str_replace('"','""',$table) . '")')->fetchAll(PDO::FETCH_ASSOC), 'name');
        $quoted = array_map(static fn($name) => '"' . str_replace('"','""',$name) . '"', $columns);
        $sql = 'SELECT ' . implode(',', $quoted) . ' FROM "' . str_replace('"','""',$table) . '" ORDER BY ' . implode(',', $quoted);
        $before[$table] = [$sql, hash('sha256', serialize($db->query($sql)->fetchAll(PDO::FETCH_ASSOC)))];
    }
    $store = new Broadcast\Infrastructure\SqliteStore($copy, directoryEnabled:true);
    $store->migrate();
    foreach ($before as [$sql,$hash]) {
        if (!hash_equals($hash,hash('sha256',serialize($db->query($sql)->fetchAll(PDO::FETCH_ASSOC))))) { throw new RuntimeException(); }
    }
    $count=(int)$db->query('SELECT COUNT(*) FROM migrations')->fetchColumn();
    $store->migrate();
    if ($count !== (int)$db->query('SELECT COUNT(*) FROM migrations')->fetchColumn() || $db->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') { throw new RuntimeException(); }
    $store->close(); $db=null;
    $restored='/smoke/migration-check/restored-'.bin2hex(random_bytes(8)).'.sqlite';
    $backup->restore($copy,$restored);
    $restoredDb=new PDO('sqlite:'.$restored);
    foreach ($before as [$sql,$hash]) {
        if (!hash_equals($hash,hash('sha256',serialize($restoredDb->query($sql)->fetchAll(PDO::FETCH_ASSOC))))) { throw new RuntimeException(); }
    }
    echo 'MIGRATION_VERIFIED unchanged_tables='.count($before).' repeated=1 restored=1 source_readonly=1'.PHP_EOL;
} catch (Throwable $failure) {
    echo 'migration_verification_failed; class='.$failure::class.PHP_EOL;
    exit(1);
}
