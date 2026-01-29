<?php
/**
 * PDF Arabic Setup Diagnostic Script
 * Run this on your server to diagnose Arabic PDF issues
 */

declare(strict_types=1);

// Handle cache clear request
if (isset($_GET['clear_cache'])) {
    $cacheCleared = false;
    $dirs = [
        __DIR__ . '/storage/mpdf_tmp',
        __DIR__ . '/storage/font_cache',
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

// 1. Check font directory
$fontDir = __DIR__ . '/fonts';
echo "=== FONT DIRECTORY CHECK ===\n";
echo "Font directory path: {$fontDir}\n";
echo "Directory exists: " . (is_dir($fontDir) ? "YES ✓" : "NO ✗ - CREATE THIS FOLDER!") . "\n";

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
    if (!$foundFont) {
        echo "\n  ⚠️ WARNING: NotoSansArabic font not found or too small!\n";
    }
} else {
    echo "\n❌ CRITICAL: fonts/ directory does not exist!\n";
    echo "   Create it with: mkdir fonts\n";
}

// 2. Check specific font file
$fontFile = $fontDir . '/NotoSansArabic-Regular.ttf';
echo "\n=== ARABIC FONT FILE CHECK ===\n";
echo "Expected font: NotoSansArabic-Regular.ttf\n";
echo "Full path: {$fontFile}\n";
echo "File exists: " . (file_exists($fontFile) ? "YES ✓" : "NO ✗ - UPLOAD THIS FILE!") . "\n";

if (file_exists($fontFile)) {
    $fileSize = filesize($fontFile);
    echo "File size: " . number_format($fileSize) . " bytes (" . round($fileSize/1024, 1) . " KB)\n";

    if ($fileSize < 100000) {
        echo "⚠️ WARNING: File is too small! Font might be corrupted.\n";
        echo "   Expected size: ~500KB or more\n";
    }

    // Verify TTF format
    $handle = fopen($fontFile, 'rb');
    $header = fread($handle, 4);
    fclose($handle);

    $validTTF = ($header === "\x00\x01\x00\x00" || $header === "true" || $header === "OTTO");
    echo "Valid TTF format: " . ($validTTF ? "YES ✓" : "NO ✗ - File may be corrupted!") . "\n";
} else {
    echo "\n❌ CRITICAL: Font file is MISSING!\n";
    echo "\nTO FIX:\n";
    echo "1. Download from: https://fonts.google.com/noto/specimen/Noto+Sans+Arabic\n";
    echo "2. Extract NotoSansArabic-Regular.ttf\n";
    echo "3. Upload to: {$fontDir}/NotoSansArabic-Regular.ttf\n";
}

// 3. Check storage directories
echo "\n=== STORAGE DIRECTORY CHECK ===\n";
$storageDirs = [
    __DIR__ . '/storage' => 'Main storage',
    __DIR__ . '/storage/mpdf_tmp' => 'mPDF temp (MUST be writable)',
    __DIR__ . '/storage/font_cache' => 'Font cache',
    __DIR__ . '/storage/invoices' => 'Invoice storage',
];

foreach ($storageDirs as $dir => $desc) {
    $relativePath = str_replace(__DIR__, '.', $dir);
    echo "\n{$desc}: {$relativePath}\n";

    if (!is_dir($dir)) {
        echo "  Status: DOES NOT EXIST - Creating... ";
        $created = @mkdir($dir, 0755, true);
        echo ($created ? "SUCCESS ✓" : "FAILED ✗ (check permissions)") . "\n";
    } else {
        echo "  Exists: YES ✓\n";
        echo "  Writable: " . (is_writable($dir) ? "YES ✓" : "NO ✗ - Run: chmod 755 {$relativePath}") . "\n";

        // Count files in cache dirs
        if (strpos($dir, 'mpdf_tmp') !== false || strpos($dir, 'font_cache') !== false) {
            $fileCount = count(glob($dir . '/*'));
            echo "  Files in cache: {$fileCount}\n";
        }
    }
}

// 4. Check mPDF installation
echo "\n=== MPDF LIBRARY CHECK ===\n";
$autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
    echo "Composer autoload: EXISTS ✓\n";

    $hasMpdf = class_exists('Mpdf\\Mpdf');
    echo "mPDF class: " . ($hasMpdf ? "AVAILABLE ✓" : "MISSING ✗") . "\n";

    if (!$hasMpdf) {
        echo "  Fix: Run 'composer require mpdf/mpdf'\n";
    }
} else {
    echo "Composer autoload: MISSING ✗\n";
    echo "  Run: composer install\n";
}

