<?php
// ═══ Ce fichier crée les sections manquantes ═══
// modules_admin, config_admin, licences, planning_admin, gallery_admin

// Détection de la section appelée
$currentFile = basename(__FILE__, '.php');

// ── MODULES ───────────────────────────────────────────────────
if ($section === 'modules') {
    Auth::require('superadmin');
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && Auth::verifyCsrf()) {
        $slug  = Helpers::sanitize($_POST['slug'] ?? '');
        $field = in_array($_POST['field']??'',['enabled','require_login']) ? $_POST['field'] : null;
        $val   = (int)($_POST['value'] ?? 0);
        if ($slug && $field) Database::run("UPDATE cc_modules SET `$field`=? WHERE slug=?",[$val,$slug]);
        if (Helpers::isAjax()) Helpers::json(['success'=>true]);
        adminFlash('success','Module mis à jour.'); Helpers::redirect(u('/admin/modules'));
    }
    $modules = Database::all("SELECT * FROM cc_modules");
    $pageTitle = 'Modules';
    ob_start();
    ?>
    <div class="page-head"><h1>🔌 Modules</h1></div>
    <div class="ac">
      <div class="ac-body">
        <p style="color:#64748b;font-size:.875rem;margin-bottom:1.25rem">Activez ou désactivez les modules et définissez si une connexion est requise pour y accéder.</p>
        <div class="at-wrap"><table class="at">
          <thead><tr><th>Module</th><th>Activé</th><th>Connexion requise</th></tr></thead>
          <tbody>
            <?php foreach($modules as $m): ?>
            <tr>
              <td><strong><?=Helpers::e($m['label'])?></strong><br><small style="color:#94a3b8">/<?=$m['slug']?></small></td>
              <td><label class="toggle"><input type="checkbox" <?=$m['enabled']?'checked':''?> onchange="toggleModule('<?=$m['slug']?>','enabled',this.checked?1:0)"><span class="toggle-track"></span></label></td>
              <td><label class="toggle"><input type="checkbox" <?=$m['require_login']?'checked':''?> onchange="toggleModule('<?=$m['slug']?>','require_login',this.checked?1:0)"><span class="toggle-track"></span></label></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>
      </div>
    </div>
    <script>
    async function toggleModule(slug,field,value){
      const r=await apiPost('/admin/modules',{slug,field,value});
      if(r.success)Toast.show('Mis à jour','success');
    }
    </script>
    <?php
    $content=ob_get_clean(); include CC_ROOT.'/admin/layout.php'; return;
}

