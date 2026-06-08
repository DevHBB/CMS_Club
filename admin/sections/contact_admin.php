<?php
/**
 * ClubCMS — Admin Contact
 */
Auth::require('admin');

// ── Marquer message comme lu ──────────────────────────────────
if (isset($_GET['read']) && (int)$_GET['read']) {
    try { Database::run("UPDATE cc_contact_messages SET read_at=NOW() WHERE id=?", [(int)$_GET['read']]); } catch(Exception $e) {}
    Helpers::redirect(u('/admin/contact?tab=messages'));
}

// ── Supprimer message ─────────────────────────────────────────
if (isset($_GET['delete_msg']) && (int)$_GET['delete_msg']) {
    try { Database::run("DELETE FROM cc_contact_messages WHERE id=?", [(int)$_GET['delete_msg']]); } catch(Exception $e) {}
    Helpers::redirect(u('/admin/contact?tab=messages'));
}

// ── Sauvegarder config ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_contact']) && Auth::verifyCsrf()) {
    $cFields = ['contact_title','contact_subtitle','contact_address','contact_city',
                'contact_phone','contact_email','contact_hours','contact_map_embed'];
    foreach ($cFields as $cf) {
        Config::set($cf, Helpers::sanitize($_POST[$cf] ?? ''), 'contact');
    }
    foreach (['contact_show_form','contact_show_info'] as $cb) {
        Config::set($cb, isset($_POST[$cb]) ? '1' : '0', 'contact');
    }
    adminFlash('success', 'Page contact sauvegardée.');
    Helpers::redirect(u('/admin/contact?tab=config'));
}

