<?php
define('UPLOAD_DIR', __DIR__ . '/uploads');
define('MAX_AGE_MINUTES', 60);

require_once __DIR__ . '/rate_limit.php';

$cron_key = getenv('CRON_KEY') ?: null;

function cleanup_expired_files(): array {
    $deleted = [];
    $errors = [];
    $now = time();
    $max_age_seconds = MAX_AGE_MINUTES * 60;
    
    if (!is_dir(UPLOAD_DIR)) {
        return ['deleted' => [], 'errors' => ['Upload directory does not exist']];
    }
    
    $dirs = scandir(UPLOAD_DIR);
    
    foreach ($dirs as $dir) {
        if ($dir === '.' || $dir === '..') {
            continue;
        }
        
        $dir_path = UPLOAD_DIR . '/' . $dir;
        
        if (!is_dir($dir_path)) {
            continue;
        }
        
        $meta_file = $dir_path . '/meta.json';
        $should_delete = false;
        
        if (file_exists($meta_file)) {
            $meta = json_decode(file_get_contents($meta_file), true);
            if ($meta && isset($meta['created'])) {
                $age = $now - $meta['created'];
                if ($age > $max_age_seconds) {
                    $should_delete = true;
                }
            }
        } else {
            $dir_mtime = filemtime($dir_path);
            if ($dir_mtime && ($now - $dir_mtime) > $max_age_seconds) {
                $should_delete = true;
            }
        }
        
        if ($should_delete) {
            if (delete_directory($dir_path)) {
                $deleted[] = $dir;
            } else {
                $errors[] = 'Failed to delete: ' . $dir;
            }
        }
    }
    
    return ['deleted' => $deleted, 'errors' => $errors];
}

function delete_directory(string $dir): bool {
    if (!is_dir($dir)) {
        return false;
    }
    
    $files = scandir($dir);
    
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        
        $path = $dir . '/' . $file;
        
        if (is_dir($path)) {
            delete_directory($path);
        } else {
            unlink($path);
        }
    }
    
    return rmdir($dir);
}

if (php_sapi_name() === 'cli') {
    $result = cleanup_expired_files();
    $rate_limit_cleaned = cleanup_rate_limits();
    
    echo "Cleanup completed at " . date('Y-m-d H:i:s') . "\n";
    echo "Deleted: " . count($result['deleted']) . " directories\n";
    echo "Rate limit entries cleaned: $rate_limit_cleaned\n";
    
    if (!empty($result['deleted'])) {
        foreach ($result['deleted'] as $dir) {
            echo "  - $dir\n";
        }
    }
    
    if (!empty($result['errors'])) {
        echo "Errors:\n";
        foreach ($result['errors'] as $error) {
            echo "  - $error\n";
        }
    }
} else {
    header('Content-Type: application/json');
    
    if ($cron_key === null) {
        http_response_code(403);
        echo json_encode(['error' => 'Cron key not configured']);
        exit;
    }
    
    if (!isset($_GET['key']) || !hash_equals($cron_key, $_GET['key'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    
    $result = cleanup_expired_files();
    $rate_limit_cleaned = cleanup_rate_limits();
    
    echo json_encode([
        'timestamp' => date('Y-m-d H:i:s'),
        'deleted_count' => count($result['deleted']),
        'deleted' => $result['deleted'],
        'errors' => $result['errors'],
        'rate_limit_cleaned' => $rate_limit_cleaned
    ], JSON_PRETTY_PRINT);
}
