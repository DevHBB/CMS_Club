<?php
/**
 * ClubCMS — Page Contact
 */

// Créer la table si besoin
try {
    Database::run("CREATE TABLE IF NOT EXISTS cc_contact_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(200) NOT NULL,
        email VARCHAR(200) NOT NULL,
        subject VARCHAR(300) DEFAULT '',
        message TEXT NOT NULL,
        ip VARCHAR(45) DEFAULT '',
        read_at DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch(Exception $e) {}

$sent    = false;
$error   = '';
$fields  = [];

// Récupérer la config de la page contact
$contactConfig = [
    'title'       => Config::get('contact_title',      'Nous contacter'),
    'subtitle'    => Config::get('contact_subtitle',   'Une question ? N\'hésitez pas à nous écrire.'),
    'show_form'   => Config::get('contact_show_form',  '1'),
    'show_info'   => Config::get('contact_show_info',  '1'),
    'address'     => Config::get('contact_address',    Config::get('club_address', '')),
    'city'        => Config::get('contact_city',       Config::get('club_city', '')),
    'phone'       => Config::get('contact_phone',      Config::get('club_phone', '')),
    'email'       => Config::get('contact_email',      Config::get('club_email', '')),
    'hours'       => Config::get('contact_hours',      ''),
    'map_embed'   => Config::get('contact_map_embed',  ''),
    'extra_blocks'=> Config::get('contact_extra_blocks', ''),
];

// Traitement POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_contact']) && Auth::verifyCsrf()) {
    $name    = Helpers::sanitize($_POST['name']    ?? '');
    $email   = Helpers::sanitize($_POST['email']   ?? '');
    $subject = Helpers::sanitize($_POST['subject']  ?? '');
    $message = Helpers::sanitize($_POST['message']  ?? '');

    if (!$name || !$email || !$message) {
        $error = 'Merci de remplir tous les champs obligatoires.';
        $fields = $_POST;
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Adresse email invalide.';
        $fields = $_POST;
    } else {
        // Sauvegarder en BDD
        try {
            Database::run(
                "INSERT INTO cc_contact_messages (name,email,subject,message,ip) VALUES (?,?,?,?,?)",
                [$name, $email, $subject, $message, $_SERVER['REMOTE_ADDR'] ?? '']
            );
        } catch(Exception $e) {}

        // Envoyer email à l'admin
        try {
            $clubEmail = Config::get('club_email', '');
            if ($clubEmail) {
                Mailer::send(
                    $clubEmail,
                    Config::get('club_name', 'Admin'),
                    '📬 Nouveau message de contact — ' . $subject,
                    '<p><strong>De :</strong> ' . htmlspecialchars($name) . ' &lt;' . htmlspecialchars($email) . '&gt;</p>'
                    . '<p><strong>Sujet :</strong> ' . htmlspecialchars($subject) . '</p>'
                    . '<p><strong>Message :</strong></p>'
                    . '<div style="background:#f8fafc;padding:1rem;border-radius:8px;white-space:pre-wrap">' . htmlspecialchars($message) . '</div>'
                );
            }
        } catch(Exception $e) {}

        $sent = true;
    }
}

$pageTitle = $contactConfig['title'];
$club      = Config::get('club_name', 'Mon Club');
ob_start();
?>

<!-- Hero -->
<section class="gallery-hero">
  <div class="container">
    <h1 class="forum-title"><?=Helpers::e($contactConfig['title'])?></h1>
    <?php if($contactConfig['subtitle']): ?>
    <p class="forum-subtitle"><?=Helpers::e($contactConfig['subtitle'])?></p>
    <?php endif; ?>
  </div>
</section>

<div class="container" style="max-width:1000px;margin:2rem auto;padding:0 1rem">
  <div style="display:grid;grid-template-columns:<?=($contactConfig['show_form'] && $contactConfig['show_info']) ? '1fr 380px' : '1fr'?>;gap:2rem;align-items:start">

    <?php if($contactConfig['show_form']): ?>
    <!-- ── Formulaire de contact ── -->
    <div>
      <?php if($sent): ?>
      <div style="background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:12px;padding:1.5rem;text-align:center">
        <div style="font-size:2.5rem;margin-bottom:.5rem">✅</div>
        <div style="font-weight:700;font-size:1.1rem;color:#166534;margin-bottom:.35rem">Message envoyé !</div>
        <p style="color:#15803d;font-size:.9rem">Merci <?=Helpers::e($name)?>, nous vous répondrons dans les meilleurs délais.</p>
      </div>
      <?php else: ?>
      <?php if($error): ?>
      <div style="background:#fff5f5;border:1.5px solid #fecaca;border-radius:10px;padding:.875rem 1rem;margin-bottom:1rem;color:#dc2626;font-size:.875rem">⚠️ <?=Helpers::e($error)?></div>
      <?php endif; ?>
      <div style="background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;padding:1.75rem">
        <h2 style="font-size:1.1rem;font-weight:700;margin-bottom:1.25rem">✉️ Envoyer un message</h2>
        <form method="post">
          <?=Auth::csrfField()?>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:.875rem;margin-bottom:.875rem">
            <div>
              <label style="font-size:.78rem;font-weight:600;color:#64748b;display:block;margin-bottom:.3rem">Votre nom *</label>
              <input type="text" name="name" value="<?=Helpers::e($fields['name']??'')?>" required
                style="width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:.6rem .875rem;font-family:inherit;font-size:.875rem;box-sizing:border-box">
            </div>
            <div>
              <label style="font-size:.78rem;font-weight:600;color:#64748b;display:block;margin-bottom:.3rem">Votre email *</label>
              <input type="email" name="email" value="<?=Helpers::e($fields['email']??'')?>" required
                style="width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:.6rem .875rem;font-family:inherit;font-size:.875rem;box-sizing:border-box">
            </div>
          </div>
          <div style="margin-bottom:.875rem">
            <label style="font-size:.78rem;font-weight:600;color:#64748b;display:block;margin-bottom:.3rem">Sujet</label>
            <input type="text" name="subject" value="<?=Helpers::e($fields['subject']??'')?>"
              placeholder="Objet de votre message"
              style="width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:.6rem .875rem;font-family:inherit;font-size:.875rem;box-sizing:border-box">
          </div>
          <div style="margin-bottom:1.25rem">
            <label style="font-size:.78rem;font-weight:600;color:#64748b;display:block;margin-bottom:.3rem">Message *</label>
            <textarea name="message" rows="6" required
              style="width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:.6rem .875rem;font-family:inherit;font-size:.875rem;resize:vertical;box-sizing:border-box"><?=Helpers::e($fields['message']??'')?></textarea>
          </div>
          <button type="submit" name="send_contact"
            style="background:var(--color-primary);color:#fff;border:none;border-radius:8px;padding:.75rem 1.75rem;font-family:inherit;font-size:.9rem;font-weight:600;cursor:pointer;width:100%">
            📨 Envoyer le message
          </button>
        </form>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if($contactConfig['show_info']): ?>
    <!-- ── Informations de contact ── -->
    <div style="display:flex;flex-direction:column;gap:.875rem">
      <?php if($contactConfig['address'] || $contactConfig['city']): ?>
      <div style="background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;padding:1.25rem">
        <div style="font-weight:700;color:#1e293b;margin-bottom:.625rem">📍 Adresse</div>
        <div style="font-size:.875rem;color:#475569;line-height:1.6">
          <?php if($contactConfig['address']): ?><?=Helpers::e($contactConfig['address'])?><br><?php endif; ?>
          <?php if($contactConfig['city']): ?><?=Helpers::e($contactConfig['city'])?><?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
      <?php if($contactConfig['phone']): ?>
      <div style="background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;padding:1.25rem">
        <div style="font-weight:700;color:#1e293b;margin-bottom:.625rem">📞 Téléphone</div>
        <a href="tel:<?=Helpers::e($contactConfig['phone'])?>" style="font-size:.875rem;color:var(--color-primary);text-decoration:none"><?=Helpers::e($contactConfig['phone'])?></a>
      </div>
      <?php endif; ?>
      <?php if($contactConfig['email']): ?>
      <div style="background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;padding:1.25rem">
        <div style="font-weight:700;color:#1e293b;margin-bottom:.625rem">✉️ Email</div>
        <a href="mailto:<?=Helpers::e($contactConfig['email'])?>" style="font-size:.875rem;color:var(--color-primary);text-decoration:none"><?=Helpers::e($contactConfig['email'])?></a>
      </div>
      <?php endif; ?>
      <?php if($contactConfig['hours']): ?>
      <div style="background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;padding:1.25rem">
        <div style="font-weight:700;color:#1e293b;margin-bottom:.625rem">🕐 Horaires</div>
        <div style="font-size:.875rem;color:#475569;line-height:1.7;white-space:pre-line"><?=Helpers::e($contactConfig['hours'])?></div>
      </div>
      <?php endif; ?>
      <?php if($contactConfig['map_embed']): ?>
      <div style="border-radius:12px;overflow:hidden;border:1.5px solid #e2e8f0">
        <iframe
          src="https://maps.google.com/maps?q=<?=urlencode($contactConfig['map_embed'])?>&output=embed&z=15"
          style="width:100%;height:220px;border:none" loading="lazy" allowfullscreen></iframe>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

  </div>

  <?php if($contactConfig['extra_blocks']): ?>
  <!-- Blocs additionnels -->
  <div style="margin-top:2rem">
    <?php
    $extraBlocks = json_decode($contactConfig['extra_blocks'], true) ?? [];
    echo BlockRenderer::render($extraBlocks, 'contact');
    ?>
  </div>
  <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
include CC_ROOT . '/templates/layout.php';
