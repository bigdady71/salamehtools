<?php
require_once __DIR__ . '/../includes/guard.php'; require_login();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$title = 'Admin • Import Products';
$defaultPath = 'file:///Z:/backuphsyn/mproduct.xlwasx'; // adjust as needed

$summary = null;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $path = trim($_POST['path'] ?? '');
  if ($path === '') { $error = 'Path required.'; }
  elseif (!file_exists($path)) { $error = "File not found: $path"; }
  else {
    $pdo = db();
    $pdo->beginTransaction();
    try {
      $spreadsheet = IOFactory::load($path);
      $sheet = $spreadsheet->getActiveSheet();
      $rows = $sheet->toArray(null, true, true, true); // preserves header labels

      // Header row mapping (adjust if headers differ)
      // Expected headers in Excel (first row):
      // Type | Name | SecondName | Code | Unit | SalePrice | WholeSalePrice | WholeSalePrice1 | WholeSalePrice2 | description | ExistQuantity | TopCat | TopCatName | MidCat | MidCatName
      $header = array_map('trim', $rows[1] ?? []);
      // Build index map by header name
      $idx = array_flip($header);

      $ins = $pdo->prepare("
        INSERT INTO products
          (sku, code_clean, item_name, second_name,
           topcat, midcat, topcat_name, midcat_name,
           type, unit, sale_price_usd, wholesale_price_usd,
           min_quantity, minqty_disc1, minqty_disc2,
           description, quantity_on_hand, is_active, created_at, updated_at)
        VALUES
          (:sku, :code_clean, :item_name, :second_name,
           :topcat, :midcat, :topcat_name, :midcat_name,
           :type, :unit, :sale_price_usd, :wholesale_price_usd,
           :min_quantity, :minqty_disc1, :minqty_disc2,
           :description, :quantity_on_hand, 1, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
          code_clean=VALUES(code_clean),
          item_name=VALUES(item_name),
          second_name=VALUES(second_name),
          topcat=VALUES(topcat),
          midcat=VALUES(midcat),
          topcat_name=VALUES(topcat_name),
          midcat_name=VALUES(midcat_name),
          type=VALUES(type),
          unit=VALUES(unit),
          sale_price_usd=VALUES(sale_price_usd),
          wholesale_price_usd=VALUES(wholesale_price_usd),
          min_quantity=VALUES(min_quantity),
          minqty_disc1=VALUES(minqty_disc1),
          minqty_disc2=VALUES(minqty_disc2),
          description=VALUES(description),
          quantity_on_hand=VALUES(quantity_on_hand),
          updated_at=NOW()
      ");

      $inserted = 0; $updated = 0; $skipped = 0;

      // Iterate from row 2
      for ($r = 2; $r <= count($rows); $r++) {
        $row = $rows[$r];

        // Pull by header keys safely
        $get = function($name) use ($row, $idx) {
          if (!isset($idx[$name])) return null;
          $colKey = array_search($name, array_keys($idx), true);
          // $row is A,B,C..., but we can just use the header->index map:
          return $row[array_keys($row)[$idx[$name]]] ?? null;
        };

        $sku     = trim((string)($get('Code') ?? ''));
        $name    = trim((string)($get('Name') ?? ''));
        if ($sku === '' && $name === '') { $skipped++; continue; } // empty line

        $second  = trim((string)($get('SecondName') ?? ''));
        $type    = (string)($get('Type') ?? '');
        $unit    = trim((string)($get('Unit') ?? ''));
        $sale    = (float)str_replace([','], ['.'], (string)($get('SalePrice') ?? 0));
        $ws      = (float)str_replace([','], ['.'], (string)($get('WholeSalePrice') ?? 0));
        $ws1     = (float)str_replace([','], ['.'], (string)($get('WholeSalePrice1') ?? 0));
        $ws2     = (float)str_replace([','], ['.'], (string)($get('WholeSalePrice2') ?? 0));
        $desc    = trim((string)($get('description') ?? ''));
        $qoh     = (float)str_replace([','], ['.'], (string)($get('ExistQuantity') ?? 0));
        $topcat  = (int)($get('TopCat') ?? 0);
        $midcat  = (int)($get('MidCat') ?? 0);
        $topname = trim((string)($get('TopCatName') ?? ''));
        $midname = trim((string)($get('MidCatName') ?? ''));
        $minq    = 0;

        $code_clean = preg_replace('/[^A-Za-z0-9\-_.]/u', '', $sku);

        // choose wholesale_price_usd: prefer base wholesale; fall back to ws1
        $wholesale = $ws ?: $ws1;

        $ins->execute([
          ':sku' => $sku,
          ':code_clean' => $code_clean,
          ':item_name' => $name,
          ':second_name' => $second,
          ':topcat' => $topcat,
          ':midcat' => $midcat,
          ':topcat_name' => $topname,
          ':midcat_name' => $midname,
          ':type' => $type,
          ':unit' => $unit,
          ':sale_price_usd' => $sale,
          ':wholesale_price_usd' => $wholesale,
          ':min_quantity' => $minq,
          ':minqty_disc1' => $ws1 ?: 0,
          ':minqty_disc2' => $ws2 ?: 0,
          ':description' => $desc,
          ':quantity_on_hand' => $qoh,
        ]);

        $affected = $ins->rowCount();
        // ON DUPLICATE KEY UPDATE returns 1 for insert, 2 for update (MySQL/MariaDB)
        if ($affected === 1) $inserted++;
        elseif ($affected === 2) $updated++;
        else $skipped++;
      }

      $pdo->commit();
      $summary = compact('inserted','updated','skipped');
    } catch (Throwable $e) {
      $pdo->rollBack();
      $error = 'Import failed: ' . $e->getMessage();
    }
  }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Import Products</title>
<link rel="stylesheet" href="../../css/app.css">
<style>
body{background:#0c1022;color:#eaeaea;font-family:sans-serif}
.wrap{max-width:780px;margin:60px auto;padding:24px;background:#1c1f2b;border-radius:10px}
label, input[type=text]{display:block;width:100%}
input[type=text]{padding:10px;border-radius:6px;border:0;background:#262a3e;color:#fff;margin:8px 0 16px}
button{padding:10px 16px;border:0;border-radius:6px;background:#00ff88;color:#000;font-weight:600;cursor:pointer}
.notice{margin-top:12px}
.ok{color:#00ff88}.err{color:#ff4b4b}
</style>
</head>
<body>
<div class="wrap">
  <h2>Import Products from XLSX</h2>
  <form method="post">
    <label for="path">XLSX path (UNC or local):</label>
    <input id="path" name="path" type="text" value="<?= htmlspecialchars($defaultPath) ?>">
    <button type="submit">Run Import</button>
  </form>

  <?php if ($error): ?>
    <div class="notice err"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <?php if ($summary): ?>
    <div class="notice ok">
      Imported successfully.<br>
      Inserted: <?= (int)$summary['inserted'] ?>,
      Updated: <?= (int)$summary['updated'] ?>,
      Skipped: <?= (int)$summary['skipped'] ?>.
    </div>
  <?php endif; ?>

  <p style="margin-top:18px;font-size:14px;opacity:.8">
    Expected headers in row 1: <em>Type, Name, SecondName, Code, Unit, SalePrice, WholeSalePrice, WholeSalePrice1, WholeSalePrice2, description, ExistQuantity, TopCat, TopCatName, MidCat, MidCatName</em>.
  </p>
</div>
</body>
</html>
