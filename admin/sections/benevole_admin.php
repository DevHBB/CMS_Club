<?php
Auth::require('coach');
if (!Auth::isAdmin() && !Auth::canAccessBenevole()) {
    adminFlash('error','Accès refusé'); Helpers::redirect(u('/admin'));
}

// ── Création des tables bénévoles si inexistantes ─────────────
$benvMigrations = [
    "CREATE TABLE IF NOT EXISTS cc_benv_events (
        id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(200) NOT NULL,
        description TEXT, location VARCHAR(200), date_start DATETIME NOT NULL,
        date_end DATETIME, max_volunteers INT DEFAULT 0, created_by INT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        recurring VARCHAR(20) DEFAULT 'none', color VARCHAR(7) DEFAULT '#6366f1'
    )",
    "CREATE TABLE IF NOT EXISTS cc_benv_participations (
        id INT AUTO_INCREMENT PRIMARY KEY, event_id INT NOT NULL,
        user_id INT NOT NULL, status VARCHAR(20) DEFAULT 'confirmed',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_evt_user (event_id, user_id)
    )",
    "CREATE TABLE IF NOT EXISTS cc_benv_tasks (
        id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(200) NOT NULL,
        description TEXT, status VARCHAR(20) DEFAULT 'todo',
        priority VARCHAR(10) DEFAULT 'normal', assigned_to INT DEFAULT NULL,
        due_date DATE DEFAULT NULL, created_by INT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        recurring VARCHAR(20) DEFAULT 'none', color VARCHAR(7) DEFAULT '#6366f1'
    )",
    "CREATE TABLE IF NOT EXISTS cc_benv_chat (
        id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
        message TEXT NOT NULL, channel VARCHAR(50) DEFAULT 'general',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE TABLE IF NOT EXISTS cc_benv_alerts (
        id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(200) NOT NULL,
        message TEXT, level VARCHAR(10) DEFAULT 'info', active TINYINT(1) DEFAULT 1,
        created_by INT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME DEFAULT NULL
    )",
    "CREATE TABLE IF NOT EXISTS cc_benv_alerts_seen (
        id INT AUTO_INCREMENT PRIMARY KEY, alert_id INT NOT NULL,
        user_id INT NOT NULL, UNIQUE KEY uq_alert_user (alert_id, user_id)
    )",
    "CREATE TABLE IF NOT EXISTS cc_benv_folders (
        id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(200) NOT NULL,
        parent_id INT DEFAULT NULL, created_by INT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE TABLE IF NOT EXISTS cc_benv_docs (
        id INT AUTO_INCREMENT PRIMARY KEY, folder_id INT DEFAULT NULL,
        title VARCHAR(200) NOT NULL, type VARCHAR(10) DEFAULT 'note',
        content TEXT, filename VARCHAR(255) DEFAULT NULL, filesize INT DEFAULT NULL,
        created_by INT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )",
    "CREATE TABLE IF NOT EXISTS cc_benv_profiles (
        user_id INT PRIMARY KEY, skills TEXT, notes TEXT,
        blacklisted TINYINT(1) DEFAULT 0, blacklist_reason TEXT,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )",
    "CREATE TABLE IF NOT EXISTS cc_benv_coach_access (
        coach_id INT PRIMARY KEY, can_access TINYINT(1) DEFAULT 0,
        see_blacklist TINYINT(1) DEFAULT 0
    )",
    "CREATE TABLE IF NOT EXISTS cc_benv_reminders_sent (
        id INT AUTO_INCREMENT PRIMARY KEY, event_id INT NOT NULL,
        user_id INT NOT NULL, sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_rem (event_id, user_id)
    )",
];
foreach ($benvMigrations as $sql) {
    try { Database::run($sql); } catch(Exception $e) {}
}

$isAdmin = Auth::isAdmin();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Auth::verifyCsrf()) {
    adminFlash('error','CSRF'); Helpers::redirect(u('/admin/benevole'));
}

// ── Handlers POST ─────────────────────────────────────────────

// Toggle canal ouvert/fermé
if (isset($_POST['toggle_channel'])) {
    $cid  = (int)($_POST['channel_id'] ?? 0);
    $open = (int)($_POST['channel_open'] ?? 0);
    try { Database::run("UPDATE cc_benv_channels SET open=? WHERE id=?", [$open, $cid]); } catch(Exception $e) {}
    adminFlash('success', $open ? 'Canal ouvert.' : 'Canal fermé.');
    Helpers::redirect(u('/admin/benevole?tab=channels'));
}

// Créer canal
if (isset($_POST['create_channel'])) {
    $name = Helpers::sanitize($_POST['channel_name'] ?? '');
    $slug = preg_replace('/[^a-z0-9_-]/', '-', strtolower($name));
    if ($name && $slug) {
        try { Database::run("INSERT INTO cc_benv_channels (name,slug,open,created_by) VALUES (?,?,1,?)", [$name,$slug,Auth::id()]); } catch(Exception $e) {}
        adminFlash('success', 'Canal créé.');
    }
    Helpers::redirect(u('/admin/benevole?tab=channels'));
}

// Supprimer canal
if (isset($_POST['delete_channel'])) {
    $cid = (int)($_POST['channel_id'] ?? 0);
    try { Database::run("DELETE FROM cc_benv_channels WHERE id=?", [$cid]); } catch(Exception $e) {}
    adminFlash('success', 'Canal supprimé.');
    Helpers::redirect(u('/admin/benevole?tab=channels'));
}

// Droits bénévoles
if (isset($_POST['save_benv_rights'])) {
    $uid = (int)($_POST['benv_user_id'] ?? 0);
    if ($uid) {
        $data = [
            'can_add_tasks'       => isset($_POST['can_add_tasks'])       ? 1 : 0,
            'can_upload'          => isset($_POST['can_upload'])          ? 1 : 0,
            'can_manage_planning' => isset($_POST['can_manage_planning']) ? 1 : 0,
            'can_delete_notes'    => isset($_POST['can_delete_notes'])    ? 1 : 0,
        ];
        try {
            Database::run("INSERT INTO cc_benv_profiles (user_id,can_add_tasks,can_upload,can_manage_planning,can_delete_notes) VALUES (?,?,?,?,?)
                ON DUPLICATE KEY UPDATE can_add_tasks=VALUES(can_add_tasks),can_upload=VALUES(can_upload),can_manage_planning=VALUES(can_manage_planning),can_delete_notes=VALUES(can_delete_notes)",
                [$uid,$data['can_add_tasks'],$data['can_upload'],$data['can_manage_planning'],$data['can_delete_notes']]);
        } catch(Exception $e) {}
        adminFlash('success', 'Droits mis à jour.');
    }
    Helpers::redirect(u('/admin/benevole?tab=benevoles'));
}

