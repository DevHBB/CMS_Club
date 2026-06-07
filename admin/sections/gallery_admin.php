<?php
Auth::require('admin');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Auth::verifyCsrf()) { adminFlash('error','CSRF'); Helpers::redirect(u('/admin/gallery')); }

// Créer dossier
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_folder'])) {
    $id   = (int)($_POST['folder_id'] ?? 0);
    $data = [
        'name'         => Helpers::sanitize($_POST['name'] ?? ''),
        'description'  => Helpers::sanitize($_POST['description'] ?? ''),
        'parent_id'    => (int)($_POST['parent_id'] ?? 0) ?: null,
        'require_login'=> (int)($_POST['require_login'] ?? 0),
        'order'        => (int)($_POST['order'] ?? 0),
    ];
    if ($id) {
        $sets = implode(',', array_map(fn($k) => "`$k`=?", array_keys($data)));
        Database::run("UPDATE cc_gallery_folders SET $sets WHERE id=?", [...array_values($data), $id]);
    } else {
        $data['slug'] = Helpers::uniqueSlug($data['name'], 'cc_gallery_folders');
        $cols = implode(',', array_map(fn($k) => "`$k`", array_keys($data)));
        $vals = implode(',', array_fill(0, count($data), '?'));
        Database::insert("INSERT INTO cc_gallery_folders ($cols, created_at) VALUES ($vals, NOW())", array_values($data));
    }
    adminFlash('success', 'Dossier sauvegardé.'); Helpers::redirect(u('/admin/gallery'));
}

// Upload photos
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_photos'])) {
    $folderId = (int)($_POST['folder_id'] ?? 0);
    if (!$folderId) { adminFlash('error', 'Choisissez un dossier.'); Helpers::redirect(u('/admin/gallery')); }
    $uploaded = 0;
    if (!empty($_FILES['photos']['tmp_name'])) {
        $files = $_FILES['photos'];
        $count = count($files['name']);
        for ($i = 0; $i < $count; $i++) {
            if ($files['error'][$i] !== 0) continue;
            $file = ['name' => $files['name'][$i], 'type' => $files['type'][$i], 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i]];
            $up = Helpers::uploadImage($file, CC_ROOT . '/assets/uploads/gallery', 20);
            if ($up['success']) {
                Database::insert("INSERT INTO cc_gallery_photos (folder_id, filename, title, created_at) VALUES (?,?,?,NOW())", [$folderId, $up['filename'], pathinfo($files['name'][$i], PATHINFO_FILENAME)]);
                $uploaded++;
            }
        }
        if ($uploaded && Config::get('notify_gallery')) {
            $folder  = Database::one("SELECT * FROM cc_gallery_folders WHERE id=?", [$folderId]);
            $members = Database::all("SELECT email, firstname FROM cc_users WHERE status='active'");
            foreach ($members as $m) Mailer::sendNewGalleryCategory($m['email'], $m['firstname'], $folder);
        }
    }
    adminFlash('success', "{$uploaded} photo(s) uploadée(s)."); Helpers::redirect(u('/admin/gallery'));
}

// Supprimer dossier
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_folder'])) {
    $id = (int)($_POST['folder_id'] ?? 0);
    $photos = Database::all("SELECT filename FROM cc_gallery_photos WHERE folder_id=?", [$id]);
    foreach ($photos as $p) @unlink(CC_ROOT . '/assets/uploads/gallery/' . $p['filename']);
    Database::run("DELETE FROM cc_gallery_photos WHERE folder_id=?", [$id]);
    $subs = Database::all("SELECT id FROM cc_gallery_folders WHERE parent_id=?", [$id]);
    foreach ($subs as $sub) {
        $subPhotos = Database::all("SELECT filename FROM cc_gallery_photos WHERE folder_id=?", [$sub['id']]);
        foreach ($subPhotos as $sp) @unlink(CC_ROOT . '/assets/uploads/gallery/' . $sp['filename']);
        Database::run("DELETE FROM cc_gallery_photos WHERE folder_id=?", [$sub['id']]);
        Database::run("DELETE FROM cc_gallery_folders WHERE id=?", [$sub['id']]);
    }
    Database::run("DELETE FROM cc_gallery_folders WHERE id=?", [$id]);
    adminFlash('success', 'Dossier supprimé.'); Helpers::redirect(u('/admin/gallery'));
}

