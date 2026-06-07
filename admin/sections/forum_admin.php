<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Auth::verifyCsrf()) { adminFlash('error','CSRF'); Helpers::redirect(u('/admin/forum')); }

// Créer / modifier catégorie
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_cat'])) {
    $id   = (int)($_POST['cat_id'] ?? 0);
    $data = [
        'name'        => Helpers::sanitize($_POST['name'] ?? ''),
        'description' => Helpers::sanitize($_POST['description'] ?? ''),
        'icon'        => Helpers::sanitize($_POST['icon'] ?? '💬'),
        'require_login'=> (int)($_POST['require_login'] ?? 1),
        'order'       => (int)($_POST['order'] ?? 0),
    ];
    if ($id) {
        $sets = implode(',',array_map(fn($k)=>"`$k`=?",array_keys($data)));
        Database::run("UPDATE cc_forum_categories SET $sets WHERE id=?", [...array_values($data),$id]);
    } else {
        $data['slug'] = Helpers::uniqueSlug($data['name'],'cc_forum_categories');
        $cols = implode(',',array_map(fn($k)=>"`$k`",array_keys($data)));
        $vals = implode(',',array_fill(0,count($data),'?'));
        Database::insert("INSERT INTO cc_forum_categories ($cols) VALUES ($vals)", array_values($data));
    }
    adminFlash('success','Catégorie sauvegardée.');
    Helpers::redirect(u('/admin/forum'));
}

// Supprimer catégorie
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_cat'])) {
    $id = (int)($_POST['cat_id'] ?? 0);
    Database::run("DELETE FROM cc_forum_posts WHERE topic_id IN (SELECT id FROM cc_forum_topics WHERE category_id=?)",[$id]);
    Database::run("DELETE FROM cc_forum_topics WHERE category_id=?",[$id]);
    Database::run("DELETE FROM cc_forum_categories WHERE id=?",[$id]);
    adminFlash('success','Catégorie supprimée.');
    Helpers::redirect(u('/admin/forum'));
}

$cats  = Database::all("SELECT c.*, (SELECT COUNT(*) FROM cc_forum_topics t WHERE t.category_id=c.id) AS nb_topics FROM cc_forum_categories c ORDER BY c.order ASC");
$editCat = isset($_GET['edit']) ? Database::one("SELECT * FROM cc_forum_categories WHERE id=?",[(int)$_GET['edit']]) : null;

$pageTitle = 'Forum — Administration';
ob_start();
?>
<div class="page-head"><h1>💬 Forum</h1></div>
<div style="display:grid;grid-template-columns:1fr 360px;gap:1.5rem;align-items:start">
  <!-- Liste catégories -->
  <div class="ac">
    <div class="ac-header"><h2>Catégories</h2></div>
    <div class="at-wrap"><table class="at">
      <thead><tr><th>Icône</th><th>Nom</th><th>Topics</th><th>Ordre</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach($cats as $c): ?>
        <tr>
          <td style="font-size:1.2rem"><?=Helpers::e($c['icon']??'💬')?></td>
          <td><strong><?=Helpers::e($c['name'])?></strong><br><small style="color:#64748b"><?=Helpers::e($c['description']??'')?></small></td>
          <td><?=$c['nb_topics']?></td>
          <td><?=$c['order']?></td>
          <td style="display:flex;gap:.35rem">
            <a href="<?=u('/admin/forum?edit='.$c['id'])?>" class="btn btn-ghost btn-sm">✏️</a>
            <form method="post" style="display:inline" onsubmit="return confirm('Supprimer cette catégorie et tous ses topics ?')">
              <?=Auth::csrfField()?>
              <input type="hidden" name="cat_id" value="<?=$c['id']?>">
              <button type="submit" name="delete_cat" class="btn btn-danger btn-sm">🗑️</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($cats)): ?><tr><td colspan="5" style="text-align:center;color:#64748b;padding:2rem">Aucune catégorie</td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </div>

  <!-- Formulaire catégorie -->
  <div class="ac">
    <div class="ac-header"><h2><?=$editCat ? 'Modifier la catégorie' : 'Nouvelle catégorie'?></h2></div>
    <div class="ac-body">
      <form method="post">
        <?=Auth::csrfField()?>
        <?php if($editCat): ?><input type="hidden" name="cat_id" value="<?=$editCat['id']?>"><?php endif; ?>
        <div class="fg"><label>Nom *</label><input type="text" name="name" value="<?=Helpers::e($editCat['name']??'')?>" required></div>
        <div class="fg"><label>Description</label><input type="text" name="description" value="<?=Helpers::e($editCat['description']??'')?>"></div>
        <div class="fg"><label>Icône (emoji)</label><input type="text" name="icon" value="<?=Helpers::e($editCat['icon']??'💬')?>" maxlength="4" style="width:80px"></div>
        <div class="fg"><label>Ordre</label><input type="number" name="order" value="<?=$editCat['order']??0?>" style="width:80px"></div>
        <div class="fg">
          <label style="display:flex;align-items:center;gap:.5rem;text-transform:none;font-size:.875rem">
            <input type="checkbox" name="require_login" value="1" <?=($editCat['require_login']??1)?'checked':'?>'?>>
            Réservé aux membres connectés
          </label>
        </div>
        <div style="display:flex;gap:.5rem">
          <button type="submit" name="save_cat" class="btn btn-primary">💾 Sauvegarder</button>
          <?php if($editCat): ?><a href="<?=u('/admin/forum')?>" class="btn btn-ghost">Annuler</a><?php endif; ?>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Topics récents -->
