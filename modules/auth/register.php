<?php
ob_start(); // Capturer tout output pour éviter les headers already sent
if(!class_exists('CriteriaRenderer'))require_once CC_ROOT.'/core/CriteriaRenderer.php';

/**
 * ClubCMS — Inscription
 */

if (Auth::check()) {
    Helpers::redirect(u('/membre'));
}

$error         = '';
$success       = '';
$successNotice = '';
$fields        = [];
$criteriaPostData = [];

$club          = Config::get('club_name', 'Mon Club');
$recaptchaSite = Config::get('recaptcha_site', '');

// Charger les critères d'inscription actifs
$regCritSettings = json_decode(Config::get('registration_criteria','{}'), true) ?? [];
$regCriteria     = [];
foreach ($regCritSettings as $cid => $s) {
    if ($s['display'] ?? 0) {
        try {
            $cr = Database::one("SELECT * FROM cc_planning_criteria WHERE id=? AND active=1", [(int)$cid]);
            if ($cr) {
                $cr['reg_required'] = (int)($s['required'] ?? 0);
                $regCriteria[]      = $cr;
            }
        } catch(Exception $e) {}
    }
}

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if (!Auth::verifyCsrf()) {
        $error = 'Requête invalide. Réessayez.';
    } else {
        // 0. Vérifier CGU obligatoire
        if (empty($_POST['cgu'])) {
            $error = 'Vous devez accepter les CGU et la politique de confidentialité.';
        }

        // 0b. Vérifier reCAPTCHA v3 si configuré
        if (!$error && $recaptchaSite) {
            $recaptchaResponse = $_POST['g-recaptcha-response'] ?? '';
            if (!$recaptchaResponse) {
                $error = 'Vérification anti-robot échouée. Réessayez.';
            } else {
                $verif = Auth::verifyRecaptcha($recaptchaResponse);
                $score = (float)($verif['score'] ?? 0);
                // En local (localhost/127.0.0.1), Google retourne souvent score=0
                // On détecte et on laisse passer pour ne pas bloquer le dev
                $isLocal = in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost','127.0.0.1'])
                    || str_starts_with($_SERVER['HTTP_HOST'] ?? '', 'localhost:');
                if (!$isLocal && !($verif['success'] ?? false)) {
                    $error = 'Vérification anti-robot échouée. Réessayez.';
                } elseif (!$isLocal && $score < 0.3) {
                    $error = 'Vérification anti-robot échouée (score insuffisant). Réessayez.';
                }
                // En local ou si score >= 0.3 → on laisse passer
            }
        }

        // 1. Récupérer les valeurs des critères depuis POST
        foreach ($regCriteria as $cr) {
            $cr['is_required_here'] = $cr['reg_required'];
            $vals = CriteriaRenderer::fromPost($cr);
            $criteriaPostData[$cr['id']] = $vals;
            if ($cr['reg_required'] && trim($vals['value']) === '') {
                $error = 'Le champ "'.htmlspecialchars($cr['name']).'" est obligatoire.';
            }
        }

        // 2. Seulement si pas d'erreur sur les critères, tenter l'inscription
        if (!$error) {
            $result = Auth::register($_POST);
            if ($result['success']) {
                $newUserId = $result['id'] ?? null;
                if ($newUserId) {
                    // Sauvegarder numéro de licence
                    $licNum = Helpers::sanitize($_POST['license_number'] ?? '');
                    if ($licNum) {
                        try { Database::run("UPDATE cc_users SET license_number=? WHERE id=?", [$licNum, $newUserId]); } catch(Exception $e) {}
                    }
                    // Sauvegarder critères
                    foreach ($criteriaPostData as $cid => $vals) {
                        $v  = is_array($vals) ? ($vals['value']  ?? '') : $vals;
                        $v2 = is_array($vals) ? ($vals['value2'] ?? '') : '';
                        if ($v !== '') {
                            try {
                                Database::run(
                                    "INSERT INTO cc_planning_criteria_values (user_id,criteria_id,value,value2) VALUES (?,?,?,?)
                                     ON DUPLICATE KEY UPDATE value=VALUES(value),value2=VALUES(value2)",
                                    [$newUserId, (int)$cid, $v, $v2]
                                );
                            } catch(Exception $e) {}
                        }
                    }
                }
                // Redirection ou message succès
                $mailNotice = !Config::get('mail_host')
                    ? '<div style="margin-top:.5rem;padding:.4rem .75rem;background:#fef3c7;border-radius:6px;font-size:.82rem;color:#92400e">⚠️ Les emails ne sont pas encore configurés par l\'administrateur.</div>'
                    : '';
                if ($result['needs_verif']) {
                    $success = 'Inscription réussie ! Vérifiez votre email pour activer votre compte.';
                    $successNotice = $mailNotice;
                } else {
                    $successNotice = '';
                    Auth::login($_POST['email'], $_POST['password']);
                    Helpers::redirect(u('/membre?welcome=1'));
                }
            } else {
                $error  = $result['error'];
                $fields = $_POST;
            }
        } else {
            $fields = $_POST;
        }
    }
  } catch (\Throwable $e) {
    $error = 'Une erreur est survenue. Veuillez réessayer.';
    $fields = $_POST;
    error_log('Register error: ' . $e->getMessage());
  }
}

