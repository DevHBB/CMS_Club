<?php
/**
 * ClubCMS — Espace Membre
 * Routes : /membre, /membre/profil, /membre/licence, /membre/carte, /membre/commandes
 */

ob_start(); // Nécessaire pour header() dans export RGPD
Auth::require('member');
$user = Auth::user();
if (!$user) {
    // Session invalide - déconnecter proprement sans boucle
    session_destroy();
    header('Location: ' . u('/login'));
    exit;
}
// ── Export RGPD ───────────────────────────────────────────────
if (isset($_GET['export_rgpd'])) {
    $uid = Auth::id();
    $data = [
        'compte'      => Database::one("SELECT id,firstname,lastname,email,phone,birthdate,address,city,zip,role,status,created_at FROM cc_users WHERE id=?", [$uid]),
        'commandes'   => Database::all("SELECT * FROM cc_shop_orders WHERE user_id=?", [$uid]),
        'reservations'=> Database::all("SELECT s.title,s.date_start,b.status,b.created_at FROM cc_planning_bookings b JOIN cc_planning_slots s ON s.id=b.slot_id WHERE b.user_id=?", [$uid]),
        'forum'       => Database::all("SELECT t.title,p.content,p.created_at FROM cc_forum_posts p JOIN cc_forum_topics t ON t.id=p.topic_id WHERE p.user_id=?", [$uid]),
    ];
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="mes-donnees-'.date('Y-m-d').'.json"');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Annuler demande de suppression ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_delete']) && Auth::verifyCsrf()) {
    try { 
        Database::run("DELETE FROM cc_config WHERE key_name=? AND group_name='delete_requests'", 
            ['delete_request_'.Auth::id()]);
    } catch(Exception $e) {
        // Fallback si DELETE ne fonctionne pas
        Config::set('delete_request_'.Auth::id(), '', 'delete_requests');
    }
    $_SESSION['flash'] = ['type'=>'success','msg'=>'Votre demande de suppression a été annulée.'];
    Helpers::redirect(u('/membre/confidentialite'));
}

// ── Demande suppression compte ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_delete']) && Auth::verifyCsrf()) {
    $reason = Helpers::sanitize($_POST['delete_reason'] ?? '');
    Config::set('delete_request_' . Auth::id(), json_encode([
        'user_id' => Auth::id(), 'name' => $user['firstname'].' '.$user['lastname'],
        'email' => $user['email'], 'reason' => $reason, 'requested_at' => date('Y-m-d H:i:s'),
    ]), 'delete_requests');
    try {
        Mailer::send(Config::get('club_email'), 'Admin',
            '🗑 Demande de suppression — '.$user['firstname'].' '.$user['lastname'],
            '<p><strong>'.$user['firstname'].' '.$user['lastname'].'</strong> ('.$user['email'].') demande la suppression de son compte.</p>'.
            ($reason ? '<p>Motif : '.htmlspecialchars($reason).'</p>' : '')
        );
    } catch(Exception $e) {}
    $_SESSION['flash'] = ['type'=>'success','msg'=>'Votre demande a été envoyée. Un administrateur la traitera sous 30 jours.'];
    Helpers::redirect(u('/membre/confidentialite'));
}


$action = $segments[1] ?? 'dashboard';

// ── TÉLÉCHARGEMENT CARTE PDF — avant tout output HTML ─────────
if ($action === 'carte' && ($param === 'telecharger')) {
    if (!$user['member_card_hash']) {
        $hash = Helpers::memberCardHash($user['id']);
        Database::run("UPDATE cc_users SET member_card_hash=?, member_card_generated_at=NOW() WHERE id=?", [$hash, $user['id']]);
        $user['member_card_hash'] = $hash;
    }
    define('CARD_DOWNLOAD_MODE', true);
    include CC_ROOT . '/modules/members/card.php';
    exit;
}

// ── Traitement POST ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Auth::verifyCsrf()) {
    Helpers::json(['error' => 'CSRF invalide'], 403);
}

