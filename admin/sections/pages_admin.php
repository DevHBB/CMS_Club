<?php
/**
 * Pages & Accueil
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Auth::verifyCsrf()) {
    adminFlash('error','CSRF'); Helpers::redirect(u('/admin/pages'));
}

// ── Migration colonnes manquantes ────────────────────────────
try { Database::run("ALTER TABLE cc_articles ADD COLUMN IF NOT EXISTS access_mode VARCHAR(20) DEFAULT 'public'"); } catch(Exception $e) {}
try { Database::run("ALTER TABLE cc_articles ADD COLUMN IF NOT EXISTS access_message TEXT DEFAULT NULL"); } catch(Exception $e) {}

// Sauvegarde page d'accueil
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_homepage'])) {
    $fields = ['hero_title','hero_subtitle','hero_btn1_label','hero_btn1_url','hero_btn2_label','hero_btn2_url'];
    foreach ($fields as $f) Config::set($f, Helpers::sanitize($_POST[$f] ?? ''), 'homepage');
    $blocks = array_values($_POST['blocks'] ?? []);
    // Log debug - supprimer après
    if (defined('CC_ROOT')) {
        file_put_contents(CC_ROOT . '/debug_blocks.txt', 
            date('H:i:s') . " - Blocs reçus: " . count($blocks) . "
" .
            json_encode($blocks, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) . "

",
            FILE_APPEND
        );
    }
    Config::set('homepage_blocks', json_encode($blocks, JSON_UNESCAPED_UNICODE), 'homepage');
    adminFlash('success','Page d\'accueil sauvegardée.');
    Helpers::redirect(u('/admin/pages?tab=homepage'));
}

// Sauvegarde / création page
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_page'])) {
    $id   = (int)($_POST['page_id'] ?? 0);
    $data = [
        'title'         => Helpers::sanitize($_POST['title'] ?? ''),
        'excerpt'       => Helpers::sanitize($_POST['excerpt'] ?? ''),
        'content'       => json_encode(array_values($_POST['blocks'] ?? []), JSON_UNESCAPED_UNICODE),
        'published'     => (int)($_POST['published'] ?? 0),
        'access_mode'   => in_array($_POST['access_mode']??'',['public','teaser','members'])?$_POST['access_mode']:'public',
        'access_message'=> Helpers::sanitize($_POST['access_message']??''),
        'type'          => 'page',
        'user_id'       => Auth::id(),
    ];
    // Champs SEO stockés en config (clé = page_{id}_seo)
    $seoData = [
        'meta_title'       => Helpers::sanitize($_POST['meta_title'] ?? ''),
        'meta_description' => Helpers::sanitize($_POST['meta_description'] ?? ''),
        'og_title'         => Helpers::sanitize($_POST['og_title'] ?? ''),
        'og_description'   => Helpers::sanitize($_POST['og_description'] ?? ''),
    ];
    if (!empty($_FILES['cover']['tmp_name'])) {
        @mkdir(CC_ROOT.'/assets/uploads/pages', 0755, true);
        $up = Helpers::uploadImage($_FILES['cover'], CC_ROOT.'/assets/uploads/pages', 5);
        if ($up['success']) $data['cover'] = 'assets/uploads/pages/'.$up['filename'];
        else adminFlash('error', 'Erreur upload image : '.$up['error']);
    } elseif ($id) {
        $existing = Database::one("SELECT cover FROM cc_articles WHERE id=?", [$id]);
        if ($existing && $existing['cover']) $data['cover'] = $existing['cover'];
    }
    if ($id) {
        $sets = implode(',', array_map(fn($k)=>"`$k`=?", array_keys($data)));
        Database::run("UPDATE cc_articles SET $sets, updated_at=NOW() WHERE id=?", [...array_values($data), $id]);
        Config::set("page_{$id}_seo", json_encode($seoData), 'pages');
        adminFlash('success','Page sauvegardée.');
    } else {
        $data['slug'] = Helpers::uniqueSlug($data['title'], 'cc_articles');
        $cols = implode(',', array_map(fn($k)=>"`$k`", array_keys($data)));
        $vals = implode(',', array_fill(0, count($data), '?'));
        $newId = Database::insert("INSERT INTO cc_articles ($cols, created_at) VALUES ($vals, NOW())", array_values($data));
        Config::set("page_{$newId}_seo", json_encode($seoData), 'pages');
        adminFlash('success','Page créée.');
    }
    Helpers::redirect(u('/admin/pages?tab=pages'));
}

// Supprimer page
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_page'])) {
    Database::run("DELETE FROM cc_articles WHERE id=? AND type='page'", [(int)$_POST['page_id']]);
    adminFlash('success','Page supprimée.');
    Helpers::redirect(u('/admin/pages?tab=pages'));
}

$tab      = $_GET['tab'] ?? 'homepage';
$editId   = (int)($_GET['edit'] ?? 0);
$editPage = $editId ? Database::one("SELECT * FROM cc_articles WHERE id=?", [$editId]) : null;
$editPageSeo = [];
if ($editPage) {
    $editPageSeo = json_decode(Config::get("page_{$editPage['id']}_seo", '{}'), true) ?? [];
}
$pages    = Database::all("SELECT * FROM cc_articles WHERE type='page' ORDER BY created_at DESC");
$homepageBlocks = json_decode(Config::get('homepage_blocks','[]'), true) ?? [];


$pageTitle = 'Pages & Accueil';
ob_start();
?>

<div class="page-head">
  <h1>📝 Pages & Accueil</h1>
  <div style="display:flex;gap:.5rem">
    <a href="<?=u('/admin/pages?tab=homepage')?>" class="btn <?=$tab==='homepage'?'btn-primary':'btn-ghost'?>">🏠 Accueil</a>
    <a href="<?=u('/admin/pages?tab=pages')?>"    class="btn <?=$tab==='pages'?'btn-primary':'btn-ghost'?>">📄 Pages</a>
    <?php if($tab==='pages'): ?>
    <a href="<?=u('/admin/pages?tab=pages&edit=0')?>" class="btn btn-primary">+ Nouvelle page</a>
    <?php endif; ?>
  </div>
</div>

<?php if($tab==='homepage'): ?>
<!-- ════ ACCUEIL ════ -->
<form method="post" id="hp-form" enctype="multipart/form-data">
  <?=Auth::csrfField()?>

  <div class="ac">
    <div class="ac-header"><h2>🖼️ Bannière principale (Hero)</h2></div>
    <div class="ac-body">
      <div class="form-row">
        <div class="fg span2"><label>Titre principal</label><input type="text" name="hero_title" value="<?=Helpers::e(Config::get('hero_title',Config::get('club_name','')))?>"></div>
        <div class="fg span2"><label>Sous-titre</label><input type="text" name="hero_subtitle" value="<?=Helpers::e(Config::get('hero_subtitle',''))?>"></div>
        <div class="fg"><label>Bouton 1 — texte</label><input type="text" name="hero_btn1_label" value="<?=Helpers::e(Config::get('hero_btn1_label','Rejoindre'))?>"></div>
        <div class="fg"><label>Bouton 1 — lien</label><input type="text" name="hero_btn1_url" value="<?=Helpers::e(Config::get('hero_btn1_url','/register'))?>"></div>
        <div class="fg"><label>Bouton 2 — texte</label><input type="text" name="hero_btn2_label" value="<?=Helpers::e(Config::get('hero_btn2_label','Planning'))?>"></div>
        <div class="fg"><label>Bouton 2 — lien</label><input type="text" name="hero_btn2_url" value="<?=Helpers::e(Config::get('hero_btn2_url','/planning'))?>"></div>
      </div>
    </div>
  </div>

  <div class="ac">
    <div class="ac-header"><h2>🧩 Blocs de contenu</h2></div>
    <div class="ac-body">
      <?php
      $blocks = $homepageBlocks;
      $fieldPrefix = 'blocks';
      include CC_ROOT . '/admin/partials/block_editor.php';
      include_once CC_ROOT . '/admin/partials/block_js.php';
      ?>
    </div>
  </div>

  <div style="display:flex;justify-content:flex-end;gap:.75rem;margin-top:1rem">
    <button type="submit" name="save_homepage" class="btn btn-primary" style="font-size:1rem;padding:.75rem 2rem">💾 Sauvegarder l'accueil</button>
  </div>
</form>

<?php elseif($tab==='pages' && isset($_GET['edit'])): ?>
<!-- ════ ÉDITEUR PAGE ════ -->
<div class="ac">
  <div class="ac-header">
    <h2><?=$editPage ? '✏️ '.Helpers::e($editPage['title']) : '+ Nouvelle page'?></h2>
    <a href="<?=u('/admin/pages?tab=pages')?>" class="btn btn-ghost btn-sm">← Retour</a>
  </div>
  <div class="ac-body">
    <form method="post" enctype="multipart/form-data">
      <?=Auth::csrfField()?>
      <?php if($editPage): ?><input type="hidden" name="page_id" value="<?=$editPage['id']?>"><?php endif; ?>

      <div class="form-row">
        <div class="fg span2"><label>Titre de la page *</label><input type="text" name="title" value="<?=Helpers::e($editPage['title']??'')?>" required placeholder="Ex: À propos du club"></div>
        <?php if($editPage): ?>
        <div class="fg span2">
          <label>URL de la page (générée automatiquement)</label>
          <div style="display:flex;align-items:center;gap:.5rem">
            <code style="background:#f1f5f9;border:1.5px solid #e2e8f0;border-radius:7px;padding:.5rem .875rem;flex:1;font-size:.875rem;color:#64748b">
              index.php?route=<?=Helpers::e($editPage['slug']??'')?>
            </code>
            <a href="<?=u('/'.($editPage['slug']??''))?>" target="_blank" class="btn btn-ghost btn-sm">👁 Voir</a>
          </div>
        </div>
        <?php endif; ?>
        <div class="fg"><label>Image de couverture (optionnel)</label>
          <input type="file" name="cover" accept="image/*">
          <?php if($editPage['cover']??null): ?><img src="<?=asset($editPage['cover'])?>" style="height:48px;border-radius:6px;margin-top:.35rem"><?php endif; ?>
        </div>
      </div>

      <!-- Éditeur de blocs -->
      <div class="fg" style="margin-top:.5rem">
        <label style="display:block;font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#64748b;margin-bottom:.75rem">Contenu de la page</label>
        <?php
        $blocks = [];
        if (!empty($editPage['content'])) {
            $decoded = @json_decode($editPage['content'], true);
            if (is_array($decoded)) { $blocks = $decoded; }
            else { $blocks = [['type'=>'paragraph','content'=>$editPage['content']]]; }
        }
        $fieldPrefix = 'blocks';
        include CC_ROOT . '/admin/partials/block_editor.php';
        include_once CC_ROOT . '/admin/partials/block_js.php';
        ?>
      </div>

      <div style="display:flex;gap:1.5rem;margin-top:.75rem">
        <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;text-transform:none;letter-spacing:0;font-size:.875rem;font-weight:400"><input type="checkbox" name="published" value="1" <?=($editPage['published']??0)?'checked':''?>> ✅ Publié (visible sur le site)</label>
        <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;text-transform:none;letter-spacing:0;font-size:.875rem;font-weight:400"><input type="checkbox" name="require_login" value="1" <?=($editPage['require_login']??0)?'checked':''?>> 🔒 Membres connectés uniquement</label>
      </div>

      <div style="display:flex;justify-content:flex-end;gap:.75rem;margin-top:1.25rem">
        <a href="<?=u('/admin/pages?tab=pages')?>" class="btn btn-ghost">Annuler</a>
        <button type="submit" name="save_page" class="btn btn-primary" style="font-size:1rem;padding:.75rem 2rem">💾 Sauvegarder</button>
      </div>
    </form>
  </div>
</div>

<?php else: ?>
<!-- ════ LISTE PAGES ════ -->
<div class="ac">
  <div class="ac-header"><h2>📄 Pages du site</h2></div>
  <table class="at">
    <thead><tr><th>Titre</th><th>URL</th><th>Publié</th><th>Créée le</th><th>Actions</th></tr></thead>
    <tbody>
      <tr style="background:#f0fdf4">
        <td><strong>🏠 Accueil</strong></td>
        <td><code style="background:#f1f5f9;padding:.1rem .35rem;border-radius:3px;font-size:.78rem">index.php?route=home</code></td>
        <td>✅ Toujours</td>
        <td>—</td>
        <td><a href="<?=u('/admin/pages?tab=homepage')?>" class="btn btn-ghost btn-sm">✏️ Modifier</a></td>
      </tr>
      <?php foreach($pages as $p): ?>
      <tr>
        <td><strong><?=Helpers::e($p['title'])?></strong></td>
        <td><code style="background:#f1f5f9;padding:.1rem .35rem;border-radius:3px;font-size:.78rem">index.php?route=<?=Helpers::e($p['slug'])?></code></td>
        <td><?=$p['published']?'✅':'<span style="color:#dc2626">⭕ Non publié</span>'?></td>
        <td style="font-size:.78rem"><?=Helpers::dateFormat($p['created_at'])?></td>
        <td style="display:flex;gap:.35rem">
          <a href="<?=u('/admin/pages?tab=pages&edit='.$p['id'])?>" class="btn btn-ghost btn-sm">✏️ Modifier</a>
          <a href="<?=u('/'.$p['slug'])?>" target="_blank" class="btn btn-ghost btn-sm">👁</a>
          <form method="post" onsubmit="return confirm('Supprimer cette page ?')" style="display:inline">
            <?=Auth::csrfField()?>
            <input type="hidden" name="page_id" value="<?=$p['id']?>">
            <button type="submit" name="delete_page" class="btn btn-danger btn-sm">🗑️</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if(empty($pages)): ?><tr><td colspan="5" style="text-align:center;padding:2rem;color:#94a3b8">Aucune page. <a href="<?=u('/admin/pages?tab=pages&edit=0')?>">Créer la première →</a></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php
// Style boutons toolbar
$tbStyle = 'background:#fff;border:1px solid #e2e8f0;border-radius:4px;padding:.2rem .55rem;font-size:.82rem;cursor:pointer;font-family:var(--font-body)';


$content = ob_get_clean();
include CC_ROOT . '/admin/layout.php';