// Supprimer photo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_photo'])) {
    $id    = (int)($_POST['photo_id'] ?? 0);
    $photo = Database::one("SELECT filename FROM cc_gallery_photos WHERE id=?", [$id]);
    if ($photo) {
        @unlink(CC_ROOT . '/assets/uploads/gallery/' . $photo['filename']);
        Database::run("DELETE FROM cc_gallery_photos WHERE id=?", [$id]);
    }
    adminFlash('success', 'Photo supprimée.'); Helpers::redirect('/admin/gallery?folder=' . ($_POST['folder_id'] ?? 0));
}

$editId   = (int)($_GET['edit'] ?? 0);
$viewFold = (int)($_GET['folder'] ?? 0);
$editFold = $editId ? Database::one("SELECT * FROM cc_gallery_folders WHERE id=?", [$editId]) : null;
$folders  = Database::all("SELECT f.*, p.name AS parent_name, (SELECT COUNT(*) FROM cc_gallery_photos ph WHERE ph.folder_id=f.id) AS cnt FROM cc_gallery_folders f LEFT JOIN cc_gallery_folders p ON f.parent_id=p.id ORDER BY f.id DESC");
$rootFolders = Database::all("SELECT * FROM cc_gallery_folders WHERE parent_id IS NULL ORDER BY name");

$pageTitle = 'Galerie — Administration';
ob_start();
?>
<div class="page-head">
  <h1>📸 Galerie</h1>
  <a href="<?=u('/admin/gallery?edit=0')?>" class="btn btn-primary">+ Nouveau dossier</a>
</div>

