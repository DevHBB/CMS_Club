<?php
Auth::require('superadmin');

define('CC_LATEST_VERSION', '1.4.0');
define('CC_GITHUB_REPO',    'DevHBB/CMS_Club');
define('CC_GITHUB_API',     'https://api.github.com/repos/'.CC_GITHUB_REPO.'/releases/latest');
define('CC_GITHUB_ZIP',     'https://github.com/'.CC_GITHUB_REPO.'/archive/refs/heads/main.zip');

// Source de vérité = le TAG GitHub sauvegardé en BDD après chaque update
// CC_VERSION dans config.php n'est utilisé QUE comme fallback si jamais rien en BDD
$currentVersion = Config::get('installed_version', CC_VERSION ?? '1.0.0');
$results         = [];
$migrateRun      = false;
$updateRun       = false;
$updateError     = '';
$latestVersion   = null;
$latestZipUrl    = null;
$updateAvailable = false;

// ── HTTP helper : cURL avec fallback file_get_contents ────────
function ccHttpGet(string $url, int $timeout=10): array {
    // Essai 1 : cURL (plus fiable sur hébergeurs)
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_USERAGENT      => 'ClubCMS-Updater/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => ['Accept: application/vnd.github+json'],
        ]);
        $body  = curl_exec($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err   = curl_error($ch);
        curl_close($ch);
        if ($body !== false && $code >= 200 && $code < 400) return ['ok'=>true,'body'=>$body];
        if ($err) return ['ok'=>false,'body'=>'','error'=>"cURL : $err"];
    }
    // Essai 2 : file_get_contents
    if (ini_get('allow_url_fopen')) {
        $ctx  = stream_context_create(['http'=>[
            'timeout'    => $timeout,
            'user_agent' => 'ClubCMS-Updater/1.0',
            'ignore_errors' => true,
        ], 'ssl'=>['verify_peer'=>true]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body !== false) return ['ok'=>true,'body'=>$body];
    }
    return ['ok'=>false,'body'=>'','error'=>'Ni cURL ni allow_url_fopen disponibles. Contactez votre hébergeur.'];
}

// ── Télécharger un fichier binaire (ZIP) ──────────────────────
function ccDownloadZip(string $url, string $dest, int $timeout=60): array {
    if (function_exists('curl_init')) {
        $fp = fopen($dest, 'wb');
        if (!$fp) return ['ok'=>false,'error'=>"Impossible de créer le fichier temporaire $dest"];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_USERAGENT      => 'ClubCMS-Updater/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $ok  = curl_exec($ch);
        $err = curl_error($ch);
        $code= curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        if (!$ok || $code < 200 || $code >= 400) {
            @unlink($dest);
            return ['ok'=>false,'error'=>"Téléchargement échoué (HTTP $code) : $err"];
        }
        return ['ok'=>true];
    }
    if (ini_get('allow_url_fopen')) {
        $ctx  = stream_context_create(['http'=>['timeout'=>$timeout,'user_agent'=>'ClubCMS-Updater/1.0'],'ssl'=>['verify_peer'=>true]]);
        $data = @file_get_contents($url, false, $ctx);
        if ($data === false) return ['ok'=>false,'error'=>'file_get_contents a échoué.'];
        file_put_contents($dest, $data);
        return ['ok'=>true];
    }
    return ['ok'=>false,'error'=>'Aucune méthode HTTP disponible (cURL et allow_url_fopen désactivés).'];
}