// Mise à jour du profil
// ── Sauvegarder les critères depuis le profil ──────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'profil' && isset($_POST['save_criteria'])) {
    $regCritSettings = json_decode(Config::get('registration_criteria','{}'),true)??[];
    foreach ($regCritSettings as $cid => $s) {
        if (!($s['display']??0)) continue;
        $val = trim($_POST['crit_'.$cid] ?? '');
        if ($val === '__other__') $val = trim($_POST['crit_'.$cid.'_other'] ?? '');
        if ($val !== '') {
            try {
                Database::run(
                    "INSERT INTO cc_planning_criteria_values (user_id,criteria_id,value) VALUES (?,?,?)
                     ON DUPLICATE KEY UPDATE value=VALUES(value)",
                    [Auth::id(), (int)$cid, $val]
                );
            } catch(Exception $e) {}
        } else {
            try { Database::run("DELETE FROM cc_planning_criteria_values WHERE user_id=? AND criteria_id=?", [Auth::id(), (int)$cid]); } catch(Exception $e) {}
        }
    }
    $_SESSION['flash'] = ['type'=>'success','msg'=>'Vos informations ont été mises à jour.'];
    Helpers::redirect(u('/membre/profil'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'profil') {
    $fields = [
        'firstname' => Helpers::sanitize($_POST['firstname'] ?? ''),
        'lastname'  => Helpers::sanitize($_POST['lastname'] ?? ''),
        'phone'     => Helpers::sanitize($_POST['phone'] ?? ''),
        'birthdate' => $_POST['birthdate'] ?? null,
        'address'   => Helpers::sanitize($_POST['address'] ?? ''),
        'city'      => Helpers::sanitize($_POST['city'] ?? ''),
        'zip'       => Helpers::sanitize($_POST['zip'] ?? ''),
        'country'   => Helpers::sanitize($_POST['country'] ?? 'France'),
    ];

    // Upload avatar
    if (!empty($_FILES['avatar']['tmp_name'])) {
        $upload = Helpers::uploadImage($_FILES['avatar'], CC_ROOT . '/assets/uploads/avatars');
        if ($upload['success']) {
            $fields['avatar'] = 'assets/uploads/avatars/' . $upload['filename'];
            // Supprime l'ancien avatar
            if ($user['avatar'] && file_exists(CC_ROOT . '/' . $user['avatar'])) {
                @unlink(CC_ROOT . '/' . $user['avatar']);
            }
        }
    }

    $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($fields)));
    Database::run(
        "UPDATE cc_users SET $sets, updated_at = NOW() WHERE id = ?",
        [...array_values($fields), $user['id']]
    );

    $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Profil mis à jour avec succès.'];
    Helpers::redirect(u('/membre/profil'));
}

// Upload licence
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'licence') {
    $licenseNumber = Helpers::sanitize($_POST['license_number'] ?? '');
    $licenseExpiry = $_POST['license_expiry'] ?? null;

    $fields = [
        'license_number' => $licenseNumber,
        'license_expiry' => $licenseExpiry ?: null,
        'license_status' => 'pending',
    ];

    if (!empty($_FILES['license_file']['tmp_name'])) {
        $upload = Helpers::uploadImage(
            $_FILES['license_file'],
            CC_ROOT . '/assets/uploads/licenses',
            10,
            ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf']
        );
        if ($upload['success']) {
            $fields['license_file'] = 'assets/uploads/licenses/' . $upload['filename'];
        }
    }

    $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($fields)));
    Database::run(
        "UPDATE cc_users SET $sets WHERE id = ?",
        [...array_values($fields), $user['id']]
    );

    $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Licence soumise. En attente de validation par le staff.'];
    Helpers::redirect(u('/membre/licence'));
}

// Raffraîchit les données utilisateur
if ($user) {
    $user = Database::one("SELECT * FROM cc_users WHERE id = ?", [$user['id']]) ?? $user;
}

// ── Rendu ──────────────────────────────────────────────────────
$pageTitle = 'Mon espace — ' . Config::get('club_name');
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Calcul complétion profil
function profileCompletion(array $u): int {
    $fields = ['firstname','lastname','phone','birthdate','address','city','zip','avatar'];
    $filled = 0;
    foreach ($fields as $f) { if (!empty($u[$f])) $filled++; }
    return (int)round(($filled / count($fields)) * 100);
}
$completion = profileCompletion($user);