<div class="ac" style="margin-top:1.5rem">
  <div class="ac-header"><h2>Topics récents</h2></div>
  <div class="at-wrap"><table class="at">
    <thead><tr><th>Titre</th><th>Catégorie</th><th>Auteur</th><th>Réponses</th><th>Épinglé</th><th>Verrouillé</th><th>Actions</th></tr></thead>
    <tbody>
      <?php foreach(Database::all("SELECT t.*, u.firstname, u.lastname, c.name AS cat_name, (SELECT COUNT(*) FROM cc_forum_posts p WHERE p.topic_id=t.id)-1 AS replies FROM cc_forum_topics t JOIN cc_users u ON t.user_id=u.id JOIN cc_forum_categories c ON t.category_id=c.id ORDER BY t.created_at DESC LIMIT 20") as $t): ?>
      <tr>
        <td><a href="/forum/topic/<?=Helpers::e($t['slug'])?>" target="_blank"><?=Helpers::e(Helpers::excerpt($t['title'],50))?></a></td>
        <td><?=Helpers::e($t['cat_name'])?></td>
        <td><?=Helpers::e($t['firstname'].' '.$t['lastname'])?></td>
        <td><?=$t['replies']?></td>
        <td><?=$t['pinned']?'📌':'—'?></td>
        <td><?=$t['locked']?'🔒':'—'?></td>
        <td>
          <button class="btn btn-ghost btn-sm" onclick="toggleTopic(<?=$t['id']?>,'pin')"><?=$t['pinned']?'Désépingler':'📌 Épingler'?></button>
          <button class="btn btn-ghost btn-sm" onclick="toggleTopic(<?=$t['id']?>,'lock')"><?=$t['locked']?'🔓':'🔒'?></button>
          <button class="btn btn-danger btn-sm" onclick="deleteTopic(<?=$t['id']?>)">🗑️</button>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php
$extraJs = "<script>
async function toggleTopic(id, action) {
  const r = await apiPost('/api/forum/topic/'+id+'/'+action, {});
  if(r.success) location.reload();
}
async function deleteTopic(id) {
  if(!confirm('Supprimer ce topic ?')) return;
  const r = await apiPost('/api/forum/topic/'+id, {_method:'DELETE'});
  if(r.success) location.reload();
  else Toast.show(r.error||'Erreur','error');
}
</script>";
$content = ob_get_clean();
include CC_ROOT . '/admin/layout.php';
