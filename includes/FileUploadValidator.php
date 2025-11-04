<?php
/**
 * FileUploadValidator - Secure file upload validation with magic byte checking
 *
 * Features:
 * - File size validation
 * - Extension whitelist
 * - MIME type validation
 * - Magic byte verification
 * - Filename sanitization
 * - Virus scanning (if ClamAV available)
 *
 * Usage:
 *   $validator = new FileUploadValidator();
 *   $result = $validator->validate($_FILES['upload'], ['xlsx', 'xls']);
 *   if (!$result['valid']) {
 *       echo implode(', ', $result['errors']);
 *   } else {
 *       $safeName = $result['safe_name'];
 *   }
 */

class FileUploadValidator
{
    private const MAX_SIZE = 10 * 1024 * 1024; // 10MB default

    // MIME types mapped to extensions
    private const ALLOWED_MIMES = [
        'xlsx' => [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip', // XLSX is a ZIP file
        ],
        'xls' => [
            'application/vnd.ms-excel',
            'application/x-msexcel',
        ],
        'csv' => [
            'text/csv',
            'text/plain',
            'application/csv',
        ],
        'pdf' => [
            'application/pdf',
        ],
        'jpg' => [
            'image/jpeg',
            'image/jpg',
        ],
        'jpeg' => [
            'image/jpeg',
            'image/jpg',
        ],
        'png' => [
            'image/png',
        ],
        'gif' => [
            'image/gif',
        ],
    ];

    // Magic bytes for file type verification
    private const MAGIC_BYTES = [
        'xlsx' => ['504b0304'], // ZIP header (XLSX is ZIP)
        'xls' => ['d0cf11e0'], // OLE2 header
        'pdf' => ['255044462d'], // %PDF-
        'jpg' => ['ffd8ff'], // JPEG
        'jpeg' => ['ffd8ff'], // JPEG
        'png' => ['89504e47'], // PNG signature
        'gif' => ['474946383761', '474946383961'], // GIF87a, GIF89a
    ];

    private int $maxSize;
    private bool $checkMagicBytes;
    private ?Logger $logger;

    /**
     * @param int $maxSize Maximum file size in bytes
     * @param bool $checkMagicBytes Enable magic byte validation
     * @param Logger|null $logger Logger instance
     */
    public function __construct(int $maxSize = self::MAX_SIZE, bool $checkMagicBytes = true, ?Logger $logger = null)
    {
        $this->maxSize = $maxSize;
        $this->checkMagicBytes = $checkMagicBytes;
        $this->logger = $logger;
    }

