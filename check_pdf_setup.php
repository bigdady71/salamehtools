<?php
/**
 * PDF Arabic Setup Diagnostic Script
 * Run this on your server to diagnose Arabic PDF issues
 */

declare(strict_types=1);

// Find the correct root directory (where vendor/ is located)
$root = __DIR__;
if (!file_exists($root . '/vendor/autoload.php')) {
    $root = dirname(__DIR__); // Try parent directory
}
if (!file_exists($root . '/vendor/autoload.php')) {
    $root = dirname(dirname(__DIR__)); // Try grandparent
}

$fontDir = $root . '/fonts';
$storageDir = $root . '/storage';
$autoload = $root . '/vendor/autoload.php';

// Handle cache clear request
if (isset($_GET['clear_cache'])) {
    $cacheCleared = false;
    $dirs = [
        $storageDir . '/mpdf_tmp',
        $storageDir . '/font_cache',
    ];
    foreach ($dirs as $dir) {
        if (is_dir($dir)) {
            $files = glob($dir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            $cacheCleared = true;
        }
    }
    echo "<script>alert('Cache cleared! " . ($cacheCleared ? "Success" : "No cache found") . "'); window.location.href='check_pdf_setup.php';</script>";
    exit;
}

echo "<h1>PDF Arabic Setup Diagnostic</h1>";
echo "<p><a href='?clear_cache=1' style='background:#dc2626;color:white;padding:10px 20px;text-decoration:none;border-radius:5px;'>Clear mPDF Cache</a></p>";
echo "<pre>";

echo "=== PATH DETECTION ===\n";
echo "Script location: " . __DIR__ . "\n";
echo "Detected root: {$root}\n";
echo "Autoload path: {$autoload}\n";
echo "Autoload exists: " . (file_exists($autoload) ? "YES ✓" : "NO ✗") . "\n";

// 1. Check font directory
echo "\n=== FONT DIRECTORY CHECK ===\n";
echo "Font directory: {$fontDir}\n";
echo "Directory exists: " . (is_dir($fontDir) ? "YES ✓" : "NO ✗") . "\n";

if (is_dir($fontDir)) {
    echo "Directory readable: " . (is_readable($fontDir) ? "YES ✓" : "NO ✗") . "\n";
    echo "\nFiles in font directory:\n";
    $files = scandir($fontDir);
    $foundFont = false;
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..') {
            $fullPath = $fontDir . '/' . $file;
            $size = filesize($fullPath);
            $sizeKB = round($size / 1024, 1);
            echo "  - {$file} ({$sizeKB} KB)\n";
            if (stripos($file, 'NotoSansArabic') !== false && $size > 100000) {
                $foundFont = true;
            }
        }
    }
}

// 2. Check specific font file
$fontFile = $fontDir . '/NotoSansArabic-Regular.ttf';
echo "\n=== ARABIC FONT FILE CHECK ===\n";
echo "Font file: {$fontFile}\n";
echo "File exists: " . (file_exists($fontFile) ? "YES ✓" : "NO ✗") . "\n";

if (file_exists($fontFile)) {
    $fileSize = filesize($fontFile);
    echo "File size: " . number_format($fileSize) . " bytes (" . round($fileSize/1024, 1) . " KB)\n";
    if ($fileSize < 100000) {
        echo "WARNING: File too small - may be corrupted!\n";
    }
}

// 3. Check storage directories
echo "\n=== STORAGE DIRECTORY CHECK ===\n";
$storageDirs = [
    $storageDir => 'Main storage',
    $storageDir . '/mpdf_tmp' => 'mPDF temp',
    $storageDir . '/font_cache' => 'Font cache',
];

foreach ($storageDirs as $dir => $desc) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    echo "{$desc}: " . (is_dir($dir) && is_writable($dir) ? "OK ✓" : "FAILED ✗") . "\n";
}