$pageTitle = 'Inscription — ' . $club;
?>
<div class="auth-container">
  <div class="auth-card">

    <div class="auth-logo">
      <?php if (Config::get('logo')): ?>
        <img src="<?=asset(Config::get('logo'))?>" alt="<?= Helpers::e($club) ?>" class="auth-club-logo">
      <?php endif; ?>
      <h1 class="auth-title">Rejoindre <?= Helpers::e($club) ?></h1>
      <p class="auth-subtitle">Créez votre espace membre</p>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-error">⚠️ <?= Helpers::e($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
      <div class="alert alert-success">✅ <?= Helpers::e($success) ?></div>
      <?php if (!empty($successNotice)) echo $successNotice; ?>
    <?php else: ?>

    <form method="post" id="register-form">
      <?= Auth::csrfField() ?>
      <?php if ($recaptchaSite): ?>
        <input type="hidden" name="g-recaptcha-response" id="g-recaptcha-response">
      <?php endif; ?>

      <div class="form-row">
        <div class="form-group">
          <label for="firstname">Prénom *</label>
          <input type="text" id="firstname" name="firstname"
                 value="<?= Helpers::e($fields['firstname'] ?? '') ?>"
                 placeholder="Jean" required autocomplete="given-name">
        </div>
        <div class="form-group">
          <label for="lastname">Nom *</label>
          <input type="text" id="lastname" name="lastname"
                 value="<?= Helpers::e($fields['lastname'] ?? '') ?>"
                 placeholder="Dupont" required autocomplete="family-name">
        </div>
      </div>

      <div class="form-group">
        <label for="email">Adresse email *</label>
        <input type="email" id="email" name="email"
               value="<?= Helpers::e($fields['email'] ?? '') ?>"
               placeholder="jean.dupont@email.fr" required autocomplete="email">
      </div>

      <div class="form-group">
        <label for="phone">Téléphone</label>
        <input type="tel" id="phone" name="phone"
               value="<?= Helpers::e($fields['phone'] ?? '') ?>"
               placeholder="+33 6 12 34 56 78" autocomplete="tel">
      </div>

      <div class="form-group">
        <label for="license_number">Numéro de licence <span style="font-weight:400;color:#94a3b8;font-size:.85em">(optionnel)</span></label>
        <input type="text" id="license_number" name="license_number"
               value="<?= Helpers::e($fields['license_number'] ?? '') ?>"
               placeholder="Ex: 123456789">
      </div>

      <div class="form-row">
        <div class="form-group">
          <label for="password">Mot de passe *</label>
          <div class="input-icon-wrap">
            <input type="password" id="password" name="password"
                   placeholder="8 caractères minimum" required
                   autocomplete="new-password" minlength="8">
            <button type="button" class="toggle-pass" onclick="togglePass('password')" tabindex="-1">👁</button>
          </div>
          <div class="password-strength" id="pass-strength"></div>
        </div>
        <div class="form-group">
          <label for="password2">Confirmer *</label>
          <div class="input-icon-wrap">
            <input type="password" id="password2" name="password2"
                   placeholder="Répétez le mot de passe" required autocomplete="new-password">
            <button type="button" class="toggle-pass" onclick="togglePass('password2')" tabindex="-1">👁</button>
          </div>
        </div>
      </div>

      <?php if(!empty($regCriteria)): ?>
      <div style="margin:.75rem 0;border-top:1.5px solid #f1f5f9;padding-top:1rem">
        <div style="font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#64748b;margin-bottom:.875rem">
          🏷 Vos informations
        </div>
        <?php foreach($regCriteria as $cr):
          $opts  = json_decode($cr['options']??'[]',true)??[];
          $saved = $criteriaPostData[$cr['id']] ?? '';
          $isOther = $saved && !empty($opts) && !array_filter($opts, fn($o)=>$o['label']===$saved);
        ?>
        <div class="form-group">
          <label><?=Helpers::e($cr['name'])?><?=$cr['reg_required']?' *':''?></label>

          <?php if(empty($opts)): ?>
          
          <input type="text" name="crit_<?=$cr['id']?>"
            value="<?=Helpers::e($saved)?>"
            placeholder="Votre <?=Helpers::e(strtolower($cr['name']))?>"
            <?=$cr['reg_required']?'required':''?>
            style="width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:.6rem .875rem;font-size:.9rem;font-family:inherit;outline:none">

          <?php else: ?>
          
          <div style="display:flex;flex-wrap:wrap;gap:.4rem;margin-top:.3rem">
            <?php foreach($opts as $o):
              $isSelected = $saved === $o['label'];
            ?>
            <label style="display:inline-flex;align-items:center;gap:.35rem;padding:.45rem .875rem;border-radius:99px;border:2px solid <?=Helpers::e($o['color']??'#6366f1')?>;cursor:pointer;font-size:.875rem;font-weight:600;transition:all .15s;background:<?=$isSelected?Helpers::e($o['color']??'#6366f1'):'transparent'?>;color:<?=$isSelected?'#fff':'inherit'?>">
              <input type="radio" name="crit_<?=$cr['id']?>" value="<?=Helpers::e($o['label'])?>"
                <?=$isSelected?'checked':''?>
                <?=$cr['reg_required']?'required':''?>
                style="accent-color:<?=Helpers::e($o['color']??'var(--color-primary)')?>;margin:0;width:14px;height:14px">
              <?=Helpers::e($o['label'])?>
            </label>
            <?php endforeach;?>
            <?php if($cr['allow_other']):?>
            <label style="display:inline-flex;align-items:center;gap:.35rem;padding:.45rem .875rem;border-radius:99px;border:2px solid #e2e8f0;cursor:pointer;font-size:.875rem;background:<?=$isOther?'#f1f5f9':'transparent'?>">
              <input type="radio" name="crit_<?=$cr['id']?>" value="__other__"
                <?=$isOther?'checked':''?>
                style="margin:0;width:14px;height:14px">
              <span>Autre :</span>
              <input type="text" name="crit_<?=$cr['id']?>_other"
                value="<?=$isOther?Helpers::e($saved):''?>"
                placeholder="Précisez…"
                style="border:none;border-bottom:1px solid #cbd5e1;outline:none;font-size:.875rem;width:100px;background:transparent">
            </label>
            <?php endif;?>
          </div>
          <?php endif; ?>
        </div>
        <?php endforeach;?>
      </div>
      <?php endif;?>

      <div class="form-group checkbox-group" style="background:#fafafa;border:1.5px solid #e2e8f0;border-radius:8px;padding:.75rem">
        <label class="checkbox-label" style="display:flex;align-items:flex-start;gap:.625rem;cursor:pointer">
          <input type="checkbox" name="cgu" id="cgu" required style="margin-top:.2rem;width:18px;height:18px;accent-color:var(--color-primary);flex-shrink:0"
            oninvalid="this.setCustomValidity('Vous devez accepter les CGU pour vous inscrire.')"
            oninput="this.setCustomValidity('')">
          <span style="font-size:.875rem;line-height:1.5">
            J'ai lu et j'accepte les <a href="<?=u('/cgu')?>" target="_blank" style="color:var(--color-primary);font-weight:600">Conditions Générales d'Utilisation</a>
            et la <a href="<?=u('/confidentialite')?>" target="_blank" style="color:var(--color-primary);font-weight:600">politique de confidentialité</a> *
          </span>
        </label>
      </div>

      <?php if ($recaptchaSite): ?>
        <p class="recaptcha-notice">
          Protégé par reCAPTCHA &mdash;
          <a href="https://policies.google.com/privacy" target="_blank">Confidentialité</a> &
          <a href="https://policies.google.com/terms" target="_blank">CGU</a>
        </p>
      <?php endif; ?>

      <button type="submit" class="btn-submit" id="submit-btn">
        <span class="btn-text">Créer mon compte</span>
        <span class="btn-loader" hidden>⏳</span>
      </button>
    </form>

    <?php endif; ?>

    <p class="auth-switch">
      Déjà membre ? <a href="<?=u('/login')?>">Se connecter</a>
    </p>
  </div>
