<?php
$pageTitle = 'Tableau de bord';
ob_start();
?>
<?php
$deleteRequests = [];
try {
    $delRows = Database::all("SELECT `key`, `value` FROM cc_config WHERE `key` LIKE 'delete_request_%' AND `group`='delete_requests' AND `value` != ''");
    foreach ($delRows as $row) {
        $d = json_decode($row['value'], true);
        if ($d && !empty($d['user_id'])) $deleteRequests[] = $d;
    }
} catch(Exception $e) {}
?>
<?php if(!empty($deleteRequests)): ?>
<div style="background:#fff5f5;border:1.5px solid #fecaca;border-radius:12px;padding:1rem 1.25rem;margin-bottom:1.25rem;display:flex;align-items:flex-start;gap:.875rem">
  <span style="font-size:1.5rem;flex-shrink:0">🗑</span>
  <div style="flex:1">
    <div style="font-weight:700;color:#dc2626;font-size:.95rem;margin-bottom:.35rem">
      <?=count($deleteRequests)?> demande<?=count($deleteRequests)>1?'s':''?> de suppression de compte en attente
    </div>
    <?php foreach($deleteRequests as $dr): ?>
    <div style="font-size:.82rem;color:#9b1c1c;margin-bottom:.25rem">
      👤 <strong><?=Helpers::e($dr['name']??'?')?></strong> (<?=Helpers::e($dr['email']??'')?>)
      <?php if(!empty($dr['reason'])): ?> — <em><?=Helpers::e($dr['reason'])?></em><?php endif; ?>
      &nbsp;<a href="<?=u('/admin/users')?>&search=<?=urlencode($dr['email']??'')?>" style="color:#dc2626;font-weight:600;text-decoration:none">→ Voir le membre</a>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>
<div class="page-head"><h1>🏠 Tableau de bord</h1></div>

<div class="stats-grid">
  <?php
  $stats = [
    ['👥', Database::scalar("SELECT COUNT(*) FROM cc_users WHERE status='active'"), 'Membres actifs'],
    ['⏳', Database::scalar("SELECT COUNT(*) FROM cc_users WHERE license_status='pending'"), 'Licences en attente'],
    ['🛒', Database::scalar("SELECT COUNT(*) FROM cc_shop_orders WHERE status='pending'"), 'Commandes en attente'],
    ['📅', Database::scalar("SELECT COUNT(*) FROM cc_planning_slots WHERE date_start >= NOW() AND published=1"), 'Créneaux à venir'],
    ['💬', Database::scalar("SELECT COUNT(*) FROM cc_forum_topics WHERE created_at > DATE_SUB(NOW(),INTERVAL 7 DAY)"), 'Topics cette semaine'],
    ['📨', Database::scalar("SELECT COUNT(*) FROM cc_newsletter_subscribers WHERE active=1"), 'Abonnés newsletter'],
  ];
  foreach ($stats as [$icon,$val,$lbl]):
  ?>
  <div class="stat-card"><div class="stat-icon"><?=$icon?></div><div class="stat-val"><?=$val?></div><div class="stat-lbl"><?=$lbl?></div></div>
  <?php endforeach; ?>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem">
  <!-- Derniers inscrits -->
  <div class="ac">
    <div class="ac-header"><h2>Derniers inscrits</h2><a href="<?=u('/admin/users')?>" class="btn btn-ghost btn-sm">Voir tout</a></div>
    <div class="at-wrap"><table class="at">
      <thead><tr><th>Nom</th><th>Email</th><th>Statut</th><th>Le</th></tr></thead>
      <tbody>
        <?php foreach(Database::all("SELECT * FROM cc_users ORDER BY created_at DESC LIMIT 6") as $u): ?>
        <tr>
          <td><strong><?=Helpers::e($u['firstname'].' '.$u['lastname'])?></strong></td>
          <td><?=Helpers::e($u['email'])?></td>
          <td><span class="badge badge-<?=$u['status']==='active'?'success':'warning'?>"><?=$u['status']?></span></td>
          <td><?=Helpers::dateFormat($u['created_at'])?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>

  <!-- Dernières commandes -->
  <div class="ac">
    <div class="ac-header"><h2>Dernières commandes</h2><a href="<?=u('/admin/shop')?>" class="btn btn-ghost btn-sm">Voir tout</a></div>
    <div class="at-wrap"><table class="at">
      <thead><tr><th>#</th><th>Total</th><th>Statut</th><th>Le</th></tr></thead>
      <tbody>
        <?php foreach(Database::all("SELECT * FROM cc_shop_orders ORDER BY created_at DESC LIMIT 6") as $o): ?>
        <tr>
          <td>#<?=$o['id']?></td>
          <td><?=Helpers::price($o['total'])?></td>
          <td><span class="badge badge-<?=$o['status']==='paid'?'success':($o['status']==='pending'?'warning':'muted')?>"><?=$o['status']?></span></td>
          <td><?=Helpers::dateFormat($o['created_at'])?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
