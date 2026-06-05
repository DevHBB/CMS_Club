<?php
Auth::require('superadmin');

define('CC_LATEST_VERSION', '1.4.0');
define('CC_GITHUB_REPO',    'DevHBB/CMS_Club');
define('CC_GITHUB_API',     'https://api.github.com/repos/'.CC_GITHUB_REPO.'/releases/latest');
define('CC_GITHUB_ZIP',     'https://github.com/'.CC_GITHUB_REPO.'/archive/refs/heads/main.zip');

$currentVersion  = CC_VERSION ?? '1.0.0';
$results         = [];
$migrateRun      = false;
$updateRun       = false;
$updateError     = '';
$latestVersion   = null;
$latestZipUrl    = null;
$updateAvailable = false;

// ── Vérifier la version sur GitHub ────────────────────────────
function checkGithubVersion(): array {
    $ctx = stream_context_create(['http'=>[
        'timeout'         => 5,
        'user_agent'      => 'ClubCMS-Updater/1.0',
        'ignore_errors'   => true,
    ]]);
    $json = @file_get_contents(CC_GITHUB_API, false, $ctx);
    if (!$json) return ['version'=>null,'zip'=>null,'error'=>'Impossible de joindre GitHub.'];
    $data = json_decode($json, true);
    if (isset($data['message'])) return ['version'=>null,'zip'=>null,'error'=>$data['message']];
    $tag = ltrim($data['tag_name'] ?? '', 'v');
    $zip = $data['zipball_url'] ?? CC_GITHUB_ZIP;
    return ['version'=>$tag, 'zip'=>$zip, 'error'=>null];
}

$ghCheck = checkGithubVersion();
if ($ghCheck['version']) {
    $latestVersion   = $ghCheck['version'];
    $latestZipUrl    = $ghCheck['zip'];
    $updateAvailable = version_compare($latestVersion, $currentVersion, '>');
}

