<?php
/**
 * Studio → Clients endpoint: create / edit companies, their logo and module toggles.
 * Admin only (session), POST only, same-site only (requireSameSiteFetch). The first
 * company-creation UI in the portal — deleting a company is refused on purpose.
 *
 *   action=create         name*, slug (blank → from the name), feature_label, logo (file, optional)
 *   action=update         id*, name*, slug*, feature_label
 *   action=logo_upload    id*, logo* (file)
 *   action=logo_remove    id*
 *   action=module_toggle  id*, module=tires|emails|pages, to=1|0     (company_modules row)
 *   action=delete         → 405, never implemented here
 *
 * Slug: [a-z0-9-]{2,40}, unique (409 when taken). Logo: an image by content (getimagesize
 * + finfo agree: PNG / JPEG / GIF / WebP), ≤ 2 MB, resized with GD to fit 512×512 (aspect
 * kept), written as uploads/logo_<slug>.png (JPEG stays .jpg); companies.logo_url is set to
 * that app-relative path, which brandLogoUrl() re-roots under the current folder. Renaming a
 * slug renames a managed logo file with it and moves the client's media/pages/<slug>/ folder
 * (uploaded Pages live under the company slug; pages-lib.php) — both realpath-contained.
 *
 * Replies JSON ({ok, …, redirect}) when the request accepts JSON (studio.js fetch); a plain
 * form post is redirected back to studio.php?tab=clients with msg= / err= instead.
 * Codes: 200 · 400 bad input · 403 not admin / cross-site · 404 unknown id · 405 not POST or
 * delete · 409 slug taken / module row missing · 413 file too large · 415 not an image · 422 validation.
 *
 * Activity (entity_type 'company', actor 'admin'): created · updated (detail = fields) · logo_changed.
 */
require __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';

const CA_MAX_LOGO_BYTES = 2 * 1024 * 1024;
const CA_LOGO_MAX_PX    = 512;

/** fetch() / API callers say so; a browser form post wants a redirect. */
function caWantsJson(): bool {
    if (($_POST['format'] ?? '') === 'json') return true;
    if (strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') return true;
    return stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;
}

/** Where a form post lands afterwards: Studio → Clients, keeping the page's client scope. */
function caClientsUrl(string $scopeSlug, int $editId = 0, array $extra = []): string {
    $qs = [];
    if ($scopeSlug !== '') $qs['client'] = $scopeSlug;
    $qs['tab'] = 'clients';
    if ($editId > 0) $qs['edit'] = $editId;
    foreach ($extra as $k => $v) { if ($v !== null && $v !== '') $qs[$k] = $v; }
    return pagePath('studio') . '?' . http_build_query($qs);
}

/** JSON or redirect, depending on the caller. $payload['redirect'] is filled in when missing. */
function caReply(int $code, array $payload, string $scopeSlug = '', int $editId = 0): void {
    $ok = $code >= 200 && $code < 300;
    $payload = ['ok' => $ok] + $payload;
    if (empty($payload['redirect'])) {
        $payload['redirect'] = caClientsUrl($scopeSlug, $editId, $ok ? ['msg' => (string)($payload['message'] ?? '')] : ['err' => (string)($payload['error'] ?? 'Something went wrong.')]);
    }
    if (caWantsJson()) {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
        exit;
    }
    header('Location: ' . $payload['redirect'], true, 303);
    exit;
}

/** 'Cometic Gasket' → 'cometic-gasket' (ASCII, [a-z0-9-], ≤ 40). '' when nothing survives. */
function caSlugify(string $name): string {
    $s = trim($name);
    if (function_exists('iconv')) { $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s); if (is_string($t)) $s = $t; }
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim((string)$s, '-');
    $s = substr($s, 0, 40);
    return rtrim($s, '-');
}

function caValidSlug(string $slug): bool { return (bool)preg_match('/^[a-z0-9-]{2,40}$/', $slug); }