    /**
     * Validate uploaded file
     *
     * @param array $file $_FILES array element
     * @param array $allowedExtensions Whitelist of allowed extensions
     * @return array ['valid' => bool, 'errors' => array, 'safe_name' => string]
     */
    public function validate(array $file, array $allowedExtensions = ['xlsx', 'xls']): array
    {
        $errors = [];

        // Check if file was uploaded
        if (!isset($file['error']) || is_array($file['error'])) {
            $errors[] = 'Invalid file upload';
            return ['valid' => false, 'errors' => $errors, 'safe_name' => null];
        }

        // Check upload errors
        switch ($file['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $errors[] = 'File too large (max ' . $this->formatBytes($this->maxSize) . ')';
                break;
            case UPLOAD_ERR_PARTIAL:
                $errors[] = 'File upload incomplete';
                break;
            case UPLOAD_ERR_NO_FILE:
                $errors[] = 'No file uploaded';
                break;
            default:
                $errors[] = 'File upload error';
                break;
        }

        if (!empty($errors)) {
            return ['valid' => false, 'errors' => $errors, 'safe_name' => null];
        }

        // Check file exists
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            $errors[] = 'Invalid upload: file not found';
            return ['valid' => false, 'errors' => $errors, 'safe_name' => null];
        }

        // Check file size
        if ($file['size'] > $this->maxSize) {
            $errors[] = 'File too large (max ' . $this->formatBytes($this->maxSize) . ', got ' . $this->formatBytes($file['size']) . ')';
        }

        // Check minimum file size (prevent empty files)
        if ($file['size'] < 1) {
            $errors[] = 'File is empty';
        }

        // Get file extension
        $originalName = $file['name'] ?? 'upload';
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        // Check extension whitelist
        if (!in_array($ext, $allowedExtensions)) {
            $errors[] = 'Invalid file type. Allowed: ' . implode(', ', $allowedExtensions);
        }

        // Check MIME type
        if (!$this->validateMimeType($file['tmp_name'], $ext)) {
            $errors[] = 'File content does not match extension (possible file type spoofing)';
        }

        // Check magic bytes
        if ($this->checkMagicBytes && !$this->validateMagicBytes($file['tmp_name'], $ext)) {
            $errors[] = 'File signature verification failed (file may be corrupted or malicious)';
        }

        // Check for dangerous content in filenames
        if ($this->containsDangerousPatterns($originalName)) {
            $errors[] = 'Filename contains dangerous patterns';
        }

        // Sanitize filename
        $safeName = $this->sanitizeFilename($originalName);

        // Log validation if logger available
        if ($this->logger) {
            if (!empty($errors)) {
                $this->logger->warning('File upload validation failed', [
                    'original_name' => $originalName,
                    'size' => $file['size'],
                    'errors' => $errors,
                ]);
            } else {
                $this->logger->info('File upload validated', [
                    'original_name' => $originalName,
                    'safe_name' => $safeName,
                    'size' => $file['size'],
                    'type' => $ext,
                ]);
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'safe_name' => $safeName,
            'original_name' => $originalName,
            'extension' => $ext,
            'size' => $file['size'],
        ];
    }

    /**
     * Validate MIME type using finfo
     *
     * @param string $filePath Path to uploaded file
     * @param string $extension Expected extension
     * @return bool
     */
    private function validateMimeType(string $filePath, string $extension): bool
    {
        if (!isset(self::ALLOWED_MIMES[$extension])) {
            return false;
        }

        if (!function_exists('finfo_open')) {
            // finfo not available, skip check
            return true;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return true; // Can't validate, allow
        }

        $mimeType = finfo_file($finfo, $filePath);
        finfo_close($finfo);

        if ($mimeType === false) {
            return true; // Can't validate, allow
        }

        $allowedMimes = self::ALLOWED_MIMES[$extension];
        return in_array($mimeType, $allowedMimes);
    }

    /**
     * Validate magic bytes (file signature)
     *
     * @param string $filePath Path to uploaded file
     * @param string $extension Expected extension
     * @return bool
     */
    private function validateMagicBytes(string $filePath, string $extension): bool
    {
        if (!isset(self::MAGIC_BYTES[$extension])) {
            return true; // No signature defined, allow
        }

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            return true; // Can't read, allow
        }

        // Read first 16 bytes
        $header = fread($handle, 16);
        fclose($handle);

        if ($header === false) {
            return true; // Can't read, allow
        }

        $headerHex = bin2hex($header);
        $expectedSignatures = self::MAGIC_BYTES[$extension];

        foreach ($expectedSignatures as $signature) {
            if (str_starts_with($headerHex, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check for dangerous patterns in filename
     *
     * @param string $filename Filename to check
     * @return bool True if dangerous patterns found
     */
    private function containsDangerousPatterns(string $filename): bool
    {
        // Check for path traversal
        if (strpos($filename, '..') !== false) {
            return true;
        }

        // Check for null bytes
        if (strpos($filename, "\0") !== false) {
            return true;
        }

        // Check for dangerous extensions hidden with multiple extensions
        $dangerousExtensions = ['php', 'phtml', 'php3', 'php4', 'php5', 'phar', 'exe', 'sh', 'bat', 'cmd'];
        $nameLower = strtolower($filename);

        foreach ($dangerousExtensions as $dangerousExt) {
            if (strpos($nameLower, '.' . $dangerousExt) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sanitize filename
     *
     * @param string $filename Original filename
     * @return string Sanitized filename
     */
    private function sanitizeFilename(string $filename): string
    {
        // Get extension
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $name = pathinfo($filename, PATHINFO_FILENAME);

        // Remove special characters, keep only alphanumeric, dash, underscore
        $name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);

        // Remove multiple underscores
        $name = preg_replace('/_+/', '_', $name);

        // Trim underscores from start/end
        $name = trim($name, '_');

        // Ensure name is not empty
        if (empty($name)) {
            $name = 'file_' . time();
        }

        // Limit length
        $name = substr($name, 0, 100);

        return $name . '.' . $ext;
    }

    /**
     * Format bytes to human-readable size
     *
     * @param int $bytes Bytes
     * @return string Formatted size
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Check if ClamAV is available
     *
     * @return bool
     */
    public static function hasClamAV(): bool
    {
        exec('which clamscan 2>&1', $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * Scan file with ClamAV (if available)
     *
     * @param string $filePath Path to file
     * @return array ['clean' => bool, 'output' => string]
     */
    public function scanWithClamAV(string $filePath): array
    {
        if (!self::hasClamAV()) {
            return ['clean' => true, 'output' => 'ClamAV not available'];
        }

        $escapedPath = escapeshellarg($filePath);
        exec("clamscan --no-summary $escapedPath 2>&1", $output, $returnCode);

        $clean = $returnCode === 0;
        $outputStr = implode("\n", $output);

        if ($this->logger) {
            if (!$clean) {
                $this->logger->error('ClamAV virus detected', [
                    'file' => $filePath,
                    'output' => $outputStr,
                ]);
            }
        }

        return ['clean' => $clean, 'output' => $outputStr];
    }
}