// ── Migrations disponibles ─────────────────────────────────────
$allMigrations = [
    "CREATE TABLE IF NOT EXISTS cc_benv_events (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(200) NOT NULL, description TEXT, location VARCHAR(200), date_start DATETIME NOT NULL, date_end DATETIME, max_volunteers INT DEFAULT 0, created_by INT NOT NULL, recurring VARCHAR(20) DEFAULT 'none', color VARCHAR(7) DEFAULT '#6366f1', created_at DATETIME DEFAULT CURRENT_TIMESTAMP)",
    "CREATE TABLE IF NOT EXISTS cc_benv_participations (id INT AUTO_INCREMENT PRIMARY KEY, event_id INT NOT NULL, user_id INT NOT NULL, status VARCHAR(20) DEFAULT 'confirmed', created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_ep (event_id,user_id))",
    "CREATE TABLE IF NOT EXISTS cc_benv_tasks (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(200) NOT NULL, description TEXT, status VARCHAR(20) DEFAULT 'todo', priority VARCHAR(10) DEFAULT 'normal', assigned_to INT DEFAULT NULL, due_date DATE DEFAULT NULL, created_by INT NOT NULL, recurring VARCHAR(20) DEFAULT 'none', color VARCHAR(7) DEFAULT '#6366f1', created_at DATETIME DEFAULT CURRENT_TIMESTAMP)",
    "CREATE TABLE IF NOT EXISTS cc_benv_task_volunteers (id INT AUTO_INCREMENT PRIMARY KEY, task_id INT NOT NULL, user_id INT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_tv (task_id,user_id))",
    "CREATE TABLE IF NOT EXISTS cc_benv_task_suggestions (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(200) NOT NULL, description TEXT, suggested_by INT NOT NULL, status VARCHAR(20) DEFAULT 'pending', reviewed_by INT DEFAULT NULL, review_note TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)",
    "CREATE TABLE IF NOT EXISTS cc_benv_channels (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL, slug VARCHAR(100) NOT NULL UNIQUE, open TINYINT(1) DEFAULT 1, created_by INT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)",
    "CREATE TABLE IF NOT EXISTS cc_benv_chat (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, message TEXT NOT NULL, channel VARCHAR(50) DEFAULT 'general', created_at DATETIME DEFAULT CURRENT_TIMESTAMP)",
    "CREATE TABLE IF NOT EXISTS cc_benv_chat_muted (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, muted_by INT NOT NULL, until DATETIME DEFAULT NULL, UNIQUE KEY uq_muted (user_id))",
    "CREATE TABLE IF NOT EXISTS cc_benv_alerts (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(200) NOT NULL, message TEXT, level VARCHAR(10) DEFAULT 'info', active TINYINT(1) DEFAULT 1, created_by INT NOT NULL, expires_at DATETIME DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)",
    "CREATE TABLE IF NOT EXISTS cc_benv_alerts_seen (id INT AUTO_INCREMENT PRIMARY KEY, alert_id INT NOT NULL, user_id INT NOT NULL, UNIQUE KEY uq_as (alert_id,user_id))",
    "CREATE TABLE IF NOT EXISTS cc_benv_folders (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(200) NOT NULL, parent_id INT DEFAULT NULL, created_by INT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)",
    "CREATE TABLE IF NOT EXISTS cc_benv_docs (id INT AUTO_INCREMENT PRIMARY KEY, folder_id INT DEFAULT NULL, title VARCHAR(200) NOT NULL, type VARCHAR(10) DEFAULT 'note', content TEXT, filename VARCHAR(255) DEFAULT NULL, filesize INT DEFAULT NULL, mimetype VARCHAR(100) DEFAULT NULL, visibility VARCHAR(20) DEFAULT 'all', can_download VARCHAR(20) DEFAULT 'all', allowed_users TEXT DEFAULT NULL, created_by INT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)",
    "CREATE TABLE IF NOT EXISTS cc_benv_profiles (user_id INT PRIMARY KEY, skills TEXT, notes TEXT, blacklisted TINYINT(1) DEFAULT 0, blacklist_reason TEXT, can_add_tasks TINYINT(1) DEFAULT 0, can_upload TINYINT(1) DEFAULT 0, can_manage_planning TINYINT(1) DEFAULT 0, can_delete_notes TINYINT(1) DEFAULT 0)",
    "CREATE TABLE IF NOT EXISTS cc_benv_coach_access (coach_id INT PRIMARY KEY, can_access TINYINT(1) DEFAULT 0, see_blacklist TINYINT(1) DEFAULT 0)",
    "CREATE TABLE IF NOT EXISTS cc_benv_reminders_sent (id INT AUTO_INCREMENT PRIMARY KEY, event_id INT NOT NULL, user_id INT NOT NULL, UNIQUE KEY uq_rem (event_id,user_id))",
    "CREATE TABLE IF NOT EXISTS cc_benv_slot_volunteers (id INT AUTO_INCREMENT PRIMARY KEY, slot_id INT NOT NULL, user_id INT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_sv (slot_id,user_id))",
    "ALTER TABLE cc_planning_slots ADD COLUMN IF NOT EXISTS booking_mode VARCHAR(20) DEFAULT 'auto'",
    "ALTER TABLE cc_planning_slots ADD COLUMN IF NOT EXISTS color VARCHAR(7) DEFAULT '#6366f1'",
    "ALTER TABLE cc_planning_slots ADD COLUMN IF NOT EXISTS max_participants INT DEFAULT 0",
    "ALTER TABLE cc_benv_tasks ADD COLUMN IF NOT EXISTS time_start TIME DEFAULT NULL",
    "ALTER TABLE cc_benv_tasks ADD COLUMN IF NOT EXISTS time_end TIME DEFAULT NULL",
    "ALTER TABLE cc_benv_tasks ADD COLUMN IF NOT EXISTS recurring_days VARCHAR(50) DEFAULT NULL",
    "ALTER TABLE cc_benv_tasks ADD COLUMN IF NOT EXISTS max_volunteers INT DEFAULT 1",
    "ALTER TABLE cc_benv_tasks ADD COLUMN IF NOT EXISTS volunteer_id INT DEFAULT NULL",
    "ALTER TABLE cc_benv_profiles ADD COLUMN IF NOT EXISTS can_add_tasks TINYINT(1) DEFAULT 0",
    "ALTER TABLE cc_benv_profiles ADD COLUMN IF NOT EXISTS can_upload TINYINT(1) DEFAULT 0",
    "ALTER TABLE cc_benv_profiles ADD COLUMN IF NOT EXISTS can_manage_planning TINYINT(1) DEFAULT 0",
    "ALTER TABLE cc_benv_profiles ADD COLUMN IF NOT EXISTS can_delete_notes TINYINT(1) DEFAULT 0",
    "ALTER TABLE cc_benv_docs ADD COLUMN IF NOT EXISTS mimetype VARCHAR(100) DEFAULT NULL",
    "ALTER TABLE cc_benv_docs ADD COLUMN IF NOT EXISTS visibility VARCHAR(20) DEFAULT 'all'",
    "ALTER TABLE cc_benv_docs ADD COLUMN IF NOT EXISTS can_download VARCHAR(20) DEFAULT 'all'",
    "ALTER TABLE cc_benv_docs ADD COLUMN IF NOT EXISTS allowed_users TEXT DEFAULT NULL",
];