ob_start();
?>
<div class="member-wrap container">

  <!-- Sidebar -->
  <aside class="member-sidebar">
    <div class="member-avatar-block">
      <?php if ($user['avatar']): ?>
        <img src="<?=asset(Helpers::e($user['avatar']))?>" class="member-avatar-img" alt="">
      <?php else: ?>
        <div class="member-avatar-placeholder">
          <?= mb_strtoupper(mb_substr($user['firstname'] ?? 'M', 0, 1) . mb_substr($user['lastname'] ?? '', 0, 1)) ?>
        </div>
      <?php endif; ?>
      <div class="member-name"><?= Helpers::e(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? '')) ?></div>
      <span class="role-badge role-<?= Helpers::e($user['role']) ?>"><?= Auth::ROLE_LABELS[$user['role']] ?? Helpers::e(ucfirst($user['role'])) ?></span>
    </div>

    <!-- Complétion profil -->
    <div class="profile-completion">
      <div class="completion-label">
        <span>Profil complété</span>
        <strong><?= $completion ?>%</strong>
      </div>
      <div class="completion-bar">
        <div class="completion-fill" style="width:<?= $completion ?>%"></div>
      </div>
    </div>

    <nav class="member-nav">
      <a href="<?=u('/membre')?>" class="member-nav-item <?= $action === 'dashboard' ? 'active' : '' ?>">
        <span class="mnav-icon">🏠</span> Tableau de bord
      </a>
      <a href="<?=u('/membre/profil')?>" class="member-nav-item <?= $action === 'profil' ? 'active' : '' ?>">
        <span class="mnav-icon">👤</span> Mon profil
      </a>
      <a href="<?=u('/membre/licence')?>" class="member-nav-item <?= $action === 'licence' ? 'active' : '' ?>">
        <span class="mnav-icon">📄</span> Ma licence
        <?php
        $lstatus = $user['license_status'] ?? 'none';
        if ($lstatus === 'valid'):   ?><span class="mnav-badge green">✓ Valide</span>
        <?php elseif ($lstatus === 'pending'): ?><span class="mnav-badge yellow">⏳ Attente</span>
        <?php elseif ($lstatus === 'expired'): ?><span class="mnav-badge red">Expirée</span>
        <?php elseif ($lstatus === 'rejected'): ?><span class="mnav-badge red">Refusée</span>
        <?php endif; ?>
      </a>
      <a href="<?=u('/membre/carte')?>" class="member-nav-item <?= $action === 'carte' ? 'active' : '' ?>">
        <span class="mnav-icon">🪪</span> Carte membre
      </a>
      <a href="<?=u('/membre/commandes')?>" class="member-nav-item <?= $action === 'commandes' ? 'active' : '' ?>">
        <span class="mnav-icon">📦</span> Mes commandes
      </a>
      <a href="<?=u('/membre/confidentialite')?>" class="member-nav-item <?= $action === 'confidentialite' ? 'active' : '' ?>">
        <span class="mnav-icon">🔒</span> Mes données
      </a>
      <a href="<?=u('/membre/reservations')?>" class="member-nav-item <?= $action === 'reservations' ? 'active' : '' ?>">
        <span class="mnav-icon">📅</span> Mes réservations
      </a>
    </nav>
  </aside>

  <!-- Contenu principal -->
  <div class="member-content">

    <?php if ($flash): ?>
      <div class="alert alert-<?= $flash['type'] ?>"><?= Helpers::e($flash['msg']) ?></div>
    <?php endif; ?>

    <?php if ($action === 'dashboard'): ?>
      <!-- TABLEAU DE BORD -->
      <h2 class="page-heading">Bonjour <?= Helpers::e($user['firstname'] ?? 'Membre') ?> 👋</h2>

      <div class="dashboard-stats">
        <?php
        $nbCommandes   = Database::scalar("SELECT COUNT(*) FROM cc_shop_orders WHERE user_id = ?", [$user['id']]);
        $nbReservations= Database::scalar("SELECT COUNT(*) FROM cc_planning_bookings WHERE user_id = ? AND status='confirmed'", [$user['id']]);
        $nbTopics      = Database::scalar("SELECT COUNT(*) FROM cc_forum_topics WHERE user_id = ?", [$user['id']]);
        ?>
        <div class="stat-card">
          <div class="stat-icon">📦</div>
          <div class="stat-value"><?= $nbCommandes ?></div>
          <div class="stat-label">Commande<?= $nbCommandes > 1 ? 's' : '' ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-icon">📅</div>
          <div class="stat-value"><?= $nbReservations ?></div>
          <div class="stat-label">Réservation<?= $nbReservations > 1 ? 's' : '' ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-icon">💬</div>
          <div class="stat-value"><?= $nbTopics ?></div>
          <div class="stat-label">Topic<?= $nbTopics > 1 ? 's' : '' ?> forum</div>
        </div>
        <div class="stat-card">
          <div class="stat-icon">🪪</div>
          <div class="stat-value"><?= match($user['license_status']) {
            'valid'    => '✅',
            'pending'  => '⏳',
            'expired'  => '❌',
            'rejected' => '🚫',
            default    => '—'
          } ?></div>
          <div class="stat-label">Licence</div>
        </div>
      </div>

      <?php if ($completion < 100): ?>
      <div class="alert alert-info" style="margin-top:1.5rem">
        💡 Votre profil est complété à <strong><?= $completion ?>%</strong>.
        <a href="<?=u('/membre/profil')?>">Compléter mon profil →</a>
      </div>
      <?php endif; ?>

      <!-- Prochaines réservations -->
      <?php
      $reservations = Database::all(
          "SELECT b.*, s.title, s.date_start, s.date_end, s.type
           FROM cc_planning_bookings b
           JOIN cc_planning_slots s ON b.slot_id = s.id
           WHERE b.user_id = ? AND s.date_start >= NOW() AND b.status = 'confirmed'
           ORDER BY s.date_start ASC LIMIT 3",
          [$user['id']]
      );
      ?>
      <?php if ($reservations): ?>
      <div class="dashboard-section">
        <h3 class="section-heading">📅 Prochaines réservations</h3>
        <div class="reservation-list">
          <?php foreach ($reservations as $r): ?>
          <div class="reservation-item">
            <div class="res-date"><?= Helpers::dateTimeFormat($r['date_start']) ?></div>
            <div class="res-title"><?= Helpers::e($r['title']) ?></div>
            <div class="res-type">
              <span class="badge badge-<?= match($r['type']) { 'training' => 'primary', 'event' => 'warning', default => 'muted' } ?>">
                <?= match($r['type']) { 'open' => 'Libre', 'training' => 'Entraînement', 'event' => 'Événement', default => $r['type'] } ?>
              </span>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

    <?php elseif ($action === 'profil'): ?>
      <!-- PROFIL -->
      <h2 class="page-heading">Mon profil</h2>
      <div class="card">
        <form method="post" enctype="multipart/form-data">
          <?= Auth::csrfField() ?>
          <div class="form-section">
            <h3 class="form-section-title">Avatar</h3>
            <div class="avatar-upload-wrap">
              <div class="avatar-preview" id="avatar-preview">
                <?php if ($user['avatar']): ?>
                  <img src="<?=asset(Helpers::e($user['avatar']))?>" alt="" id="avatar-img">
                <?php else: ?>
                  <div class="avatar-initials" id="avatar-initials">
                    <?= mb_strtoupper(mb_substr($user['firstname'] ?? 'M', 0, 1) . mb_substr($user['lastname'] ?? '', 0, 1)) ?>
                  </div>
                <?php endif; ?>
              </div>
              <div>
                <label class="btn-upload" for="avatar-input">📷 Changer la photo</label>
                <input type="file" id="avatar-input" name="avatar" accept="image/*" style="display:none"
                       onchange="previewAvatar(this)">
                <p style="color:var(--color-muted);font-size:.8rem;margin-top:.3rem">JPG, PNG, WEBP — 5 Mo max</p>
              </div>
            </div>
          </div>

          <div class="form-section">
            <h3 class="form-section-title">Informations personnelles</h3>
            <div class="form-row">
              <div class="form-group">
                <label>Prénom *</label>
                <input type="text" name="firstname" value="<?= Helpers::e($user['firstname'] ?? '') ?>" required>
              </div>
              <div class="form-group">
                <label>Nom *</label>
                <input type="text" name="lastname" value="<?= Helpers::e($user['lastname'] ?? '') ?>" required>
              </div>
              <div class="form-group">
                <label>Téléphone</label>
                <input type="tel" name="phone" value="<?= Helpers::e($user['phone'] ?? '') ?>">
              </div>
              <div class="form-group">
                <label>Date de naissance</label>
                <input type="date" name="birthdate" value="<?= Helpers::e($user['birthdate'] ?? '') ?>">
              </div>
            </div>
          </div>

          <div class="form-section">
            <h3 class="form-section-title">Adresse</h3>
            <div class="form-row">
              <div class="form-group" style="grid-column:span 2">
                <label>Adresse</label>
                <input type="text" name="address" value="<?= Helpers::e($user['address'] ?? '') ?>">
              </div>
              <div class="form-group">
                <label>Ville</label>
                <input type="text" name="city" value="<?= Helpers::e($user['city'] ?? '') ?>">
              </div>
              <div class="form-group">
                <label>Code postal</label>
                <input type="text" name="zip" value="<?= Helpers::e($user['zip'] ?? '') ?>">
              </div>
            </div>
          </div>

          <div class="form-actions">
            <button type="submit" class="btn btn-primary">💾 Enregistrer</button>
          </div>
        </form>
      </div>

      <!-- Section critères du profil -->
      <?php
      $regCritSettings2 = json_decode(Config::get('registration_criteria','{}'),true)??[];
      $profileCriteria  = [];
      foreach ($regCritSettings2 as $cid2 => $s2) {
          if (!($s2['display']??0)) continue;
          try {
              $cr2 = Database::one("SELECT * FROM cc_planning_criteria WHERE id=? AND active=1", [(int)$cid2]);
              if ($cr2) {
                  $cr2['reg_required'] = (int)($s2['required']??0);
                  $profileCriteria[]   = $cr2;
              }
          } catch(Exception $e) {}
      }
      // Valeurs actuelles du membre
      $memberVals2 = [];
      try {
          $rows2 = Database::all("SELECT criteria_id,value FROM cc_planning_criteria_values WHERE user_id=?", [Auth::id()]);
          foreach($rows2 as $r2) $memberVals2[$r2['criteria_id']] = $r2['value'];
      } catch(Exception $e) {}
      ?>
      <?php if(!empty($profileCriteria)): ?>
      <div class="card" style="margin-top:1.25rem">
        <h3 style="font-family:var(--font-heading);font-size:1.1rem;margin-bottom:1rem;padding-bottom:.75rem;border-bottom:1.5px solid #f1f5f9">
          🏷 Mes informations
        </h3>
        <p style="font-size:.83rem;color:#64748b;margin-bottom:1.25rem">
          Ces informations sont pré-remplies automatiquement lors de vos inscriptions aux créneaux du planning.
        </p>
        <form method="post">
          <?=Auth::csrfField()?>
          <input type="hidden" name="save_criteria" value="1">
          <?php foreach($profileCriteria as $cr3):
            $opts3   = json_decode($cr3['options']??'[]',true)??[];
            $saved3  = $memberVals2[$cr3['id']] ?? '';
            $isOther3 = $saved3 && !empty($opts3) && !array_filter($opts3,fn($o)=>$o['label']===$saved3);
          ?>
          <div style="margin-bottom:1.25rem">
            <label style="display:block;font-weight:700;font-size:.875rem;margin-bottom:.5rem;color:#374151">
              <?=Helpers::e($cr3['name'])?><?=$cr3['reg_required']?' <span style="color:#ef4444">*</span>':''?>
            </label>

            <?php if(empty($opts3)): ?>
            <input type="text" name="crit_<?=$cr3['id']?>"
              value="<?=Helpers::e($saved3)?>"
              placeholder="Votre <?=Helpers::e(strtolower($cr3['name']))?>"
              style="width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:.6rem .875rem;font-size:.875rem;font-family:inherit;outline:none;transition:border-color .15s"
              onfocus="this.style.borderColor='var(--color-primary)'"
              onblur="this.style.borderColor='#e2e8f0'">

            <?php else: ?>
            <div style="display:flex;flex-wrap:wrap;gap:.4rem">
              <?php foreach($opts3 as $o3):
                $isSelected3 = $saved3 === $o3['label'];
              ?>
              <label style="display:inline-flex;align-items:center;gap:.35rem;padding:.45rem .875rem;border-radius:99px;border:2px solid <?=Helpers::e($o3['color']??'#6366f1')?>;cursor:pointer;font-size:.875rem;font-weight:600;transition:all .15s;background:<?=$isSelected3?Helpers::e($o3['color']??'#6366f1'):'transparent'?>;color:<?=$isSelected3?'#fff':'inherit'?>">
                <input type="radio" name="crit_<?=$cr3['id']?>" value="<?=Helpers::e($o3['label'])?>"
                  <?=$isSelected3?'checked':''?>
                  style="accent-color:<?=Helpers::e($o3['color']??'var(--color-primary)')?>;margin:0;width:14px;height:14px">
                <?=Helpers::e($o3['label'])?>
              </label>
              <?php endforeach;?>
              <?php if($cr3['allow_other']):?>
              <label style="display:inline-flex;align-items:center;gap:.35rem;padding:.45rem .875rem;border-radius:99px;border:2px solid #e2e8f0;cursor:pointer;font-size:.875rem;background:<?=$isOther3?'#f1f5f9':'transparent'?>">
                <input type="radio" name="crit_<?=$cr3['id']?>" value="__other__"
                  <?=$isOther3?'checked':''?>
                  style="margin:0;width:14px;height:14px">
                <span>Autre :</span>
                <input type="text" name="crit_<?=$cr3['id']?>_other"
                  value="<?=$isOther3?Helpers::e($saved3):''?>"
                  placeholder="Précisez…"
                  style="border:none;border-bottom:1px solid #cbd5e1;outline:none;font-size:.875rem;width:100px;background:transparent">
              </label>
              <?php endif;?>
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach;?>
          <button type="submit" class="btn btn-primary" style="margin-top:.5rem">💾 Mettre à jour</button>
        </form>
      </div>
      <?php endif;?>

    <?php elseif ($action === 'licence'): ?>
      <!-- LICENCE -->
      <h2 class="page-heading">Ma licence</h2>

      <?php
      $statusInfo = match($user['license_status']) {
        'valid'    => ['color' => 'success', 'label' => '✅ Licence valide', 'desc' => 'Votre licence a été validée par le staff.'],
        'pending'  => ['color' => 'warning', 'label' => '⏳ En attente de validation', 'desc' => 'Votre licence a été soumise et est en cours d\'examen.'],
        'expired'  => ['color' => 'error',   'label' => '❌ Licence expirée', 'desc' => 'Votre licence a expiré. Veuillez en soumettre une nouvelle.'],
        'rejected' => ['color' => 'error',   'label' => '🚫 Licence refusée', 'desc' => 'Votre licence a été refusée. Vérifiez les informations et réessayez.'],
        default    => ['color' => 'info',    'label' => 'ℹ️ Aucune licence', 'desc' => 'Soumettez votre licence pour que le staff puisse la vérifier.'],
      };
      ?>
      <div class="alert alert-<?= $statusInfo['color'] === 'success' ? 'success' : ($statusInfo['color'] === 'error' ? 'error' : 'info') ?>">
        <strong><?= $statusInfo['label'] ?></strong><br><?= $statusInfo['desc'] ?>
      </div>

      <?php if ($user['license_status'] !== 'valid'): ?>
      <div class="card" style="margin-top:1.5rem">
        <h3 class="form-section-title">Soumettre / mettre à jour ma licence</h3>
        <form method="post" enctype="multipart/form-data">
          <?= Auth::csrfField() ?>
          <div class="form-row">
            <div class="form-group">
              <label>Numéro de licence *</label>
              <input type="text" name="license_number" value="<?= Helpers::e($user['license_number'] ?? '') ?>" placeholder="Ex: FF123456" required>
            </div>
            <div class="form-group">
              <label>Date d'expiration</label>
              <input type="date" name="license_expiry" value="<?= Helpers::e($user['license_expiry'] ?? '') ?>">
            </div>
            <div class="form-group" style="grid-column:span 2">
              <label>Document justificatif (photo ou PDF)</label>
              <div class="file-upload-zone" onclick="document.getElementById('lic-file').click()">
                <input type="file" id="lic-file" name="license_file" accept="image/*,.pdf" style="display:none" onchange="showFileName(this)">
                <div class="file-upload-icon">📎</div>
                <div class="file-upload-text" id="lic-filename">
                  <?= $user['license_file'] ? '📄 Fichier existant — cliquez pour remplacer' : 'Cliquez ou glissez votre fichier' ?>
                </div>
                <small style="color:var(--color-muted)">JPG, PNG, PDF — 10 Mo max</small>
              </div>
            </div>
          </div>
          <button type="submit" class="btn btn-primary">📤 Soumettre ma licence</button>
        </form>
      </div>
      <?php endif; ?>

    <?php elseif ($action === 'carte'): ?>
      <!-- CARTE MEMBRE -->
      <?php include CC_ROOT . '/modules/members/card.php'; ?>

    <?php elseif ($action === 'commandes'): ?>
      <!-- COMMANDES -->
      <h2 class="page-heading">Mes commandes</h2>
      <?php
      $orders = Database::all(
          "SELECT * FROM cc_shop_orders WHERE user_id = ? ORDER BY created_at DESC",
          [$user['id']]
      );
      ?>
      <?php if (empty($orders)): ?>
        <div class="empty-state">
          <div class="empty-icon">📦</div>
          <p>Aucune commande pour le moment.</p>
          <a href="<?=u('/boutique')?>" class="btn btn-primary">Voir la boutique</a>
        </div>
      <?php else: ?>
        <div class="orders-list">
          <?php foreach ($orders as $order):
            $statusLabel = match($order['status']) {
              'pending'   => ['label' => 'En attente', 'class' => 'warning'],
              'paid'      => ['label' => 'Payée', 'class' => 'success'],
              'shipped'   => ['label' => 'Expédiée', 'class' => 'primary'],
              'cancelled' => ['label' => 'Annulée', 'class' => 'error'],
              'refunded'  => ['label' => 'Remboursée', 'class' => 'muted'],
              default     => ['label' => $order['status'], 'class' => 'muted'],
            };
            $items = json_decode($order['items'], true) ?? [];
          ?>
          <div class="order-card card">
            <div class="order-header">
              <div>
                <strong>Commande #<?= $order['id'] ?></strong>
                <span class="order-date"><?= Helpers::dateFormat($order['created_at'], 'd/m/Y') ?></span>
              </div>
              <div class="order-status">
                <span class="badge badge-<?= $statusLabel['class'] ?>"><?= $statusLabel['label'] ?></span>
                <strong class="order-total"><?= Helpers::price($order['total']) ?></strong>
              </div>
            </div>
            <div class="order-items">
              <?php foreach (array_slice($items, 0, 3) as $item): ?>
                <span class="order-item-name"><?= Helpers::e($item['name'] ?? '') ?> ×<?= $item['qty'] ?? 1 ?></span>
              <?php endforeach; ?>
              <?php if (count($items) > 3): ?>
                <span style="color:var(--color-muted)">+<?= count($items)-3 ?> article(s)</span>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

    <?php elseif ($action === 'confidentialite'): ?>
