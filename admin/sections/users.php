<?php
// ── POST : Sauvegarde profil complet ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Auth::verifyCsrf()) {
    adminFlash('error','CSRF invalide'); Helpers::redirect(u('/admin/users'));
}

// ── Supprimer utilisateur ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
    $uid = (int)$_POST['user_id'];
    $target = Database::one("SELECT id,role FROM cc_users WHERE id=?", [$uid]);
    if (!$target) {
        adminFlash('error', 'Utilisateur introuvable.');
    } elseif ($uid === Auth::id()) {
        adminFlash('error', 'Vous ne pouvez pas supprimer votre propre compte.');
    } elseif ($target['role'] === 'superadmin') {
        adminFlash('error', 'Impossible de supprimer un super-administrateur.');
    } else {
        foreach (['cc_planning_bookings','cc_planning_criteria_values',
                  'cc_benv_participations','cc_benv_task_volunteers',
                  'cc_benv_chat','cc_benv_alerts_seen',
                  'cc_benv_profiles','cc_benv_coach_access'] as $tbl) {
            try { Database::run("DELETE FROM $tbl WHERE user_id=?", [$uid]); } catch(Exception $e) {}
        }
        try {
            Database::run("DELETE FROM cc_users WHERE id=?", [$uid]);
            adminFlash('success', 'Utilisateur supprimé avec succès.');
        } catch(Exception $e) {
            adminFlash('error', 'Erreur suppression : '.$e->getMessage());
        }
    }
    Helpers::redirect(u('/admin/users'));
}
// ── POST : Sauvegarder critères depuis admin ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_user_criteria'])) {
    $uid = (int)($_POST['user_id'] ?? 0);
    if ($uid) {
        $regCritSettings = json_decode(Config::get('registration_criteria','{}'),true)??[];
        foreach ($regCritSettings as $cid => $s) {
            $val = trim($_POST['crit_'.$cid] ?? '');
            if ($val === '__other__') $val = trim($_POST['crit_'.$cid.'_other'] ?? '');
            $val2 = trim($_POST['crit_'.$cid.'_2'] ?? '');
            if ($val !== '') {
                try {
                    Database::run(
                        "INSERT INTO cc_planning_criteria_values (user_id,criteria_id,value,value2) VALUES (?,?,?,?)
                         ON DUPLICATE KEY UPDATE value=VALUES(value),value2=VALUES(value2)",
                        [$uid, (int)$cid, $val, $val2]
                    );
                } catch(Exception $e) {}
            } else {
                try { Database::run("DELETE FROM cc_planning_criteria_values WHERE user_id=? AND criteria_id=?", [$uid, (int)$cid]); } catch(Exception $e) {}
            }
        }
        adminFlash('success','Informations mises à jour.');
    }
    Helpers::redirect(u('/admin/users/edit/'.$uid));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($subact === 'save' || isset($_POST['save_user']))) {
    $uid    = (int)($_POST['user_id'] ?? 0);
    $fields = [
        'firstname'     => Helpers::sanitize($_POST['firstname'] ?? ''),
        'lastname'      => Helpers::sanitize($_POST['lastname'] ?? ''),
        'email'         => strtolower(trim($_POST['email'] ?? '')),
        'phone'         => Helpers::sanitize($_POST['phone'] ?? ''),
        'birthdate'     => $_POST['birthdate'] ?: null,
        'address'       => Helpers::sanitize($_POST['address'] ?? ''),
        'city'          => Helpers::sanitize($_POST['city'] ?? ''),
        'zip'           => Helpers::sanitize($_POST['zip'] ?? ''),
        'role'          => in_array($_POST['role'] ?? '', Auth::ROLES) ? $_POST['role'] : 'member',
        'status'        => in_array($_POST['status'],['pending','active','suspended','banned']) ? $_POST['status'] : 'active',
        'license_status'=> in_array($_POST['license_status'],['none','pending','valid','expired','rejected']) ? $_POST['license_status'] : 'none',
        'license_number'=> Helpers::sanitize($_POST['license_number'] ?? ''),
        'license_expiry'=> $_POST['license_expiry'] ?: null,
    ];
    // Changement mot de passe optionnel
    if (!empty($_POST['new_password']) && strlen($_POST['new_password']) >= 8) {
        $fields['password'] = password_hash($_POST['new_password'], PASSWORD_BCRYPT, ['cost'=>12]);
    }
    $oldUser = Database::one("SELECT role FROM cc_users WHERE id=?", [$uid]);
    $sets = implode(', ', array_map(fn($k) => "`$k`=?", array_keys($fields)));
    Database::run("UPDATE cc_users SET $sets, updated_at=NOW() WHERE id=?", [...array_values($fields), $uid]);
    // Si rôle changé : invalider toutes les sessions de cet utilisateur
    // (simple : on ne peut pas invalider les autres sessions en PHP natif,
    //  mais on met à jour la BDD et le user devra se reconnecter)
    adminFlash('success', "Profil sauvegardé.");
    Helpers::redirect(u('/admin/users/edit/'.$uid));
}

