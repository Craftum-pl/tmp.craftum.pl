<?php
require_once __DIR__ . '/rate_limit.php';

define('UPLOAD_DIR', __DIR__ . '/uploads');
define('MAX_AGE_MINUTES', 60);

define('BLOCKED_EXTENSIONS', [
    'php', 'php3', 'php4', 'php5', 'phtml', 'phar', 'pht', 'phps',
    'cgi', 'pl', 'py', 'sh', 'bash', 'zsh', 'exe', 'bat', 'cmd',
    'asp', 'aspx', 'jsp', 'jspx', 'war', 'ear', 'jar', 'class',
    'shtml', 'stm', 'shtm', 'htaccess', 'htpasswd', 'ini', 'conf'
]);

function generate_uuid(): string {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function format_size(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}

function get_max_upload_size(): string {
    $max_upload = ini_get('upload_max_filesize');
    $max_post = ini_get('post_max_size');
    
    $upload_bytes = return_bytes($max_upload);
    $post_bytes = return_bytes($max_post);
    
    $min_bytes = min($upload_bytes, $post_bytes);
    
    return format_size($min_bytes);
}

function return_bytes(string $val): int {
    $val = trim($val);
    $last = strtolower($val[strlen($val) - 1]);
    $val = (int)$val;
    switch ($last) {
        case 'g': $val *= 1024;
        case 'm': $val *= 1024;
        case 'k': $val *= 1024;
    }
    return $val;
}

function format_time(int $seconds): string {
    if ($seconds < 60) {
        return $seconds . 's';
    }
    $minutes = floor($seconds / 60);
    $secs = $seconds % 60;
    return $minutes . 'm ' . $secs . 's';
}

function validate_file_extension(string $filename): bool {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (empty($ext)) {
        return true;
    }
    return !in_array($ext, BLOCKED_EXTENSIONS, true);
}

function detect_mime_type(string $filepath): string {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $filepath);
    finfo_close($finfo);
    return $mime ?: 'application/octet-stream';
}

function sanitize_filename(string $filename): string {
    $filename = basename($filename);
    $filename = preg_replace('/[^\w\.\-]/', '_', $filename);
    $filename = preg_replace('/\.{2,}/', '.', $filename);
    return $filename;
}

function sanitize_header_value(string $value): string {
    return preg_replace('/[\r\n\"]/', '', $value);
}

function get_file_info(string $uuid): ?array {
    $dir = UPLOAD_DIR . '/' . $uuid;
    $meta_file = $dir . '/meta.json';
    
    if (!is_dir($dir) || !file_exists($meta_file)) {
        return null;
    }
    
    $meta = json_decode(file_get_contents($meta_file), true);
    if (!$meta || isset($meta['deleted'])) {
        return null;
    }
    
    $file_path = $dir . '/' . $meta['filename'];
    if (!file_exists($file_path)) {
        return null;
    }
    
    $age = time() - $meta['created'];
    $remaining = max(0, (MAX_AGE_MINUTES * 60) - $age);
    
    return [
        'uuid' => $uuid,
        'filename' => $meta['filename'],
        'size' => filesize($file_path),
        'mime' => $meta['mime'] ?? 'application/octet-stream',
        'created' => $meta['created'],
        'remaining' => $remaining,
        'expired' => $remaining <= 0
    ];
}

function base_url(): string {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    return $protocol . '://' . $_SERVER['HTTP_HOST'];
}

function delete_file(string $uuid): bool {
    $dir = UPLOAD_DIR . '/' . $uuid;
    $meta_file = $dir . '/meta.json';
    
    if (!file_exists($meta_file)) {
        return false;
    }
    
    $meta = json_decode(file_get_contents($meta_file), true);
    if (!$meta) {
        return false;
    }
    
    $meta['deleted'] = time();
    file_put_contents($meta_file, json_encode($meta));
    
    return true;
}

$message = null;
$message_type = null;
$file_info = null;

function is_curl_request(): bool {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return stripos($ua, 'curl') !== false;
}

