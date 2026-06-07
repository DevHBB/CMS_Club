<?php
/**
 * ClubCMS — Module Résultats & Classements
 */
try {
    Database::run("INSERT IGNORE INTO cc_modules (slug,label,enabled,require_login) VALUES ('results','Résultats',1,0)");
    Database::run("CREATE TABLE IF NOT EXISTS cc_results_categories (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(200) NOT NULL, slug VARCHAR(200) NOT NULL UNIQUE, icon VARCHAR(10) DEFAULT '🏆', `order` INT DEFAULT 0)");
    Database::run("CREATE TABLE IF NOT EXISTS cc_results (id INT AUTO_INCREMENT PRIMARY KEY, category_id INT NOT NULL, title VARCHAR(200) NOT NULL, date DATE, source_type VARCHAR(20) DEFAULT 'manual', iframe_url VARCHAR(500), content TEXT, published TINYINT(1) DEFAULT 1, created_by INT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
} catch(Exception $e) {}

$catSlug  = $segments[1] ?? null;
$resultId = (int)($_GET['r'] ?? 0);
try { $categories = Database::all("SELECT * FROM cc_results_categories ORDER BY `order`,name"); } catch(Exception $e) { $categories=[]; }

if ($catSlug) {
    $cat     = Database::one("SELECT * FROM cc_results_categories WHERE slug=?", [$catSlug]);
    if (!$cat) Helpers::redirect(u('/resultats'));
    $results = Database::all("SELECT * FROM cc_results WHERE category_id=? AND published=1 ORDER BY date DESC, created_at DESC", [$cat['id']]);
    $pageTitle = Helpers::e($cat['name']).' — Résultats';
} else {
    $pageTitle = 'Résultats & Classements';
}

ob_start();
$ph_t = Config::get('ph_results_title','');
$ph_s = Config::get('ph_results_subtitle','');
?>
<div class="gallery-hero">
  <div class="container">
    <h1 class="forum-title"><?=$ph_t?Helpers::e($ph_t):'🏆 Résultats & Classements'?></h1>
    <p class="forum-subtitle"><?=$ph_s?Helpers::e($ph_s):'Retrouvez tous nos résultats et classements'?></p>
  </div>
</div>

<div class="container" style="max-width:1000px;margin:0 auto;padding:2rem 0">

<?php if(!$catSlug): ?>
<!-- Liste des catégories -->
<?php if(empty($categories)): ?>
<div style="text-align:center;padding:4rem;color:#94a3b8;background:#f8fafc;border-radius:12px;border:2px dashed #e2e8f0">
  <div style="font-size:3rem;margin-bottom:.75rem">🏆</div>
  <div style="font-weight:600">Aucun résultat disponible pour le moment.</div>
</div>
<?php else: ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:1.25rem">
  <?php foreach($categories as $c):
    $count = (int)Database::scalar("SELECT COUNT(*) FROM cc_results WHERE category_id=? AND published=1", [$c['id']]);
    $last  = Database::one("SELECT title,date FROM cc_results WHERE category_id=? AND published=1 ORDER BY date DESC LIMIT 1", [$c['id']]);
  ?>
  <a href="<?=u('/resultats/'.$c['slug'])?>" style="display:block;text-decoration:none;border:1.5px solid #e2e8f0;border-radius:12px;padding:1.5rem;background:#fff;transition:all .15s;text-align:center"
    onmouseover="this.style.borderColor='var(--color-primary)';this.style.transform='translateY(-2px)'" onmouseout="this.style.borderColor='#e2e8f0';this.style.transform='none'">
    <div style="font-size:2.5rem;margin-bottom:.5rem"><?=$c['icon']?></div>
    <div style="font-weight:700;font-size:1rem;color:#1e293b;margin-bottom:.25rem"><?=Helpers::e($c['name'])?></div>
    <div style="font-size:.78rem;color:#94a3b8"><?=$count?> résultat<?=$count>1?'s':''?></div>
    <?php if($last): ?><div style="font-size:.72rem;color:#64748b;margin-top:.35rem">Dernier : <?=Helpers::e(mb_substr($last['title'],0,30))?></div><?php endif; ?>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php else: ?>
<!-- Résultats d'une catégorie -->
<nav style="font-size:.82rem;color:#94a3b8;margin-bottom:1.25rem">
  <a href="<?=u('/resultats')?>" style="color:var(--color-primary);text-decoration:none">Résultats</a> › <?=Helpers::e($cat['name'])?>
</nav>
<h2 style="font-size:1.35rem;font-weight:800;margin-bottom:1.25rem"><?=$cat['icon']?> <?=Helpers::e($cat['name'])?></h2>

<?php foreach($results as $r): ?>
<div style="border:1.5px solid #e2e8f0;border-radius:12px;overflow:hidden;background:#fff;margin-bottom:1.25rem">
  <div style="background:#f8fafc;padding:.875rem 1.25rem;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem">
    <h3 style="font-size:1rem;font-weight:700;margin:0"><?=Helpers::e($r['title'])?></h3>
    <?php if($r['date']): ?><span style="font-size:.78rem;color:#64748b;background:#e2e8f0;padding:.2rem .6rem;border-radius:99px"><?=(new DateTime($r['date']))->format('d/m/Y')?></span><?php endif; ?>
  </div>
  <div style="padding:1rem">
    <?php if($r['source_type']==='manual'): ?>
    <?php
    $tbl  = json_decode($r['content']??'{}', true);
    $hdrs = $tbl['headers'] ?? [];
    $rows = $tbl['rows']    ?? [];
    // Compat ancienne structure
    if (!empty($hdrs) && is_string($hdrs[0])) {
        $hdrs = array_map(fn($h)=>['label'=>$h,'type'=>'text'], $hdrs);
    }
    // Réindexer les rows en tableau simple
    $rows = array_values(array_map('array_values', $rows));
    ?>
    <?php if(!empty($hdrs)||!empty($rows)): ?>
    <div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:.875rem">
      <thead><tr>
        <?php foreach($hdrs as $h): ?>
        <th style="padding:.5rem .75rem;background:#f8fafc;border:1px solid #e2e8f0;font-weight:700;text-align:left"><?=Helpers::e($h['label']??$h)?></th>
        <?php endforeach; ?>
      </tr></thead>
      <tbody>
      <?php foreach($rows as $i=>$row): ?>
      <tr style="background:<?=$i%2===0?'#fff':'#f8fafc'?>">
        <?php foreach($hdrs as $ci=>$hdr):
          $cell    = $row[$ci] ?? '';
          $colType = $hdr['type'] ?? 'text';
        ?>
        <td style="padding:.5rem .75rem;border:1px solid #e2e8f0;<?=$ci===0?'font-weight:700':''?>">
          <?php if($colType==='photo' && $cell): ?>
          <img src="<?=Helpers::e($cell)?>" alt="" style="width:36px;height:36px;border-radius:50%;object-fit:cover;vertical-align:middle">
          <?php elseif($colType==='time'): ?>
          <span style="font-family:monospace;font-size:.875rem"><?=Helpers::e($cell)?></span>
          <?php elseif($colType==='number'): ?>
          <span style="font-weight:600;color:var(--color-primary)"><?=Helpers::e($cell)?></span>
          <?php else: ?>
          <?=Helpers::e($cell)?>
          <?php endif; ?>
        </td>
        <?php endforeach; ?>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
    <?php else: ?>
    <iframe src="<?=Helpers::e($r['iframe_url'])?>" style="width:100%;min-height:400px;border:none;border-radius:8px" loading="lazy"></iframe>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
<?php if(empty($results)): ?>
<div style="text-align:center;padding:3rem;color:#94a3b8;background:#f8fafc;border-radius:12px">Aucun résultat dans cette catégorie.</div>
<?php endif; ?>
<?php endif; ?>

</div>
<?php
$content = ob_get_clean();
include CC_ROOT . '/templates/layout.php';
