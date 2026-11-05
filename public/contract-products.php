<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
admin_required();
$companyId = require_company_id();
$adminId = (int)($_SESSION['admin']['id'] ?? 0);
verify_csrf();

function product_redirect(): never { redirect('contract-products.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'save');
    $id = max(0, (int)($_POST['id'] ?? 0));
    if ($action === 'delete') {
        $st = db()->prepare('DELETE FROM contract_products WHERE id=? AND company_id=?');
        $st->execute([$id, $companyId]);
        if ($st->rowCount()) {
            audit_company($companyId, 'contract_product_deleted', 'contract_product', $id, 'Deleted contract product');
            flash('success', 'Product deleted.');
        } else {
            flash('error', 'Product not found.');
        }
        product_redirect();
    }

    $sku = trim((string)($_POST['sku'] ?? ''));
    $name = trim((string)($_POST['name'] ?? ''));
    $short = trim((string)($_POST['short_description'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $currency = strtoupper(trim((string)($_POST['currency'] ?? 'IDR')));
    $publishRaw = trim((string)($_POST['publish_price'] ?? '0'));
    $contractRaw = trim((string)($_POST['contract_price'] ?? '0'));
    $active = isset($_POST['is_active']) ? 1 : 0;
    $inclusions = array_values(array_filter(array_map('trim', preg_split('/\\R/', (string)($_POST['inclusions'] ?? '')) ?: []), fn($v) => $v !== ''));

    $validPrice = static fn(string $v): bool => preg_match('/^\\d{1,12}(?:\\.\\d{1,2})?$/', $v) === 1;
    if ($name === '' || mb_strlen($name) > 180 || ($sku !== '' && mb_strlen($sku) > 80)
        || !preg_match('/^[A-Z]{3}$/', $currency) || !$validPrice($publishRaw) || !$validPrice($contractRaw)) {
        flash('error', 'Please check product name, currency and non-negative prices.');
        product_redirect();
    }

    try {
        db()->beginTransaction();
        if ($id > 0) {
            $check = db()->prepare('SELECT id FROM contract_products WHERE id=? AND company_id=? LIMIT 1');
            $check->execute([$id, $companyId]);
            if (!$check->fetchColumn()) {
                db()->rollBack(); flash('error', 'Product not found.'); product_redirect();
            }
            db()->prepare('UPDATE contract_products SET sku=?,name=?,short_description=?,description=?,currency=?,publish_price=?,contract_price=?,is_active=?,updated_by=? WHERE id=? AND company_id=?')
                ->execute([$sku !== '' ? $sku : null,$name,$short !== '' ? $short : null,$description !== '' ? $description : null,$currency,$publishRaw,$contractRaw,$active,$adminId,$id,$companyId]);
            db()->prepare('DELETE FROM contract_product_inclusions WHERE product_id=? AND company_id=?')->execute([$id,$companyId]);
            $productId = $id;
            $event = 'contract_product_updated';
        } else {
            db()->prepare('INSERT INTO contract_products(company_id,sku,name,short_description,description,currency,publish_price,contract_price,is_active,created_by,updated_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$companyId,$sku !== '' ? $sku : null,$name,$short !== '' ? $short : null,$description !== '' ? $description : null,$currency,$publishRaw,$contractRaw,$active,$adminId,$adminId]);
            $productId = (int)db()->lastInsertId();
            $event = 'contract_product_created';
        }
        $insert = db()->prepare('INSERT INTO contract_product_inclusions(company_id,product_id,title,sort_order) VALUES(?,?,?,?)');
        foreach ($inclusions as $i => $title) {
            if (mb_strlen($title) > 220) continue;
            $insert->execute([$companyId,$productId,$title,$i]);
        }
        audit_company($companyId, $event, 'contract_product', $productId, $name);
        db()->commit();
        flash('success', 'Product saved.');
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        error_log('Contract product save failed: '.$e->getMessage());
        flash('error', 'Could not save product. Check that migration 003 has been applied and SKU is unique.');
    }
    product_redirect();
}

$id = max(0, (int)($_GET['id'] ?? 0));
$product = ['id'=>0,'sku'=>'','name'=>'','short_description'=>'','description'=>'','currency'=>'IDR','publish_price'=>'0.00','contract_price'=>'0.00','is_active'=>1];
$inclusionText = '';
if ($id > 0) {
    $st = db()->prepare('SELECT * FROM contract_products WHERE id=? AND company_id=? LIMIT 1');
    $st->execute([$id,$companyId]);
    $found = $st->fetch();
    if (!$found) { http_response_code(404); exit('Product not found.'); }
    $product = $found;
    $st = db()->prepare('SELECT title FROM contract_product_inclusions WHERE product_id=? AND company_id=? ORDER BY sort_order,id');
    $st->execute([$id,$companyId]);
    $inclusionText = implode("\\n", array_column($st->fetchAll(), 'title'));
}
$st = db()->prepare('SELECT id,sku,name,currency,publish_price,contract_price,is_active,updated_at FROM contract_products WHERE company_id=? ORDER BY sort_order,name');
$st->execute([$companyId]);
$products = $st->fetchAll();
page_header('Contract Product Catalog');
?>
<div class="card">
 <div class="actions" style="justify-content:space-between;align-items:center">
  <div><h2 style="margin:0"><?= $id ? 'Edit product' : 'Create product' ?></h2><p class="muted">Products are visible only inside the active company.</p></div>
  <?php if($id): ?><a class="btn light" href="<?=e(url('contract-products.php'))?>">Cancel edit</a><?php endif; ?>
 </div>
 <form method="post" class="product-form">
  <?=csrf_field()?><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="save">
  <div class="formgrid">
   <div><label>Product name *</label><input name="name" maxlength="180" required value="<?=e((string)$product['name'])?>"></div>
   <div><label>SKU / Code</label><input name="sku" maxlength="80" value="<?=e((string)($product['sku'] ?? ''))?>"></div>
   <div><label>Publish price</label><input name="publish_price" type="number" min="0" step="0.01" required value="<?=e((string)$product['publish_price'])?>"></div>
   <div><label>Contract / Net price</label><input name="contract_price" type="number" min="0" step="0.01" required value="<?=e((string)$product['contract_price'])?>"></div>
   <div><label>Currency (3 letters)</label><input name="currency" maxlength="3" pattern="[A-Za-z]{3}" required value="<?=e((string)$product['currency'])?>"></div>
   <div><label>Short description</label><input name="short_description" maxlength="500" value="<?=e((string)($product['short_description'] ?? ''))?>"></div>
  </div>
  <label>Full description</label><textarea name="description" rows="4"><?=e((string)($product['description'] ?? ''))?></textarea>
  <label>Inclusions (one item per line)</label><textarea name="inclusions" rows="5" placeholder="Welcome drink&#10;Professional instructor&#10;Insurance"><?=e($inclusionText)?></textarea>
  <label><input type="checkbox" name="is_active" value="1" style="width:auto" <?=$product['is_active']?'checked':''?>> Active product</label>
  <p><button class="btn" type="submit">Save product</button></p>
 </form>
</div>
<div class="card">
 <h2>Products (<?=count($products)?>)</h2>
 <table><thead><tr><th>Product</th><th>Publish price</th><th>Contract / Net</th><th>Status</th><th>Actions</th></tr></thead><tbody>
 <?php foreach($products as $p): ?><tr>
  <td><strong><?=e($p['name'])?></strong><br><span class="muted"><?=e((string)($p['sku'] ?? '—'))?></span></td>
  <td><?=e($p['currency'])?> <?=number_format((float)$p['publish_price'],2)?></td>
  <td><?=e($p['currency'])?> <?=number_format((float)$p['contract_price'],2)?></td>
  <td><span class="badge"><?=$p['is_active']?'Active':'Inactive'?></span></td>
  <td><div class="actions"><a class="btn light" href="<?=e(url('contract-products.php?id='.(int)$p['id']))?>">Edit</a>
   <form method="post" onsubmit="return confirm('Delete this product?')"><?=csrf_field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=(int)$p['id']?>"><button class="btn danger" type="submit">Delete</button></form></div></td>
 </tr><?php endforeach; ?>
 <?php if(!$products): ?><tr><td colspan="5" class="muted">No products added yet.</td></tr><?php endif; ?>
 </tbody></table>
</div>
<style>
.product-form textarea{resize:vertical}.product-form label input[type=checkbox]{display:inline-block}
@media(max-width:700px){.card table{min-width:720px}}
</style>
<?php page_footer(); ?>
