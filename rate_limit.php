<?php
define('RATE_LIMIT_DB', __DIR__ . '/uploads/rate_limits.db');
define('RATE_LIMIT_MAX_UPLOADS', 2);
define('RATE_LIMIT_WINDOW_SECONDS', 60);

function get_rate_limit_db(): SQLite3 {
    static $db = null;
    if ($db === null) {
        $db = new SQLite3(RATE_LIMIT_DB);
        $db->exec('CREATE TABLE IF NOT EXISTS uploads (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip TEXT NOT NULL,
            timestamp INTEGER NOT NULL
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_ip_timestamp ON uploads(ip, timestamp)');
    }
    return $db;
}

function get_upload_count(string $ip): int {
    $db = get_rate_limit_db();
    $cutoff = time() - RATE_LIMIT_WINDOW_SECONDS;
    $stmt = $db->prepare('SELECT COUNT(*) as cnt FROM uploads WHERE ip = :ip AND timestamp > :cutoff');
    $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
    $stmt->bindValue(':cutoff', $cutoff, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return $row ? (int)$row['cnt'] : 0;
}

function is_rate_limited(string $ip): bool {
    return get_upload_count($ip) >= RATE_LIMIT_MAX_UPLOADS;
}

function record_upload(string $ip): void {
    $db = get_rate_limit_db();
    $stmt = $db->prepare('INSERT INTO uploads (ip, timestamp) VALUES (:ip, :timestamp)');
    $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
    $stmt->bindValue(':timestamp', time(), SQLITE3_INTEGER);
    $stmt->execute();
}

function cleanup_rate_limits(): int {
    $db = get_rate_limit_db();
    $cutoff = time() - RATE_LIMIT_WINDOW_SECONDS;
    $stmt = $db->prepare('DELETE FROM uploads WHERE timestamp < :cutoff');
    $stmt->bindValue(':cutoff', $cutoff, SQLITE3_INTEGER);
    $stmt->execute();
    return $db->changes();
}
