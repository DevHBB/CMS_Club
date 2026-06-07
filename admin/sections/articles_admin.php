<?php
/**
 * Articles / Actualités
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Auth::verifyCsrf()) {
    adminFlash('error','CSRF'); Helpers::redirect(u('/admin/articles'));
}

// ── Migration colonnes manquantes ────────────────────────────
try { Database::run("ALTER TABLE cc_articles ADD COLUMN IF NOT EXISTS access_mode VARCHAR(20) DEFAULT 'public'"); } catch(Exception $e) {}
try { Database::run("ALTER TABLE cc_articles ADD COLUMN IF NOT EXISTS access_message TEXT DEFAULT NULL"); } catch(Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_article'])) {
    $id   = (int)($_POST['article_id'] ?? 0);
    $data = [
        'title'        => Helpers::sanitize($_POST['title'] ?? ''),
        'excerpt'      => Helpers::sanitize($_POST['excerpt'] ?? ''),
        'content'      => json_encode(array_values($_POST['blocks'] ?? []), JSON_UNESCAPED_UNICODE),
        'published'    => (int)($_POST['published'] ?? 0),
        'access_mode'   => in_array($_POST['access_mode']??'',['public','teaser','members'])?$_POST['access_mode']:'public',
        'access_message'=> Helpers::sanitize($_POST['access_message']??''),
        'type'         => 'article',
        'user_id'      => Auth::id(),
    ];
    if (!empty($_FILES['cover']['tmp_name'])) {
        @mkdir(CC_ROOT.'/assets/uploads/articles', 0755, true);
        $up = Helpers::uploadImage($_FILES['cover'], CC_ROOT.'/assets/uploads/articles', 5);
        if ($up['success']) $data['cover'] = 'assets/uploads/articles/'.$up['filename'];
        else adminFlash('error', 'Erreur upload image : '.$up['error']);
    } elseif ($id) {
        // Pas de nouveau fichier → conserver l'image existante
        $existing = Database::one("SELECT cover FROM cc_articles WHERE id=?", [$id]);
        if ($existing && $existing['cover']) $data['cover'] = $existing['cover'];
    }
    if ($id) {
        $sets = implode(',', array_map(fn($k)=>"`$k`=?", array_keys($data)));
        Database::run("UPDATE cc_articles SET $sets, updated_at=NOW() WHERE id=?", [...array_values($data),$id]);
        adminFlash('success','Article sauvegardé.');
    } else {
        $data['slug'] = Helpers::uniqueSlug($data['title'], 'cc_articles');
        $cols = implode(',', array_map(fn($k)=>"`$k`", array_keys($data)));
        $vals = implode(',', array_fill(0, count($data), '?'));
        Database::insert("INSERT INTO cc_articles ($cols, created_at) VALUES ($vals, NOW())", array_values($data));
        adminFlash('success','Article créé.');
    }
    Helpers::redirect(u('/admin/articles'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_article'])) {
    Database::run("DELETE FROM cc_articles WHERE id=? AND type='article'", [(int)$_POST['article_id']]);
    adminFlash('success','Article supprimé.');
    Helpers::redirect(u('/admin/articles'));
}

$editId  = (int)($_GET['edit'] ?? -1);
$editArt = ($editId > 0) ? Database::one("SELECT * FROM cc_articles WHERE id=? AND type='article'", [$editId]) : null;
$articles= Database::all("SELECT a.*,u.firstname,u.lastname FROM cc_articles a JOIN cc_users u ON a.user_id=u.id WHERE a.type='article' ORDER BY a.created_at DESC LIMIT 100");

$tbStyle = 'background:#fff;border:1px solid #e2e8f0;border-radius:4px;padding:.2rem .55rem;font-size:.82rem;cursor:pointer;font-family:var(--font-body)';

$pageTitle = 'Articles & Actualités';
ob_start();
?>

<div class="page-head">
  <h1>📰 Articles & Actualités</h1>
  <?php if(!isset($_GET['edit'])): ?>
  <a href="<?=u('/admin/articles?edit=0')?>" class="btn btn-primary">+ Nouvel article</a>
  <?php else: ?>
  <a href="<?=u('/admin/articles')?>" class="btn btn-ghost">← Retour à la liste</a>
  <?php endif; ?>
</div>

<?php if(isset($_GET['edit'])): ?>
<!-- ════ ÉDITEUR ════ -->
<div class="ac">
  <div class="ac-header">
    <h2><?=$editArt ? '✏️ '.Helpers::e($editArt['title']) : '+ Nouvel article'?></h2>
  </div>
  <div class="ac-body">
    <form method="post" enctype="multipart/form-data">
      <?=Auth::csrfField()?>
      <?php if($editArt): ?><input type="hidden" name="article_id" value="<?=$editArt['id']?>"><?php endif; ?>

      <div class="form-row">
        <div class="fg span2">
          <label>Titre de l'article *</label>
          <input type="text" name="title" value="<?=Helpers::e($editArt['title']??'')?>" required placeholder="Ex: Résultats du championnat régional">
        </div>
        <div class="fg span2">
          <label>Résumé (affiché dans la liste des actualités)</label>
          <input type="text" name="excerpt" value="<?=Helpers::e($editArt['excerpt']??'')?>" placeholder="Une phrase résumant l'article…">
        </div>
        <?php if($editArt): ?>
        <div class="fg span2">
          <label>URL de l'article</label>
          <div style="display:flex;align-items:center;gap:.5rem">
            <code style="background:#f1f5f9;border:1.5px solid #e2e8f0;border-radius:7px;padding:.5rem .875rem;flex:1;font-size:.875rem;color:#64748b">
              index.php?route=<?=Helpers::e($editArt['slug']??'')?>
            </code>
            <a href="<?=u('/'.($editArt['slug']??''))?>" target="_blank" class="btn btn-ghost btn-sm">👁 Voir</a>
          </div>
        </div>
        <?php endif; ?>
        <div class="fg">
          <label>Image de couverture</label>
          <input type="file" name="cover" accept="image/*">
          <?php if($editArt['cover']??null): ?>
          <img src="<?=asset($editArt['cover'])?>" style="height:60px;border-radius:6px;margin-top:.35rem;object-fit:cover">
          <?php endif; ?>
        </div>
      </div>

      <!-- Éditeur de blocs -->
      <div class="fg" style="margin-top:.5rem">
        <label style="display:block;font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#64748b;margin-bottom:.75rem">Contenu de l'article</label>
        <?php
        $blocks = [];
        if (!empty($editArt['content'])) {
            $decoded = @json_decode($editArt['content'], true);
            if (is_array($decoded)) {
                $blocks = $decoded;
            } else {
                $blocks = [['type'=>'paragraph','content'=>$editArt['content']]];
            }
        }
        $fieldPrefix = 'blocks';
        include CC_ROOT . '/admin/partials/block_editor.php';
        include_once CC_ROOT . '/admin/partials/block_js.php';
        ?>
      </div>

      <div style="display:flex;gap:1.5rem;margin-top:.875rem">
        <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;text-transform:none;letter-spacing:0;font-size:.875rem;font-weight:400">
          <input type="checkbox" name="published" value="1" <?=($editArt['published']??0)?'checked':''?>>
          ✅ Publié <span style="color:#94a3b8;font-size:.78rem">(visible par tout le monde)</span>
        </label>
        <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;text-transform:none;letter-spacing:0;font-size:.875rem;font-weight:400">
          <input type="checkbox" name="require_login" value="1" <?=($editArt['require_login']??0)?'checked':''?>>
          🔒 Membres uniquement
        </label>
      </div>

      <div style="display:flex;justify-content:flex-end;gap:.75rem;margin-top:1.25rem">
        <a href="<?=u('/admin/articles')?>" class="btn btn-ghost">Annuler</a>
        <button type="submit" name="save_article" class="btn btn-primary" style="font-size:1rem;padding:.75rem 2rem">💾 Sauvegarder</button>
      </div>
    </form>
  </div>
</div>

<?php else: ?>
<!-- ════ LISTE ════ -->
<?php if(empty($articles)): ?>
<div style="text-align:center;padding:4rem;background:#fff;border:1px solid #e2e8f0;border-radius:12px">
  <div style="font-size:3rem;margin-bottom:1rem">📰</div>
  <h2 style="font-family:var(--font-heading);font-size:1.5rem;margin-bottom:.5rem">Aucun article</h2>
  <p style="color:#64748b;margin-bottom:1.5rem">Commencez par créer votre premier article.</p>
  <a href="<?=u('/admin/articles?edit=0')?>" class="btn btn-primary">+ Créer le premier article</a>
</div>
<?php else: ?>
<div class="ac">
  <div class="at-wrap"><table class="at">
    <thead>
      <tr><th>Titre</th><th>URL (à copier pour les liens)</th><th>Publié</th><th>Auteur</th><th>Date</th><th>Actions</th></tr>
    </thead>
    <tbody>
      <?php foreach($articles as $a): ?>
      <tr>
        <td>
          <strong><?=Helpers::e($a['title'])?></strong>
          <?php if($a['excerpt']): ?><br><small style="color:#94a3b8"><?=Helpers::e(Helpers::excerpt($a['excerpt'],50))?></small><?php endif; ?>
        </td>
        <td>
          <code style="background:#f1f5f9;padding:.1rem .35rem;border-radius:3px;font-size:.75rem;user-select:all">index.php?route=<?=Helpers::e($a['slug'])?></code>
        </td>
        <td>
          <?php if($a['published']): ?>
            <span class="badge badge-success">✅ Publié</span>
          <?php else: ?>
            <span class="badge badge-error">⭕ Non publié</span>
          <?php endif; ?>
        </td>
        <td style="font-size:.82rem"><?=Helpers::e($a['firstname'].' '.$a['lastname'])?></td>
        <td style="font-size:.78rem"><?=Helpers::dateFormat($a['created_at'])?></td>
        <td style="display:flex;gap:.35rem">
          <a href="<?=u('/admin/articles?edit='.$a['id'])?>" class="btn btn-ghost btn-sm">✏️ Modifier</a>
          <a href="<?=u('/'.$a['slug'])?>" target="_blank" class="btn btn-ghost btn-sm">👁</a>
          <form method="post" onsubmit="return confirm('Supprimer cet article ?')" style="display:inline">
            <?=Auth::csrfField()?>
            <input type="hidden" name="article_id" value="<?=$a['id']?>">
            <button type="submit" name="delete_article" class="btn btn-danger btn-sm">🗑️</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>
<?php endif; ?>

<script>
function ins(open, close) {
  const ta = document.getElementById('art-content');
  if(!ta) return;
  const s=ta.selectionStart, e=ta.selectionEnd, sel=ta.value.substring(s,e);
  ta.value = ta.value.substring(0,s)+open+sel+close+ta.value.substring(e);
  ta.focus();
  ta.setSelectionRange(s+open.length, s+open.length+sel.length);
}
</script>
<?php
$content = ob_get_clean();
include CC_ROOT . '/admin/layout.php';