function get_client_ip(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'];
        if (strpos($forwarded, ',') !== false) {
            $ip = trim(explode(',', $forwarded)[0]);
        } else {
            $ip = $forwarded;
        }
    }
    return $ip;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    $file = $_FILES['file'];
    
    $error_msg = null;

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error_msg = 'Upload error: ' . $file['error'];
    } elseif ($file['size'] === 0) {
        $error_msg = 'File is empty';
    } elseif (!is_uploaded_file($file['tmp_name'])) {
        $error_msg = 'Invalid upload';
    } elseif (!validate_file_extension($file['name'])) {
        $error_msg = 'File type not allowed';
    }
    
    if ($error_msg) {
        if (is_curl_request()) {
            header('Content-Type: text/plain');
            echo "Error: $error_msg\n";
            exit;
        }
        $message = $error_msg;
        $message_type = 'error';
    } else {
        $client_ip = get_client_ip();
        
        if (is_rate_limited($client_ip)) {
            $error_msg = 'Rate limit exceeded. Maximum ' . RATE_LIMIT_MAX_UPLOADS . ' uploads per ' . RATE_LIMIT_WINDOW_SECONDS . ' seconds.';
        }
        
        if ($error_msg) {
            if (is_curl_request()) {
                header('Content-Type: text/plain');
                echo "Error: $error_msg\n";
                exit;
            }
            http_response_code(429);
            $message = $error_msg;
            $message_type = 'error';
        } else {
            $uuid = generate_uuid();
            $dir = UPLOAD_DIR . '/' . $uuid;
        
            if (mkdir($dir, 0755, true)) {
                $safe_filename = sanitize_filename($file['name']);
                $dest = $dir . '/' . $safe_filename;
                
                if (move_uploaded_file($file['tmp_name'], $dest)) {
                    $detected_mime = detect_mime_type($dest);
                    $meta = [
                        'filename' => $safe_filename,
                        'mime' => $detected_mime,
                        'created' => time()
                    ];
                    file_put_contents($dir . '/meta.json', json_encode($meta));
                    
                    record_upload($client_ip);
                    
                    if (is_curl_request()) {
                        header('Content-Type: text/plain');
                        echo base_url() . '/f/' . $uuid . "\n";
                        exit;
                    }
                    
                    header('Location: /f/' . $uuid);
                    exit;
                } else {
                    rmdir($dir);
                    if (is_curl_request()) {
                        header('Content-Type: text/plain');
                        echo "Error: Failed to save file\n";
                        exit;
                    }
                    $message = 'Failed to save file';
                    $message_type = 'error';
                }
            } else {
                if (is_curl_request()) {
                    header('Content-Type: text/plain');
                    echo "Error: Failed to create upload directory\n";
                    exit;
                }
                $message = 'Failed to create upload directory';
                $message_type = 'error';
            }
        }
    }
}

if (isset($_GET['f'])) {
    $uuid = preg_replace('/[^a-f0-9-]/', '', $_GET['f']);
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete'])) {
        if (delete_file($uuid)) {
            header('Location: /');
            exit;
        } else {
            $message = 'Failed to delete file';
            $message_type = 'error';
        }
    }
    
    $file_info = get_file_info($uuid);
    
    if (!$file_info) {
        http_response_code(404);
        $message = 'File not found or expired';
        $message_type = 'error';
    } elseif ($file_info['expired']) {
        $message = 'File has expired';
        $message_type = 'error';
        $file_info = null;
    }
}