// Muter un bénévole
if (isset($_POST['mute_user'])) {
    $uid   = (int)($_POST['mute_uid'] ?? 0);
    $until = Helpers::sanitize($_POST['mute_until'] ?? '') ?: null;
    if ($uid) {
        try { Database::run("INSERT INTO cc_benv_chat_muted (user_id,muted_by,until) VALUES (?,?,?) ON DUPLICATE KEY UPDATE until=VALUES(until),muted_by=VALUES(muted_by)", [$uid,Auth::id(),$until]); } catch(Exception $e) {}
        adminFlash('success', 'Utilisateur muté.');
    }
    Helpers::redirect(u('/admin/benevole?tab=benevoles'));
}

// Dé-muter
if (isset($_POST['unmute_user'])) {
    $uid = (int)($_POST['mute_uid'] ?? 0);
    try { Database::run("DELETE FROM cc_benv_chat_muted WHERE user_id=?", [$uid]); } catch(Exception $e) {}

try { Database::run("CREATE TABLE IF NOT EXISTS cc_benv_channels (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL, slug VARCHAR(100) NOT NULL, open TINYINT(1) DEFAULT 1, created_by INT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY slug (slug))"); } catch(Exception $e) {}
try { Database::run("CREATE TABLE IF NOT EXISTS cc_benv_chat_muted (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, muted_by INT NOT NULL, until DATETIME DEFAULT NULL, UNIQUE KEY uq_muted (user_id))"); } catch(Exception $e) {}
try { Database::run("ALTER TABLE cc_benv_profiles ADD COLUMN IF NOT EXISTS can_add_tasks TINYINT(1) DEFAULT 0"); } catch(Exception $e) {}
try { Database::run("ALTER TABLE cc_benv_profiles ADD COLUMN IF NOT EXISTS can_upload TINYINT(1) DEFAULT 0"); } catch(Exception $e) {}
try { Database::run("ALTER TABLE cc_benv_profiles ADD COLUMN IF NOT EXISTS can_manage_planning TINYINT(1) DEFAULT 0"); } catch(Exception $e) {}
try { Database::run("ALTER TABLE cc_benv_profiles ADD COLUMN IF NOT EXISTS can_delete_notes TINYINT(1) DEFAULT 0"); } catch(Exception $e) {}
try { Database::run("ALTER TABLE cc_benv_tasks ADD COLUMN IF NOT EXISTS time_start TIME DEFAULT NULL"); } catch(Exception $e) {}
try { Database::run("ALTER TABLE cc_benv_tasks ADD COLUMN IF NOT EXISTS time_end TIME DEFAULT NULL"); } catch(Exception $e) {}
try { Database::run("ALTER TABLE cc_benv_tasks ADD COLUMN IF NOT EXISTS recurring_days VARCHAR(50) DEFAULT NULL"); } catch(Exception $e) {}
// Canaux par défaut
try { $nb=(int)Database::scalar("SELECT COUNT(*) FROM cc_benv_channels"); if($nb===0){foreach([['Général','general'],['Organisation','organisation'],['Logistique','logistique']] as [$n,$s]){Database::run("INSERT IGNORE INTO cc_benv_channels (name,slug,open,created_by) VALUES (?,?,1,1)",[$n,$s]);}}} catch(Exception $e) {}

    adminFlash('success', 'Utilisateur dé-muté.');
    Helpers::redirect(u('/admin/benevole?tab=benevoles'));
}

// ── Handlers POST ─────────────────────────────────────────────

// Créer/modifier événement
if (isset($_POST['save_benv_event'])) {
    $eid   = (int)($_POST['event_id']??0);
    $title = Helpers::sanitize($_POST['title']??'');
    $desc  = Helpers::sanitize($_POST['description']??'');
    $loc   = Helpers::sanitize($_POST['location']??'');
    $ds    = str_replace('T',' ',Helpers::sanitize($_POST['date_start']??''));
    $de    = ($_POST['date_end']??'') ? str_replace('T',' ',Helpers::sanitize($_POST['date_end'])) : null;
    $max   = (int)($_POST['max_volunteers']??0);
    $rec   = in_array($_POST['recurring']??'',['none','daily','weekly','monthly'])?$_POST['recurring']:'none';
    $color = preg_match('/^#[0-9a-fA-F]{6}$/',$_POST['color']??'')?$_POST['color']:'#6366f1';
    if ($title && $ds) {
        if ($eid) {
            Database::run("UPDATE cc_benv_events SET title=?,description=?,location=?,date_start=?,date_end=?,max_volunteers=?,recurring=?,color=? WHERE id=?",
                [$title,$desc,$loc,$ds,$de,$max,$rec,$color,$eid]);
        } else {
            Database::run("INSERT INTO cc_benv_events (title,description,location,date_start,date_end,max_volunteers,recurring,color,created_by) VALUES (?,?,?,?,?,?,?,?,?)",
                [$title,$desc,$loc,$ds,$de,$max,$rec,$color,Auth::id()]);
        }
        adminFlash('success','Événement sauvegardé.');
    }
    Helpers::redirect(u('/admin/benevole?tab=events'));
}

if (isset($_POST['delete_benv_event'])) {
    Database::run("DELETE FROM cc_benv_events WHERE id=?", [(int)$_POST['event_id']]);
    Database::run("DELETE FROM cc_benv_participations WHERE event_id=?", [(int)$_POST['event_id']]);
    adminFlash('success','Événement supprimé.');
    Helpers::redirect(u('/admin/benevole?tab=events'));
}

// Créer/modifier tâche
if (isset($_POST['save_benv_task'])) {
    $tid   = (int)($_POST['task_id']??0);
    $title = Helpers::sanitize($_POST['title']??'');
    $desc  = Helpers::sanitize($_POST['description']??'');
    $prio  = in_array($_POST['priority']??'',['low','normal','high'])?$_POST['priority']:'normal';
    $st    = in_array($_POST['status']??'',['todo','inprogress','done'])?$_POST['status']:'todo';
    $due   = Helpers::sanitize($_POST['due_date']??'') ?: null;
    $rec   = in_array($_POST['recurring']??'',['none','daily','weekly','monthly'])?$_POST['recurring']:'none';
    $assignTo = (int)($_POST['assigned_to']??0) ?: null;
    $color = preg_match('/^#[0-9a-fA-F]{6}$/',$_POST['color']??'')?$_POST['color']:'#6366f1';
    if ($title) {
        if ($tid) {
            Database::run("UPDATE cc_benv_tasks SET title=?,description=?,priority=?,status=?,due_date=?,recurring=?,assigned_to=?,color=? WHERE id=?",
                [$title,$desc,$prio,$st,$due,$rec,$assignTo,$color,$tid]);
        } else {
            Database::run("INSERT INTO cc_benv_tasks (title,description,priority,status,due_date,recurring,assigned_to,color,created_by) VALUES (?,?,?,?,?,?,?,?,?)",
                [$title,$desc,$prio,$st,$due,$rec,$assignTo,$color,Auth::id()]);
        }
        adminFlash('success','Tâche sauvegardée.');
    }
    Helpers::redirect(u('/admin/benevole?tab=tasks'));
}

