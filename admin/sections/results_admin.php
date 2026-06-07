<?php
Auth::require('admin');
if ($_SERVER['REQUEST_METHOD']==='POST' && !Auth::verifyCsrf()) { adminFlash('error','CSRF'); Helpers::redirect(u('/admin/results')); }

try {
    Database::run("CREATE TABLE IF NOT EXISTS cc_results_categories (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(200) NOT NULL, slug VARCHAR(200) NOT NULL UNIQUE, icon VARCHAR(10) DEFAULT '🏆', `order` INT DEFAULT 0)");
    Database::run("CREATE TABLE IF NOT EXISTS cc_results (id INT AUTO_INCREMENT PRIMARY KEY, category_id INT NOT NULL, title VARCHAR(200) NOT NULL, date DATE, source_type VARCHAR(20) DEFAULT 'manual', iframe_url VARCHAR(500), content TEXT, published TINYINT(1) DEFAULT 1, created_by INT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
} catch(Exception $e) {}

if (isset($_POST['save_category'])) {
    $id   = (int)($_POST['cat_id']??0);
    $name = Helpers::sanitize($_POST['name']??'');
    $icon = Helpers::sanitize($_POST['icon']??'🏆');
    if ($id) {
        Database::run("UPDATE cc_results_categories SET name=?,icon=? WHERE id=?", [$name,$icon,$id]);
    } else {
        $slug = Helpers::uniqueSlug($name, 'cc_results_categories');
        Database::run("INSERT INTO cc_results_categories (name,slug,icon) VALUES (?,?,?)", [$name,$slug,$icon]);
    }
    adminFlash('success','Catégorie sauvegardée.'); Helpers::redirect(u('/admin/results'));
}
if (isset($_POST['delete_category'])) {
    Database::run("DELETE FROM cc_results WHERE category_id=?", [(int)$_POST['cat_id']]);
    Database::run("DELETE FROM cc_results_categories WHERE id=?", [(int)$_POST['cat_id']]);
    adminFlash('success','Catégorie supprimée.'); Helpers::redirect(u('/admin/results'));
}
if (isset($_POST['save_result'])) {
    $id     = (int)($_POST['result_id']??0);
    $catId  = (int)$_POST['category_id'];
    $title  = Helpers::sanitize($_POST['title']??'');
    $date   = $_POST['date']??null ?: null;
    $type   = in_array($_POST['source_type']??'',['manual','iframe','gsheet']) ? $_POST['source_type'] : 'manual';
    $iframe = Helpers::sanitize($_POST['iframe_url']??'');
    $content= null;
    if ($type==='manual' && !empty($_POST['rows'])) {
        $rows = [];
        foreach($_POST['rows'] as $r) { if(array_filter($r)) $rows[] = array_map('trim', $r); }
        $content = json_encode(['headers'=>$_POST['headers']??[],'rows'=>$rows], JSON_UNESCAPED_UNICODE);
    }
    $pub = (int)($_POST['published']??1);
    if ($id) {
        Database::run("UPDATE cc_results SET category_id=?,title=?,date=?,source_type=?,iframe_url=?,content=?,published=? WHERE id=?",
            [$catId,$title,$date,$type,$iframe?:null,$content,$pub,$id]);
    } else {
        Database::run("INSERT INTO cc_results (category_id,title,date,source_type,iframe_url,content,published,created_by) VALUES (?,?,?,?,?,?,?,?)",
            [$catId,$title,$date,$type,$iframe?:null,$content,$pub,Auth::id()]);
    }
    adminFlash('success','Résultat sauvegardé.'); Helpers::redirect(u('/admin/results?cat='.$catId));
}
if (isset($_POST['delete_result'])) {
    Database::run("DELETE FROM cc_results WHERE id=?", [(int)$_POST['result_id']]);
    adminFlash('success','Supprimé.'); Helpers::redirect(u('/admin/results'));
}

$cats    = Database::all("SELECT * FROM cc_results_categories ORDER BY `order`,name");
$catId   = (int)($_GET['cat']??0);
$results = $catId ? Database::all("SELECT * FROM cc_results WHERE category_id=? ORDER BY date DESC, created_at DESC",[$catId]) : [];
$curCat  = $catId ? Database::one("SELECT * FROM cc_results_categories WHERE id=?",[$catId]) : null;
$editRes = isset($_GET['edit']) ? Database::one("SELECT * FROM cc_results WHERE id=?",[(int)$_GET['edit']]) : null;

$pageTitle = '🏆 Classements & Résultats';
ob_start(); ?>

<div class="page-head">
  <h1>🏆 Classements & Résultats</h1>
  <a href="<?=u('/resultats')?>" class="btn btn-ghost btn-sm" target="_blank">Voir la page →</a>
</div>

<div style="display:grid;grid-template-columns:240px 1fr;gap:1.25rem;align-items:start">
  <!-- Catégories -->
  <div class="ac">
    <div class="ac-header"><h2>📁 Catégories</h2></div>
    <div class="ac-body" style="padding:.5rem">
      <a href="<?=u('/admin/results')?>" class="btn <?=!$catId?'btn-primary':'btn-ghost'?> btn-sm" style="display:block;margin-bottom:.5rem;width:100%">Toutes</a>
      <?php foreach($cats as $c): ?>
      <div style="display:flex;align-items:center;gap:.25rem;margin-bottom:.25rem">
        <a href="<?=u('/admin/results?cat='.$c['id'])?>" style="flex:1;display:block;padding:.4rem .75rem;border-radius:8px;text-decoration:none;font-size:.85rem;font-weight:<?=$catId===$c['id']?'700':'400'?>;background:<?=$catId===$c['id']?'var(--color-primary)':'#f8fafc'?>;color:<?=$catId===$c['id']?'#fff':'#475569'?>">
          <?=$c['icon']?> <?=Helpers::e($c['name'])?>
        </a>
        <form method="post" onsubmit="return confirm('Supprimer ?')" style="margin:0">
          <?=Auth::csrfField()?><input type="hidden" name="cat_id" value="<?=$c['id']?>">
          <button type="submit" name="delete_category" style="background:none;border:none;cursor:pointer;color:#dc2626;opacity:.4" onmouseover="this.style.opacity=1" onmouseout="this.style.opacity=.4">🗑</button>
        </form>
      </div>
      <?php endforeach; ?>
      <form method="post" style="display:flex;flex-direction:column;gap:.35rem;margin-top:.75rem;padding-top:.75rem;border-top:1px solid #f1f5f9">
        <?=Auth::csrfField()?>
        <div style="display:grid;grid-template-columns:40px 1fr;gap:.35rem">
          <input type="text" name="icon" class="bi" value="🏆" style="text-align:center;padding:.4rem .2rem">
          <input type="text" name="name" class="bi" placeholder="Nouvelle catégorie…" required>
        </div>
        <button type="submit" name="save_category" class="btn btn-primary btn-sm">+ Créer</button>
      </form>
    </div>
  </div>

  <!-- Résultats -->
  <div>
    <?php if(!$catId): ?>
    <div class="ac"><div class="ac-body" style="text-align:center;padding:2rem;color:#94a3b8">← Sélectionnez une catégorie</div></div>
    <?php else: ?>
    <!-- Formulaire ajout/édition -->
    <div class="ac" style="margin-bottom:1.25rem">
      <div class="ac-header"><h2><?=$editRes?'✏️ Modifier':'+ Ajouter un résultat'?></h2></div>
      <div class="ac-body">
        <form method="post" id="res-form">
          <?=Auth::csrfField()?>
          <input type="hidden" name="category_id" value="<?=$catId?>">
          <?php if($editRes): ?><input type="hidden" name="result_id" value="<?=$editRes['id']?>"><?php endif; ?>
          <div style="display:grid;grid-template-columns:1fr 160px;gap:.75rem;margin-bottom:.75rem">
            <div><label style="font-size:.78rem;font-weight:600;color:#64748b;display:block;margin-bottom:.25rem">Titre</label>
              <input type="text" name="title" class="bi" value="<?=Helpers::e($editRes['title']??'')?>" required placeholder="Ex: Championnat régional 2024"></div>
            <div><label style="font-size:.78rem;font-weight:600;color:#64748b;display:block;margin-bottom:.25rem">Date</label>
              <input type="date" name="date" class="bi" value="<?=$editRes['date']??''?>"></div>
          </div>
          <div style="margin-bottom:.75rem">
            <label style="font-size:.78rem;font-weight:600;color:#64748b;display:block;margin-bottom:.35rem">Source du classement</label>
            <div style="display:flex;gap:.75rem;flex-wrap:wrap">
              <?php foreach(['manual'=>'📝 Tableau manuel','iframe'=>'🖼 Iframe (Google Sheets, Sporteasy…)','gsheet'=>'📊 Google Sheets public'] as $v=>$l): ?>
              <label style="display:flex;align-items:center;gap:.35rem;font-size:.85rem;cursor:pointer">
                <input type="radio" name="source_type" value="<?=$v?>" <?=($editRes['source_type']??'manual')===$v?'checked':''?> onchange="showSource('<?=$v?>')">
                <?=$l?>
              </label>
              <?php endforeach; ?>
            </div>
          </div>
          <!-- Iframe -->
          <div id="src-iframe" style="display:<?=($editRes['source_type']??'')!=='manual'?'block':'none'?>;margin-bottom:.75rem">
            <label style="font-size:.78rem;font-weight:600;color:#64748b;display:block;margin-bottom:.25rem">URL (iframe ou Google Sheets public)</label>
            <input type="url" name="iframe_url" class="bi" value="<?=Helpers::e($editRes['iframe_url']??'')?>" placeholder="https://docs.google.com/spreadsheets/d/...">
            <div style="font-size:.72rem;color:#94a3b8;margin-top:.25rem">Google Sheets : Fichier → Partager → Publier sur le web → copier l'URL</div>
          </div>
          <!-- Tableau manuel avec colonnes configurables -->
          <div id="src-manual" style="display:<?=($editRes['source_type']??'manual')==='manual'?'block':'none'?>;margin-bottom:.75rem">
            <?php
            $tblData = json_decode($editRes['content']??'{}', true);
            $headers = $tblData['headers'] ?? [['label'=>'#','type'=>'text'],['label'=>'Nom','type'=>'text'],['label'=>'Points','type'=>'text']];
            // Compatibilité ancienne structure (tableau simple de strings)
            if (!empty($headers) && is_string($headers[0])) {
                $headers = array_map(fn($h)=>['label'=>$h,'type'=>'text'], $headers);
            }
            $rows = $tblData['rows'] ?? [['','',''],['','',''],['','','']];
            $colTypes = ['text'=>'Texte','number'=>'Nombre','time'=>'Temps (chrono)','photo'=>'Photo (URL)'];
            ?>
            <!-- En-têtes configurables -->
            <div style="margin-bottom:.75rem">
              <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.5rem">
                <label style="font-size:.78rem;font-weight:600;color:#64748b">Colonnes</label>
                <button type="button" onclick="addCol()" class="btn btn-ghost btn-sm">+ Colonne</button>
              </div>
              <div id="cols-list" style="display:flex;gap:.5rem;flex-wrap:wrap">
                <?php foreach($headers as $ci=>$hdr): ?>
                <div class="col-item" style="display:flex;align-items:center;gap:.35rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:.35rem .5rem">
                  <input type="text" name="headers[<?=$ci?>][label]" value="<?=Helpers::e($hdr['label']??'')?>"
                    style="border:none;background:none;font-weight:600;font-size:.8rem;width:80px">
                  <select name="headers[<?=$ci?>][type]" class="bi" style="font-size:.72rem;padding:.2rem .3rem">
                    <?php foreach($colTypes as $v=>$l): ?>
                    <option value="<?=$v?>" <?=($hdr['type']??'text')===$v?'selected':''?>><?=$l?></option>
                    <?php endforeach; ?>
                  </select>
                  <button type="button" onclick="removeCol(this)" style="background:none;border:none;cursor:pointer;color:#dc2626;font-size:.8rem">✕</button>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
            <!-- Tableau -->
            <div style="overflow-x:auto">
            <table style="width:100%;border-collapse:collapse;min-width:400px" id="result-table">
              <thead><tr>
                <?php foreach($headers as $i=>$h): ?>
                <th style="padding:.35rem .5rem;background:#f8fafc;border:1px solid #e2e8f0;font-size:.78rem;font-weight:700;white-space:nowrap">
                  <?=Helpers::e($h['label']??'')?><?=$h['type']==='photo'?' 🖼':''?>
                </th>
                <?php endforeach; ?>
                <th style="padding:.35rem;background:#f8fafc;border:1px solid #e2e8f0;width:30px"></th>
              </tr></thead>
              <tbody>
                <?php foreach($rows as $ri=>$row): ?>
                <tr>
                  <?php foreach($headers as $ci=>$h):
                    $cell = $row[$ci] ?? '';
                    $colType = $h['type']??'text';
                  ?>
                  <td style="border:1px solid #e2e8f0;padding:.25rem">
                    <input type="text" name="rows[<?=$ri?>][<?=$ci?>]" value="<?=Helpers::e($cell)?>"
                      placeholder="<?=$colType==='photo'?'https://...':($colType==='time'?'00:00:00':'')?>"
                      style="width:100%;border:none;font-size:.82rem;padding:.2rem .35rem;min-width:<?=$colType==='photo'?'160px':'60px'?>">
                  </td>
                  <?php endforeach; ?>
                  <td style="border:1px solid #e2e8f0;text-align:center">
                    <button type="button" onclick="this.closest('tr').remove()" style="background:none;border:none;cursor:pointer;color:#dc2626;font-size:.8rem">✕</button>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            </div>
            <button type="button" onclick="addRow()" class="btn btn-ghost btn-sm" style="margin-top:.5rem">+ Ligne</button>
          </div>
          <div style="display:flex;gap:.75rem;align-items:center">
            <button type="submit" name="save_result" class="btn btn-primary">💾 Sauvegarder</button>
            <label style="display:flex;align-items:center;gap:.4rem;font-size:.85rem">
              <input type="checkbox" name="published" value="1" <?=($editRes['published']??1)?'checked':''?>> Publié
            </label>
            <?php if($editRes): ?><a href="<?=u('/admin/results?cat='.$catId)?>" class="btn btn-ghost">Annuler</a><?php endif; ?>
          </div>
        </form>
      </div>
    </div>

    <!-- Liste des résultats -->
    <?php if(!empty($results)): ?>
    <div class="ac">
      <div class="ac-header"><h2>📋 Résultats — <?=Helpers::e($curCat['name'])?></h2></div>
      <div class="at-wrap"><table class="at">
        <thead><tr><th>Titre</th><th>Date</th><th>Type</th><th>Statut</th><th></th></tr></thead>
        <tbody>
        <?php foreach($results as $r): ?>
        <tr>
          <td><strong><?=Helpers::e($r['title'])?></strong></td>
          <td><?=$r['date']?(new DateTime($r['date']))->format('d/m/Y'):'-'?></td>
          <td style="font-size:.78rem"><?=['manual'=>'📝 Manuel','iframe'=>'🖼 Iframe','gsheet'=>'📊 GSheets'][$r['source_type']]?></td>
          <td><?=$r['published']?'<span style="color:#16a34a;font-size:.78rem">✅ Publié</span>':'<span style="color:#94a3b8;font-size:.78rem">⬜ Brouillon</span>'?></td>
          <td style="display:flex;gap:.35rem">
            <a href="<?=u('/admin/results?cat='.$catId.'&edit='.$r['id'])?>" class="btn btn-ghost btn-sm">✏️</a>
            <form method="post" onsubmit="return confirm('Supprimer ?')" style="margin:0">
              <?=Auth::csrfField()?><input type="hidden" name="result_id" value="<?=$r['id']?>">
              <button type="submit" name="delete_result" class="btn btn-sm" style="background:#fee2e2;color:#dc2626;border:1.5px solid #fecaca">🗑</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<script>
function showSource(v){
  document.getElementById('src-manual').style.display=v==='manual'?'block':'none';
  document.getElementById('src-iframe').style.display=v!=='manual'?'block':'none';
}
function addRow(){
  var tb=document.querySelector('#result-table tbody');
  var hdrs=document.querySelectorAll('#result-table thead th');
  var tr=document.createElement('tr');
  var ri=Date.now();
  var colCount=hdrs.length-1; // -1 pour le th vide (bouton suppr)
  for(var i=0;i<colCount;i++){
    tr.innerHTML+='<td style="border:1px solid #e2e8f0;padding:.25rem"><input type="text" name="rows['+ri+']['+i+']" style="width:100%;border:none;font-size:.82rem;padding:.2rem .35rem"></td>';
  }
  tr.innerHTML+='<td style="border:1px solid #e2e8f0;text-align:center"><button type="button" onclick="this.closest(\'tr\').remove()" style="background:none;border:none;cursor:pointer;color:#dc2626;font-size:.8rem">✕</button></td>';
  tb.appendChild(tr);
}
function addCol(){
  var list=document.getElementById('cols-list');
  var ci=list.querySelectorAll('.col-item').length;
  var types=['text','number','time','photo'];
  var labels=['Texte','Nombre','Temps (chrono)','Photo (URL)'];
  var opts=types.map((v,i)=>'<option value="'+v+'">'+labels[i]+'</option>').join('');
  var d=document.createElement('div');
  d.className='col-item';
  d.style='display:flex;align-items:center;gap:.35rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:.35rem .5rem';
  d.innerHTML='<input type="text" name="headers['+ci+'][label]" placeholder="Colonne" style="border:none;background:none;font-weight:600;font-size:.8rem;width:80px" oninput="syncColHeader(this,'+ci+')">'
    +'<select name="headers['+ci+'][type]" class="bi" style="font-size:.72rem;padding:.2rem .3rem">'+opts+'</select>'
    +'<button type="button" onclick="removeCol(this,'+ci+')" style="background:none;border:none;cursor:pointer;color:#dc2626;font-size:.8rem">✕</button>';
  list.appendChild(d);
  // Ajouter la colonne dans le tableau immédiatement
  var thead=document.querySelector('#result-table thead tr');
  var lastTh=thead.lastElementChild; // le th vide (suppr)
  var newTh=document.createElement('th');
  newTh.style='padding:.35rem .5rem;background:#f8fafc;border:1px solid #e2e8f0;font-size:.78rem;font-weight:700';
  newTh.id='th-col-'+ci;
  newTh.textContent='Colonne';
  thead.insertBefore(newTh,lastTh);
  // Ajouter une cellule dans chaque ligne existante
  document.querySelectorAll('#result-table tbody tr').forEach(function(tr){
    var lastTd=tr.lastElementChild;
    var ri=tr.querySelector('input[name]')?.name.match(/rows\[(\d+)\]/)?.[1]||Date.now();
    var td=document.createElement('td');
    td.style='border:1px solid #e2e8f0;padding:.25rem';
    td.innerHTML='<input type="text" name="rows['+ri+']['+ci+']" style="width:100%;border:none;font-size:.82rem;padding:.2rem .35rem">';
    tr.insertBefore(td,lastTd);
  });
}
function syncColHeader(input, ci){
  var th=document.getElementById('th-col-'+ci);
  if(th) th.textContent=input.value||'Colonne';
}
function removeCol(btn, ci){
  btn.closest('.col-item').remove();
  // Retirer aussi la colonne du tableau
  var th=document.getElementById('th-col-'+ci);
  if(th) th.remove();
}
</script>

<?php
$content = ob_get_clean();
include CC_ROOT . '/admin/layout.php';