// 4. Check mPDF installation
echo "\n=== MPDF LIBRARY CHECK ===\n";
if (file_exists($autoload)) {
    require_once $autoload;
    echo "Composer autoload: LOADED ✓\n";

    $hasMpdf = class_exists('Mpdf\\Mpdf');
    echo "mPDF class: " . ($hasMpdf ? "AVAILABLE ✓" : "MISSING ✗") . "\n";
} else {
    echo "Composer autoload: NOT FOUND ✗\n";
    echo "Tried path: {$autoload}\n";
    $hasMpdf = false;
}

// 5. Test mPDF Arabic rendering
if ($hasMpdf && file_exists($fontFile)) {
    echo "\n=== MPDF ARABIC RENDERING TEST ===\n";

    try {
        $tempDir = $storageDir . '/mpdf_tmp';

        $defaultConfig = (new \Mpdf\Config\ConfigVariables())->getDefaults();
        $fontDirs = $defaultConfig['fontDir'];

        $defaultFontConfig = (new \Mpdf\Config\FontVariables())->getDefaults();
        $fontData = $defaultFontConfig['fontdata'];

        $fontData['notosansarabic'] = [
            'R' => 'NotoSansArabic-Regular.ttf',
            'useOTL' => 0xFF,
            'useKashida' => 75
        ];

        echo "Initializing mPDF...\n";

        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'tempDir' => $tempDir,
            'fontDir' => array_merge($fontDirs, [$fontDir]),
            'fontdata' => $fontData,
            'default_font' => 'notosansarabic',
            'directionality' => 'rtl',
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'autoArabic' => true,
            'useOTL' => 0xFF,
            'biDirectional' => true
        ]);

        echo "mPDF initialized: SUCCESS ✓\n";

        $testHtml = '<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head><meta charset="UTF-8"></head>
<body style="font-family: notosansarabic; direction: rtl; text-align: right; padding: 20mm;">
    <h1 style="font-size: 24px;">اختبار اللغة العربية</h1>
    <div style="border: 2px solid #000; padding: 15px; margin: 20px 0; background: #f0f0f0;">
        <p><strong>فاتورة رقم:</strong> 12345-67-000001</p>
        <p><strong>التاريخ:</strong> 29/01/2026</p>
        <p><strong>العميل:</strong> محمد أحمد</p>
    </div>
    <p>إذا ظهر هذا النص بشكل صحيح فإن الإعدادات صحيحة.</p>
    <hr>
    <p style="text-align:center;"><strong>SALAMEH TOOLS</strong></p>
</body>
</html>';

        $mpdf->SetDirectionality('rtl');
        $mpdf->WriteHTML($testHtml);
        $pdfContent = $mpdf->Output('', 'S');

        echo "PDF generated: SUCCESS ✓\n";
        echo "PDF size: " . number_format(strlen($pdfContent)) . " bytes\n";

        // Save test PDF
        $testPdfPath = $storageDir . '/test_arabic_' . date('His') . '.pdf';
        file_put_contents($testPdfPath, $pdfContent);
        echo "Test PDF saved: {$testPdfPath}\n";

    } catch (\Mpdf\MpdfException $e) {
        echo "mPDF ERROR: " . $e->getMessage() . "\n";
    } catch (Exception $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
    }
}

// Summary
echo "\n=== SUMMARY ===\n";
$issues = [];
if (!file_exists($autoload)) $issues[] = "Autoload not found";
if (!$hasMpdf) $issues[] = "mPDF not available";
if (!file_exists($fontFile)) $issues[] = "Font file missing";

if (empty($issues)) {
    echo "All checks passed! ✓\n";
} else {
    echo "Issues: " . implode(", ", $issues) . "\n";
}

echo "</pre>";

// Download link
$testPdfs = glob($storageDir . '/test_arabic_*.pdf');
if (!empty($testPdfs)) {
    $latestPdf = end($testPdfs);
    $filename = basename($latestPdf);
    // Use relative path from web root
    echo "<p style='margin-top:20px;'>";
    echo "<a href='storage/{$filename}' target='_blank' style='background:#059669;color:white;padding:15px 30px;text-decoration:none;border-radius:5px;font-size:18px;'>Download Test Arabic PDF</a>";
    echo "</p>";
}