if (isset($_POST['delete_benv_task'])) {
    $tid = (int)$_POST['task_id'];
    Database::run("DELETE FROM cc_benv_tasks WHERE id=?", [$tid]);
    try { Database::run("DELETE FROM cc_benv_task_volunteers WHERE task_id=?", [$tid]); } catch(Exception $e) {}
    adminFlash('success','Tâche supprimée.');
    Helpers::redirect(u('/admin/benevole?tab=tasks'));
}

// Auto-supprimer les tâches terminées avec une date passée (>7 jours)
if (isset($_GET['tab']) && $_GET['tab']==='tasks') {
    try { Database::run("DELETE FROM cc_benv_tasks WHERE status='done' AND due_date < DATE_SUB(NOW(), INTERVAL 7 DAY)"); } catch(Exception $e) {}
}

// Créer alerte
if (isset($_POST['save_benv_alert'])) {
    $aid   = (int)($_POST['alert_id']??0);
    $title = Helpers::sanitize($_POST['title']??'');
    $msg   = Helpers::sanitize($_POST['message']??'');
    $lvl   = in_array($_POST['level']??'',['info','warning','urgent'])?$_POST['level']:'info';
    $act   = isset($_POST['active'])?1:0;
    $exp   = Helpers::sanitize($_POST['expires_at']??'') ?: null;
    if ($title) {
        if ($aid) {
            Database::run("UPDATE cc_benv_alerts SET title=?,message=?,level=?,active=?,expires_at=? WHERE id=?",
                [$title,$msg,$lvl,$act,$exp,$aid]);
        } else {
            Database::run("INSERT INTO cc_benv_alerts (title,message,level,active,expires_at,created_by) VALUES (?,?,?,?,?,?)",
                [$title,$msg,$lvl,$act,$exp,Auth::id()]);
        }
        adminFlash('success','Alerte sauvegardée.');
    }
    Helpers::redirect(u('/admin/benevole?tab=alerts'));
}

if (isset($_POST['delete_benv_alert'])) {
    Database::run("DELETE FROM cc_benv_alerts WHERE id=?", [(int)$_POST['alert_id']]);
    adminFlash('success','Alerte supprimée.');
    Helpers::redirect(u('/admin/benevole?tab=alerts'));
}

// Blacklist
if (isset($_POST['save_blacklist'])) {
    $uid    = (int)$_POST['benv_user_id'];
    $bl     = isset($_POST['blacklisted'])?1:0;
    $reason = Helpers::sanitize($_POST['blacklist_reason']??'');
    Database::run("INSERT INTO cc_benv_profiles (user_id,blacklisted,blacklist_reason) VALUES (?,?,?)
        ON DUPLICATE KEY UPDATE blacklisted=VALUES(blacklisted),blacklist_reason=VALUES(blacklist_reason)",
        [$uid,$bl,$reason]);
    adminFlash('success','Profil mis à jour.');
    Helpers::redirect(u('/admin/benevole?tab=benevoles'));
}

// Accès coach
if (isset($_POST['save_coach_access'])) {
    $coaches = Database::all("SELECT id FROM cc_users WHERE role='coach'");
    foreach($coaches as $c) {
        $can  = isset($_POST['coach_access_'.$c['id']])?1:0;
        $seeBl= isset($_POST['coach_bl_'.$c['id']])?1:0;
        Database::run("INSERT INTO cc_benv_coach_access (coach_id,can_access,see_blacklist) VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE can_access=VALUES(can_access),see_blacklist=VALUES(see_blacklist)",
            [$c['id'],$can,$seeBl]);
    }
    adminFlash('success','Accès coachs mis à jour.');
    Helpers::redirect(u('/admin/benevole?tab=access'));
}

// Mailing bénévoles
if (isset($_POST['send_benv_mailing'])) {
    $subject = Helpers::sanitize($_POST['mailing_subject']??'');
    $body    = Helpers::sanitize($_POST['mailing_body']??'');
    $targets = $_POST['targets'] ?? 'all';
    if ($subject && $body) {
        $users = $targets==='all'
            ? Database::all("SELECT email,firstname FROM cc_users WHERE role='benevole' AND status='active'")
            : Database::all("SELECT u.email,u.firstname FROM cc_users u WHERE u.id IN (".implode(',',array_map('intval',$_POST['target_ids']??[0])).")");
        $sent = 0;
        foreach($users as $u) {
            Mailer::send($u['email'],$u['firstname'],$subject,"<div style='font-family:Arial,sans-serif'>".nl2br(htmlspecialchars($body))."</div>");
            $sent++;
        }
        adminFlash('success',"Email envoyé à $sent bénévole".($sent>1?'s':'').'.');
    }
    Helpers::redirect(u('/admin/benevole?tab=mailing'));
}

// ── Charger données ───────────────────────────────────────────
$tab       = $_GET['tab'] ?? 'dashboard';
$editEvt   = isset($_GET['edit_event'])  ? Database::one("SELECT * FROM cc_benv_events WHERE id=?",[(int)$_GET['edit_event']])  : null;
$editTask  = isset($_GET['edit_task'])   ? Database::one("SELECT * FROM cc_benv_tasks WHERE id=?", [(int)$_GET['edit_task']])   : null;
$editAlert = isset($_GET['edit_alert'])  ? Database::one("SELECT * FROM cc_benv_alerts WHERE id=?",[(int)$_GET['edit_alert']]) : null;
$benevoles = Database::all("SELECT u.*,p.skills,p.blacklisted,p.blacklist_reason FROM cc_users u LEFT JOIN cc_benv_profiles p ON p.user_id=u.id WHERE u.role='benevole' ORDER BY u.firstname");
$coaches   = Database::all("SELECT u.*,a.can_access,a.see_blacklist FROM cc_users u LEFT JOIN cc_benv_coach_access a ON a.coach_id=u.id WHERE u.role='coach' ORDER BY u.firstname");


// ── Paramètres dossiers ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && Auth::verifyCsrf() && isset($_POST['save_folder_params'])) {
    $who = in_array($_POST['folder_who']??'', ['all','coach','admin','specific']) ? $_POST['folder_who'] : 'admin';
    Config::set('benv_folder_who', $who, 'benevole');
    $ids = array_map('intval', $_POST['folder_specific'] ?? []);
    Config::set('benv_folder_specific', json_encode($ids), 'benevole');
    adminFlash('success', 'Paramètres dossiers sauvegardés.');
    Helpers::redirect(u('/admin/benevole?tab=docparams'));
}

// ── Paramètres documents ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && Auth::verifyCsrf() && isset($_POST['save_doc_params'])) {
    $who = in_array($_POST['upload_who']??'', ['all','coach','admin','specific']) ? $_POST['upload_who'] : 'coach';
    Config::set('benv_upload_who', $who, 'benevole');
    $ids = array_map('intval', $_POST['upload_specific'] ?? []);
    Config::set('benv_upload_specific', json_encode($ids), 'benevole');
    adminFlash('success', 'Paramètres documents sauvegardés.');
    Helpers::redirect(u('/admin/benevole?tab=docparams'));
}

