<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
admin_required();

$companyId = require_company_id();
$adminId = (int)($_SESSION['admin']['id'] ?? 0);

function studio_json_response(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST['action'])) {
    studio_json_response(['ok'=>false, 'message'=>'Request body tidak diterima server. Periksa PHP post_max_size (disarankan minimal 10M) dan batas hosting.'], 413);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_template') {
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['_csrf'] ?? ''))) {
        studio_json_response(['ok'=>false, 'message'=>'Security token expired. Refresh the page.'], 419);
    }
    $id = max(0, (int)($_POST['id'] ?? 0));
    $name = trim((string)($_POST['name'] ?? ''));
    $documentRaw = (string)($_POST['document_json'] ?? '');
    $document = json_decode($documentRaw, true);
    if ($name === '' || mb_strlen($name) > 180 || !is_array($document)) {
        studio_json_response(['ok'=>false, 'message'=>'Template name or document data is invalid.'], 422);
    }
    if (($document['schema_version'] ?? null) !== 1 || !isset($document['pages']) || !is_array($document['pages']) || count($document['pages']) < 1 || count($document['pages']) > 50) {
        studio_json_response(['ok'=>false, 'message'=>'Unsupported document structure.'], 422);
    }
    $json = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false || strlen($json) > 7 * 1024 * 1024) {
        studio_json_response(['ok'=>false, 'message'=>'Template exceeds the 7 MB server limit. Please use smaller images.'], 413);
    }

    try {
        db()->beginTransaction();
        if ($id > 0) {
            $check = db()->prepare('SELECT id FROM contract_templates WHERE id=? AND company_id=? LIMIT 1');
            $check->execute([$id, $companyId]);
            if (!$check->fetchColumn()) {
                db()->rollBack();
                studio_json_response(['ok'=>false, 'message'=>'Template not found.'], 404);
            }
            db()->prepare('UPDATE contract_templates SET name=?, editor_mode=?, page_size=?, orientation=?, document_json=?, updated_by=? WHERE id=? AND company_id=?')
                ->execute([$name, 'design', 'A4', in_array(($document['orientation'] ?? 'portrait'), ['portrait','landscape'], true) ? $document['orientation'] : 'portrait', $json, $adminId, $id, $companyId]);
        } else {
            db()->prepare('INSERT INTO contract_templates(company_id,name,editor_mode,page_size,orientation,document_json,created_by,updated_by) VALUES(?,?,?,?,?,?,?,?)')
                ->execute([$companyId, $name, 'design', 'A4', in_array(($document['orientation'] ?? 'portrait'), ['portrait','landscape'], true) ? $document['orientation'] : 'portrait', $json, $adminId, $adminId]);
            $id = (int)db()->lastInsertId();
        }
        $versionQuery = db()->prepare('SELECT COALESCE(MAX(version_number),0)+1 FROM contract_template_versions WHERE company_id=? AND template_id=?');
        $versionQuery->execute([$companyId, $id]);
        $version = (int)$versionQuery->fetchColumn();
        db()->prepare('INSERT INTO contract_template_versions(company_id,template_id,version_number,document_json,created_by) VALUES(?,?,?,?,?)')
            ->execute([$companyId, $id, $version, $json, $adminId]);
        audit_company($companyId, 'contract_template_saved', 'contract_template', $id, 'Saved Contract Studio template version '.$version);
        db()->commit();
        studio_json_response(['ok'=>true, 'id'=>$id, 'version'=>$version, 'message'=>'Template saved.']);
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        error_log('Contract Studio save failed: '.$e->getMessage());
        studio_json_response(['ok'=>false, 'message'=>'Unable to save template. Check server logs.'], 500);
    }
}

$id = max(0, (int)($_GET['id'] ?? 0));
$template = null;
if ($id > 0) {
    $st = db()->prepare('SELECT id,name,orientation,document_json FROM contract_templates WHERE id=? AND company_id=? LIMIT 1');
    $st->execute([$id, $companyId]);
    $template = $st->fetch();
    if (!$template) { http_response_code(404); exit('Template not found.'); }
}
$initial = $template ? json_decode((string)$template['document_json'], true) : null;
if (!is_array($initial)) {
    $initial = ['schema_version'=>1,'orientation'=>'portrait','pages'=>[['id'=>'page-1','background'=>'#ffffff','objects'=>[]]]];
}
$title = $template ? (string)$template['name'] : 'Untitled Contract Template';
$csrf = csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Contract Studio · <?=e($title)?></title>
<link rel="stylesheet" href="<?=e(url('assets/app.css'))?>">
<link rel="stylesheet" href="<?=e(url('assets/contract-studio.css?v=20261105i'))?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/konva@9.3.18/konva.min.js"></script>
</head>
<body class="studio-body">
<header class="studio-topbar">
  <a class="studio-brand" href="<?=e(url('dashboard.php'))?>">CONTRACT <span>STUDIO</span></a>
  <input id="templateName" class="studio-name" maxlength="180" value="<?=e($title)?>" aria-label="Template name">
  <div class="studio-top-actions">
    <span id="saveState" class="save-state">Unsaved changes</span>
    <button id="undoBtn" class="tool-btn" type="button" title="Undo">↶</button>
    <button id="redoBtn" class="tool-btn" type="button" title="Redo">↷</button>
    <button id="saveBtn" class="studio-primary" type="button">Save template</button>
    <a class="tool-btn" href="<?=e(url('contract-templates.php'))?>">Exit</a>
  </div>