</div>

<?php
try {
    Database::run("CREATE TABLE IF NOT EXISTS cc_page_views (id INT AUTO_INCREMENT PRIMARY KEY, page VARCHAR(200) NOT NULL, views INT DEFAULT 1, date DATE NOT NULL, UNIQUE KEY uq_pd (page,date))");
    $pvToday  = (int)Database::scalar("SELECT SUM(views) FROM cc_page_views WHERE date=CURDATE()");
    $pvWeek   = (int)Database::scalar("SELECT SUM(views) FROM cc_page_views WHERE date>=DATE_SUB(CURDATE(),INTERVAL 7 DAY)");
    $pvMonth  = (int)Database::scalar("SELECT SUM(views) FROM cc_page_views WHERE date>=DATE_SUB(CURDATE(),INTERVAL 30 DAY)");
    $pvPages  = Database::all("SELECT page, SUM(views) as total FROM cc_page_views WHERE date>=DATE_SUB(CURDATE(),INTERVAL 30 DAY) GROUP BY page ORDER BY total DESC LIMIT 8");
    $pvDays   = Database::all("SELECT date, SUM(views) as total FROM cc_page_views WHERE date>=DATE_SUB(CURDATE(),INTERVAL 14 DAY) GROUP BY date ORDER BY date ASC");
    $hasStats = true;
} catch(Exception $e) { $hasStats=false; }
?>
<?php if($hasStats): ?>
<div class="ac" style="margin-top:1.5rem">
  <div class="ac-header"><h2>📈 Statistiques de visites <small style="font-weight:400;color:#94a3b8;font-size:.78rem">(RGPD — aucun cookie, aucune donnée personnelle)</small></h2></div>
  <div class="ac-body">
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:1.25rem">
      <?php foreach([["Aujourd'hui",$pvToday,'📅'],["7 derniers jours",$pvWeek,'📆'],["30 derniers jours",$pvMonth,'🗓']] as [$l,$v,$ic]): ?>
      <div style="background:#f8fafc;border-radius:10px;padding:1rem;text-align:center">
        <div style="font-size:1.5rem"><?=$ic?></div>
        <div style="font-size:1.75rem;font-weight:800;color:var(--color-primary)"><?=number_format($v,0,',',' ')?></div>
        <div style="font-size:.78rem;color:#64748b"><?=$l?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem">
      <!-- Pages populaires -->
      <div>
        <div style="font-weight:700;font-size:.875rem;margin-bottom:.75rem;color:#475569">Pages populaires (30j)</div>
        <?php foreach($pvPages as $pv): $pct=$pvMonth>0?round($pv['total']/$pvMonth*100):0; ?>
        <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.5rem">
          <div style="font-size:.8rem;color:#374151;width:80px;flex-shrink:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=Helpers::e($pv['page'])?></div>
          <div style="flex:1;background:#f1f5f9;border-radius:99px;height:8px"><div style="width:<?=$pct?>%;background:var(--color-primary);border-radius:99px;height:8px;min-width:4px"></div></div>
          <div style="font-size:.78rem;color:#64748b;width:36px;text-align:right"><?=$pv['total']?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <!-- Graphique 14 jours -->
      <div>
        <div style="font-weight:700;font-size:.875rem;margin-bottom:.75rem;color:#475569">Visites — 14 derniers jours</div>
        <?php if(!empty($pvDays)):
          $maxV = max(array_column($pvDays,'total'));
        ?>
        <div style="display:flex;align-items:flex-end;gap:4px;height:80px">
          <?php foreach($pvDays as $d):
            $h = $maxV>0 ? max(4, round($d['total']/$maxV*80)) : 4;
          ?>
          <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:3px">
            <div title="<?=(new DateTime($d['date']))->format('d/m').' : '.$d['total']?> vues"
              style="width:100%;height:<?=$h?>px;background:var(--color-primary);border-radius:3px 3px 0 0;opacity:.8;cursor:default;transition:opacity .2s"
              onmouseover="this.style.opacity=1" onmouseout="this.style.opacity=.8"></div>
            <div style="font-size:.6rem;color:#94a3b8"><?=(new DateTime($d['date']))->format('d/m')?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
$content = ob_get_clean();
include CC_ROOT . '/admin/layout.php';