$pageTitle = '🤝 Administration Bénévoles';

ob_start();
?>
<div class="page-head">
  <h1>🤝 Bénévoles</h1>
  <a href="<?=u('/benevole')?>" class="btn btn-ghost btn-sm" target="_blank">Voir le panel →</a>
</div>

<!-- Nav tabs -->
<div style="display:flex;gap:.35rem;flex-wrap:wrap;margin-bottom:1.25rem">
  <?php foreach(['dashboard'=>'📊 Dashboard','events'=>'📅 Événements','tasks'=>'✅ Tâches','alerts'=>'🚨 Alertes','benevoles'=>'👥 Bénévoles','channels'=>'💬 Canaux chat','mailing'=>'📧 Mailing','access'=>'🔑 Accès coachs','docparams'=>'📁 Documents'] as $k=>$l): ?>
  <a href="<?=u('/admin/benevole?tab='.$k)?>" class="btn <?=$tab===$k?'btn-primary':'btn-ghost'?>"><?=$l?></a>
  <?php endforeach; ?>
</div>

<?php if($tab==='dashboard'): ?>
<?php
$stats = [
    Database::scalar("SELECT COUNT(*) FROM cc_users WHERE role='benevole'"),
    Database::scalar("SELECT COUNT(*) FROM cc_benv_events WHERE date_start>=NOW()"),
    Database::scalar("SELECT COUNT(*) FROM cc_benv_tasks WHERE status!='done'"),
    Database::scalar("SELECT COUNT(*) FROM cc_benv_alerts WHERE active=1"),
];
$recentParts = Database::all(
    "SELECT p.*,u.firstname,u.lastname,e.title FROM cc_benv_participations p
     JOIN cc_users u ON u.id=p.user_id JOIN cc_benv_events e ON e.id=p.event_id
     ORDER BY p.created_at DESC LIMIT 10"
);
?>
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.25rem">
  <?php foreach([['👥',$stats[0],'Bénévoles'],['📅',$stats[1],'Événements à venir'],['✅',$stats[2],'Tâches actives'],['🚨',$stats[3],'Alertes actives']] as [$ico,$n,$l]): ?>
  <div class="ac" style="text-align:center;padding:1.25rem">
    <div style="font-size:1.5rem"><?=$ico?></div>
    <div style="font-size:2rem;font-weight:800;color:var(--color-primary)"><?=$n?></div>
    <div style="font-size:.78rem;color:#64748b"><?=$l?></div>
  </div>
  <?php endforeach; ?>
</div>
<div class="ac">
  <div class="ac-header"><h2>Inscriptions récentes</h2></div>
  <?php if(empty($recentParts)): ?><div style="padding:1.5rem;text-align:center;color:#94a3b8">Aucune inscription</div>
  <?php else: ?>
  <div class="at-wrap"><table class="at">
    <thead><tr><th>Bénévole</th><th>Événement</th><th>Statut</th><th>Date</th></tr></thead>
    <tbody>
    <?php foreach($recentParts as $p): ?>
    <tr>
      <td><?=Helpers::e($p['firstname'].' '.$p['lastname'])?></td>
      <td><?=Helpers::e($p['title'])?></td>
      <td><span class="badge badge-<?=$p['status']==='confirmed'?'success':'muted'?>"><?=$p['status']==='confirmed'?'✅ Confirmé':'❌ Annulé'?></span></td>
      <td style="font-size:.8rem;color:#64748b"><?=(new DateTime($p['created_at']))->format('d/m/Y H:i')?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php elseif($tab==='events'): ?>
<?php $events = Database::all("SELECT e.*,COUNT(p.id) AS nb_parts FROM cc_benv_events e LEFT JOIN cc_benv_participations p ON p.event_id=e.id AND p.status='confirmed' GROUP BY e.id ORDER BY e.date_start"); ?>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;align-items:start">
  <div class="ac">
    <div class="ac-header"><h2><?=$editEvt?'✏️ Modifier l\'événement':'➕ Nouvel événement'?></h2>
      <?php if($editEvt): ?><a href="<?=u('/admin/benevole?tab=events')?>" class="btn btn-ghost btn-sm">← Annuler</a><?php endif; ?>
    </div>
    <div class="ac-body">
      <form method="post">
        <?=Auth::csrfField()?>
        <input type="hidden" name="event_id" value="<?=$editEvt['id']??0?>">
        <div class="form-row">
          <div class="fg"><label>Titre *</label><input type="text" name="title" class="be-input" required value="<?=Helpers::e($editEvt['title']??'')?>"></div>
          <div class="fg"><label>Lieu</label><input type="text" name="location" class="be-input" value="<?=Helpers::e($editEvt['location']??'')?>"></div>
        </div>
        <div class="form-row">
          <div class="fg"><label>Début *</label>
            <?php $dsVal = $editEvt ? date('Y-m-d',strtotime($editEvt['date_start'])).'T'.date('H:i',strtotime($editEvt['date_start'])) : ''; ?>
            <input type="datetime-local" name="date_start" class="be-input" required value="<?=Helpers::e($dsVal)?>">
          </div>
          <div class="fg"><label>Fin</label>
            <?php $deVal = ($editEvt && $editEvt['date_end']) ? date('Y-m-d',strtotime($editEvt['date_end'])).'T'.date('H:i',strtotime($editEvt['date_end'])) : ''; ?>
            <input type="datetime-local" name="date_end" class="be-input" value="<?=Helpers::e($deVal)?>">
          </div>
        </div>
        <div class="fg"><label>Description</label><textarea name="description" class="be-input" rows="2"><?=Helpers::e($editEvt['description']??'')?></textarea></div>
        <div class="form-row">
          <div class="fg"><label>Max bénévoles (0=illimité)</label><input type="number" name="max_volunteers" class="be-input" min="0" value="<?=$editEvt['max_volunteers']??0?>"></div>
          <div class="fg"><label>Récurrence</label>
            <select name="recurring" class="be-select">
              <?php foreach(['none'=>'Aucune','daily'=>'Quotidien','weekly'=>'Hebdomadaire','monthly'=>'Mensuel'] as $k=>$l): ?>
              <option value="<?=$k?>" <?=($editEvt['recurring']??'none')===$k?'selected':''?>><?=$l?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="fg"><label>Couleur</label><input type="color" name="color" value="<?=Helpers::e($editEvt['color']??'#6366f1')?>" style="height:42px;width:100%;border-radius:8px;border:1.5px solid #e2e8f0;cursor:pointer;padding:3px"></div>
        </div>
        <button type="submit" name="save_benv_event" class="btn btn-primary"><?=$editEvt?'💾 Modifier':'➕ Créer'?></button>
      </form>
    </div>
  </div>
  <div class="ac">
    <div class="ac-header"><h2>Événements (<?=count($events)?>)</h2></div>
    <div class="at-wrap"><table class="at">
      <thead><tr><th>Date</th><th>Titre</th><th>Bénévoles</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach($events as $ev): $dt=new DateTime($ev['date_start']); $past=$dt<new DateTime(); ?>
      <tr style="<?php echo $past ? 'opacity:.6' : ''; ?>">
        <td style="font-size:.8rem"><strong><?=$dt->format('d/m/Y')?></strong><br><span style="color:#64748b"><?=$dt->format('H:i')?></span></td>
        <td>
          <div style="font-weight:600;display:flex;align-items:center;gap:.35rem">
            <span style="width:8px;height:8px;border-radius:50%;background:<?=Helpers::e($ev['color'])?>;flex-shrink:0"></span>
            <?=Helpers::e($ev['title'])?>
          </div>
          <?php if($ev['location']): ?><div style="font-size:.72rem;color:#64748b">📍 <?=Helpers::e($ev['location'])?></div><?php endif; ?>
        </td>
        <td style="text-align:center"><strong><?=$ev['nb_parts']?></strong><?=$ev['max_volunteers']?' / '.$ev['max_volunteers']:''?></td>
        <td style="display:flex;gap:.35rem">
          <a href="<?=u('/admin/benevole?tab=events&edit_event='.$ev['id'])?>" class="btn btn-ghost btn-sm">✏️</a>
          <form method="post" onsubmit="return confirm('Supprimer ?')">
            <?=Auth::csrfField()?><input type="hidden" name="event_id" value="<?=$ev['id']?>">
            <button type="submit" name="delete_benv_event" class="btn btn-danger btn-sm">🗑️</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