</header>
<div class="studio-toolbar">
  <button class="tool-btn active" data-tool="select" type="button">↖ Select</button>
  <button class="tool-btn" id="addText" type="button">T Text</button>
  <button class="tool-btn" id="addParagraph" type="button">¶ Paragraph box</button>
  <label class="page-control">Font Awesome <select id="iconChoice"><option value="star">Star</option><option value="check">Check</option><option value="phone">Phone</option><option value="envelope">Envelope</option><option value="location">Location</option><option value="globe">Globe</option><option value="car">Car</option><option value="calendar">Calendar</option><option value="clock">Clock</option><option value="user">User</option><option value="camera">Camera</option><option value="heart">Heart</option><option value="plane">Plane</option><option value="wifi">Wi-Fi</option><option value="whatsapp">WhatsApp</option></select></label><button class="tool-btn" id="addIcon" type="button">＋ Icon</button>

  <button class="tool-btn" id="addRect" type="button">▭ Shape</button>
  <button class="tool-btn" id="addImage" type="button">▧ Add image</button>
  <button class="tool-btn" id="addBackground" type="button">▧ Upload background</button>
  <input id="imageInput" type="file" accept="image/png,image/jpeg,image/webp" hidden>
  <input id="backgroundInput" type="file" accept="image/png,image/jpeg,image/webp" hidden>
  <span class="toolbar-separator"></span>
  <label class="page-control">Page <select id="orientation"><option value="portrait">A4 Portrait</option><option value="landscape">A4 Landscape</option></select></label>
  <button class="tool-btn" id="addPage" type="button">+ Page</button>
  <button class="tool-btn danger" id="deleteObject" type="button">Delete</button>
  <span class="studio-hint">Drag objects on the page · Select an object to edit properties</span>