// ── POST : Changement rapide role/status via AJAX ─────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && Helpers::isAjax()) {
    $uid   = (int)($_POST['user_id'] ?? 0);
    $field = in_array($_POST['field'],['role','status']) ? $_POST['field'] : null;
    $val   = Helpers::sanitize($_POST['value'] ?? '');
    if ($uid && $field) {
        Database::run("UPDATE cc_users SET `$field`=? WHERE id=?", [$val, $uid]);
        Helpers::json(['success'=>true]);
    }
    Helpers::json(['error'=>'Paramètres invalides'],400);
}

// ── Vue : édition d'un membre ─────────────────────────────────
if ($subact === 'edit' && $itemId) {
    $u = Database::one("SELECT * FROM cc_users WHERE id=?", [$itemId]);
    if (!$u) { adminFlash('error','Membre introuvable'); Helpers::redirect(u('/admin/users')); }
    // Charger les critères du membre
    $adminUserCriteria = [];
    $adminUserCritVals = [];
    $regCritSettings3  = json_decode(Config::get('registration_criteria','{}'),true)??[];
    foreach ($regCritSettings3 as $cid3 => $s3) {
        if (!($s3['display']??0)) continue;
        try {
            $cr3 = Database::one("SELECT * FROM cc_planning_criteria WHERE id=? AND active=1", [(int)$cid3]);
            if ($cr3) $adminUserCriteria[] = $cr3;
        } catch(Exception $e) {}
    }
    if (!empty($adminUserCriteria)) {
        try {
            $rows3 = Database::all("SELECT criteria_id, value, value2 FROM cc_planning_criteria_values WHERE user_id=?", [$itemId]);
            foreach ($rows3 as $r3) {
                $adminUserCritVals[$r3['criteria_id']]  = $r3['value'];
                $adminUserCritVals2[$r3['criteria_id']] = $r3['value2'] ?? '';
            }
        } catch(Exception $e) {}
    }

    $pageTitle = 'Modifier — ' . $u['firstname'] . ' ' . $u['lastname'];
    ob_start();
    ?>
    <div class="page-head">
      <h1>👤 <?=Helpers::e($u['firstname'].' '.$u['lastname'])?></h1>
      <a href="<?=u('/admin/users')?>" class="btn btn-ghost">← Retour</a>
    </div>
    <form method="post" action="<?=u('/admin/users/save')?>">
      <?=Auth::csrfField()?>
      <input type="hidden" name="user_id" value="<?=$u['id']?>">
      <input type="hidden" name="save_user" value="1">

      <div class="ac">
        <div class="ac-header"><h2>Informations personnelles</h2></div>
        <div class="ac-body">
          <div class="form-row">
            <div class="fg"><label>Prénom</label><input type="text" name="firstname" value="<?=Helpers::e($u['firstname']??'')?>"></div>
            <div class="fg"><label>Nom</label><input type="text" name="lastname" value="<?=Helpers::e($u['lastname']??'')?>"></div>
            <div class="fg"><label>Email</label><input type="email" name="email" value="<?=Helpers::e($u['email'])?>"></div>
            <div class="fg"><label>Téléphone</label><input type="tel" name="phone" value="<?=Helpers::e($u['phone']??'')?>"></div>
            <div class="fg"><label>Date de naissance</label><input type="date" name="birthdate" value="<?=Helpers::e($u['birthdate']??'')?>"></div>
            <div class="fg"><label>Adresse</label><input type="text" name="address" value="<?=Helpers::e($u['address']??'')?>"></div>
            <div class="fg"><label>Ville</label><input type="text" name="city" value="<?=Helpers::e($u['city']??'')?>"></div>
            <div class="fg"><label>Code postal</label><input type="text" name="zip" value="<?=Helpers::e($u['zip']??'')?>"></div>
          </div>
        </div>
      </div>

      <div class="ac">
        <div class="ac-header"><h2>Rôle & Statut</h2></div>
        <div class="ac-body">
          <div class="form-row">
            <div class="fg"><label>Rôle</label>
              <select name="role" class="be-select">
                <?php foreach(Auth::ROLES as $r): ?>
                <option value="<?=Helpers::e($r)?>" <?=$u['role']===$r?'selected':''?>>
                  <?=Helpers::e(Auth::ROLE_LABELS[$r] ?? ucfirst($r))?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="fg"><label>Statut du compte</label>
              <select name="status">
                <option value="pending"   <?=$u['status']==='pending'?  'selected':''?>>En attente</option>
                <option value="active"    <?=$u['status']==='active'?   'selected':''?>>Actif</option>
                <option value="suspended" <?=$u['status']==='suspended'?'selected':''?>>Suspendu</option>
                <option value="banned"    <?=$u['status']==='banned'?   'selected':''?>>Banni</option>
              </select>
            </div>
          </div>
        </div>
      </div>

      <div class="ac">
        <div class="ac-header"><h2>Licence</h2></div>
        <div class="ac-body">
          <div class="form-row">
            <div class="fg"><label>Statut licence</label>
              <select name="license_status">
                <option value="none"     <?=$u['license_status']==='none'?    'selected':''?>>Aucune</option>
                <option value="pending"  <?=$u['license_status']==='pending'? 'selected':''?>>En attente</option>
                <option value="valid"    <?=$u['license_status']==='valid'?   'selected':''?>>Valide</option>
                <option value="expired"  <?=$u['license_status']==='expired'? 'selected':''?>>Expirée</option>
                <option value="rejected" <?=$u['license_status']==='rejected'?'selected':''?>>Refusée</option>
              </select>
            </div>
            <div class="fg"><label>Numéro de licence</label><input type="text" name="license_number" value="<?=Helpers::e($u['license_number']??'')?>"></div>

            <div class="fg"><label>Date d'expiration</label><input type="date" name="license_expiry" value="<?=Helpers::e($u['license_expiry']??'')?>"></div>
          </div>
          <?php if($u['license_file']): ?>
            <a href="/<?=Helpers::e($u['license_file'])?>" target="_blank" class="btn btn-ghost btn-sm">📎 Voir le document</a>
          <?php endif; ?>
        </div>
      </div>

      <div class="ac">
        <div class="ac-header"><h2>Changer le mot de passe</h2></div>
        <div class="ac-body">
          <div class="fg" style="max-width:320px"><label>Nouveau mot de passe (laisser vide = inchangé)</label><input type="password" name="new_password" placeholder="8 caractères minimum"></div>
        </div>
      </div>

      <div style="display:flex;gap:1rem;justify-content:flex-end;margin-top:1rem">
        <a href="<?=u('/admin/users')?>" class="btn btn-ghost">Annuler</a>
        <button type="submit" class="btn btn-primary">💾 Sauvegarder</button>
      </div>
    </form>

    <?php if(!empty($adminUserCriteria)): ?>
    <?php if(!class_exists('CriteriaRenderer'))require_once CC_ROOT.'/core/CriteriaRenderer.php'; ?>
    <form method="post" action="<?=u('/admin/users/edit/'.$u['id'])?>">
      <?=Auth::csrfField()?>
      <input type="hidden" name="user_id" value="<?=$u['id']?>">
      <input type="hidden" name="save_user_criteria" value="1">
      <div class="ac" style="margin-top:1rem">
        <div class="ac-header"><h2>🏷 Informations membre</h2></div>
        <div class="ac-body">
          <div class="form-row" style="grid-template-columns:repeat(auto-fill,minmax(260px,1fr))">
          <?php foreach($adminUserCriteria as $cr4):
            $saved4  = $adminUserCritVals[$cr4['id']]  ?? '';
            $saved4b = $adminUserCritVals2[$cr4['id']] ?? '';
            $cr4['is_required_here'] = 0;
          ?>
          <div class="fg">
            <label><?=Helpers::e($cr4['name'])?></label>
            <?=CriteriaRenderer::field($cr4, $saved4, $saved4b)?>
          </div>
          <?php endforeach;?>
          </div>
          <div style="margin-top:.875rem">
            <button type="submit" class="btn btn-primary btn-sm">💾 Enregistrer les informations</button>
          </div>
        </div>
      </div>
    </form>
    <?php endif;?>
    <?php
    $content = ob_get_clean();
    include CC_ROOT . '/admin/layout.php';
    return;
}