</div>

<?php elseif($tab==='tasks'): ?>
<?php $tasks = Database::all("SELECT t.*,u.firstname,u.lastname FROM cc_benv_tasks t LEFT JOIN cc_users u ON t.assigned_to=u.id ORDER BY t.priority='high' DESC,t.due_date,t.created_at"); ?>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;align-items:start">
  <div class="ac">
    <div class="ac-header"><h2><?=$editTask?'✏️ Modifier':'➕ Nouvelle tâche'?></h2>
      <?php if($editTask): ?><a href="<?=u('/admin/benevole?tab=tasks')?>" class="btn btn-ghost btn-sm">← Annuler</a><?php endif; ?>
    </div>
    <div class="ac-body">
      <form method="post">
        <?=Auth::csrfField()?>
        <input type="hidden" name="task_id" value="<?=$editTask['id']??0?>">
        <div class="fg"><label>Titre *</label><input type="text" name="title" class="be-input" required value="<?=Helpers::e($editTask['title']??'')?>"></div>
        <div class="fg"><label>Description</label><textarea name="description" class="be-input" rows="2"><?=Helpers::e($editTask['description']??'')?></textarea></div>
        <div class="form-row">
          <div class="fg"><label>Priorité</label>
            <select name="priority" class="be-select">
              <?php foreach(['low'=>'🟢 Basse','normal'=>'🟡 Normale','high'=>'🔴 Haute'] as $k=>$l): ?>
              <option value="<?=$k?>" <?=($editTask['priority']??'normal')===$k?'selected':''?>><?=$l?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="fg"><label>Statut</label>
            <select name="status" class="be-select">
              <?php foreach(['todo'=>'📋 À faire','inprogress'=>'⚡ En cours','done'=>'✅ Terminé'] as $k=>$l): ?>
              <option value="<?=$k?>" <?=($editTask['status']??'todo')===$k?'selected':''?>><?=$l?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-row">
          <div class="fg"><label>Assigné à</label>
            <select name="assigned_to" class="be-select">
              <option value="">— Non assigné —</option>
              <?php foreach($benevoles as $b): ?><option value="<?=$b['id']?>" <?=($editTask['assigned_to']??'')==$b['id']?'selected':''?>><?=Helpers::e($b['firstname'].' '.$b['lastname'])?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="fg"><label>Échéance</label><input type="date" name="due_date" class="be-input" value="<?=$editTask['due_date']??''?>"></div>
        </div>
        <div class="form-row">
          <div class="fg"><label>Récurrence</label>
            <select name="recurring" class="be-select">
              <?php foreach(['none'=>'Aucune','daily'=>'Quotidien','weekly'=>'Hebdo','monthly'=>'Mensuel'] as $k=>$l): ?>
              <option value="<?=$k?>" <?=($editTask['recurring']??'none')===$k?'selected':''?>><?=$l?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="fg"><label>Couleur</label><input type="color" name="color" value="<?=Helpers::e($editTask['color']??'#6366f1')?>" style="height:42px;width:100%;border-radius:8px;border:1.5px solid #e2e8f0;cursor:pointer;padding:3px"></div>
        </div>
        <button type="submit" name="save_benv_task" class="btn btn-primary"><?=$editTask?'💾 Modifier':'➕ Créer'?></button>
      </form>
    </div>
  </div>
  <div class="ac">
    <div class="ac-header"><h2>Tâches (<?=count($tasks)?>)</h2></div>
    <div class="at-wrap"><table class="at">
      <thead><tr><th>Tâche</th><th>Assigné</th><th>Priorité</th><th>Statut</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach($tasks as $tk): ?>
      <tr>
        <td>
          <div style="display:flex;align-items:center;gap:.35rem">
            <span style="width:8px;height:8px;border-radius:50%;background:<?=Helpers::e($tk['color'])?>;flex-shrink:0"></span>
            <div>
              <strong><?=Helpers::e($tk['title'])?></strong>
              <?php if($tk['due_date']): ?><div style="font-size:.72rem;color:#64748b">📅 <?=(new DateTime($tk['due_date']))->format('d/m/Y')?></div><?php endif; ?>
            </div>
          </div>
        </td>
        <td style="font-size:.82rem"><?=$tk['firstname']?Helpers::e($tk['firstname'].' '.$tk['lastname']):'—'?></td>
        <td><span style="font-size:.75rem"><?=['low'=>'🟢 Basse','normal'=>'🟡 Normale','high'=>'🔴 Haute'][$tk['priority']]?></span></td>
        <td><span class="badge badge-<?=$tk['status']?>"><?=['todo'=>'À faire','inprogress'=>'En cours','done'=>'Terminé'][$tk['status']]?></span></td>
        <td style="display:flex;gap:.35rem">
          <a href="<?=u('/admin/benevole?tab=tasks&edit_task='.$tk['id'])?>" class="btn btn-ghost btn-sm">✏️</a>
          <form method="post" onsubmit="return confirm('Supprimer ?')">
            <?=Auth::csrfField()?><input type="hidden" name="task_id" value="<?=$tk['id']?>">
            <button type="submit" name="delete_benv_task" class="btn btn-danger btn-sm">🗑️</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
</div>