<div class="member-section">
  <h2 style="font-size:1.15rem;font-weight:700;margin-bottom:1.25rem">🔒 Mes données personnelles (RGPD)</h2>

  <!-- Export -->
  <div style="border:1.5px solid #e2e8f0;border-radius:12px;overflow:hidden;margin-bottom:1.25rem">
    <div style="background:#f0fdf4;padding:.875rem 1.25rem;border-bottom:1px solid #e2e8f0">
      <div style="font-weight:700;color:#16a34a;font-size:.9rem">📦 Exporter mes données</div>
    </div>
    <div style="padding:1rem">
      <p style="font-size:.875rem;color:#64748b;margin-bottom:.875rem">Conformément au RGPD, vous pouvez télécharger toutes vos données (compte, commandes, réservations, forum) au format JSON.</p>
      <a href="<?=u('/membre/confidentialite')?>&amp;export_rgpd=1" class="btn btn-primary">⬇️ Télécharger mes données</a>
    </div>
  </div>

  <!-- Suppression -->
  <div style="border:1.5px solid #fecaca;border-radius:12px;overflow:hidden">
    <div style="background:#fff5f5;padding:.875rem 1.25rem;border-bottom:1px solid #fecaca">
      <div style="font-weight:700;color:#dc2626;font-size:.9rem">🗑 Demander la suppression de mon compte</div>
    </div>
    <div style="padding:1rem">
      <p style="font-size:.875rem;color:#64748b;margin-bottom:.875rem">Droit à l'oubli (RGPD art. 17) — Un administrateur traitera votre demande sous 30 jours maximum.</p>
      <?php if(Config::get('delete_request_'.Auth::id())): ?>
      <div style="background:#fef3c7;border:1.5px solid #fde68a;border-radius:8px;padding:.75rem;font-size:.875rem;color:#92400e;margin-bottom:.75rem">
        ⏳ Votre demande est en cours de traitement par l'administrateur (délai max 30 jours).
      </div>
      <form method="post">
        <?=Auth::csrfField()?>
        <button type="submit" name="cancel_delete" class="btn" style="background:#e2e8f0;color:#475569;font-size:.82rem"
          onclick="return confirm('Annuler votre demande de suppression ?')">
          ↩️ Annuler ma demande
        </button>
      </form>
      <?php else: ?>
      <form method="post">
        <?=Auth::csrfField()?>
        <div style="margin-bottom:.75rem">
          <label style="font-size:.82rem;font-weight:600;color:#64748b;display:block;margin-bottom:.3rem">Motif (optionnel)</label>
          <textarea name="delete_reason" rows="2" placeholder="Pourquoi souhaitez-vous supprimer votre compte ?" style="width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:.5rem .75rem;font-family:inherit;font-size:.875rem;resize:vertical"></textarea>
        </div>
        <button type="submit" name="request_delete" class="btn" style="background:#dc2626;color:#fff"
          onclick="return confirm('Confirmer la demande de suppression de votre compte ?')">
          🗑 Demander la suppression
        </button>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>

    <?php endif; ?>
  </div><!-- .member-content -->