// ── Handler : migrations BDD ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && Auth::verifyCsrf() && isset($_POST['run_migrations'])) {
    $migrateRun = true;
    foreach ($allMigrations as $sql) {
        try { Database::run($sql); $results[] = ['ok'=>true,  'sql'=>$sql]; }
        catch(Exception $e)       { $results[] = ['ok'=>false, 'sql'=>$sql, 'err'=>$e->getMessage()]; }
    }
    Config::set('installed_version', $currentVersion, 'system');
}

// ── Handler : mise à jour automatique ─────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && Auth::verifyCsrf() && isset($_POST['run_update'])) {
    $updateRun = true;
    $zipUrl    = $_POST['zip_url'] ?? CC_GITHUB_ZIP;
    $tmpZip    = sys_get_temp_dir().'/clubcms_update.zip';
    $tmpDir    = sys_get_temp_dir().'/clubcms_update_'.time();

    // Fichiers/dossiers à NE JAMAIS écraser
    $protected = ['config/config.php', 'uploads'];

    try {
        // 1. Télécharger le ZIP
        $ctx = stream_context_create(['http'=>['timeout'=>30,'user_agent'=>'ClubCMS-Updater/1.0']]);
        $zipData = @file_get_contents($zipUrl, false, $ctx);
        if (!$zipData) throw new Exception("Impossible de télécharger la mise à jour depuis GitHub.");

        file_put_contents($tmpZip, $zipData);

        // 2. Extraire le ZIP
        $zip = new ZipArchive();
        if ($zip->open($tmpZip) !== true) throw new Exception("Impossible d'extraire le ZIP téléchargé.");
        @mkdir($tmpDir, 0755, true);
        $zip->extractTo($tmpDir);
        $zip->close();
        @unlink($tmpZip);

        // 3. Trouver le dossier racine dans le ZIP (GitHub ajoute un sous-dossier)
        $entries = scandir($tmpDir);
        $rootDir = $tmpDir;
        foreach ($entries as $e) {
            if ($e!=='.' && $e!=='..' && is_dir($tmpDir.'/'.$e)) {
                $rootDir = $tmpDir.'/'.$e; break;
            }
        }

        // 4. Copier récursivement en protégeant les fichiers sensibles
        $updateLog = [];
        function copyUpdate(string $src, string $dst, array $protected, string $base, array &$log): void {
            foreach (scandir($src) as $item) {
                if ($item==='.' || $item==='..') continue;
                $relPath = ltrim(str_replace($base, '', $dst).'/'.$item, '/');
                // Vérifier protection
                foreach ($protected as $p) {
                    if (str_starts_with($relPath, $p)) return;
                }
                $srcPath = $src.'/'.$item;
                $dstPath = $dst.'/'.$item;
                if (is_dir($srcPath)) {
                    @mkdir($dstPath, 0755, true);
                    copyUpdate($srcPath, $dstPath, $protected, $base, $log);
                } else {
                    if (@copy($srcPath, $dstPath)) $log[] = '✓ '.$relPath;
                    else $log[] = '✗ '.$relPath.' (échec copie)';
                }
            }
        }
        copyUpdate($rootDir, CC_ROOT, $protected, CC_ROOT, $updateLog);

        // 5. Nettoyer le dossier temporaire
        function rrmdir(string $dir): void {
            if (!is_dir($dir)) return;
            foreach (scandir($dir) as $f) {
                if ($f==='.' || $f==='..') continue;
                $p = $dir.'/'.$f;
                is_dir($p) ? rrmdir($p) : @unlink($p);
            }
            @rmdir($dir);
        }
        rrmdir($tmpDir);

        // 6. Migrations automatiques post-update
        foreach ($allMigrations as $sql) {
            try { Database::run($sql); } catch(Exception $e) {}
        }
        Config::set('installed_version', $latestVersion ?? $currentVersion, 'system');
        $results = $updateLog;

    } catch(Exception $e) {
        $updateError = $e->getMessage();
    }
}