<?php elseif($tab==='alerts'): ?>
<?php $alerts = Database::all("SELECT * FROM cc_benv_alerts ORDER BY active DESC,created_at DESC"); ?>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;align-items:start">
  <div class="ac">
    <div class="ac-header"><h2><?=$editAlert?'✏️ Modifier l\'alerte':'➕ Nouvelle alerte'?></h2>
      <?php if($editAlert): ?><a href="<?=u('/admin/benevole?tab=alerts')?>" class="btn btn-ghost btn-sm">← Annuler</a><?php endif; ?>
    </div>
    <div class="ac-body">
      <form method="post">
        <?=Auth::csrfField()?>
        <input type="hidden" name="alert_id" value="<?=$editAlert['id']??0?>">
        <div class="fg"><label>Titre *</label><input type="text" name="title" class="be-input" required value="<?=Helpers::e($editAlert['title']??'')?>"></div>
        <div class="fg"><label>Message</label><textarea name="message" class="be-input" rows="3"><?=Helpers::e($editAlert['message']??'')?></textarea></div>
        <div class="form-row">
          <div class="fg"><label>Niveau</label>
            <select name="level" class="be-select">
              <option value="info"    <?=($editAlert['level']??'info')==='info'   ?'selected':''?>>ℹ️ Information</option>
              <option value="warning" <?=($editAlert['level']??'')==='warning'    ?'selected':''?>>⚠️ Avertissement</option>
              <option value="urgent"  <?=($editAlert['level']??'')==='urgent'     ?'selected':''?>>🚨 Urgent</option>
            </select>
          </div>
          <div class="fg"><label>Expire le</label>
            <?php $expVal = ($editAlert && $editAlert['expires_at']) ? date('Y-m-d',strtotime($editAlert['expires_at'])).'T'.date('H:i',strtotime($editAlert['expires_at'])) : ''; ?>
            <input type="datetime-local" name="expires_at" class="be-input" value="<?=Helpers::e($expVal??'')?>"></div>
        </div>
        <div style="margin-bottom:.875rem;display:flex;align-items:center;gap:.5rem">
          <input type="checkbox" name="active" value="1" id="al-active"
            <?php echo ($editAlert['active']??1) ? 'checked' : ''; ?>
            style="accent-color:var(--color-primary);width:16px;height:16px">
          <label for="al-active" style="font-weight:600;cursor:pointer">Active</label>
        </div>
        <?php $alertBtnTxt = $editAlert ? 'Modifier' : "Creer l'alerte"; ?>
        <button type="submit" name="save_benv_alert" class="btn btn-primary">💾 <?=Helpers::e($alertBtnTxt)?></button>
      </form>
    </div>
  </div>
  <div class="ac">
    <div class="ac-header"><h2>Alertes (<?=count($alerts)?>)</h2></div>
    <div style="padding:.5rem">
    <?php foreach($alerts as $al): $lvlStyles=['info'=>['#eff6ff','#1d4ed8','ℹ️'],'warning'=>['#fef3c7','#d97706','⚠️'],'urgent'=>['#fee2e2','#dc2626','🚨']][$al['level']]??['#f8fafc','#64748b','📢']; ?>
    <div style="background:<?=$lvlStyles[0]?>;border:1.5px solid;border-color:<?=$lvlStyles[1]?>33;border-radius:10px;padding:.75rem;margin-bottom:.5rem;display:flex;align-items:flex-start;gap:.75rem">
      <span><?=$lvlStyles[2]?></span>
      <div style="flex:1">
        <div style="font-weight:700;font-size:.875rem;color:<?=$lvlStyles[1]?>"><?=Helpers::e($al['title'])?></div>
        <?php if($al['message']): ?><div style="font-size:.78rem;margin-top:.2rem"><?=Helpers::e($al['message'])?></div><?php endif; ?>
        <div style="font-size:.7rem;margin-top:.3rem;color:#94a3b8"><?=$al['active']?'✅ Active':'❌ Inactive'?><?=$al['expires_at']?' · expire '.(new DateTime($al['expires_at']))->format('d/m/Y'):''?></div>
      </div>
      <div style="display:flex;gap:.25rem;flex-shrink:0">
        <a href="<?=u('/admin/benevole?tab=alerts&edit_alert='.$al['id'])?>" class="btn btn-ghost btn-sm">✏️</a>
        <form method="post" onsubmit="return confirm('Supprimer ?')">
          <?=Auth::csrfField()?><input type="hidden" name="alert_id" value="<?=$al['id']?>">
          <button type="submit" name="delete_benv_alert" class="btn btn-danger btn-sm">🗑️</button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
    </div>
  </div>
</div>

<?php elseif($tab==='benevoles'): ?>
<div class="ac">
  <div class="ac-header"><h2>👥 Bénévoles (<?=count($benevoles)?>)</h2></div>
  <div class="at-wrap"><table class="at">
    <thead><tr><th>Bénévole</th><th>Email</th><th>Compétences</th><th>Statut</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach($benevoles as $b): ?>
    <tr style="<?php echo $b['blacklisted'] ? 'background:#fff5f5' : ''; ?>">
      <td>
        <strong><?=Helpers::e($b['firstname'].' '.$b['lastname'])?></strong>
        <?php if($b['blacklisted']): ?><br><span class="badge badge-urgent" style="font-size:.65rem">⚠ Liste attention</span><?php endif; ?>
      </td>
      <td style="font-size:.82rem"><?=Helpers::e($b['email'])?></td>
      <td style="font-size:.78rem;color:#64748b"><?=Helpers::e($b['skills']??'—')?></td>
      <td><span style="padding:.2rem .5rem;border-radius:99px;font-size:.72rem;font-weight:700;background:<?=$b['status']==='active'?'#dcfce7':'#fee2e2'?>;color:<?=$b['status']==='active'?'#16a34a':'#dc2626'?>"><?=$b['status']==='active'?'Actif':'Inactif'?></span></td>
      <td>
        <button onclick="document.getElementById('bl-form-<?=$b['id']?>').style.display=document.getElementById('bl-form-<?=$b['id']?>').style.display==='none'?'block':'none'"
          class="btn btn-ghost btn-sm"><?=$b['blacklisted']?'✏️ Modifier':'⚠ Liste attention'?></button>
        <div id="bl-form-<?=$b['id']?>" style="display:none;margin-top:.5rem">
          <form method="post">
            <?=Auth::csrfField()?>
            <input type="hidden" name="benv_user_id" value="<?=$b['id']?>">
            <label style="display:flex;align-items:center;gap:.4rem;margin-bottom:.3rem;font-size:.82rem;cursor:pointer">
              <input type="checkbox" name="blacklisted" value="1" <?=$b['blacklisted']?'checked':''?> style="accent-color:#dc2626">
              Mettre en liste d'attention
            </label>
            <input type="text" name="blacklist_reason" class="be-input" placeholder="Raison…" value="<?=Helpers::e($b['blacklist_reason']??'')?>" style="margin-bottom:.35rem">
            <button type="submit" name="save_blacklist" class="btn btn-danger btn-sm">Enregistrer</button>
          </form>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<?php elseif($tab==='channels'): ?>