// ── CONFIG ────────────────────────────────────────────────────
if ($section === 'config') {
    Auth::require('superadmin');

    // ── Handler : accès inscription/connexion ────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && Auth::verifyCsrf() && isset($_POST['save_access_settings'])) {
        Config::set('allow_register', isset($_POST['allow_register']) ? '1' : '0', 'auth');
        Config::set('allow_login',    isset($_POST['allow_login'])    ? '1' : '0', 'auth');
        adminFlash('success', "Paramètres d'accès sauvegardés.");
        Helpers::redirect(u('/admin/config?tab=registration'));
    }

    // ── Handler : critères d'inscription ───────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && Auth::verifyCsrf() && isset($_POST['save_registration_criteria'])) {
        $displays = array_map('intval', $_POST['crit_display']  ?? []); // IDs cochés
        $requires = array_map('intval', $_POST['crit_required'] ?? []); // IDs cochés
        $result   = [];
        try {
            $allC = Database::all("SELECT id FROM cc_planning_criteria WHERE active=1");
            foreach ($allC as $c) {
                $cid = (string)$c['id'];
                $result[$cid] = [
                    'display'  => in_array((int)$cid, $displays) ? 1 : 0,
                    'required' => in_array((int)$cid, $requires) ? 1 : 0,
                ];
            }
        } catch(Exception $e) {}
        Config::set('registration_criteria', json_encode($result), 'auth');
        adminFlash('success', "Paramètres d'inscription sauvegardés.");
        Helpers::redirect(u('/admin/config?tab=registration'));
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && Auth::verifyCsrf() && isset($_POST['save_config'])) {
        $group = Helpers::sanitize($_POST['group'] ?? 'general');
        $skip  = ['csrf_token','save_config','group'];
        // Checkboxes qui doivent pouvoir être décochées (valeur '0' si absentes du POST)
        $checkboxes = ['maintenance_mode','translation_enabled','cookie_banner_enabled',
            'notif_new_member','notif_new_order','notif_new_topic','notif_new_booking'];
        // Champs texte cookies
        foreach(['cookie_text','cookie_link_label','cookie_link_url'] as $ck) {
            if(isset($_POST[$ck])) Config::set($ck, Helpers::sanitize($_POST[$ck]), $group);
        }
        foreach ($checkboxes as $cb) {
            Config::set($cb, isset($_POST[$cb]) ? '1' : '0', $group);
        }
        // Champs sociaux
        foreach(['social_facebook','social_instagram','social_twitter','social_youtube','weather_city'] as $sk) {
            if(array_key_exists($sk, $_POST)) Config::set($sk, Helpers::sanitize($_POST[$sk] ?? ''), $group);
        }
        foreach($_POST as $k => $v) {
            if (in_array($k,$skip) || str_starts_with($k,'_')) continue;
            Config::set(Helpers::sanitize($k), $v, $group);
        }
        foreach(['logo','hero_image'] as $img) {
            if (!empty($_FILES[$img]['tmp_name'])) {
                $dir = $img==='logo' ? CC_ROOT.'/assets/uploads/logos' : CC_ROOT.'/assets/uploads/heroes';
                $up  = Helpers::uploadImage($_FILES[$img], $dir);
                if ($up['success']) Config::set($img, 'assets/uploads/'.($img==='logo'?'logos':'heroes').'/'.$up['filename'], 'general');
            }
        }
        adminFlash('success','Configuration sauvegardée.'); Helpers::redirect(u('/admin/config?tab='.($_POST['group']??'general')));
    }
    $tab = $_GET['tab'] ?? 'general';
    $pageTitle = 'Paramètres';
    ob_start();
    ?>
    <div class="page-head"><h1>⚙️ Paramètres</h1></div>
    <div style="display:flex;gap:.35rem;margin-bottom:1.25rem;flex-wrap:wrap">
      <?php foreach(['general'=>'Général','appearance'=>'Apparence','email'=>'Emails','payments'=>'Paiements','registration'=>'📋 Inscription'] as $k=>$l): ?>
        <a href="<?=u('/admin/config?tab='.$k)?>" class="btn <?=$tab===$k?'btn-primary':'btn-ghost'?>"><?=$l?></a>
      <?php endforeach; ?>
    </div>

    <?php if($tab==='general'): ?>
    <form method="post" class="ac"><div class="ac-body"><?=Auth::csrfField()?><input type="hidden" name="group" value="general">
      <div class="form-row">
        <div class="fg"><label>Nom du club</label><input type="text" name="club_name" value="<?=Helpers::e(Config::get('club_name'))?>"></div>
        <div class="fg"><label>Sport</label><input type="text" name="club_sport" value="<?=Helpers::e(Config::get('club_sport'))?>"></div>
        <div class="fg"><label>Email</label><input type="email" name="club_email" value="<?=Helpers::e(Config::get('club_email'))?>"></div>
        <div class="fg"><label>Téléphone</label><input type="tel" name="club_phone" value="<?=Helpers::e(Config::get('club_phone'))?>"></div>
        <div class="fg span2"><label>Adresse</label><input type="text" name="club_address" value="<?=Helpers::e(Config::get('club_address'))?>"></div>
        <div class="fg"><label>Ville</label><input type="text" name="club_city" value="<?=Helpers::e(Config::get('club_city'))?>"></div>
      </div>
      <div class="fg"><label style="display:flex;align-items:center;gap:.5rem;text-transform:none"><input type="checkbox" name="maintenance_mode" value="1" <?=Config::get('maintenance_mode')?'checked':''?>> Mode maintenance (seuls les super admins accèdent au site)</label></div>
      <div class="fg">
        <label>🌤 Ville pour le widget météo (accueil)</label>
        <input type="text" name="weather_city" class="input-std" value="<?=Helpers::e(Config::get('weather_city',''))?>" placeholder="Ex: Paris, Lyon, Marseille…">
        <small style="color:#64748b;font-size:.78rem">Laissez vide pour ne pas afficher. Utilise wttr.in (gratuit, sans clé API, RGPD friendly).</small>
      </div>
      <div style="border-top:1px solid #f1f5f9;margin:.75rem 0;padding-top:.75rem">
        <div style="font-weight:600;font-size:.82rem;color:#475569;margin-bottom:.625rem">📱 Réseaux sociaux (footer)</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
          <div class="fg"><label>Facebook</label><input type="url" name="social_facebook" class="input-std" value="<?=Helpers::e(Config::get('social_facebook',''))?>" placeholder="https://facebook.com/..."></div>
          <div class="fg"><label>Instagram</label><input type="url" name="social_instagram" class="input-std" value="<?=Helpers::e(Config::get('social_instagram',''))?>" placeholder="https://instagram.com/..."></div>
          <div class="fg"><label>X / Twitter</label><input type="url" name="social_twitter" class="input-std" value="<?=Helpers::e(Config::get('social_twitter',''))?>" placeholder="https://twitter.com/..."></div>
          <div class="fg"><label>YouTube</label><input type="url" name="social_youtube" class="input-std" value="<?=Helpers::e(Config::get('social_youtube',''))?>" placeholder="https://youtube.com/..."></div>
        </div>
      </div>
      <div class="fg">
        <label>Mention pied de page</label>
        <input type="url" name="footer_mention" class="input-std" value="<?=Helpers::e(Config::get('footer_mention',''))?>" placeholder="© 2025 Mon Club — Tous droits réservés">
        <small style="color:#64748b;font-size:.78rem">Laissez vide pour le texte automatique. Exemples : "© 2025 MonClub — Site propulsé par Valentin" ou "Tous droits réservés — MonClub"</small>
      </div>
      <div class="fg"><label style="display:flex;align-items:center;gap:.5rem;text-transform:none"><input type="checkbox" name="translation_enabled" value="1" <?=Config::get('translation_enabled')?'checked':''?>> Activer le bouton de traduction sur le site (Google Translate, gratuit)</label></div>
      <!-- Notifications admin -->
      <div style="border-top:1px solid #f1f5f9;margin:.75rem 0;padding-top:.75rem">
        <div style="font-weight:600;font-size:.82rem;color:#475569;margin-bottom:.625rem">🔔 Notifications email admin</div>
        <div style="display:flex;flex-direction:column;gap:.4rem">
          <label style="display:flex;align-items:center;gap:.5rem;font-size:.85rem;cursor:pointer"><input type="checkbox" name="notif_new_member" value="1" <?=Config::get('notif_new_member')?'checked':''?>> Nouvelle inscription membre</label>
          <label style="display:flex;align-items:center;gap:.5rem;font-size:.85rem;cursor:pointer"><input type="checkbox" name="notif_new_order" value="1" <?=Config::get('notif_new_order')?'checked':''?>> Nouvelle commande boutique</label>
          <label style="display:flex;align-items:center;gap:.5rem;font-size:.85rem;cursor:pointer"><input type="checkbox" name="notif_new_topic" value="1" <?=Config::get('notif_new_topic')?'checked':''?>> Nouveau sujet forum</label>
          <label style="display:flex;align-items:center;gap:.5rem;font-size:.85rem;cursor:pointer"><input type="checkbox" name="notif_new_booking" value="1" <?=Config::get('notif_new_booking')?'checked':''?>> Nouvelle inscription planning</label>
        </div>
      </div>
      <!-- Cookies -->
      <div style="border-top:1px solid #f1f5f9;margin:.75rem 0;padding-top:.75rem">
        <div style="font-weight:600;font-size:.82rem;color:#475569;margin-bottom:.625rem">🍪 Bandeau cookies</div>
        <div class="fg" style="margin-bottom:.5rem">
          <label style="display:flex;align-items:center;gap:.5rem;text-transform:none">
            <input type="checkbox" name="cookie_banner_enabled" value="1" <?=Config::get('cookie_banner_enabled','1')?'checked':''?>> Afficher le bandeau de consentement aux cookies
          </label>
        </div>
        <div class="fg" style="margin-bottom:.5rem">
          <label>Texte du bandeau</label>
          <textarea name="cookie_text" class="input-std" rows="2" placeholder="Ce site utilise des cookies..."><?=Helpers::e(Config::get('cookie_text','Ce site utilise des cookies pour améliorer votre expérience. En continuant à naviguer, vous acceptez leur utilisation.'))?></textarea>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
          <div class="fg">
            <label>Lien "En savoir plus" — Label</label>
            <input type="text" name="cookie_link_label" class="input-std" value="<?=Helpers::e(Config::get('cookie_link_label','En savoir plus'))?>" placeholder="En savoir plus">
          </div>
          <div class="fg">
            <label>Lien "En savoir plus" — URL</label>
            <input type="text" name="cookie_link_label" class="input-std" value="<?=Helpers::e(Config::get('cookie_link_url',''))?>" name="cookie_link_url" placeholder="/confidentialite">
          </div>
        </div>
      </div>
      <div style="display:flex;justify-content:flex-end"><button type="submit" name="save_config" class="btn btn-primary">💾 Sauvegarder</button></div>
    </div></form>

    <?php elseif($tab==='appearance'): ?>
    <form method="post" enctype="multipart/form-data" class="ac"><div class="ac-body"><?=Auth::csrfField()?><input type="hidden" name="group" value="general">
      <div class="form-row">
        <div class="fg"><label>Couleur principale</label><div style="display:flex;gap:.5rem;align-items:center"><input type="color" name="primary_color" value="<?=Helpers::e(Config::get('primary_color','#1d4ed8'))?>" style="width:44px;height:38px;border-radius:6px;border:1px solid #e2e8f0;cursor:pointer;padding:2px" oninput="this.nextElementSibling.value=this.value"><input type="text" value="<?=Helpers::e(Config::get('primary_color','#1d4ed8'))?>" style="flex:1" oninput="this.previousElementSibling.value=this.value" name="primary_color_txt"></div></div>
        <div class="fg"><label>Couleur secondaire</label><div style="display:flex;gap:.5rem;align-items:center"><input type="color" name="secondary_color" value="<?=Helpers::e(Config::get('secondary_color','#f59e0b'))?>" style="width:44px;height:38px;border-radius:6px;border:1px solid #e2e8f0;cursor:pointer;padding:2px" oninput="this.nextElementSibling.value=this.value"><input type="text" value="<?=Helpers::e(Config::get('secondary_color','#f59e0b'))?>" style="flex:1" oninput="this.previousElementSibling.value=this.value" name="secondary_color_txt"></div></div>
        <div class="fg">
          <label>Couleur de la barre de défilement</label>
          <div style="display:flex;gap:.5rem;align-items:center">
            <input type="color" name="scrollbar_color" value="<?=Helpers::e(Config::get('scrollbar_color',Config::get('primary_color','#1d4ed8')))?>" style="width:44px;height:38px;border-radius:6px;border:1px solid #e2e8f0;cursor:pointer;padding:2px" oninput="this.nextElementSibling.value=this.value">
            <input type="text" value="<?=Helpers::e(Config::get('scrollbar_color',Config::get('primary_color','#1d4ed8')))?>" style="flex:1" oninput="this.previousElementSibling.value=this.value" name="scrollbar_color_txt">
          </div>
          <small style="color:#94a3b8;font-size:.72rem">Couleur de l'ascenseur dans le navigateur (Chrome, Edge...)</small>
        </div>
        <div class="fg"><label>Police titres</label><input type="text" name="font_heading" value="<?=Helpers::e(Config::get('font_heading','Bebas Neue'))?>"></div>
        <div class="fg"><label>Police texte</label><input type="text" name="font_body" value="<?=Helpers::e(Config::get('font_body','DM Sans'))?>"></div>
        <div class="fg"><label>Logo</label><input type="file" name="logo" accept="image/*"><?php if(Config::get('logo')): ?><img src="<?=asset(Config::get('logo'))?>" style="height:36px;margin-top:.35rem"><?php endif; ?></div>
        <div class="fg"><label>Image Hero</label><input type="file" name="hero_image" accept="image/*"></div>
        <div class="fg span2"><label>CSS personnalisé</label><textarea name="custom_css" rows="6" style="font-family:monospace;font-size:.82rem"><?=Helpers::e(Config::get('custom_css',''))?></textarea></div>
      </div>
      <div style="display:flex;justify-content:flex-end"><button type="submit" name="save_config" class="btn btn-primary">💾 Sauvegarder</button></div>
    </div></form>

    <?php elseif($tab==='email'): ?>
    <form method="post" class="ac"><div class="ac-body"><?=Auth::csrfField()?><input type="hidden" name="group" value="api">
      <div class="form-row">
        <div class="fg"><label>Hôte SMTP</label><input type="text" name="mail_host" value="<?=Helpers::e(Config::get('mail_host'))?>"></div>
        <div class="fg"><label>Port SMTP</label><input type="number" name="mail_port" value="<?=Helpers::e(Config::get('mail_port','587'))?>"></div>
        <div class="fg"><label>Utilisateur SMTP</label><input type="email" name="mail_user" value="<?=Helpers::e(Config::get('mail_user'))?>"></div>
        <div class="fg"><label>Mot de passe SMTP</label><input type="password" name="mail_pass" placeholder="(inchangé si vide)"></div>
        <div class="fg"><label>Email expéditeur</label><input type="email" name="mail_from_email" value="<?=Helpers::e(Config::get('mail_from_email'))?>"></div>
        <div class="fg"><label>Nom expéditeur</label><input type="text" name="mail_from_name" value="<?=Helpers::e(Config::get('mail_from_name'))?>"></div>
        <!-- Guide SMTP -->
        <div style="background:#f0f9ff;border:1.5px solid #bae6fd;border-radius:10px;padding:1rem;margin-bottom:1.25rem">
          <div style="font-weight:700;font-size:.9rem;color:#0369a1;margin-bottom:.5rem">📧 Comment configurer l'envoi d'emails ?</div>
          <ul style="font-size:.82rem;color:#0c4a6e;line-height:1.8;margin:0;padding-left:1.25rem">
            <li><strong>Gmail :</strong> hôte <code>smtp.gmail.com</code>, port <code>587</code>, activez "Mots de passe d'application" dans votre compte Google</li>
            <li><strong>OVH :</strong> hôte <code>ssl0.ovh.net</code>, port <code>465</code> (SSL) ou <code>587</code> (TLS)</li>
            <li><strong>o2switch :</strong> hôte <code>mail.votredomaine.fr</code>, port <code>587</code></li>
            <li><strong>Infomaniak :</strong> hôte <code>mail.infomaniak.com</code>, port <code>587</code></li>
          </ul>
          <div style="margin-top:.625rem;font-size:.78rem;color:#0369a1">💡 Si les emails n'arrivent pas, vérifiez les spams et que votre hébergeur autorise les connexions SMTP sortantes.</div>
        </div>

      </div>
      <div style="display:flex;justify-content:flex-end"><button type="submit" name="save_config" class="btn btn-primary">💾 Sauvegarder</button></div>
    </div></form>

    <?php elseif($tab==='payments'): ?>
    <form method="post" class="ac"><div class="ac-body"><?=Auth::csrfField()?><input type="hidden" name="group" value="api">
      <h3 style="margin-bottom:.75rem;font-size:.95rem;font-weight:700">Stripe</h3>
      <div class="form-row">
        <div class="fg"><label>Clé publique</label><input type="text" name="stripe_public" value="<?=Helpers::e(Config::get('stripe_public'))?>" placeholder="pk_live_..."></div>
        <div class="fg"><label>Clé secrète</label><input type="password" name="stripe_secret" placeholder="(inchangé si vide)"></div>
      </div>
      <h3 style="margin:.875rem 0 .75rem;font-size:.95rem;font-weight:700">PayPal</h3>
      <div class="form-row">
        <div class="fg"><label>Client ID</label><input type="text" name="paypal_client" value="<?=Helpers::e(Config::get('paypal_client'))?>"></div>
        <div class="fg"><label>Secret</label><input type="password" name="paypal_secret" placeholder="(inchangé si vide)"></div>
        <div class="fg"><label>Mode</label><select name="paypal_mode"><option value="sandbox" <?=Config::get('paypal_mode')==='sandbox'?'selected':''?>>Sandbox</option><option value="live" <?=Config::get('paypal_mode')==='live'?'selected':''?>>Live</option></select></div>
      </div>
      <div style="display:flex;justify-content:flex-end"><button type="submit" name="save_config" class="btn btn-primary">💾 Sauvegarder</button></div>
    </div></form>
    <?php elseif($tab==='registration'): ?>
    <?php
    $regCritSettings = json_decode(Config::get('registration_criteria','{}'), true) ?? [];
    $allRegCriteria  = [];
    try {
        Database::run("CREATE TABLE IF NOT EXISTS cc_planning_criteria (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            options TEXT DEFAULT NULL,
            required TINYINT(1) NOT NULL DEFAULT 1,
            allow_other TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1
        )");
        $allRegCriteria = Database::all("SELECT * FROM cc_planning_criteria WHERE active=1 ORDER BY sort_order, name");
    } catch(Exception $e) {}
    ?>
    <style>
    .reg-toggle { display:none; }
    .reg-toggle-label {
        display:inline-flex;align-items:center;gap:.5rem;cursor:pointer;user-select:none;
    }
    .reg-toggle-track {
        width:44px;height:24px;border-radius:99px;background:#e2e8f0;
        position:relative;transition:background .2s;flex-shrink:0;
    }
    .reg-toggle-thumb {
        position:absolute;top:3px;left:3px;width:18px;height:18px;
        background:#fff;border-radius:50%;transition:left .2s;
        box-shadow:0 1px 3px rgba(0,0,0,.25);
    }
    .reg-toggle:checked + .reg-toggle-label .reg-toggle-track { background:var(--color-primary); }
    .reg-toggle:checked + .reg-toggle-label .reg-toggle-thumb { left:23px; }
    .reg-toggle.req-toggle:checked + .reg-toggle-label .reg-toggle-track { background:#ef4444; }
    </style>

    <!-- reCAPTCHA -->
    <form method="post" class="ac" style="max-width:720px;margin-bottom:1.25rem">
      <div class="ac-header"><h2>🤖 Protection anti-robots (reCAPTCHA v3)</h2></div>
      <div class="ac-body">
        <?=Auth::csrfField()?><input type="hidden" name="group" value="auth">
        <div style="background:#f0f9ff;border:1.5px solid #bae6fd;border-radius:10px;padding:1rem;margin-bottom:1.25rem">
          <div style="font-weight:700;font-size:.9rem;color:#0369a1;margin-bottom:.5rem">Comment installer Google reCAPTCHA v3 ?</div>
          <ol style="font-size:.82rem;color:#0c4a6e;line-height:1.9;margin:0;padding-left:1.25rem">
            <li>Allez sur <a href="https://www.google.com/recaptcha/admin/create" target="_blank" style="color:#2563eb;font-weight:600">google.com/recaptcha/admin/create</a> (connecté à votre compte Google)</li>
            <li>Donnez un nom au site (ex: <em>MonClub</em>), choisissez le type <strong>reCAPTCHA v3</strong></li>
            <li>Ajoutez votre domaine (ex: <code style="background:#e0f2fe;padding:.1rem .3rem;border-radius:4px">monclub.fr</code> — sans https://)</li>
            <li>Acceptez les CGU et cliquez <strong>Enregistrer</strong></li>
            <li>Copiez la <strong>Clé du site</strong> (publique) et la <strong>Clé secrète</strong> ci-dessous</li>
          </ol>
          <div style="margin-top:.625rem;font-size:.78rem;color:#0369a1">💡 reCAPTCHA v3 est invisible — aucun clic ni image à sélectionner pour l'utilisateur. Il analyse le comportement en arrière-plan et attribue un score de 0 à 1 (0 = robot, 1 = humain). Les soumissions avec score &lt; 0.5 sont bloquées.</div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-bottom:.75rem">
          <div>
            <label style="font-size:.78rem;font-weight:600;color:#64748b;display:block;margin-bottom:.3rem">Clé du site (Site Key) — publique</label>
            <input type="text" name="recaptcha_site" class="input-std" value="<?=Helpers::e(Config::get('recaptcha_site'))?>" placeholder="6Lc…">
          </div>
          <div>
            <label style="font-size:.78rem;font-weight:600;color:#64748b;display:block;margin-bottom:.3rem">Clé secrète (Secret Key) — privée</label>
            <input type="text" name="recaptcha_secret" class="input-std" value="<?=Helpers::e(Config::get('recaptcha_secret'))?>" placeholder="6Lc…">
          </div>
        </div>
        <?php if(Config::get('recaptcha_site') && Config::get('recaptcha_secret')): ?>
        <div style="background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:8px;padding:.625rem .875rem;font-size:.82rem;color:#15803d;margin-bottom:.75rem">
          ✅ reCAPTCHA v3 activé — les inscriptions sont protégées.
        </div>
        <?php else: ?>
        <div style="background:#fef3c7;border:1.5px solid #fde68a;border-radius:8px;padding:.625rem .875rem;font-size:.82rem;color:#92400e;margin-bottom:.75rem">
          ⚠️ Non configuré — laisser vide pour désactiver le captcha.
        </div>
        <?php endif; ?>
        <button type="submit" name="save_config" class="btn btn-primary">💾 Sauvegarder</button>
      </div>
    </form>

    <?php
    $allowRegister = Config::get('allow_register', '1');
    $allowLogin    = Config::get('allow_login',    '1');
    ?>
    <div class="ac" style="max-width:720px;margin-bottom:1.25rem">
      <div class="ac-header"><h2>🔐 Accès au site</h2></div>
      <div class="ac-body">
        <style>
        .acc-toggle-row{display:flex;align-items:center;justify-content:space-between;padding:.875rem;background:#f8fafc;border-radius:10px;border:1.5px solid #e2e8f0;margin-bottom:.75rem}
        .acc-switch{position:relative;width:44px;height:24px;flex-shrink:0}
        .acc-switch input{position:absolute;opacity:0;width:0;height:0}
        .acc-switch .track{position:absolute;inset:0;border-radius:99px;background:#e2e8f0;cursor:pointer;transition:background .2s}
        .acc-switch .thumb{position:absolute;top:3px;left:3px;width:18px;height:18px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.25);transition:left .2s;pointer-events:none}
        .acc-switch input:checked ~ .track{background:var(--color-primary)}
        .acc-switch input:checked ~ .thumb{left:23px}
        </style>
        <form method="post"><?=Auth::csrfField()?>
          <!-- Connexion -->
          <div class="acc-toggle-row">
            <div>
              <div style="font-weight:600;font-size:.9rem">Autoriser la connexion</div>
              <div style="font-size:.78rem;color:#64748b">Si désactivé, le bouton "Connexion" disparaît du site</div>
            </div>
            <label class="acc-switch">
              <input type="checkbox" name="allow_login" value="1" <?=$allowLogin?'checked':''?>>
              <span class="track"></span>
              <span class="thumb"></span>
            </label>
          </div>
          <!-- Inscription -->
          <div class="acc-toggle-row">
            <div>
              <div style="font-weight:600;font-size:.9rem">Autoriser l'inscription</div>
              <div style="font-size:.78rem;color:#64748b">Si désactivé, le bouton "S'inscrire" disparaît du site</div>
            </div>
            <label class="acc-switch">
              <input type="checkbox" name="allow_register" value="1" <?=$allowRegister?'checked':''?>>
              <span class="track"></span>
              <span class="thumb"></span>
            </label>
          </div>
          <button type="submit" name="save_access_settings" class="btn btn-primary">💾 Sauvegarder</button>
        </form>
      </div>
    </div>

    <div class="ac" style="max-width:720px">
      <div class="ac-header">
        <h2>📋 Critères demandés à l'inscription</h2>
        <a href="<?=u('/admin/planning?tab=criteria')?>" class="btn btn-ghost btn-sm">+ Créer un critère</a>
      </div>
      <div class="ac-body">
        <p style="color:#64748b;font-size:.875rem;margin-bottom:1.5rem;line-height:1.6">
          Partagés avec le Planning. Les réponses sont pré-remplies lors des inscriptions aux créneaux.
        </p>

        <?php if(empty($allRegCriteria)): ?>
        <div style="text-align:center;padding:2.5rem;color:#94a3b8;border:2px dashed #e2e8f0;border-radius:12px">
          <div style="font-size:2.5rem;margin-bottom:.75rem">🏷</div>
          <div style="font-weight:600;margin-bottom:.4rem">Aucun critère créé</div>
          <div style="font-size:.875rem;margin-bottom:1rem">Créez des critères dans Planning pour les afficher ici.</div>
          <a href="<?=u('/admin/planning?tab=criteria')?>" class="btn btn-primary btn-sm">✏️ Créer un critère →</a>
        </div>
        <?php else: ?>
        <form method="post">
          <?=Auth::csrfField()?>
          <div style="display:flex;flex-direction:column;gap:.75rem">
          <?php foreach($allRegCriteria as $cr):
            $cid   = (string)$cr['id'];
            $s     = $regCritSettings[$cid] ?? ['display'=>0,'required'=>0];
            $isOn  = (int)($s['display']  ?? 0);
            $isReq = (int)($s['required'] ?? 0);
            $opts  = json_decode($cr['options']??'[]',true)??[];
            $dispId = 'disp-'.$cr['id'];
            $reqId  = 'req-'.$cr['id'];
          ?>
          <div style="padding:1.1rem 1.25rem;border-radius:12px;border:2px solid #e2e8f0;background:#fff;transition:all .2s" id="card-<?=$cr['id']?>">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;flex-wrap:wrap">

              <!-- Info -->
              <div style="flex:1;min-width:180px">
                <div style="font-weight:700;font-size:.95rem;margin-bottom:.35rem"><?=Helpers::e($cr['name'])?></div>
                <div style="display:flex;flex-wrap:wrap;gap:.25rem">
                  <?php foreach($opts as $o):?>
                  <span style="background:<?=Helpers::e($o['color']??'#6366f1')?>;color:#fff;padding:.1rem .45rem;border-radius:99px;font-size:.72rem;font-weight:700"><?=Helpers::e($o['label'])?></span>
                  <?php endforeach;?>
                  <?php if($cr['allow_other']):?><span style="background:#f1f5f9;color:#64748b;padding:.1rem .45rem;border-radius:99px;font-size:.72rem">Autre…</span><?php endif;?>
                </div>
              </div>

              <!-- Toggles vrais checkboxes -->
              <div style="display:flex;flex-direction:column;gap:.625rem;align-items:flex-end">
                <!-- Toggle Demandé -->
                <input type="checkbox" class="reg-toggle" name="crit_display[]" value="<?=$cr['id']?>"
                  id="<?=$dispId?>" <?=$isOn?'checked':''?>
                  onchange="onToggleDisplay(<?=$cr['id']?>, this.checked)">
                <label for="<?=$dispId?>" class="reg-toggle-label">
                  <span style="font-size:.82rem;font-weight:600;color:#374151">Demandé à l'inscription</span>
                  <span class="reg-toggle-track"><span class="reg-toggle-thumb"></span></span>
                </label>

                <!-- Toggle Obligatoire -->
                <div id="req-row-<?=$cr['id']?>" style="display:<?=$isOn?'flex':'none'?>">
                  <input type="checkbox" class="reg-toggle req-toggle" name="crit_required[]" value="<?=$cr['id']?>"
                    id="<?=$reqId?>" <?=$isReq?'checked':''?>>
                  <label for="<?=$reqId?>" class="reg-toggle-label">
                    <span style="font-size:.82rem;font-weight:600;color:#ef4444">Obligatoire</span>
                    <span class="reg-toggle-track"><span class="reg-toggle-thumb"></span></span>
                  </label>
                </div>
              </div>
            </div>
          </div>
          <?php endforeach;?>
          </div>
          <div style="margin-top:1.25rem;display:flex;align-items:center;gap:.75rem">
            <button type="submit" name="save_registration_criteria" class="btn btn-primary">💾 Sauvegarder</button>
            <a href="<?=u('/admin/planning?tab=criteria')?>" class="btn btn-ghost btn-sm">+ Ajouter un critère</a>
          </div>
        </form>
        <?php endif;?>
      </div>
    </div>

    <script>
    function onToggleDisplay(id, checked) {
      var reqRow = document.getElementById('req-row-'+id);
      if (reqRow) reqRow.style.display = checked ? 'flex' : 'none';
      if (!checked) {
        var reqChk = document.getElementById('req-'+id);
        if (reqChk) reqChk.checked = false;
      }
      var card = document.getElementById('card-'+id);
      if (card) {
        card.style.border     = '2px solid ' + (checked ? 'var(--color-primary)' : '#e2e8f0');
        card.style.background = checked ? '#eff6ff' : '#fff';
      }
    }
    // Init état des cartes au chargement
    document.querySelectorAll('.reg-toggle').forEach(function(chk) {
      if (chk.name && chk.name.indexOf('crit_display') !== -1 && chk.checked) {
        var id = chk.value;
        var card = document.getElementById('card-'+id);
        if (card) { card.style.border = '2px solid var(--color-primary)'; card.style.background = '#eff6ff'; }
      }
    });
    </script>

    <?php endif; ?>
    <?php
    $content=ob_get_clean(); include CC_ROOT.'/admin/layout.php'; return;
}