// ── Vérifier la version sur GitHub ────────────────────────────
function checkGithubVersion(): array {
    $r = ccHttpGet(CC_GITHUB_API, 5);
    if (!$r['ok']) return ['version'=>null,'zip'=>null,'error'=>$r['error']??'GitHub injoignable'];
    $data = json_decode($r['body'], true);
    if (!$data) return ['version'=>null,'zip'=>null,'error'=>'Réponse GitHub invalide'];
    if (isset($data['message'])) return ['version'=>null,'zip'=>null,'error'=>$data['message']];
    $tag = ltrim($data['tag_name'] ?? '', 'v');
    $zip = $data['zipball_url'] ?? CC_GITHUB_ZIP;
    return ['version'=>$tag,'zip'=>$zip,'error'=>null];
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
    $updateRun   = true;
    $updateSteps = []; // log pas à pas
    $updateError = '';

    try {
        // Vérif prérequis
        if (!class_exists('ZipArchive')) throw new Exception("ZipArchive non disponible. Activez l'extension zip dans php.ini (extension=zip).");

        $zipUrl = $_POST['zip_url'] ?? CC_GITHUB_ZIP;
        $tmpZip = sys_get_temp_dir().DIRECTORY_SEPARATOR.'clubcms_update_'.time().'.zip';
        $tmpDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'clubcms_upd_'.time();

        $updateSteps[] = ['ok'=>true, 'msg'=>"Dossier temp : ".sys_get_temp_dir()];
        $updateSteps[] = ['ok'=>true, 'msg'=>"URL : $zipUrl"];

        // Tester accès écriture dossier temp
        if (!is_writable(sys_get_temp_dir())) throw new Exception("Dossier temporaire non accessible en écriture : ".sys_get_temp_dir());
        $updateSteps[] = ['ok'=>true, 'msg'=>"Dossier temp accessible ✓"];

        // Télécharger
        $dlResult = ccDownloadZip($zipUrl, $tmpZip, 60);
        if (!$dlResult['ok']) throw new Exception("Téléchargement échoué : ".($dlResult['error']??'erreur inconnue'));
        $zipSize = file_exists($tmpZip) ? filesize($tmpZip) : 0;
        if ($zipSize < 1000) throw new Exception("ZIP téléchargé trop petit ($zipSize octets) — URL invalide ou GitHub inaccessible.");
        $updateSteps[] = ['ok'=>true, 'msg'=>"ZIP téléchargé : ".round($zipSize/1024)." Ko ✓"];

        // Extraire
        @mkdir($tmpDir, 0755, true);
        $zip = new ZipArchive();
        $zr  = $zip->open($tmpZip);
        if ($zr !== true) throw new Exception("Impossible d'ouvrir le ZIP (code $zr). Fichier corrompu ?");
        $zip->extractTo($tmpDir);
        $zip->close();
        @unlink($tmpZip);
        $updateSteps[] = ['ok'=>true, 'msg'=>"ZIP extrait dans $tmpDir ✓"];

        // Trouver dossier racine dans le ZIP (GitHub ajoute un sous-dossier)
        $rootDir = $tmpDir;
        foreach (scandir($tmpDir) as $e) {
            if ($e!=='.' && $e!=='..' && is_dir($tmpDir.DIRECTORY_SEPARATOR.$e)) {
                $rootDir = $tmpDir.DIRECTORY_SEPARATOR.$e; break;
            }
        }
        $updateSteps[] = ['ok'=>true, 'msg'=>"Racine ZIP : $rootDir ✓"];

        // Fichiers/dossiers protégés (jamais écrasés)
        $protected = ['config'.DIRECTORY_SEPARATOR.'config.php', 'uploads', 'install.php'];

        // Copier récursivement
        $copied = 0; $failed = 0;
        function ccCopyDir(string $src, string $dst, array $prot, string $base, int &$ok, int &$ko): void {
            if (!is_dir($dst)) @mkdir($dst, 0755, true);
            foreach (scandir($src) as $item) {
                if ($item==='.' || $item==='..') continue;
                $rel = ltrim(str_replace($base, '', $dst).DIRECTORY_SEPARATOR.$item, DIRECTORY_SEPARATOR);
                foreach ($prot as $p) { if (str_starts_with($rel, $p)) return; }
                $s = $src.DIRECTORY_SEPARATOR.$item;
                $d = $dst.DIRECTORY_SEPARATOR.$item;
                if (is_dir($s))  ccCopyDir($s, $d, $prot, $base, $ok, $ko);
                elseif (@copy($s, $d)) $ok++;
                else $ko++;
            }
        }
        ccCopyDir($rootDir, CC_ROOT, $protected, CC_ROOT, $copied, $failed);
        $updateSteps[] = ['ok'=>$failed===0, 'msg'=>"Fichiers copiés : $copied ✓".($failed?" · $failed échec(s)":'')];

        // Nettoyage
        function ccRmDir(string $dir): void {
            if (!is_dir($dir)) return;
            foreach (scandir($dir) as $f) {
                if ($f==='.'||$f==='..') continue;
                $p = $dir.DIRECTORY_SEPARATOR.$f;
                is_dir($p) ? ccRmDir($p) : @unlink($p);
            }
            @rmdir($dir);
        }
        ccRmDir($tmpDir);
        $updateSteps[] = ['ok'=>true, 'msg'=>"Fichiers temporaires nettoyés ✓"];

        // Migrations BDD
        $migOk = 0; $migFail = 0;
        foreach ($allMigrations as $sql) {
            try { Database::run($sql); $migOk++; }
            catch(Exception $e) { $migFail++; }
        }
        // On sauvegarde le TAG GitHub comme version installée
        // (pas CC_VERSION du ZIP qui peut être différent)
        $newVersion = $latestVersion; // = tag GitHub ex: "1.4.1"
        Config::set('installed_version', $newVersion, 'system');
        $updateSteps[] = ['ok'=>true, 'msg'=>"Version installée enregistrée : v$newVersion ✓"];

        $updateSteps[] = ['ok'=>true, 'msg'=>"Migrations BDD : $migOk appliquées".($migFail?" · $migFail ignorées":'')." ✓"];
        $results = $updateSteps;

    } catch(Exception $e) {
        $updateError = $e->getMessage();
        $results = $updateSteps; // afficher les étapes déjà complétées
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
    <div style="opacity:.85;font-size:.875rem">Vous utilisez v<?=$currentVersion?> — La mise à jour est automatique, vos données sont protégées.</div>
  </div>
  <form method="post" id="update-form">
    <?=Auth::csrfField()?>
    <input type="hidden" name="zip_url" value="<?=Helpers::e($latestZipUrl ?? CC_GITHUB_ZIP)?>">
    <input type="hidden" name="run_update" value="1">
    <button type="submit" id="update-btn"
      style="background:#fff;color:#6366f1;font-weight:700;border:none;border-radius:8px;padding:.6rem 1.25rem;cursor:pointer;font-size:.9rem">
      ⬇️ Mettre à jour maintenant
    </button>
  </form>
  <script>
  document.getElementById('update-form').addEventListener('submit',function(){
    var btn=document.getElementById('update-btn');
    btn.disabled=true;
    btn.style.opacity='0.7';
    btn.innerHTML='⏳ Téléchargement en cours…';
  });
  </script>
</div>
<?php elseif(!$ghCheck['error'] && !$updateAvailable): ?>
<div style="background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:10px;padding:.875rem 1.25rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:.75rem">
  <span style="font-size:1.25rem">✅</span>
  <div><strong>ClubCMS est à jour</strong> — vous utilisez la dernière version (v<?=$currentVersion?>).</div>
</div>
<?php elseif($ghCheck['error']): ?>
<div style="background:#fef3c7;border:1.5px solid #fde68a;border-radius:10px;padding:.875rem 1.25rem;margin-bottom:1.25rem">
  <div style="font-weight:700;color:#92400e;margin-bottom:.35rem">⚠️ Impossible de vérifier la version sur GitHub</div>
  <div style="font-size:.82rem;color:#78350f;margin-bottom:.5rem"><?=Helpers::e($ghCheck['error'])?></div>
  <div style="font-size:.78rem;color:#92400e">
    <?php if(!function_exists('curl_init')): ?>❌ cURL non disponible sur ce serveur.<?php else: ?>✅ cURL disponible<?php endif; ?> &nbsp;·&nbsp;
    <?php if(!ini_get('allow_url_fopen')): ?>❌ allow_url_fopen désactivé.<?php else: ?>✅ allow_url_fopen activé<?php endif; ?>
    <?php if(!function_exists('curl_init') && !ini_get('allow_url_fopen')): ?>
    <br><strong style="color:#dc2626">Contactez votre hébergeur pour activer cURL ou allow_url_fopen.</strong>
    <?php elseif(strpos(CC_GITHUB_API,'github.com')!==false): ?>
    <br>Le serveur ne parvient pas à joindre GitHub — vérifiez le pare-feu ou réessayez dans quelques instants.
    <?php endif; ?>
  </div>
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
    <div style="background:#fff5f5;border:1.5px solid #fecaca;border-radius:8px;padding:.875rem;color:#dc2626;margin-bottom:.75rem;font-weight:600">
      ❌ <?=Helpers::e($updateError)?>
    </div>
    <?php else: ?>
    <div style="background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:8px;padding:.875rem;color:#15803d;margin-bottom:.75rem;font-weight:600">
      ✅ Mise à jour terminée avec succès — migrations BDD appliquées.
    </div>
    <?php endif; ?>
    <?php if(!empty($results)): ?>
    <div style="background:#0f172a;border-radius:8px;padding:.875rem;margin-bottom:.875rem">
      <div style="font-size:.72rem;color:#64748b;margin-bottom:.5rem;font-family:monospace">Journal d'installation :</div>
      <?php foreach($results as $step):
        $stepOk  = is_array($step) ? ($step['ok']??true) : str_starts_with((string)$step,'✓');
        $stepMsg = is_array($step) ? ($step['msg']??'') : (string)$step;
      ?>
      <div style="font-family:monospace;font-size:.78rem;color:<?=$stepOk?'#86efac':'#fca5a5'?>;margin-bottom:.3rem;display:flex;gap:.5rem">
        <span><?=$stepOk?'✓':'✗'?></span>
        <span><?=Helpers::e($stepMsg)?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <a href="<?=u('/admin/update')?>" class="btn btn-primary">← Retour</a>
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
          ['Version installée','v'.$currentVersion],
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
      <input type="hidden" name="run_migrations" value="1">
      <button type="submit" class="btn btn-primary">⚙️ Lancer les migrations</button>
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