<?php $chans = Database::all("SELECT * FROM cc_benv_channels ORDER BY created_at"); ?>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;align-items:start">
  <!-- Créer canal -->
  <div class="ac">
    <div class="ac-header"><h2>➕ Nouveau canal</h2></div>
    <div class="ac-body">
      <form method="post">
        <?=Auth::csrfField()?>
        <div class="fg"><label>Nom du canal *</label><input type="text" name="channel_name" class="be-input" required placeholder="Ex: Animation, Sécurité…"></div>
        <button type="submit" name="create_channel" class="btn btn-primary" style="margin-top:.75rem">➕ Créer</button>
      </form>
    </div>
  </div>
  <!-- Liste canaux -->
  <div class="ac">
    <div class="ac-header"><h2>Canaux (<?=count($chans)?>)</h2></div>
    <div class="at-wrap"><table class="at">
      <thead><tr><th>Canal</th><th>Statut</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach($chans as $ch): ?>
      <tr>
        <td><strong><?=Helpers::e($ch['name'])?></strong><div style="font-size:.72rem;color:#94a3b8;font-family:monospace"><?=Helpers::e($ch['slug'])?></div></td>
        <td><span style="padding:.2rem .6rem;border-radius:99px;font-size:.75rem;font-weight:700;background:<?=$ch['open']?'#dcfce7':'#fee2e2'?>;color:<?=$ch['open']?'#16a34a':'#dc2626'?>"><?=$ch['open']?'🔓 Ouvert':'🔒 Fermé'?></span></td>
        <td style="display:flex;gap:.35rem">
          <form method="post"><?=Auth::csrfField()?>
            <input type="hidden" name="channel_id" value="<?=$ch['id']?>">
            <input type="hidden" name="channel_open" value="<?=$ch['open']?0:1?>">
            <button type="submit" name="toggle_channel" class="btn btn-ghost btn-sm"><?=$ch['open']?'🔒 Fermer':'🔓 Ouvrir'?></button>
          </form>
          <form method="post" onsubmit="return confirm('Supprimer ce canal et ses messages ?')"><?=Auth::csrfField()?>
            <input type="hidden" name="channel_id" value="<?=$ch['id']?>">
            <button type="submit" name="delete_channel" class="btn btn-danger btn-sm">🗑️</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
</div>

<?php elseif($tab==='mailing'): ?>
<div class="ac" style="max-width:680px">
  <div class="ac-header"><h2>📧 Mailing bénévoles</h2></div>
  <div class="ac-body">
    <p style="font-size:.82rem;color:#64748b;margin-bottom:1.25rem">Envoyez un email à tous les bénévoles ou à une sélection.</p>
    <form method="post">
      <?=Auth::csrfField()?>
      <div class="fg" style="margin-bottom:.875rem"><label>Sujet *</label><input type="text" name="mailing_subject" class="be-input" required placeholder="Objet du message"></div>
      <div class="fg" style="margin-bottom:.875rem"><label>Message *</label><textarea name="mailing_body" class="be-input" rows="8" required placeholder="Contenu de votre message…" style="resize:vertical"></textarea></div>
      <div style="margin-bottom:1rem">
        <label style="font-weight:700;font-size:.875rem;display:block;margin-bottom:.5rem">Destinataires</label>
        <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;margin-bottom:.35rem">
          <input type="radio" name="targets" value="all" checked style="accent-color:var(--color-primary)">
          Tous les bénévoles actifs (<?=count($benevoles)?>)
        </label>
      </div>
      <button type="submit" name="send_benv_mailing" class="btn btn-primary">📧 Envoyer</button>
    </form>
  </div>
</div>

<?php elseif($tab==='access'): ?>
<div class="ac" style="max-width:680px">
  <div class="ac-header"><h2>🔑 Accès coachs au panel bénévole</h2></div>
  <div class="ac-body">
    <p style="font-size:.82rem;color:#64748b;margin-bottom:1.25rem">Choisissez quels coachs peuvent accéder au panel bénévole et voir la liste d'attention.</p>
    <form method="post">
      <?=Auth::csrfField()?>
      <div class="at-wrap"><table class="at">
        <thead><tr><th>Coach</th><th>Accès panel</th><th>Voir liste attention</th></tr></thead>
        <tbody>
        <?php foreach($coaches as $c): ?>
        <tr>
          <td><strong><?=Helpers::e($c['firstname'].' '.$c['lastname'])?></strong><div style="font-size:.75rem;color:#64748b"><?=Helpers::e($c['email'])?></div></td>
          <td style="text-align:center">
            <label class="popup-toggle-wrap" style="position:relative;display:inline-block;width:44px;height:24px">
              <input type="checkbox" name="coach_access_<?=$c['id']?>" value="1" <?=$c['can_access']?'checked':''?>
                style="opacity:0;position:absolute;inset:0;cursor:pointer;z-index:2;width:100%;height:100%">
              <span style="position:absolute;inset:0;border-radius:99px;background:<?=$c['can_access']?'var(--color-primary)':'#e2e8f0'?>;transition:background .2s"></span>
              <span style="position:absolute;top:3px;<?=$c['can_access']?'right:3px':'left:3px'?>;width:18px;height:18px;background:#fff;border-radius:50%;transition:all .2s;box-shadow:0 1px 3px rgba(0,0,0,.2)"></span>
            </label>
          </td>
          <td style="text-align:center">
            <label class="popup-toggle-wrap" style="position:relative;display:inline-block;width:44px;height:24px">
              <input type="checkbox" name="coach_bl_<?=$c['id']?>" value="1" <?=$c['see_blacklist']?'checked':''?>
                style="opacity:0;position:absolute;inset:0;cursor:pointer;z-index:2;width:100%;height:100%">
              <span style="position:absolute;inset:0;border-radius:99px;background:<?=$c['see_blacklist']?'#ef4444':'#e2e8f0'?>;transition:background .2s"></span>
              <span style="position:absolute;top:3px;<?=$c['see_blacklist']?'right:3px':'left:3px'?>;width:18px;height:18px;background:#fff;border-radius:50%;transition:all .2s;box-shadow:0 1px 3px rgba(0,0,0,.2)"></span>
            </label>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($coaches)): ?><tr><td colspan="3" style="text-align:center;color:#94a3b8;padding:1.5rem">Aucun coach</td></tr><?php endif; ?>
        </tbody>
      </table></div>
      <div style="margin-top:1rem"><button type="submit" name="save_coach_access" class="btn btn-primary">💾 Sauvegarder</button></div>
    </form>
  </div>
</div>