// ── État des tables ────────────────────────────────────────────
$expectedTables = [
    'cc_config','cc_users','cc_modules','cc_articles','cc_menu',
    'cc_forum_categories','cc_forum_topics','cc_forum_posts',
    'cc_shop_categories','cc_shop_products','cc_shop_orders',
    'cc_gallery_folders','cc_gallery_photos',
    'cc_planning_types','cc_planning_slots','cc_planning_bookings',
    'cc_planning_criteria','cc_planning_criteria_values',
    'cc_mail_queue','cc_newsletter_subscribers','cc_newsletter_campaigns',
    'cc_benv_events','cc_benv_participations','cc_benv_tasks',
    'cc_benv_task_volunteers','cc_benv_task_suggestions',
    'cc_benv_channels','cc_benv_chat','cc_benv_chat_muted',
    'cc_benv_alerts','cc_benv_alerts_seen',
    'cc_benv_folders','cc_benv_docs','cc_benv_profiles',
    'cc_benv_coach_access','cc_benv_reminders_sent','cc_benv_slot_volunteers',
];
$tableCheck = [];
try {
    $raw = Database::all("SHOW TABLES");
    $existing = array_map(fn($r)=>array_values($r)[0], $raw);
    foreach ($expectedTables as $t) $tableCheck[$t] = in_array($t, $existing);
} catch(Exception $e) {}
$missingTables = array_keys(array_filter($tableCheck, fn($v)=>!$v));

$pageTitle = '🔄 Mise à jour — ClubCMS';
ob_start();
?>
<div class="page-head">
  <h1>🔄 Mise à jour & Maintenance</h1>
</div>

<!-- Bannière mise à jour disponible -->
<?php if($updateAvailable && !$updateRun): ?>
<div style="background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;border-radius:14px;padding:1.25rem 1.5rem;margin-bottom:1.25rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap">
  <div>
    <div style="font-weight:800;font-size:1.05rem;margin-bottom:.25rem">🚀 Nouvelle version disponible : v<?=Helpers::e($latestVersion)?></div>
    <div style="opacity:.85;font-size:.875rem">Vous utilisez v<?=CC_VERSION?> — La mise à jour est automatique, vos données sont protégées.</div>
  </div>
  <form method="post">
    <?=Auth::csrfField()?>
    <input type="hidden" name="zip_url" value="<?=Helpers::e($latestZipUrl ?? CC_GITHUB_ZIP)?>">
    <button type="submit" name="run_update"
      onclick="this.disabled=true;this.innerHTML='⏳ Téléchargement…';this.form.submit()"
      style="background:#fff;color:#6366f1;font-weight:700;border:none;border-radius:8px;padding:.6rem 1.25rem;cursor:pointer;font-size:.9rem">
      ⬇️ Mettre à jour maintenant
    </button>
  </form>
</div>
<?php elseif(!$ghCheck['error'] && !$updateAvailable): ?>
<div style="background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:10px;padding:.875rem 1.25rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:.75rem">
  <span style="font-size:1.25rem">✅</span>
  <div><strong>ClubCMS est à jour</strong> — vous utilisez la dernière version (v<?=CC_VERSION?>).</div>
</div>
<?php elseif($ghCheck['error']): ?>
<div style="background:#fef3c7;border:1.5px solid #fde68a;border-radius:10px;padding:.875rem 1.25rem;margin-bottom:1.25rem">
  ⚠️ Impossible de vérifier la version : <?=Helpers::e($ghCheck['error'])?> — vérifiez votre connexion internet.
