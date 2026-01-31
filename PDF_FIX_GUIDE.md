# PDF Generation Fix Guide

## Problem
PDF downloads are working locally but not on the production Hostinger server.

## Why It Fails Online (Hostinger-specific)

Hostinger hosting has several limitations that affect Dompdf PDF generation:

1. **Restricted Temp Directory** - Dompdf cannot write to `/tmp` reliably
2. **File System Permissions** - `/storage/invoices` may not have write permissions
3. **Memory/Execution Limits** - Large PDFs timeout or run out of memory
4. **Disabled PHP Functions** - Some servers disable required functions

## Solution: Configure Dompdf Temp Directory

### Step 1: Update InvoicePDF.php Dompdf Options

Find this section in `includes/InvoicePDF.php` (around line 625):

```php
$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('isHtml5ParserEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isFontSubsettingEnabled', true);
$options->set('chroot', __DIR__ . '/..');
```

**Add this line before `new Dompdf($options)`:**

```php
// Use writable storage directory for temp files instead of system /tmp
$tempDir = __DIR__ . '/../storage/temp_pdf';
if (!is_dir($tempDir)) {
    @mkdir($tempDir, 0755, true);
}
$options->set('tempDir', $tempDir);
```

### Step 2: Create Required Directories

Create these directories on your hosting:
```bash
# Via SSH or File Manager
mkdir -p /home/username/public_html/storage/temp_pdf
chmod 755 /home/username/public_html/storage/temp_pdf
chmod 755 /home/username/public_html/storage
chmod 755 /home/username/public_html/storage/invoices
```

### Step 3: Update Memory and Timeout in .user.ini

Ensure `.user.ini` has these values (already added):

```ini
memory_limit = 512M
max_execution_time = 600
```

If PDFs still fail, increase to:
```ini
memory_limit = 1024M
max_execution_time = 1200
```

## Complete Fixed Code

Replace the `generatePDF` method in `includes/InvoicePDF.php` with:

```php
public function generatePDF(array $invoice): string
{
    $html = $this->generateHTML($invoice);

    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('isFontSubsettingEnabled', true);
    $options->set('chroot', __DIR__ . '/..');
    
    // HOSTINGER FIX: Use storage directory for temp files
    $tempDir = __DIR__ . '/../storage/temp_pdf';
    if (!is_dir($tempDir)) {
        @mkdir($tempDir, 0755, true);
    }
    $options->set('tempDir', $tempDir);

    $dompdf = new Dompdf($options);

    // Load Arabic font
    $fontDir = __DIR__ . '/../fonts';
    $arabicFontPath = $fontDir . '/NotoSansArabic-Regular.ttf';

    if (file_exists($arabicFontPath)) {
        $dompdf->getFontMetrics()->registerFont(
            ['family' => 'NotoSansArabic', 'style' => 'normal', 'weight' => 'normal'],
            $arabicFontPath
        );
    }

    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    return $dompdf->output();
}
```

## Testing

1. **Locally** - Test that PDF still works:
   - Go to Customer Portal → Invoices
   - Download a PDF
   - Should work as before

2. **On Production** - Test after uploading:
   - Login at `https://salameh-tools-wholesale.com/pages/login.php`
   - Navigate to Customer Portal (if customer account)
   - Or via admin: Admin → Invoices → Download PDF
   - Should now generate without errors

## Troubleshooting

### If PDF still doesn't work:

1. **Check File Permissions**
   ```bash
   # SSH into hosting
   ls -la storage/
   # Should show: drwxr-xr-x (755)
   ```

2. **Test with Diagnostic Script**
   ```
   Upload pdf_diagnostic.php to root
   Visit: https://salameh-tools-wholesale.com/pdf_diagnostic.php
   Check "Directory Permissions" section
   ```

3. **Check Error Logs**
   ```bash
   # SSH: check PHP error log
   tail -f error_log
   
   # Or in app logs
   cat storage/logs/php_errors.log
   ```

4. **Verify Dompdf Installation**
   ```bash
   # Check vendor folder
   ls vendor/dompdf/dompdf/
   # Should exist and have files
   ```

5. **Memory Issue?**
   - Edit `.user.ini` to increase memory:
   ```ini
   memory_limit = 2048M
   ```

6. **Contact Hostinger Support** if:
   - You cannot create temp directories
   - `/tmp` is inaccessible
   - PHP functions are disabled
   - Permission issues cannot be resolved

## Additional Optimizations

For better performance, add this caching to `InvoicePDF.php`:

```php
// At the top of the class
private static $fontCache = [];

// In generatePDF method, add caching:
$cacheKey = md5($arabicFontPath);
if (!isset(self::$fontCache[$cacheKey]) && file_exists($arabicFontPath)) {
    $dompdf->getFontMetrics()->registerFont(
        ['family' => 'NotoSansArabic', 'style' => 'normal', 'weight' => 'normal'],
        $arabicFontPath
    );
    self::$fontCache[$cacheKey] = true;
}
```

---

## Quick Checklist

- [ ] Create `/storage/temp_pdf` directory
- [ ] Set permissions to 755 on all storage directories
- [ ] Update `InvoicePDF.php` with tempDir setting
- [ ] Verify `.user.ini` has proper settings
- [ ] Test PDF download locally
- [ ] Upload changes to production
- [ ] Test PDF download on production
- [ ] Run `pdf_diagnostic.php` if issues persist
- [ ] Check error logs if still failing

---

**Last Updated:** 2026-01-31