// ── Vue : liste des membres ────────────────────────────────────
$pageTitle = 'Gestion des membres';
$search    = Helpers::sanitize($_GET['q'] ?? '');
$where     = $search ? "WHERE (firstname LIKE ? OR lastname LIKE ? OR email LIKE ?)" : "";
$params    = $search ? ["%$search%","%$search%","%$search%"] : [];
$total     = (int)Database::scalar("SELECT COUNT(*) FROM cc_users $where", $params);
$pager     = Helpers::paginate($total, 25);
$users     = Database::all("SELECT * FROM cc_users $where ORDER BY created_at DESC LIMIT ? OFFSET ?",
    [...$params, $pager['perPage'], $pager['offset']]);

ob_start();
?>
<div class="page-head">
  <h1>👥 Membres (<?=$total?>)</h1>

  <form method="get" style="display:flex;gap:.5rem">
    <input type="search" name="q" value="<?=Helpers::e($search)?>" placeholder="Rechercher…" style="padding:.4rem .75rem;border:1.5px solid #e2e8f0;border-radius:8px;font-size:.85rem;width:200px">
    <button type="submit" class="btn btn-ghost btn-sm">🔍</button>
  </form>
</div>
<div class="ac">
  <table class="at">
    <thead><tr><th>Membre</th><th>Email</th><th>Rôle</th><th>Statut</th><th>Licence</th><th>Inscrit le</th><th>Actions</th></tr></thead>
    <tbody>
      <?php foreach($users as $u): ?>
      <tr>
        <td>
          <div style="display:flex;align-items:center;gap:.5rem">
            <?php if($u['avatar']): ?><img src="<?=asset(Helpers::e($u['avatar']))?>" style="width:28px;height:28px;border-radius:50%;object-fit:cover;flex-shrink:0"><?php else: ?>
            <div style="width:28px;height:28px;border-radius:50%;background:var(--color-primary);color:#fff;display:flex;align-items:center;justify-content:center;font-size:.65rem;font-weight:700;flex-shrink:0"><?=mb_strtoupper(mb_substr($u['firstname']??'M',0,1).mb_substr($u['lastname']??'',0,1))?></div>
            <?php endif; ?>
            <strong><?=Helpers::e($u['firstname'].' '.$u['lastname'])?></strong>
          </div>
        </td>
        <td style="font-size:.8rem"><?=Helpers::e($u['email'])?></td>
        <td><span class="role-badge role-<?=Helpers::e($u['role'])?>"><?=Auth::ROLE_LABELS[$u['role']] ?? Helpers::e(ucfirst($u['role']))?></span></td>
        <td><span class="badge badge-<?=$u['status']==='active'?'success':($u['status']==='pending'?'warning':'error')?>"><?=$u['status']?></span></td>
        <td><span class="badge badge-<?=match($u['license_status']??'none'){'valid'=>'success','pending'=>'warning','expired','rejected'=>'error',default=>'muted'}?>"><?=match($u['license_status']??'none'){'valid'=>'✓','pending'=>'⏳','expired'=>'Exp.','rejected'=>'✗',default=>'—'}?></span></td>
        <td style="font-size:.78rem"><?=Helpers::dateFormat($u['created_at'])?></td>
        <td style="display:flex;gap:.35rem;flex-wrap:wrap;align-items:center">
          <a href="<?=u('/admin/users/edit/'.$u['id'])?>" class="btn btn-ghost btn-sm">✏️ Modifier</a>
          <?php if(Auth::isSuperAdmin() && $u['id']!==Auth::id() && $u['role']!=='superadmin'): ?>
          <form method="post" onsubmit="return confirm('Supprimer cet utilisateur ? Irréversible.')" style="margin:0">
            <?=Auth::csrfField()?>
            <input type="hidden" name="user_id" value="<?=$u['id']?>">
            <button type="submit" name="delete_user" class="btn btn-sm" style="background:#fee2e2;color:#dc2626;border:1.5px solid #fecaca">🗑 Supprimer</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php if($pager['pages']>1): ?>
  <div style="padding:1rem;display:flex;gap:.35rem;justify-content:center">
    <?php for($i=1;$i<=$pager['pages'];$i++): ?>
      <a href="?page=<?=$i?>&q=<?=urlencode($search)?>" class="page-btn <?=$i===$pager['page']?'active':''?>"><?=$i?></a>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
include CC_ROOT . '/admin/layout.php';