</div>
<div class="studio-layout">
  <aside class="studio-sidebar">
    <div class="side-heading">ADD ELEMENTS</div>
    <button class="side-item" id="sideText" type="button"><b>T</b><span>Text</span></button>
    <button class="side-item" id="sideShape" type="button"><b>▭</b><span>Shapes</span></button>
    <button class="side-item" id="sideImage" type="button"><b>▧</b><span>Upload image</span></button>
    <button class="side-item" id="sideBackground" type="button"><b>▧</b><span>Upload background</span></button>
    <div class="side-heading">CONTRACT BLOCKS</div>
    <button class="side-item" id="addHeading" type="button"><b>H</b><span>Contract heading</span></button>
    <button class="side-item" id="addCompanyVariable" type="button"><b>{ }</b><span>Company name variable</span></button>
    <button class="side-item" id="addProductTable" type="button"><b>▤</b><span>Product rate table</span></button>
    <button class="side-item" id="addTerms" type="button"><b>¶</b><span>Terms & conditions</span></button>
    <button class="side-item" id="addFooter" type="button"><b>▱</b><span>Contact footer</span></button>
    <div class="side-note">More components (catalog, gallery, signature and QR) will be added in the next editor phase.</div>
    <div class="side-heading">PAGES</div>
    <div id="pageList" class="page-list"></div>
  </aside>
  <main class="studio-workspace">
    <div class="workspace-label"><span>WORKSPACE</span><span id="pageDimensions">A4 · 210 × 297 mm</span></div>
    <div id="canvasViewport" class="canvas-viewport"><div id="canvasMount"></div></div>
    <div class="zoom-controls"><button id="zoomOut" type="button">−</button><span id="zoomValue">70%</span><button id="zoomIn" type="button">+</button><button id="fitPage" type="button">Fit</button></div>
  </main>
  <aside class="studio-properties">
    <div class="side-heading">PROPERTIES</div>
    <div id="emptyProperties" class="empty-properties">Select an object on the canvas to edit its properties.</div>
    <div id="objectProperties" hidden>
      <label>Object name<input id="propName" type="text"></label>
      <label>Text content<textarea id="propText" rows="3"></textarea></label>
      <div class="prop-grid">
        <label>Font family<select id="propFontFamily"><option value="Arial">Arial</option><option value="Inter">Inter</option><option value="Roboto">Roboto</option><option value="Open Sans">Open Sans</option><option value="Lato">Lato</option><option value="Montserrat">Montserrat</option><option value="Poppins">Poppins</option><option value="Nunito">Nunito</option><option value="Roboto Condensed">Roboto Condensed</option><option value="Playfair Display">Playfair Display</option><option value="Merriweather">Merriweather</option><option value="Lora">Lora</option><option value="Oswald">Oswald</option><option value="Raleway">Raleway</option><option value="Source Sans 3">Source Sans 3</option><option value="PT Sans">PT Sans</option><option value="DM Sans">DM Sans</option><option value="Ubuntu">Ubuntu</option><option value="Georgia">Georgia</option><option value="Times New Roman">Times New Roman</option></select></label>
        <label>Font style<select id="propFontStyle"><option value="normal">Regular</option><option value="bold">Bold</option><option value="italic">Italic</option><option value="bold italic">Bold Italic</option></select></label>
        <label>Font size<input id="propFontSize" type="number" min="6" max="160"></label>
        <label>Text color<input id="propColor" type="color"></label>
        <label>Width<input id="propWidth" type="number" min="10"></label>
        <label>Height<input id="propHeight" type="number" min="10"></label>
        <label>X position<input id="propX" type="number"></label>
        <label>Y position<input id="propY" type="number"></label>
      </div>
      <label>Fill color<input id="propFill" type="color"></label>
      <div id="textBoxProperties" hidden><div class="side-heading">TEXT BOX</div><p class="empty-properties">Paragraph wraps automatically inside the selected width and height.</p></div>
      <div id="tableProperties" hidden>
        <div class="side-heading">TABLE DESIGN</div>
        <div class="prop-grid">
          <label>Row to resize<select id="tableRowTarget"><option value="all">All rows</option></select></label>
          <label>Row height (px)<input id="tableRowHeight" type="number" min="20" max="240"></label>
          <label>Border width<input id="tableBorderWidth" type="number" min="0" max="8" step="0.5"></label>
          <label>Header background<input id="tableHeaderFill" type="color"></label>
          <label>Body background<input id="tableBodyFill" type="color"></label>
          <label>Border color<input id="tableBorderColor" type="color"></label>
          <label>Header font color<input id="tableHeaderFontColor" type="color"></label>
          <label>Body font color<input id="tableBodyFontColor" type="color"></label>
          <label>Font family<select id="tableFontFamily"><option>Arial</option><option>Inter</option><option>Roboto</option><option>Georgia</option><option>Times New Roman</option><option>Verdana</option><option>Tahoma</option><option>Courier New</option></select></label>
          <label>Header font size<input id="tableHeaderFontSize" type="number" min="6" max="48"></label>
          <label>Body font size<input id="tableBodyFontSize" type="number" min="6" max="48"></label>
          <label>Header font style<select id="tableHeaderFontStyle"><option value="normal">Regular</option><option value="bold">Bold</option><option value="italic">Italic</option><option value="bold italic">Bold Italic</option></select></label>
          <label>Body font style<select id="tableBodyFontStyle"><option value="normal">Regular</option><option value="bold">Bold</option><option value="italic">Italic</option><option value="bold italic">Bold Italic</option></select></label>
          <label>Header horizontal<select id="tableHeaderAlign"><option value="center">Center</option><option value="left">Left</option><option value="right">Right</option></select></label>
          <label>Header vertical<select id="tableHeaderVerticalAlign"><option value="middle">Center</option><option value="top">Up</option><option value="bottom">Down</option></select></label>
          <label>Body horizontal<select id="tableBodyAlign"><option value="center">Center</option><option value="left">Left</option><option value="right">Right</option></select></label>
          <label>Body vertical<select id="tableBodyVerticalAlign"><option value="middle">Center</option><option value="top">Up</option><option value="bottom">Down</option></select></label>
        </div>
        <div class="prop-actions table-dim-actions"><button id="addTableRow" type="button">+ Add row</button><button id="removeTableRow" type="button">− Remove row</button></div>
        <div class="prop-actions table-dim-actions"><button id="addTableColumn" type="button">+ Add column</button><button id="removeTableColumn" type="button">− Remove column</button></div>
        <div id="columnWidthControls"></div>
        <div id="columnFontColors"></div>
      </div>
      <div class="prop-actions"><button id="bringForward" type="button">Bring forward</button><button id="sendBackward" type="button">Send backward</button></div>
      <button id="lockObject" class="tool-btn" type="button">Lock / Unlock</button>
    </div>
    <div class="side-heading layer-heading">LAYERS</div>
    <div id="layerList" class="layer-list"></div>
  </aside>
</div>
<script>
window.CONTRACT_STUDIO_CONFIG = {
  templateId: <?=json_encode($id)?>,
  csrf: <?=json_encode($csrf, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>,
  saveUrl: <?=json_encode(url('contract-studio.php'))?>,
  initialDocument: <?=json_encode($initial, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>
};
</script>
<script src="<?=e(url('assets/contract-studio.js?v=20261106a'))?>"></script>
</body></html>