</div>
<?php endif; ?>

<!-- Résultat mise à jour automatique -->
<?php if($updateRun): ?>
<div class="ac" style="margin-bottom:1.25rem">
  <div class="ac-header">
    <h2><?=$updateError ? '❌ Erreur lors de la mise à jour' : '✅ Mise à jour effectuée avec succès'?></h2>
  </div>
  <div class="ac-body">
    <?php if($updateError): ?>
    <div style="background:#fff5f5;border:1.5px solid #fecaca;border-radius:8px;padding:.875rem;color:#dc2626;margin-bottom:.75rem">
      <?=Helpers::e($updateError)?>
    </div>
    <?php else: ?>
    <div style="background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:8px;padding:.875rem;color:#15803d;margin-bottom:.75rem;font-weight:600">
      <?=count($results)?> fichiers mis à jour — migrations BDD appliquées automatiquement.
    </div>
    <?php endif; ?>
    <div style="max-height:220px;overflow-y:auto;background:#0f172a;border-radius:8px;padding:.75rem">
      <?php foreach($results as $line): ?>
      <div style="font-family:monospace;font-size:.72rem;color:<?=str_starts_with((string)$line,'✓')?'#86efac':'#fca5a5'?>;margin-bottom:.15rem">
        <?=Helpers::e((string)$line)?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php if(!$updateError): ?>
    <a href="<?=u('/admin/update')?>" class="btn btn-primary" style="margin-top:1rem">← Retour</a>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.25rem">

  <!-- Version -->
  <div class="ac">
    <div class="ac-header"><h2>📦 Versions</h2></div>
    <div class="ac-body">
      <div style="display:flex;flex-direction:column;gap:.625rem">
        <?php foreach([
          ['Version installée','v'.CC_VERSION],
          ['Dernière version GitHub', $latestVersion ? 'v'.$latestVersion : ($ghCheck['error'] ? '—' : 'Vérification…')],
          ['Tables BDD', count(array_filter($tableCheck)).'/'.count($expectedTables).' présentes'],
        ] as [$label,$val]): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:.625rem .875rem;background:#f8fafc;border-radius:8px;font-size:.875rem">
          <span style="color:#64748b"><?=$label?></span>
          <strong><?=Helpers::e($val)?></strong>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Tables -->
  <div class="ac">
    <div class="ac-header">
      <h2>🗄️ Tables BDD</h2>
      <?php if(!empty($missingTables)): ?>
      <span style="background:#fef3c7;color:#92400e;font-size:.72rem;padding:.2rem .5rem;border-radius:99px;font-weight:700"><?=count($missingTables)?> manquante(s)</span>
      <?php endif; ?>
    </div>
    <div class="ac-body" style="max-height:220px;overflow-y:auto">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:.25rem">
        <?php foreach($tableCheck as $t=>$ok): ?>
        <div style="display:flex;align-items:center;gap:.35rem;font-size:.72rem;padding:.25rem .4rem;border-radius:4px;background:<?=$ok?'#f0fdf4':'#fff5f5'?>">
          <span><?=$ok?'✅':'❌'?></span>
          <?php $tc=$ok?'#15803d':'#dc2626'; ?>
          <code style="color:<?=$tc?>"><?=$t?></code>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

</div>