<?php if (isset($_GET['edit'])): ?>
<!-- Formulaire dossier -->
<div class="ac">
  <div class="ac-header"><h2><?= $editFold ? 'Modifier le dossier' : 'Nouveau dossier' ?></h2><a href="<?=u('/admin/gallery')?>" class="btn btn-ghost btn-sm">← Retour</a></div>
  <div class="ac-body">
    <form method="post">
      <?= Auth::csrfField() ?>
      <?php if ($editFold): ?><input type="hidden" name="folder_id" value="<?= $editFold['id'] ?>"><?php endif; ?>
      <div class="form-row">
        <div class="fg"><label>Nom *</label><input type="text" name="name" value="<?= Helpers::e($editFold['name'] ?? '') ?>" required></div>
        <div class="fg"><label>Ordre</label><input type="number" name="order" value="<?= $editFold['order'] ?? 0 ?>" style="width:80px"></div>
        <div class="fg span2"><label>Description</label><input type="text" name="description" value="<?= Helpers::e($editFold['description'] ?? '') ?>"></div>
        <div class="fg"><label>Dossier parent (sous-album)</label>
          <select name="parent_id">
            <option value="">Aucun (dossier racine)</option>
            <?php foreach ($rootFolders as $rf): ?>
              <?php if ($editFold && $rf['id'] === $editFold['id']) continue; ?>
              <option value="<?= $rf['id'] ?>" <?= ($editFold['parent_id'] ?? 0) == $rf['id'] ? 'selected' : '' ?>><?= Helpers::e($rf['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fg"><label style="display:flex;align-items:center;gap:.5rem;text-transform:none;margin-top:1.5rem"><input type="checkbox" name="require_login" value="1" <?= ($editFold['require_login'] ?? 0) ? 'checked' : '' ?>> Réservé aux membres connectés</label></div>
      </div>
      <div style="display:flex;gap:.75rem;justify-content:flex-end">
        <a href="<?=u('/admin/gallery')?>" class="btn btn-ghost">Annuler</a>
        <button type="submit" name="save_folder" class="btn btn-primary">💾 Sauvegarder</button>
      </div>
    </form>
  </div>
</div>

<?php elseif ($viewFold): ?>
<!-- Photos d'un dossier -->
<?php
$folder = Database::one("SELECT * FROM cc_gallery_folders WHERE id=?", [$viewFold]);
$photos = Database::all("SELECT * FROM cc_gallery_photos WHERE folder_id=? ORDER BY `order` ASC, id DESC", [$viewFold]);
?>
<div class="page-head" style="margin-bottom:1rem">
  <h2><?= Helpers::e($folder['name'] ?? '') ?> (<?= count($photos) ?> photos)</h2>
  <a href="<?=u('/admin/gallery')?>" class="btn btn-ghost btn-sm">← Retour</a>
</div>

<!-- Upload dans ce dossier -->
<div class="ac" style="margin-bottom:1.25rem">
  <div class="ac-body">
    <form method="post" enctype="multipart/form-data" style="display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap">
      <?= Auth::csrfField() ?>
      <input type="hidden" name="folder_id" value="<?= $viewFold ?>">
      <div class="fg" style="flex:1;min-width:200px;margin:0"><label>Photos à uploader (multiple)</label><input type="file" name="photos[]" multiple accept="image/*" required></div>
      <button type="submit" name="upload_photos" class="btn btn-primary">📤 Uploader</button>
    </form>
  </div>
</div>

<!-- Grille photos -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:.75rem">
  <?php foreach ($photos as $ph): ?>
  <div style="position:relative;border-radius:8px;overflow:hidden;border:1px solid #e2e8f0;background:#f8fafc">
    <img src="<?=asset('assets/uploads/gallery/'.Helpers::e($ph['filename']))?>" style="width:100%;aspect-ratio:1;object-fit:cover;display:block" loading="lazy">
    <div style="padding:.35rem .5rem;font-size:.72rem;color:#64748b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= Helpers::e($ph['title'] ?? $ph['filename']) ?></div>
    <form method="post" style="position:absolute;top:.3rem;right:.3rem" onsubmit="return confirm('Supprimer cette photo ?')">
      <?= Auth::csrfField() ?>
      <input type="hidden" name="photo_id" value="<?= $ph['id'] ?>">
      <input type="hidden" name="folder_id" value="<?= $viewFold ?>">
      <button type="submit" name="delete_photo" style="background:rgba(220,38,38,.85);border:none;color:#fff;width:22px;height:22px;border-radius:50%;cursor:pointer;font-size:.7rem;display:flex;align-items:center;justify-content:center">✕</button>
    </form>
  </div>
  <?php endforeach; ?>
  <?php if (empty($photos)): ?><p style="grid-column:1/-1;text-align:center;padding:3rem;color:#94a3b8">Aucune photo dans ce dossier.</p><?php endif; ?>
</div>

<?php else: ?>
<!-- Liste dossiers + Upload rapide -->
<div style="display:grid;grid-template-columns:1fr 320px;gap:1.5rem;align-items:start">
  <div class="ac">
    <div class="ac-header"><h2>Dossiers & albums</h2></div>
    <div class="at-wrap"><table class="at">
      <thead><tr><th>Nom</th><th>Parent</th><th>Photos</th><th>🔒</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($folders as $f): ?>
        <tr>
          <td><strong><?= Helpers::e($f['name']) ?></strong><br><small style="color:#94a3b8">/galerie/<?= $f['slug'] ?></small></td>
          <td><?= $f['parent_name'] ? Helpers::e($f['parent_name']) : '<span style="color:#94a3b8">Racine</span>' ?></td>
          <td><a href="?folder=<?= $f['id'] ?>" class="btn btn-ghost btn-sm"><?= $f['cnt'] ?> 📷</a></td>
          <td><?= $f['require_login'] ? '✅' : '—' ?></td>
          <td style="display:flex;gap:.35rem">
            <a href="?edit=<?= $f['id'] ?>" class="btn btn-ghost btn-sm">✏️</a>
            <a href="/galerie/<?= $f['slug'] ?>" target="_blank" class="btn btn-ghost btn-sm">👁</a>
            <form method="post" onsubmit="return confirm('Supprimer ce dossier et toutes ses photos ?')">
              <?= Auth::csrfField() ?><input type="hidden" name="folder_id" value="<?= $f['id'] ?>">
              <button type="submit" name="delete_folder" class="btn btn-danger btn-sm">🗑️</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($folders)): ?><tr><td colspan="5" style="text-align:center;padding:2rem;color:#94a3b8">Aucun dossier</td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </div>

  <!-- Upload rapide -->
  <div class="ac">
    <div class="ac-header"><h2>⬆️ Upload rapide</h2></div>
    <div class="ac-body">
      <form method="post" enctype="multipart/form-data">
        <?= Auth::csrfField() ?>
        <div class="fg"><label>Dossier *</label>
          <select name="folder_id" required>
            <option value="">Choisir…</option>
            <?php foreach ($folders as $f): ?><option value="<?= $f['id'] ?>"><?= Helpers::e($f['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="fg"><label>Photos (multiple)</label><input type="file" name="photos[]" multiple accept="image/*" required></div>
        <div class="fg"><label style="display:flex;align-items:center;gap:.5rem;text-transform:none"><input type="checkbox" name="notify" value="1"> Notifier les membres par email</label></div>
        <button type="submit" name="upload_photos" class="btn btn-primary" style="width:100%">📤 Uploader</button>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
$content = ob_get_clean();
include CC_ROOT . '/admin/layout.php';