// Créer la table si besoin
try {
    Database::run("CREATE TABLE IF NOT EXISTS cc_contact_messages (
        id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(200) NOT NULL,
        email VARCHAR(200) NOT NULL, subject VARCHAR(300) DEFAULT '',
        message TEXT NOT NULL, ip VARCHAR(45) DEFAULT '',
        read_at DATETIME DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch(Exception $e) {}

$tab = $_GET['tab'] ?? 'config';
try { $unread = (int)Database::scalar("SELECT COUNT(*) FROM cc_contact_messages WHERE read_at IS NULL"); }
catch(Exception $e) { $unread = 0; }

$cc = [
    'title'     => Config::get('contact_title',     'Nous contacter'),
    'subtitle'  => Config::get('contact_subtitle',  "Une question ? N'hésitez pas à nous écrire."),
    'show_form' => Config::get('contact_show_form', '1'),
    'show_info' => Config::get('contact_show_info', '1'),
    'address'   => Config::get('contact_address',   Config::get('club_address','')),
    'city'      => Config::get('contact_city',      Config::get('club_city','')),
    'phone'     => Config::get('contact_phone',     Config::get('club_phone','')),
    'email'     => Config::get('contact_email',     Config::get('club_email','')),
    'hours'     => Config::get('contact_hours',     ''),
    'map_embed' => Config::get('contact_map_embed', ''),
];

$pageTitle = '📬 Contact';
ob_start();
?>

<div class="page-head">
  <h1>📬 Contact</h1>
  <a href="<?=u('/contact')?>" target="_blank" class="btn btn-ghost btn-sm">👁 Voir la page →</a>
</div>

<!-- Onglets -->
<div style="display:flex;gap:.35rem;margin-bottom:1.5rem">
  <a href="<?=u('/admin/contact?tab=config')?>"   class="btn <?=$tab==='config'  ?'btn-primary':'btn-ghost'?>">⚙️ Configuration</a>
  <a href="<?=u('/admin/contact?tab=messages')?>" class="btn <?=$tab==='messages'?'btn-primary':'btn-ghost'?>">
    📥 Messages reçus
    <?php if($unread>0): ?><span style="background:#dc2626;color:#fff;border-radius:99px;font-size:.65rem;padding:.1rem .45rem;margin-left:.25rem;font-weight:700"><?=$unread?></span><?php endif; ?>
  </a>
</div>

<?php if($tab==='config'): ?>
<!-- ── Configuration ── -->
<form method="post" style="max-width:700px">
  <?=Auth::csrfField()?>

  <div class="ac" style="margin-bottom:1.25rem">
    <div class="ac-header"><h2>🏷️ Titre & Description</h2></div>
    <div class="ac-body">
      <div class="fg"><label>Titre de la page</label>
        <input type="text" name="contact_title" class="input-std" value="<?=Helpers::e($cc['title'])?>">
      </div>
      <div class="fg"><label>Sous-titre</label>
        <input type="text" name="contact_subtitle" class="input-std" value="<?=Helpers::e($cc['subtitle'])?>">
      </div>
      <div style="display:flex;gap:1.5rem;margin-top:.5rem;flex-wrap:wrap">
        <label style="display:flex;align-items:center;gap:.5rem;font-size:.875rem;cursor:pointer">
          <input type="checkbox" name="contact_show_form" value="1" <?=$cc['show_form']?'checked':''?>>
          Afficher le formulaire de contact
        </label>
        <label style="display:flex;align-items:center;gap:.5rem;font-size:.875rem;cursor:pointer">
          <input type="checkbox" name="contact_show_info" value="1" <?=$cc['show_info']?'checked':''?>>
          Afficher les coordonnées
        </label>
      </div>
    </div>
  </div>

  <div class="ac" style="margin-bottom:1.25rem">
    <div class="ac-header"><h2>📍 Coordonnées</h2></div>
    <div class="ac-body">
      <div class="form-row">
        <div class="fg"><label>Adresse</label>
          <input type="text" name="contact_address" class="input-std" value="<?=Helpers::e($cc['address'])?>" placeholder="12 rue des Sports">
        </div>
        <div class="fg"><label>Ville / Code postal</label>
          <input type="text" name="contact_city" class="input-std" value="<?=Helpers::e($cc['city'])?>" placeholder="75001 Paris">
        </div>
      </div>
      <div class="form-row">
        <div class="fg"><label>Téléphone</label>
          <input type="tel" name="contact_phone" class="input-std" value="<?=Helpers::e($cc['phone'])?>">
        </div>
        <div class="fg"><label>Email de contact affiché</label>
          <input type="email" name="contact_email" class="input-std" value="<?=Helpers::e($cc['email'])?>">
        </div>
      </div>
      <div class="fg"><label>Horaires d'ouverture</label>
        <textarea name="contact_hours" class="input-std" rows="3"
          placeholder="Lun-Ven : 9h-18h&#10;Sam : 9h-12h"><?=Helpers::e($cc['hours'])?></textarea>
      </div>
    </div>
  </div>

  <div class="ac" style="margin-bottom:1.25rem">
    <div class="ac-header"><h2>🗺️ Carte (optionnel)</h2></div>
    <div class="ac-body">
      <div class="fg">
        <label>Adresse à afficher sur la carte</label>
        <input type="text" name="contact_map_embed" class="input-std"
          value="<?=Helpers::e($cc['map_embed'])?>"
          placeholder="12 rue des Sports, 75001 Paris">
        <small style="color:#64748b;font-size:.75rem">
          Entrez l'adresse complète — la carte sera générée automatiquement via Google Maps.
        </small>
      </div>
    </div>
  </div>

  <button type="submit" name="save_contact" class="btn btn-primary">💾 Sauvegarder</button>
</form>

<?php else: ?>
<!-- ── Messages reçus ── -->
<?php
try { $msgs = Database::all("SELECT * FROM cc_contact_messages ORDER BY created_at DESC LIMIT 100"); }
catch(Exception $e) { $msgs = []; }
?>
<div style="max-width:800px">
  <?php if(empty($msgs)): ?>
  <div style="background:#f8fafc;border-radius:12px;padding:3rem;text-align:center;color:#94a3b8">
    <div style="font-size:2.5rem;margin-bottom:.5rem">📭</div>
    Aucun message reçu pour le moment.
  </div>
  <?php else: ?>
  <?php foreach($msgs as $msg): ?>
  <div style="background:#fff;border:1.5px solid <?=$msg['read_at']?'#e2e8f0':'#bfdbfe'?>;border-radius:12px;padding:1.25rem;margin-bottom:.875rem">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-bottom:.75rem">
      <div>
        <strong><?=Helpers::e($msg['name'])?></strong>
        <span style="color:#64748b;font-size:.82rem;margin-left:.5rem">&lt;<?=Helpers::e($msg['email'])?>&gt;</span>
        <?php if(!$msg['read_at']): ?>
        <span style="background:#1d4ed8;color:#fff;border-radius:99px;font-size:.65rem;padding:.1rem .45rem;margin-left:.5rem;font-weight:700">Nouveau</span>
        <?php endif; ?>
      </div>
      <div style="font-size:.75rem;color:#94a3b8">
        <?=(new DateTime($msg['created_at']))->format('d/m/Y H:i')?>
      </div>
    </div>
    <?php if($msg['subject']): ?>
    <div style="font-size:.82rem;font-weight:600;color:#475569;margin-bottom:.5rem">Sujet : <?=Helpers::e($msg['subject'])?></div>
    <?php endif; ?>
    <div style="font-size:.875rem;color:#374151;line-height:1.6;background:#f8fafc;border-radius:8px;padding:.75rem;white-space:pre-wrap"><?=Helpers::e($msg['message'])?></div>
    <div style="display:flex;gap:.5rem;margin-top:.75rem;flex-wrap:wrap">
      <a href="mailto:<?=Helpers::e($msg['email'])?>" class="btn btn-primary btn-sm">↩️ Répondre par email</a>
      <?php if(!$msg['read_at']): ?>
      <a href="<?=u('/admin/contact?tab=messages&read='.$msg['id'])?>" class="btn btn-ghost btn-sm">✅ Marquer comme lu</a>
      <?php endif; ?>
      <a href="<?=u('/admin/contact?tab=messages&delete_msg='.$msg['id'])?>"
        class="btn btn-sm" style="background:#fee2e2;color:#dc2626;border:1.5px solid #fecaca"
        onclick="return confirm('Supprimer ce message ?')">🗑 Supprimer</a>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php
$content = ob_get_clean();
include CC_ROOT . '/admin/layout.php';