function caCompany(PDO $pdo, int $id): ?array {
    if ($id <= 0) return null;
    $st = $pdo->prepare("SELECT id, name, slug, feature_label, logo_url FROM companies WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ? $row : null;
}

function caSlugTaken(PDO $pdo, string $slug, int $exceptId = 0): bool {
    $st = $pdo->prepare("SELECT id FROM companies WHERE slug = ? LIMIT 1");
    $st->execute([$slug]);
    $id = (int)$st->fetchColumn();
    return $id > 0 && $id !== $exceptId;
}

function caLog(PDO $pdo, int $companyId, string $action, string $summary, ?string $detail = null): void {
    try { if (hasActivityLog($pdo)) logActivity($pdo, $companyId, 'company', $companyId, $action, 'admin', $summary, $detail); }
    catch (Throwable $e) { error_log('client-admin log failed: ' . $e->getMessage()); }
}

/** Every managed logo file for a slug (uploads/logo_<slug>.<ext>). */
function caManagedLogoFiles(string $slug): array {
    if (!caValidSlug($slug)) return [];
    $out = [];
    foreach (['png', 'jpg', 'jpeg', 'gif', 'webp'] as $ext) {
        $f = __DIR__ . '/uploads/logo_' . $slug . '.' . $ext;
        if (is_file($f)) $out[] = $f;
    }
    return $out;
}

/**
 * Validate + resize + store an uploaded logo for a company row.
 * Returns ['code' => int, 'error' => string] on failure, ['code' => 200, 'logo_url' => 'uploads/logo_x.png', 'width' => w, 'height' => h] on success.
 */
function caStoreLogo(array $co, ?array $file): array {
    if (!$file || !isset($file['error']) || (int)$file['error'] === UPLOAD_ERR_NO_FILE) return ['code' => 422, 'error' => 'Choose an image file first.'];
    $err = (int)$file['error'];
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) return ['code' => 413, 'error' => 'That file is too large — logos are limited to 2 MB.'];
    if ($err !== UPLOAD_ERR_OK) return ['code' => 400, 'error' => 'The upload did not complete (code ' . $err . ').'];
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_file($tmp) || (function_exists('is_uploaded_file') && !is_uploaded_file($tmp) && PHP_SAPI !== 'cli')) return ['code' => 400, 'error' => 'The upload did not complete.'];
    $size = (int)($file['size'] ?? filesize($tmp));
    if ($size <= 0) return ['code' => 422, 'error' => 'That file is empty.'];
    if ($size > CA_MAX_LOGO_BYTES) return ['code' => 413, 'error' => 'That file is too large — logos are limited to 2 MB.'];

    // Content sniffing: getimagesize AND finfo must agree it is one of the four web image types.
    $info = @getimagesize($tmp);
    $types = [IMAGETYPE_PNG => 'image/png', IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_GIF => 'image/gif', IMAGETYPE_WEBP => 'image/webp'];
    if (!is_array($info) || !isset($types[(int)$info[2]])) return ['code' => 415, 'error' => 'That is not a PNG, JPG, GIF or WebP image.'];
    if (class_exists('finfo')) {
        $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if ($mime !== $types[(int)$info[2]]) return ['code' => 415, 'error' => 'That is not a PNG, JPG, GIF or WebP image.'];
    }
    $w = (int)$info[0]; $h = (int)$info[1];
    if ($w <= 0 || $h <= 0) return ['code' => 415, 'error' => 'That image has no size.'];
    if ($w * $h > 40000000) return ['code' => 413, 'error' => 'That image is too large (over 40 megapixels).'];
    if (!function_exists('imagecreatefromstring') || !function_exists('imagescale')) return ['code' => 500, 'error' => 'Image resizing (GD) is not available on this server.'];

    $data = @file_get_contents($tmp);
    $im = $data !== false && $data !== '' ? @imagecreatefromstring($data) : false;
    unset($data);
    if (!$im) return ['code' => 415, 'error' => 'That image could not be decoded.'];

    // Fit inside 512×512, aspect kept, alpha preserved for PNG / GIF / WebP.
    if ($w > CA_LOGO_MAX_PX || $h > CA_LOGO_MAX_PX) {
        $scaled = $w >= $h ? imagescale($im, CA_LOGO_MAX_PX, -1, IMG_BICUBIC) : imagescale($im, (int)max(1, round($w * CA_LOGO_MAX_PX / $h)), CA_LOGO_MAX_PX, IMG_BICUBIC);
        if ($scaled) { imagedestroy($im); $im = $scaled; }
    }
    $isJpeg = (int)$info[2] === IMAGETYPE_JPEG;
    $ext    = $isJpeg ? 'jpg' : 'png';
    $dir    = __DIR__ . '/uploads';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    if (!is_dir($dir) || !is_writable($dir)) { imagedestroy($im); return ['code' => 500, 'error' => 'uploads/ is not writable.']; }
    foreach (caManagedLogoFiles((string)$co['slug']) as $old) { @unlink($old); }   // one managed file per slug
    $dest = $dir . '/logo_' . $co['slug'] . '.' . $ext;
    if ($isJpeg) { $ok = @imagejpeg($im, $dest, 90); }
    else { imagealphablending($im, false); imagesavealpha($im, true); $ok = @imagepng($im, $dest, 6); }
    $outW = imagesx($im); $outH = imagesy($im);
    imagedestroy($im);
    if (!$ok || !is_file($dest)) return ['code' => 500, 'error' => 'Could not write the logo file.'];
    @chmod($dest, 0644);
    return ['code' => 200, 'logo_url' => 'uploads/logo_' . $co['slug'] . '.' . $ext, 'width' => $outW, 'height' => $outH];
}

// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}
if (!currentAdmin()) {
    if (caWantsJson()) { http_response_code(403); header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => 'Admin sign-in required']); exit; }
    requireAdmin();   // browser form post → login redirect
}
requireSameSiteFetch();   // cross-site POSTs get a JSON 403 (helpers.php)

$scope  = (string)($client['slug'] ?? '');     // the page's ?client= scope (helpers.php), kept in the redirect
$action = strtolower(trim((string)($_POST['action'] ?? '')));
$id     = (int)($_POST['id'] ?? 0);

if ($action === 'delete' || $action === 'destroy') {
    caReply(405, ['error' => 'Deleting clients is not supported here.'], $scope, $id);
}

/** Shared name / slug / feature label validation. */
function caFields(PDO $pdo, int $exceptId): array {
    $name  = trim(preg_replace('/\s+/u', ' ', (string)($_POST['name'] ?? '')));
    $slug  = strtolower(trim((string)($_POST['slug'] ?? '')));
    $label = trim(preg_replace('/\s+/u', ' ', (string)($_POST['feature_label'] ?? '')));
    if ($name === '')                 return ['error' => 'Name is required.', 'code' => 422];
    if (mb_strlen($name) > 120)       return ['error' => 'Name is too long (120 characters max).', 'code' => 422];
    if ($slug === '')                 $slug = caSlugify($name);
    if (!caValidSlug($slug))          return ['error' => 'Slug must be 2–40 characters of a–z, 0–9 and dashes.', 'code' => 422];
    if (mb_strlen($label) > 60)       return ['error' => 'Feature label is too long (60 characters max).', 'code' => 422];
    if (caSlugTaken($pdo, $slug, $exceptId)) return ['error' => 'The slug "' . $slug . '" is already used by another client.', 'code' => 409];
    return ['name' => $name, 'slug' => $slug, 'feature_label' => $label];
}

