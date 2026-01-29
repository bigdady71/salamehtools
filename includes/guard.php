<?php
require_once __DIR__ . '/auth.php';

if (!function_exists('require_login')) {
    function require_login(): void {
      if (!auth_user()) {
        // Prevent infinite redirect loop - use cookie since session may be broken
        $redirectCount = isset($_COOKIE['_redir_count']) ? (int)$_COOKIE['_redir_count'] : 0;
        if ($redirectCount >= 3) {
            // Clear the counter cookie
            setcookie('_redir_count', '', time() - 3600, '/');
            http_response_code(500);
            // Show diagnostic info
            $sessionId = session_id();
            $sessionStatus = session_status();
            $savePath = session_save_path();
            echo "<h2>Session Error</h2>";
            echo "<p>Unable to maintain login session. Debug info:</p>";
            echo "<ul>";
            echo "<li>Session ID: " . ($sessionId ?: '(none)') . "</li>";
            echo "<li>Session Status: {$sessionStatus} (1=disabled, 2=active)</li>";
            echo "<li>Save Path: " . ($savePath ?: '(default)') . "</li>";
            echo "<li>Session Data: " . (empty($_SESSION) ? '(empty)' : 'Has data') . "</li>";
            echo "</ul>";
            echo "<p><a href='/pages/login.php'>Clear cookies and try again</a></p>";
            exit;
        }
        setcookie('_redir_count', (string)($redirectCount + 1), time() + 60, '/');

        $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
        $pos = strpos($scriptPath, '/pages/');
        $base = $pos === false ? '' : substr($scriptPath, 0, $pos);
        header('Location: ' . $base . '/pages/login.php');
        exit;
      }
      // Clear redirect counter on successful auth
      if (isset($_COOKIE['_redir_count'])) {
          setcookie('_redir_count', '', time() - 3600, '/');
      }
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string {
      if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
      }
      return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string {
      $token = csrf_token();
      return '<input type="hidden" name="_csrf" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }
}

if (!function_exists('verify_csrf')) {
    function verify_csrf(string $token): bool {
      if ($token === '' || !isset($_SESSION['csrf_token'])) {
        return false;
      }
      return hash_equals($_SESSION['csrf_token'], $token);
    }
}