</div><!-- .member-wrap -->

<script>
function previewAvatar(input) {
  if (!input.files[0]) return;
  const reader = new FileReader();
  reader.onload = e => {
    const preview = document.getElementById('avatar-preview');
    preview.innerHTML = `<img src="${e.target.result}" alt="" id="avatar-img" style="width:100%;height:100%;object-fit:cover;border-radius:50%">`;
  };
  reader.readAsDataURL(input.files[0]);
}
function showFileName(input) {
  if (input.files[0]) {
    document.getElementById('lic-filename').textContent = '📄 ' + input.files[0].name;
  }
}
</script>

<style>
.member-wrap{display:grid;grid-template-columns:280px 1fr;gap:2rem;padding:2rem 0;align-items:start}
.member-sidebar{background:#fff;border:1px solid var(--color-border);border-radius:var(--radius-md);padding:1.5rem;position:sticky;top:80px}
.member-avatar-block{text-align:center;padding-bottom:1.25rem;border-bottom:1px solid var(--color-border);margin-bottom:1.25rem}
.member-avatar-img{width:80px;height:80px;border-radius:50%;object-fit:cover;margin:0 auto .75rem;border:3px solid var(--color-primary)}
.member-avatar-placeholder{width:80px;height:80px;border-radius:50%;background:var(--color-primary);color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.6rem;font-weight:700;margin:0 auto .75rem}
.member-name{font-weight:700;margin-bottom:.25rem}
.profile-completion{margin-bottom:1rem}
.completion-label{display:flex;justify-content:space-between;font-size:.8rem;color:var(--color-muted);margin-bottom:.35rem}
.completion-bar{height:5px;background:var(--color-border);border-radius:99px;overflow:hidden}
.completion-fill{height:100%;background:linear-gradient(90deg,var(--color-primary),var(--color-secondary));border-radius:99px;transition:width .5s ease}
.member-nav{display:flex;flex-direction:column;gap:.15rem}
.member-nav-item{display:flex;align-items:center;gap:.6rem;padding:.55rem .75rem;border-radius:var(--radius-sm);font-size:.9rem;color:var(--color-muted);transition:all .2s;text-decoration:none}
.member-nav-item:hover,.member-nav-item.active{background:color-mix(in srgb,var(--color-primary) 8%,transparent);color:var(--color-primary);text-decoration:none}
.member-nav-item.active{font-weight:600}
.mnav-icon{font-size:1rem;width:1.2rem;text-align:center}
.mnav-badge{margin-left:auto;font-size:.65rem;padding:.1rem .35rem;border-radius:4px;font-weight:700}
.mnav-badge.green{background:#dcfce7;color:#166534}
.mnav-badge.yellow{background:#fef3c7;color:#92400e}
.mnav-badge.red{background:#fee2e2;color:#991b1b}
.member-content{min-width:0}
.page-heading{font-family:var(--font-heading);font-size:2rem;letter-spacing:.05em;margin-bottom:1.5rem}
.dashboard-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem}
.stat-card{background:#fff;border:1px solid var(--color-border);border-radius:var(--radius-md);padding:1.25rem;text-align:center}
.stat-icon{font-size:1.75rem;margin-bottom:.4rem}
.stat-value{font-size:1.8rem;font-weight:700;font-family:var(--font-heading);letter-spacing:.05em}
.stat-label{font-size:.75rem;color:var(--color-muted);text-transform:uppercase;letter-spacing:.05em}
.dashboard-section{margin-top:2rem}
.section-heading{font-family:var(--font-heading);font-size:1.3rem;letter-spacing:.05em;margin-bottom:1rem}
.reservation-list{display:flex;flex-direction:column;gap:.5rem}
.reservation-item{display:flex;align-items:center;gap:1rem;background:#fff;border:1px solid var(--color-border);border-radius:var(--radius-sm);padding:.75rem 1rem}
.res-date{font-size:.8rem;color:var(--color-muted);min-width:150px}
.res-title{flex:1;font-weight:500}
.form-section{margin-bottom:2rem;padding-bottom:2rem;border-bottom:1px solid var(--color-border)}
.form-section:last-of-type{border-bottom:none}
.form-section-title{font-size:1rem;font-weight:700;margin-bottom:1rem;color:var(--color-text)}
.avatar-upload-wrap{display:flex;align-items:center;gap:1.5rem;margin-bottom:1rem}
.avatar-preview{width:80px;height:80px;border-radius:50%;overflow:hidden;background:var(--color-primary);display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.5rem;font-weight:700;flex-shrink:0;border:2px solid var(--color-border)}
.btn-upload{display:inline-block;padding:.5rem 1rem;border:1.5px solid var(--color-border);border-radius:var(--radius-sm);cursor:pointer;font-size:.875rem;font-weight:600;transition:all .2s}
.btn-upload:hover{border-color:var(--color-primary);color:var(--color-primary)}
.file-upload-zone{border:2px dashed var(--color-border);border-radius:var(--radius-sm);padding:1.5rem;text-align:center;cursor:pointer;transition:border-color .2s}
.file-upload-zone:hover{border-color:var(--color-primary)}
.file-upload-icon{font-size:1.8rem;margin-bottom:.3rem}
.file-upload-text{color:var(--color-muted);font-size:.875rem;margin-bottom:.2rem}
.btn{display:inline-flex;align-items:center;gap:.4rem;padding:.65rem 1.5rem;border-radius:var(--radius-sm);font-weight:600;font-size:.9rem;cursor:pointer;border:none;font-family:var(--font-body);transition:all .2s;text-decoration:none}
.btn-primary{background:var(--color-primary);color:#fff}
.btn-primary:hover{opacity:.9;text-decoration:none}
.form-actions{margin-top:1.5rem;padding-top:1.5rem;border-top:1px solid var(--color-border)}
.orders-list{display:flex;flex-direction:column;gap:1rem}
.order-card{padding:1.25rem}
.order-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem}
.order-date{color:var(--color-muted);font-size:.8rem;margin-left:.75rem}
.order-status{display:flex;align-items:center;gap:.75rem}
.order-total{font-size:1.1rem}
.order-items{display:flex;flex-wrap:wrap;gap:.5rem}
.order-item-name{background:var(--color-surface);border-radius:4px;padding:.15rem .5rem;font-size:.8rem}
.empty-state{text-align:center;padding:4rem 2rem;color:var(--color-muted)}
.empty-icon{font-size:3rem;margin-bottom:1rem}
.empty-state p{margin-bottom:1.5rem}
@media(max-width:900px){.member-wrap{grid-template-columns:1fr}.member-sidebar{position:static}.dashboard-stats{grid-template-columns:repeat(2,1fr)}}
@media(max-width:480px){.dashboard-stats{grid-template-columns:1fr 1fr}}
</style>

<?php
$content = ob_get_clean();
include CC_ROOT . '/templates/layout.php';