if (isset($_GET['d'])) {
    $uuid = preg_replace('/[^a-f0-9-]/', '', $_GET['d']);
    $file_info = get_file_info($uuid);
    
    if ($file_info && !$file_info['expired']) {
        $file_path = UPLOAD_DIR . '/' . $uuid . '/' . $file_info['filename'];
        $safe_filename = sanitize_header_value($file_info['filename']);
        
        header('Content-Description: File Transfer');
        header('Content-Type: ' . $file_info['mime']);
        header('Content-Disposition: attachment; filename="' . $safe_filename . '"');
        header('Content-Length: ' . $file_info['size']);
        header('Pragma: public');
        header('X-Content-Type-Options: nosniff');
        
        readfile($file_path);
        exit;
    } else {
        http_response_code(404);
        $message = 'File not found or expired';
        $message_type = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>tmp.craftum.pl - Temporary File Sharing</title>
    <link rel="stylesheet" href="/style.css">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
</head>
<body>
    <div class="container">
        <header>
            <h1><a href="/" style="color: inherit; text-decoration: none;">tmp.craftum.pl</a></h1>
            <p class="subtitle">Temporary file sharing - Files deleted after 60 minutes</p>
        </header>

        <?php if ($message): ?>
            <div class="alert alert-<?= $message_type === 'error' ? 'error' : 'success' ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <?php if ($file_info): ?>
            <div class="card">
                <div class="card-header">File Information</div>
                
                <div class="countdown" id="countdown">
                    Expires in: <span id="time-remaining"><?= format_time($file_info['remaining']) ?></span>
                </div>
                
                <div class="progress-bar">
                    <div class="progress-fill" id="progress-fill" style="width: <?= ($file_info['remaining'] / (MAX_AGE_MINUTES * 60)) * 100 ?>%"></div>
                </div>
                
                <div class="file-info">
                    <div class="file-info-item">
                        <span class="file-info-label">Filename</span>
                        <span class="file-info-value"><?= htmlspecialchars($file_info['filename']) ?></span>
                    </div>
                    <div class="file-info-item">
                        <span class="file-info-label">Size</span>
                        <span class="file-info-value"><?= format_size($file_info['size']) ?></span>
                    </div>
                    <div class="file-info-item">
                        <span class="file-info-label">Type</span>
                        <span class="file-info-value"><?= htmlspecialchars($file_info['mime']) ?></span>
                    </div>
                    <div class="file-info-item">
                        <span class="file-info-label">File ID</span>
                        <span class="file-info-value" style="font-size: 0.8rem;"><?= $file_info['uuid'] ?></span>
                    </div>
                </div>
                
                <div class="actions">
                    <a href="/d/<?= $file_info['uuid'] ?>" class="btn btn-success">Download File</a>
                    <button class="btn" onclick="navigator.clipboard.writeText('<?= base_url() ?>/f/<?= $file_info['uuid'] ?>')">Copy Link</button>
                    <a href="/" class="btn btn-primary">Upload New File</a>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Delete this file?');">
                        <input type="hidden" name="delete" value="1">
                        <button type="submit" class="btn btn-danger">Delete</button>
                    </form>
                </div>
            </div>
            
            <script>
            (function() {
                const initialRemaining = <?= $file_info['remaining'] ?>;
                const maxSeconds = <?= MAX_AGE_MINUTES * 60 ?>;
                let remaining = initialRemaining;
                
                function formatTime(seconds) {
                    if (seconds < 60) return seconds + 's';
                    const mins = Math.floor(seconds / 60);
                    const secs = seconds % 60;
                    return mins + 'm ' + secs + 's';
                }
                
                function update() {
                    if (remaining <= 0) {
                        location.reload();
                        return;
                    }
                    
                    document.getElementById('time-remaining').textContent = formatTime(remaining);
                    document.getElementById('progress-fill').style.width = (remaining / maxSeconds * 100) + '%';
                    remaining--;
                }
                
                update();
                setInterval(update, 1000);
            })();
            </script>

        <?php else: ?>
            <div class="card">
                <div class="card-header">Upload a File</div>
                <form action="/" method="post" enctype="multipart/form-data" id="upload-form">
                    <div class="upload-zone" id="drop-zone">
                        <p>Click to select a file or drag & drop</p>
                        <p class="hint">Max file size: <?= get_max_upload_size() ?></p>
                        <input type="file" name="file" id="file-input" required>
                    </div>
                </form>
            </div>

            <div class="card">
                <div class="card-header">How It Works</div>
                <div class="file-info">
                    <div class="file-info-item">
                        <span class="file-info-label">1. Upload</span>
                        <span class="file-info-value">Select or drag any file</span>
                    </div>
                    <div class="file-info-item">
                        <span class="file-info-label">2. Share</span>
                        <span class="file-info-value">Get a unique link</span>
                    </div>
                    <div class="file-info-item">
                        <span class="file-info-label">3. Expire</span>
                        <span class="file-info-value">Auto-deleted after 60 minutes</span>
                    </div>
                    <div class="file-info-item">
                        <span class="file-info-label">Security</span>
                        <span class="file-info-value">None, why are you asking?</span>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">Why?</div>
                <p>i needed a simple temp file hosting where i can curl files from terminal</p>
            </div>

            <div class="card">
                <div class="card-header">API / cURL</div>
                <pre style="background: #000; color: #0f0; padding: 16px; overflow-x: auto; font-size: 0.9rem; border: 3px solid var(--border-color);">curl -X POST -F "file=@yourfile.txt" https://tmp.craftum.pl/</pre>
                <p style="margin-top: 16px; font-weight: 700;">Bash/Zsh alias (add to ~/.bashrc or ~/.zshrc):</p>
                <pre style="background: #000; color: #0f0; padding: 16px; overflow-x: auto; font-size: 0.85rem; border: 3px solid var(--border-color); margin-top: 8px;">tmp() { curl -X POST -F "file=@$1" https://tmp.craftum.pl/ }</pre>
                <p style="margin-top: 8px; opacity: 0.7; font-size: 0.9rem;">Usage: tmp myfile.txt</p>
            </div>
        <?php endif; ?>

        <footer>
            <p>tmp.craftum.pl - Temporary file sharing service</p>
            <p>Files are automatically deleted after 60 minutes. No guarantees, no encryption.</p>
            <p>Crafted by GLM-5 with lack of love and two forests burnt.</p>
            <p>See this piece of crap on <a href="https://github.com/Craftum-pl/tmp.craftum.pl" target="_blank">GitHub</a></p>
        </footer>
    </div>

    <script>
    (function() {
        const dropZone = document.getElementById('drop-zone');
        const fileInput = document.getElementById('file-input');
        const form = document.getElementById('upload-form');
        
        if (!dropZone || !fileInput || !form) return;
        
        let progressBar = null;
        let progressText = null;
        let isUploading = false;
        
        function setUploading(uploading) {
            isUploading = uploading;
            fileInput.disabled = uploading;
            dropZone.style.opacity = uploading ? '0.5' : '1';
            dropZone.style.pointerEvents = uploading ? 'none' : 'auto';
            dropZone.classList.toggle('uploading', uploading);
        }
        
        function createProgressUI() {
            if (progressBar) return;
            
            progressBar = document.createElement('div');
            progressBar.className = 'progress-bar';
            progressBar.style.marginTop = '16px';
            progressBar.innerHTML = '<div class="progress-fill" id="upload-progress" style="width: 0%; transition: width 0.2s;"></div>';
            
            progressText = document.createElement('p');
            progressText.className = 'hint';
            progressText.id = 'upload-progress-text';
            progressText.textContent = 'Uploading... 0%';
            
            dropZone.appendChild(progressBar);
            dropZone.appendChild(progressText);
        }
        
        function uploadFile(file) {
            setUploading(true);
            createProgressUI();
            
            const formData = new FormData();
            formData.append('file', file);
            
            const xhr = new XMLHttpRequest();
            
            xhr.upload.addEventListener('progress', (e) => {
                if (e.lengthComputable) {
                    const percent = Math.round((e.loaded / e.total) * 100);
                    document.getElementById('upload-progress').style.width = percent + '%';
                    document.getElementById('upload-progress-text').textContent = 'Uploading... ' + percent + '%';
                }
            });
            
            xhr.addEventListener('load', () => {
                setUploading(false);
                if (xhr.status === 200) {
                    const response = xhr.responseText;
                    const match = response.match(/\/f\/([a-f0-9-]{36})/);
                    if (match) {
                        window.location.href = '/f/' + match[1];
                    } else {
                        document.getElementById('upload-progress-text').textContent = 'Done!';
                        setTimeout(() => {
                            if (progressBar) progressBar.remove();
                            if (progressText) progressText.remove();
                            progressBar = null;
                            progressText = null;
                        }, 1000);
                    }
                } else if (xhr.status === 413) {
                    const alertHtml = '<div class="alert alert-error" style="margin-top: 16px;">File too large</div>';
                    dropZone.insertAdjacentHTML('afterend', alertHtml);
                    document.getElementById('upload-progress-text').textContent = 'File too large';
                } else if (xhr.status === 429) {
                    const alertHtml = '<div class="alert alert-error" style="margin-top: 16px;">Rate limit exceeded. Please wait before uploading again.</div>';
                    dropZone.insertAdjacentHTML('afterend', alertHtml);
                    document.getElementById('upload-progress-text').textContent = 'Rate limited';
                } else {
                    document.getElementById('upload-progress-text').textContent = 'Upload failed';
                }
            });
            
            xhr.addEventListener('error', () => {
                setUploading(false);
                document.getElementById('upload-progress-text').textContent = 'Upload failed';
            });
            
            xhr.open('POST', '/');
            xhr.send(formData);
        }
        
        dropZone.addEventListener('click', (e) => {
            if (e.target === dropZone || e.target.tagName === 'P') {
                fileInput.click();
            }
        });
        
        dropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropZone.classList.add('dragover');
        });
        
        dropZone.addEventListener('dragleave', () => {
            dropZone.classList.remove('dragover');
        });
        
        dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropZone.classList.remove('dragover');
            
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                uploadFile(files[0]);
            }
        });
        
        fileInput.addEventListener('change', () => {
            if (fileInput.files.length > 0) {
                uploadFile(fileInput.files[0]);
            }
        });
    })();
    </script>
</body>
</html>