switch ($action) {
    // ---- create -------------------------------------------------------------
    case 'create': {
        $f = caFields($pdo, 0);
        if (isset($f['error'])) caReply($f['code'], ['error' => $f['error']], $scope);
        try {
            $pdo->prepare("INSERT INTO companies (name, slug, feature_label, logo_url) VALUES (?, ?, ?, '')")
                ->execute([$f['name'], $f['slug'], $f['feature_label']]);
            $newId = (int)$pdo->lastInsertId();
        } catch (Throwable $e) {
            error_log('client-admin create failed: ' . $e->getMessage());
            caReply(500, ['error' => 'The database refused the new client: ' . mb_substr($e->getMessage(), 0, 160)], $scope);
        }
        if ($newId <= 0) {
            $st = $pdo->prepare("SELECT id FROM companies WHERE slug = ?"); $st->execute([$f['slug']]); $newId = (int)$st->fetchColumn();
        }
        caLog($pdo, $newId, 'created', 'Client created: ' . $f['name'] . ' (' . $f['slug'] . ')');
        $msg = 'Client "' . $f['name'] . '" created.';
        $logo = null;
        if (!empty($_FILES['logo']) && (int)($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $res = caStoreLogo(['id' => $newId, 'slug' => $f['slug']], $_FILES['logo']);
            if ($res['code'] === 200) {
                $pdo->prepare("UPDATE companies SET logo_url = ? WHERE id = ?")->execute([$res['logo_url'], $newId]);
                caLog($pdo, $newId, 'logo_changed', 'Logo uploaded for ' . $f['name'], $res['logo_url']);
                $logo = $res['logo_url'];
            } else {
                $msg .= ' The logo was not saved: ' . $res['error'];
            }
        }
        caReply(200, ['message' => $msg, 'id' => $newId, 'slug' => $f['slug'], 'name' => $f['name'], 'logo_url' => $logo,
                      'redirect' => caClientsUrl($scope, $newId, ['msg' => $msg])], $scope, $newId);
    }

    // ---- update -------------------------------------------------------------
    case 'update': {
        $co = caCompany($pdo, $id);
        if (!$co) caReply(404, ['error' => 'Unknown client.'], $scope);
        $f = caFields($pdo, $id);
        if (isset($f['error'])) caReply($f['code'], ['error' => $f['error']], $scope, $id);
        $changed = [];
        foreach (['name', 'slug', 'feature_label'] as $k) { if ((string)($co[$k] ?? '') !== $f[$k]) $changed[] = $k; }
        $logoUrl = (string)$co['logo_url'];
        $pagesMoveWarning = '';
        if (in_array('slug', $changed, true)) {
            // A managed logo file follows the slug (uploads/logo_<old>.<ext> → logo_<new>.<ext>).
            if (preg_match('#^uploads/logo_' . preg_quote((string)$co['slug'], '#') . '\.(png|jpe?g|gif|webp)$#', $logoUrl, $m) && is_file(__DIR__ . '/' . $logoUrl)) {
                $newRel = 'uploads/logo_' . $f['slug'] . '.' . $m[1];
                if (@rename(__DIR__ . '/' . $logoUrl, __DIR__ . '/' . $newRel)) { $logoUrl = $newRel; }
            }
            // Uploaded Pages live in media/pages/<company-slug>/… — move the folder so their files stay reachable.
            // Both ends must be plain [a-z0-9-] slugs, the source must be a real (non-symlink) folder directly
            // under media/pages/ (realpath agrees), and the target must not exist yet.
            if (function_exists('pagesMediaRootPath') && caValidSlug((string)$co['slug']) && caValidSlug($f['slug'])) {
                $pRoot = pagesMediaRootPath();
                $pOld  = $pRoot . '/' . $co['slug'];
                $pNew  = $pRoot . '/' . $f['slug'];
                if (is_dir($pOld) && !is_link($pOld)) {
                    $rootReal = realpath($pRoot); $oldReal = realpath($pOld);
                    $contained = $rootReal !== false && $oldReal !== false && $oldReal === rtrim($rootReal, '/') . '/' . $co['slug'];
                    if (!$contained || file_exists($pNew) || !@rename($pOld, $pNew)) {
                        $pagesMoveWarning = ' The pages folder media/pages/' . $co['slug'] . '/ could not be moved to media/pages/' . $f['slug'] . '/ — move it by hand or the client\'s uploaded pages will not load.';
                    }
                }
            }
        }
        if ($changed) {
            try {
                $pdo->prepare("UPDATE companies SET name = ?, slug = ?, feature_label = ?, logo_url = ? WHERE id = ?")
                    ->execute([$f['name'], $f['slug'], $f['feature_label'], $logoUrl, $id]);
            } catch (Throwable $e) {
                error_log('client-admin update failed: ' . $e->getMessage());
                caReply(500, ['error' => 'The database refused the change: ' . mb_substr($e->getMessage(), 0, 160)], $scope, $id);
            }
            caLog($pdo, $id, 'updated', 'Client updated: ' . $f['name'], implode(', ', $changed));
        }
        if ($scope !== '' && $scope === (string)$co['slug']) $scope = $f['slug'];   // the scoped client was renamed: follow it
        $msg = ($changed ? 'Saved ' . implode(', ', array_map(static fn($k) => str_replace('_', ' ', $k), $changed)) . ' for ' . $f['name'] . '.' : 'Nothing changed.') . $pagesMoveWarning;
        caReply(200, ['message' => $msg, 'id' => $id, 'slug' => $f['slug'], 'name' => $f['name'], 'changed' => $changed, 'logo_url' => $logoUrl], $scope, $id);
    }

    // ---- logo -----------------------------------------------------------------
    case 'logo_upload': {
        $co = caCompany($pdo, $id);
        if (!$co) caReply(404, ['error' => 'Unknown client.'], $scope);
        $res = caStoreLogo($co, $_FILES['logo'] ?? null);
        if ($res['code'] !== 200) caReply($res['code'], ['error' => $res['error']], $scope, $id);
        $pdo->prepare("UPDATE companies SET logo_url = ? WHERE id = ?")->execute([$res['logo_url'], $id]);
        caLog($pdo, $id, 'logo_changed', 'Logo uploaded for ' . $co['name'], $res['logo_url']);
        caReply(200, ['message' => 'Logo updated for ' . $co['name'] . '.', 'id' => $id, 'logo_url' => $res['logo_url'],
                      'url' => brandLogoUrl($res['logo_url'], (string)$co['slug']), 'width' => $res['width'], 'height' => $res['height']], $scope, $id);
    }
    case 'logo_remove': {
        $co = caCompany($pdo, $id);
        if (!$co) caReply(404, ['error' => 'Unknown client.'], $scope);
        $removed = 0;
        // Only a file this endpoint manages is deleted (uploads/logo_<slug>.*, realpath-contained); any other logo_url is just cleared.
        foreach (caManagedLogoFiles((string)$co['slug']) as $f) {
            $rel = 'uploads/' . basename($f);
            if (function_exists('uploadsPathOrNull') && uploadsPathOrNull($rel) !== null && @unlink($f)) $removed++;
        }
        $pdo->prepare("UPDATE companies SET logo_url = '' WHERE id = ?")->execute([$id]);
        caLog($pdo, $id, 'logo_changed', 'Logo removed for ' . $co['name'], 'removed');
        caReply(200, ['message' => 'Logo removed for ' . $co['name'] . '.', 'id' => $id, 'removed_files' => $removed, 'logo_url' => '',
                      'url' => brandLogoUrl('', (string)$co['slug'])], $scope, $id);
    }

    // ---- modules ------------------------------------------------------------
    case 'module_toggle': {
        $co = caCompany($pdo, $id);
        if (!$co) caReply(404, ['error' => 'Unknown client.'], $scope);
        $module = strtolower(trim((string)($_POST['module'] ?? '')));
        if (!in_array($module, ['tires', 'emails', 'pages'], true)) caReply(400, ['error' => 'Unknown module.'], $scope, $id);
        $on = (int)($_POST['to'] ?? -1);
        if ($on !== 0 && $on !== 1) caReply(400, ['error' => 'to must be 1 or 0.'], $scope, $id);
        $st = $pdo->prepare("SELECT id FROM modules WHERE slug = ?");
        $st->execute([$module]);
        $mid = (int)$st->fetchColumn();
        if ($mid <= 0) caReply(409, ['error' => 'The "' . $module . '" module row is missing — run migrate.php first.'], $scope, $id);
        $st = $pdo->prepare("SELECT 1 FROM company_modules WHERE company_id = ? AND module_id = ? LIMIT 1");
        $st->execute([$id, $mid]);
        $had = (bool)$st->fetchColumn();
        $flipped = false;
        if ($on === 1 && !$had)    { $pdo->prepare("INSERT IGNORE INTO company_modules (company_id, module_id, sort_order) VALUES (?, ?, ?)")->execute([$id, $mid, 99]); $flipped = true; }
        elseif ($on === 0 && $had) { $pdo->prepare("DELETE FROM company_modules WHERE company_id = ? AND module_id = ?")->execute([$id, $mid]); $flipped = true; }
        $label = ['tires' => 'Tires tab', 'emails' => 'Emails tab', 'pages' => 'Pages tab'][$module];
        if ($flipped) caLog($pdo, $id, 'updated', ($on ? 'Enabled ' : 'Disabled ') . $label . ' for ' . $co['name'], $module . ($on ? ' on' : ' off'));
        caReply(200, ['message' => $label . ($on ? ' enabled' : ' disabled') . ' for ' . $co['name'] . '.', 'id' => $id, 'module' => $module, 'enabled' => $on === 1], $scope, $id);
    }

    default:
        caReply(400, ['error' => 'Unknown action.'], $scope, $id);
}