<!-- Migrations manuelles -->
<div class="ac" style="margin-bottom:1.25rem">
  <div class="ac-header"><h2>⚙️ Migrations BDD manuelles</h2></div>
  <div class="ac-body">
    <p style="font-size:.875rem;color:#64748b;margin-bottom:.875rem">
      Toutes les migrations utilisent <code>IF NOT EXISTS</code> — elles n'écrasent jamais les données existantes.
      Relançable autant de fois que nécessaire.
    </p>
    <?php if($migrateRun): ?>
    <?php $ok=count(array_filter($results,fn($r)=>is_array($r)&&$r['ok'])); ?>
    <div style="background:<?=$ok===count($results)?'#f0fdf4':'#fef3c7'?>;border:1.5px solid <?=$ok===count($results)?'#bbf7d0':'#fde68a'?>;border-radius:8px;padding:.75rem;font-weight:600;color:<?=$ok===count($results)?'#15803d':'#92400e'?>;margin-bottom:.75rem">
      ✅ <?=$ok?>/<?=count($results)?> migrations réussies.
    </div>
    <div style="max-height:180px;overflow-y:auto;background:#0f172a;border-radius:8px;padding:.75rem;margin-bottom:.875rem">
      <?php foreach($results as $r): if(!is_array($r)) continue; ?>
      <div style="font-family:monospace;font-size:.7rem;color:<?=$r['ok']?'#86efac':'#fca5a5'?>;margin-bottom:.2rem">
        <?=$r['ok']?'✓':'✗'?> <?=Helpers::e(substr($r['sql'],0,85)).(strlen($r['sql'])>85?'…':'')?><?php if(!$r['ok']): ?> <span style="color:#fbbf24"><?=Helpers::e($r['err'])?></span><?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <form method="post">
      <?=Auth::csrfField()?>
      <button type="submit" name="run_migrations" class="btn btn-primary">⚙️ Lancer les migrations</button>
    </form>
  </div>
</div>

<!-- FAQ -->
<div class="ac">
  <div class="ac-header"><h2>❓ FAQ — Modifications courantes</h2></div>
  <div class="ac-body">
    <?php $faq=[
      ['Ajouter un item dans le menu','Admin → Menu → "Ajouter un lien". Choisissez une page existante ou une URL externe. L\'ordre se modifie par glisser-déposer.'],
      ['Modifier les couleurs et le logo','Admin → Paramètres → onglet "Apparence". Changez la couleur primaire et uploadez votre logo.'],
      ['Créer un créneau de planning','Admin → Planning → onglet "Types" pour créer un type, puis "Créneaux". Cochez "Publié" pour le rendre visible.'],
      ['Ajouter un bénévole','Admin → Membres → créez ou modifiez un utilisateur → rôle "Bénévole". Il accède automatiquement à l\'espace bénévoles.'],
      ['Donner accès au panel bénévoles à un coach','Admin → Bénévoles → onglet "Accès coachs" → activez l\'accès pour le coach.'],
      ['Activer/désactiver l\'inscription','Admin → Paramètres → onglet "Inscription" → toggles "Autoriser l\'inscription" / "Autoriser la connexion".'],
      ['Envoyer une newsletter','Admin → Newsletter → rédigez la campagne et cliquez Envoyer. Configurez d\'abord le SMTP dans Paramètres → Emails.'],
      ['Exporter la liste des inscrits à un créneau','Admin → Planning → onglet "Inscriptions" → sélectionnez un créneau → bouton PDF.'],
      ['Créer une pop-up d\'annonce','Admin → Pop-up annonces → activez, choisissez un thème, ajoutez votre message et un compte à rebours optionnel.'],
      ['Sauvegarder la base de données','phpMyAdmin → sélectionnez votre base → "Exporter" → format SQL. À faire régulièrement, surtout avant une mise à jour.'],
      ['Ne jamais faire après l\'installation','Ne jamais relancer install.php — cela réinitialiserait toute la configuration.'],
    ]; ?>
    <div style="display:grid;gap:.4rem">
      <?php foreach($faq as [$q,$a]): ?>
      <details style="border:1.5px solid #e2e8f0;border-radius:8px;overflow:hidden">
        <summary style="padding:.7rem 1rem;cursor:pointer;font-weight:600;font-size:.875rem;background:#f8fafc;display:flex;align-items:center;gap:.5rem;user-select:none;list-style:none">
          <span style="color:#6366f1;transition:transform .2s">▶</span> <?=Helpers::e($q)?>
        </summary>
        <div style="padding:.875rem 1rem;font-size:.875rem;color:#475569;line-height:1.7;border-top:1px solid #f1f5f9"><?=Helpers::e($a)?></div>
      </details>
      <?php endforeach; ?>
    </div>
    <script>
    document.querySelectorAll('details').forEach(function(d){
      d.addEventListener('toggle',function(){
        var a=d.querySelector('summary span');
        if(a) a.style.transform=d.open?'rotate(90deg)':'rotate(0deg)';
      });
    });
    </script>
  </div>
</div>

<?php
$content = ob_get_clean();
include CC_ROOT . '/admin/layout.php';