// 5. Test mPDF Arabic rendering
if (class_exists('Mpdf\\Mpdf') && file_exists($fontFile)) {
    echo "\n=== MPDF ARABIC RENDERING TEST ===\n";

    try {
        $tempDir = __DIR__ . '/storage/mpdf_tmp';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $defaultConfig = (new \Mpdf\Config\ConfigVariables())->getDefaults();
        $fontDirs = $defaultConfig['fontDir'];

        $defaultFontConfig = (new \Mpdf\Config\FontVariables())->getDefaults();
        $fontData = $defaultFontConfig['fontdata'];

        // Add Arabic font
        $fontData['notosansarabic'] = [
            'R' => 'NotoSansArabic-Regular.ttf',
            'useOTL' => 0xFF,
            'useKashida' => 75
        ];

        echo "Initializing mPDF with Arabic support...\n";

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

        // Test HTML with Arabic
        $testHtml = '<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <style>
        body {
            font-family: notosansarabic, "Noto Sans Arabic", Arial;
            direction: rtl;
            text-align: right;
            padding: 20mm;
        }
        h1 { font-size: 24px; margin-bottom: 20px; }
        p { font-size: 14px; line-height: 1.8; }
        .box {
            border: 2px solid #000;
            padding: 15px;
            margin: 20px 0;
            background: #f0f0f0;
        }
    </style>
</head>
<body>
    <h1>اختبار اللغة العربية</h1>
    <div class="box">
        <p><strong>فاتورة رقم:</strong> 12345-67-000001</p>
        <p><strong>التاريخ:</strong> 29/01/2026</p>
        <p><strong>العميل:</strong> محمد أحمد</p>
    </div>
    <p>هذا اختبار لعرض النص العربي في ملف PDF.</p>
    <p>إذا ظهر هذا النص بشكل صحيح (من اليمين إلى اليسار) فإن الإعدادات صحيحة.</p>
    <p>إذا ظهر معكوساً، فهناك مشكلة في الخط أو الإعدادات.</p>
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
        $testPdfPath = __DIR__ . '/storage/test_arabic_' . date('His') . '.pdf';
        file_put_contents($testPdfPath, $pdfContent);

        $webPath = str_replace(__DIR__, '', $testPdfPath);
        echo "\n✅ Test PDF created!\n";
        echo "File: {$testPdfPath}\n";

    } catch (\Mpdf\MpdfException $e) {
        echo "\n❌ mPDF ERROR: " . $e->getMessage() . "\n";
        echo "This usually means:\n";
        echo "1. Font file is missing or corrupted\n";
        echo "2. temp directory is not writable\n";
        echo "3. mPDF library issue\n";
    } catch (Exception $e) {
        echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    }
}

echo "\n=== DOWNLOAD TEST PDF ===\n";
$testPdfs = glob(__DIR__ . '/storage/test_arabic_*.pdf');
if (!empty($testPdfs)) {
    $latestPdf = end($testPdfs);
    $webUrl = '/salamehtools/storage/' . basename($latestPdf);
    echo "Latest test PDF: <a href='{$webUrl}' target='_blank' style='color:blue;font-weight:bold;'>DOWNLOAD AND CHECK</a>\n";
    echo "\nOpen the PDF and verify:\n";
    echo "- Arabic text reads RIGHT to LEFT\n";
    echo "- 'فاتورة رقم' should NOT appear as 'مقر ةروتاف'\n";
} else {
    echo "No test PDF found. Check errors above.\n";
}

echo "\n=== SUMMARY ===\n";
$issues = [];

if (!file_exists($fontFile)) {
    $issues[] = "Font file missing";
}
if (!is_writable(__DIR__ . '/storage/mpdf_tmp')) {
    $issues[] = "mPDF temp not writable";
}

if (empty($issues)) {
    echo "✅ All checks passed!\n\n";
    echo "If Arabic text is STILL reversed in your invoices:\n";
    echo "1. Click 'Clear mPDF Cache' button above\n";
    echo "2. Try generating a new invoice\n";
    echo "3. Download the test PDF above and check if it's correct\n";
} else {
    echo "❌ Issues found: " . implode(", ", $issues) . "\n";
}

echo "</pre>";

// Add download link for test PDF
$testPdfs = glob(__DIR__ . '/storage/test_arabic_*.pdf');
if (!empty($testPdfs)) {
    $latestPdf = end($testPdfs);
    $filename = basename($latestPdf);
    echo "<p style='margin-top:20px;'>";
    echo "<a href='/salamehtools/storage/{$filename}' target='_blank' style='background:#059669;color:white;padding:15px 30px;text-decoration:none;border-radius:5px;font-size:18px;'>📄 Download Test Arabic PDF</a>";
    echo "</p>";
}