</div>

<?php if ($recaptchaSite): ?>
<script src="https://www.google.com/recaptcha/api.js?render=<?= Helpers::e($recaptchaSite) ?>"></script>
<script>
const form = document.getElementById('register-form');
if (form) {
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    document.getElementById('submit-btn').disabled = true;
    document.querySelector('.btn-text').hidden = true;
    document.querySelector('.btn-loader').hidden = false;

    try {
      const token = await new Promise((resolve, reject) =>
        grecaptcha.ready(() => grecaptcha.execute('<?= Helpers::e($recaptchaSite) ?>', {action:'register'}).then(resolve).catch(reject))
      );
      document.getElementById('g-recaptcha-response').value = token;
    } catch(e) {
      // Si reCAPTCHA échoue (ex: localhost), soumettre quand même
      // Le serveur PHP validera côté serveur
      console.warn('reCAPTCHA non disponible, soumission directe:', e);
    }
    form.submit();
  });
}
</script>
<?php else: ?>
<script>
// Force bouton loading sans reCAPTCHA
const form = document.getElementById('register-form');
if (form) form.addEventListener('submit', () => {
  document.getElementById('submit-btn').disabled = true;
  document.querySelector('.btn-text').hidden = true;
  document.querySelector('.btn-loader').hidden = false;
});
</script>
<?php endif; ?>

<script>
// Indicateur de force du mot de passe
const passInput = document.getElementById('password');
const strength  = document.getElementById('pass-strength');
if (passInput) {
  passInput.addEventListener('input', () => {
    const v = passInput.value;
    let score = 0;
    if (v.length >= 8)              score++;
    if (/[A-Z]/.test(v))           score++;
    if (/[0-9]/.test(v))           score++;
    if (/[^A-Za-z0-9]/.test(v))   score++;
    const labels = ['', 'Faible', 'Moyen', 'Bien', 'Fort'];
    const colors = ['', '#ef4444', '#f59e0b', '#3b82f6', '#10b981'];
    strength.textContent = v.length ? '🔒 ' + (labels[score] || 'Faible') : '';
    strength.style.color = colors[score] || '#ef4444';
  });
}

function togglePass(id) {
  const el = document.getElementById(id);
  el.type = el.type === 'password' ? 'text' : 'password';
}
</script>

<?php
$content = ob_get_clean();
include CC_ROOT . '/templates/layout.php';
