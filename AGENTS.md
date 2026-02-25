# AGENTS.md - Agent Guidelines for tmp.craftum.pl

## Project Overview

- **Project name**: tmp.craftum.pl
- **Type**: Simple PHP temporary file hosting service
- **Live URL**: https://tmp.craftum.pl/
- **Stack**: Plain PHP (no framework), SQLite for rate limiting, vanilla JavaScript

## Directory Structure

```
/home/artur9010/dev/tmp.craftum.pl/
├── index.php      # Main application (upload, file view, download)
├── rate_limit.php # Rate limiting logic with SQLite
├── cron.php       # Cleanup cron job for expired files/rate limits
├── style.css      # All styles
├── uploads/       # Uploaded files storage
└── README.md      # Brief project description
```

## Build/Lint/Test Commands

This is a plain PHP project with **no automated tests, linting, or build system**.

### Running the Application

```bash
# Using PHP's built-in server (for local development)
php -S localhost:8080

# Check PHP syntax
php -l index.php
php -l rate_limit.php
php -l cron.php
```

### Testing

- **No test framework exists** - tests would need to be added
- Manual testing via curl:
  ```bash
  # Upload a file
  curl -X POST -F "file=@test.txt" https://tmp.craftum.pl/
  
  # Test rate limiting (after 2 uploads, should get 429)
  curl -X POST -F "file=@test.txt" https://tmp.craftum.pl/
  ```

### Linting

- No PHP linter configured (could use PHP_CodeSniffer)
- No JS linter configured
- No CSS linter configured

---

## Code Style Guidelines

### General Principles

1. **Keep it simple** - This is a minimal file hosting service
2. **No over-engineering** - Avoid adding unnecessary complexity
3. **Security-first** - Validate all inputs, sanitize outputs

### PHP Style

#### Naming Conventions
- Functions: `snake_case` (e.g., `get_client_ip()`, `is_rate_limited()`)
- Constants: `UPPER_SNAKE_CASE` (e.g., `RATE_LIMIT_MAX_UPLOADS`)
- Variables: `$snake_case` (e.g., `$client_ip`, `$message_type`)

#### File Organization
- One class/function per file not required for this small project
- Put helper functions at top of file
- Main logic follows the "everything in index.php" pattern
- Include guards: `require_once __DIR__ . '/file.php';`

#### Error Handling
- Return `null` or `false` on error instead of throwing exceptions
- Use `http_response_code()` for HTTP errors (e.g., 404, 429)
- Log errors appropriately but don't expose sensitive info
- Always escape output with `htmlspecialchars()` to prevent XSS

#### Code Examples

**Defining constants:**
```php
define('UPLOAD_DIR', __DIR__ . '/uploads');
define('MAX_AGE_MINUTES', 60);
```

**Function definition:**
```php
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
```

**SQLite usage:**
```php
function get_rate_limit_db(): SQLite3 {
    static $db = null;
    if ($db === null) {
        $db = new SQLite3(RATE_LIMIT_DB);
        $db->exec('CREATE TABLE IF NOT EXISTS uploads (...)');
    }
    return $db;
}
```

### JavaScript Style

- Use **vanilla JavaScript** (no frameworks)
- Wrap in IIFE: `(function() { ... })();`
- Use `const` and `let`, never `var`
- Use template literals for string interpolation
- Use `addEventListener` instead of inline handlers
- Use XHR (`XMLHttpRequest`) for AJAX (not fetch - keep compatibility)

#### Code Example
```javascript
(function() {
    const dropZone = document.getElementById('drop-zone');
    const fileInput = document.getElementById('file-input');
    
    function uploadFile(file) {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', '/');
        xhr.send(formData);
    }
    
    dropZone.addEventListener('click', (e) => {
        fileInput.click();
    });
})();
```

### CSS Style

- Use CSS custom properties (variables) for theming
- Follow BEM-like naming for classes (e.g., `.card-header`, `.alert-error`)
- Keep styles in `style.css` only
- Use flexbox for layout
- Mobile-first responsive design

### Database

- **SQLite** for rate limiting data
- Database file: `uploads/rate_limits.db`
- Tables created on first use with `CREATE TABLE IF NOT EXISTS`

---

## Common Tasks

### Adding a New Feature

1. Decide if it belongs in `index.php` or a separate file
2. Add PHP logic at top of file, HTML/JS at bottom
3. Test with both browser and curl
4. Ensure rate limiting applies if applicable

### Modifying Rate Limiting

- Edit `rate_limit.php`
- Constants to change:
  - `RATE_LIMIT_MAX_UPLOADS` - max uploads per window
  - `RATE_LIMIT_WINDOW_SECONDS` - time window in seconds

### Adding Error Handling

1. Set `$message` and `$message_type` variables
2. Use template to render:
   ```php
   <?php if ($message): ?>
       <div class="alert alert-<?= $message_type ?>">
           <?= htmlspecialchars($message) ?>
       </div>
   <?php endif; ?>
   ```

---

## Security Considerations

1. **File validation** - Check extensions, MIME types
2. **Input sanitization** - Use `preg_replace` for UUIDs, `htmlspecialchars` for output
3. **Rate limiting** - Prevent abuse
4. **No authentication** - Intentionally simple, no user accounts

---

## Notes for Agents

- This is a small project - don't introduce heavy dependencies
- Keep backward compatibility with curl CLI users
- Test uploads via both browser and curl
- The project intentionally lacks tests - consider adding PHPUnit if extending
- File expiry is handled by the cron job in `cron.php`