<?php elseif($tab==='docparams'): ?>
<?php
$uploadWho      = Config::get('benv_upload_who', 'coach');
$uploadSpecific = json_decode(Config::get('benv_upload_specific', '[]'), true) ?? [];
$allBenevoles   = Database::all("SELECT id,firstname,lastname FROM cc_users WHERE role='benevole' AND status='active' ORDER BY firstname");
?>
<div class="ac" style="max-width:700px">
  <div class="ac-header"><h2>📁 Qui peut uploader des documents ?</h2></div>
  <div class="ac-body">
    <p style="font-size:.82rem;color:#64748b;margin-bottom:1.25rem">Définit quels utilisateurs peuvent ajouter des fichiers dans l'espace documents des bénévoles.</p>
    <style>
    .dp-opt{display:flex;align-items:flex-start;gap:.75rem;padding:.875rem;border-radius:10px;border:2px solid #e2e8f0;margin-bottom:.5rem;cursor:pointer;transition:border-color .15s}
    .dp-opt:has(input:checked){border-color:var(--color-primary);background:#f5f3ff}
    .dp-opt input{margin-top:.15rem;accent-color:var(--color-primary)}
    .dp-opt-title{font-weight:600;font-size:.9rem;color:#1e293b}
    .dp-opt-desc{font-size:.78rem;color:#64748b;margin-top:.15rem}
    </style>
    <form method="post"><?=Auth::csrfField()?>
      <label class="dp-opt">
        <input type="radio" name="upload_who" value="all" <?=$uploadWho==='all'?'checked':''?>>
        <div><div class="dp-opt-title">👥 Tous les bénévoles</div><div class="dp-opt-desc">N'importe quel bénévole connecté peut uploader</div></div>
      </label>
      <label class="dp-opt">
        <input type="radio" name="upload_who" value="coach" <?=$uploadWho==='coach'?'checked':''?>>
        <div><div class="dp-opt-title">🏅 Coachs + Admins</div><div class="dp-opt-desc">Seuls les coachs et administrateurs peuvent uploader</div></div>
      </label>
      <label class="dp-opt">
        <input type="radio" name="upload_who" value="admin" <?=$uploadWho==='admin'?'checked':''?>>
        <div><div class="dp-opt-title">🔒 Admins uniquement</div><div class="dp-opt-desc">Seuls les administrateurs peuvent uploader</div></div>
      </label>
      <label class="dp-opt">
        <input type="radio" name="upload_who" value="specific" <?=$uploadWho==='specific'?'checked':''?>>
        <div style="flex:1">
          <div class="dp-opt-title">🙋 Bénévoles spécifiques</div>
          <div class="dp-opt-desc">Seuls les bénévoles cochés peuvent uploader (coachs et admins toujours autorisés)</div>
          <div id="dp-specific" style="margin-top:.75rem;display:<?=$uploadWho==='specific'?'flex':'none'?>;flex-wrap:wrap;gap:.35rem">
            <?php foreach($allBenevoles as $bv): ?>
            <label style="display:inline-flex;align-items:center;gap:.35rem;padding:.3rem .625rem;border-radius:6px;border:1.5px solid #e2e8f0;cursor:pointer;font-size:.8rem;background:#fff">
              <input type="checkbox" name="upload_specific[]" value="<?=$bv['id']?>" <?=in_array($bv['id'],$uploadSpecific)?'checked':''?> style="accent-color:var(--color-primary)">
              <?=Helpers::e($bv['firstname'].' '.$bv['lastname'])?>
            </label>
            <?php endforeach; ?>
            <?php if(empty($allBenevoles)): ?><span style="font-size:.82rem;color:#94a3b8">Aucun bénévole enregistré</span><?php endif; ?>
          </div>
        </div>
      </label>
      <script>
      document.querySelectorAll('input[name="upload_who"]').forEach(function(r){
        r.addEventListener('change',function(){
          document.getElementById('dp-specific').style.display=(this.value==='specific'?'flex':'none');
        });
      });
      </script>
      <div style="margin-top:1rem">
        <button type="submit" name="save_doc_params" class="btn btn-primary">💾 Sauvegarder</button>
      </div>
    </form>
  </div>
</div>

<?php
$folderWho      = Config::get('benv_folder_who', 'admin');
$folderSpecific = json_decode(Config::get('benv_folder_specific', '[]'), true) ?? [];
?>
<div class="ac" style="max-width:700px;margin-top:1.25rem">
  <div class="ac-header"><h2>📂 Qui peut créer des dossiers ?</h2></div>
  <div class="ac-body">
    <p style="font-size:.82rem;color:#64748b;margin-bottom:1.25rem">Définit qui peut créer et supprimer des dossiers dans l'espace documents.</p>
    <form method="post"><?=Auth::csrfField()?>
      <label class="dp-opt">
        <input type="radio" name="folder_who" value="all" <?=$folderWho==='all'?'checked':''?>>
        <div><div class="dp-opt-title">👥 Tous les bénévoles</div><div class="dp-opt-desc">N'importe quel bénévole peut créer des dossiers</div></div>
      </label>
      <label class="dp-opt">
        <input type="radio" name="folder_who" value="coach" <?=$folderWho==='coach'?'checked':''?>>
        <div><div class="dp-opt-title">🏅 Coachs + Admins</div><div class="dp-opt-desc">Seuls les coachs et administrateurs peuvent créer des dossiers</div></div>
      </label>
      <label class="dp-opt">
        <input type="radio" name="folder_who" value="admin" <?=$folderWho==='admin'?'checked':''?>>
        <div><div class="dp-opt-title">🔒 Admins uniquement</div><div class="dp-opt-desc">Seuls les administrateurs peuvent créer des dossiers (par défaut)</div></div>
      </label>
      <label class="dp-opt">
        <input type="radio" name="folder_who" value="specific" <?=$folderWho==='specific'?'checked':''?>>
        <div style="flex:1">
          <div class="dp-opt-title">🙋 Bénévoles spécifiques</div>
          <div class="dp-opt-desc">Seuls les bénévoles cochés peuvent créer des dossiers</div>
          <div id="dp-folder-specific" style="margin-top:.75rem;display:<?=$folderWho==='specific'?'flex':'none'?>;flex-wrap:wrap;gap:.35rem">
            <?php foreach($allBenevoles as $bv): ?>
            <label style="display:inline-flex;align-items:center;gap:.35rem;padding:.3rem .625rem;border-radius:6px;border:1.5px solid #e2e8f0;cursor:pointer;font-size:.8rem;background:#fff">
              <input type="checkbox" name="folder_specific[]" value="<?=$bv['id']?>" <?=in_array($bv['id'],$folderSpecific)?'checked':''?> style="accent-color:var(--color-primary)">
              <?=Helpers::e($bv['firstname'].' '.$bv['lastname'])?>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
      </label>
      <script>
      document.querySelectorAll('input[name="folder_who"]').forEach(function(r){
        r.addEventListener('change',function(){
          document.getElementById('dp-folder-specific').style.display=(this.value==='specific'?'flex':'none');
        });
      });
      </script>
      <div style="margin-top:1rem">
        <button type="submit" name="save_folder_params" class="btn btn-primary">💾 Sauvegarder</button>
      </div>
    </form>
  </div>
</div>

<?php endif; ?>

<?php
$content = ob_get_clean();
include CC_ROOT . '/admin/layout.php';
