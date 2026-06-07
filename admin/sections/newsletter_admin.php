<?php
// Créer la table si elle n'existe pas
Database::run("CREATE TABLE IF NOT EXISTS `cc_newsletter_subscribers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(191) NOT NULL,
  `firstname` varchar(100) DEFAULT NULL,
  `active` tinyint(1) DEFAULT 1,
  `token` varchar(64) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

Database::run("CREATE TABLE IF NOT EXISTS `cc_newsletter_campaigns` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subject` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `sent_at` datetime DEFAULT NULL,
  `sent_count` int(11) DEFAULT 0,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Auth::verifyCsrf()) { adminFlash('error','CSRF'); Helpers::redirect(u('/admin/newsletter')); }

// Envoyer une campagne
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_campaign'])) {
    Auth::require('admin');
    $subject = Helpers::sanitize($_POST['subject'] ?? '');
    $body    = $_POST['body'] ?? '';
    if (!$subject) { adminFlash('error','Sujet requis.'); Helpers::redirect(u('/admin/newsletter')); }

    $subscribers = Database::all("SELECT * FROM cc_newsletter_subscribers WHERE active=1");
    $sent = 0;
    foreach($subscribers as $sub) {
        $personalBody = str_replace(
            ['{firstname}','{club_name}','{unsubscribe_url}'],
            [Helpers::e($sub['firstname']??''), Config::get('club_name'), CC_URL.'/newsletter/unsubscribe?token='.($sub['token']??'')],
            $body
        );
        Mailer::send($sub['email'], $sub['firstname']??'', $subject, $personalBody);
        $sent++;
    }
    Database::insert("INSERT INTO cc_newsletter_campaigns (subject, body, sent_at, sent_count) VALUES (?,?,NOW(),?)",[$subject,$body,$sent]);
    adminFlash('success',"Campagne envoyée à {$sent} abonnés."); Helpers::redirect(u('/admin/newsletter'));
}

// Désabonner manuellement
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unsubscribe_id'])) {
    Database::run("UPDATE cc_newsletter_subscribers SET active=0 WHERE id=?",[(int)$_POST['unsubscribe_id']]);
    adminFlash('success','Abonné désabonné.'); Helpers::redirect(u('/admin/newsletter'));
}

// Supprimer abonné
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_sub_id'])) {
    Database::run("DELETE FROM cc_newsletter_subscribers WHERE id=?",[(int)$_POST['delete_sub_id']]);
    adminFlash('success','Abonné supprimé.'); Helpers::redirect(u('/admin/newsletter'));
}

$subscribers = Database::all("SELECT * FROM cc_newsletter_subscribers ORDER BY created_at DESC");
$campaigns   = Database::all("SELECT * FROM cc_newsletter_campaigns ORDER BY created_at DESC LIMIT 20");
$tab = $_GET['tab'] ?? 'send';

$pageTitle = 'Newsletter';
ob_start();
?>
<div class="page-head">
  <h1>📨 Newsletter</h1>
  <div style="display:flex;gap:.5rem">
    <a href="?tab=send" class="btn <?=$tab==='send'?'btn-primary':'btn-ghost'?>">✉️ Envoyer</a>
    <a href="?tab=subscribers" class="btn <?=$tab==='subscribers'?'btn-primary':'btn-ghost'?>">👥 Abonnés (<?=count(array_filter($subscribers,fn($s)=>$s['active']))?> actifs)</a>
    <a href="?tab=history" class="btn <?=$tab==='history'?'btn-primary':'btn-ghost'?>">📋 Historique</a>
  </div>
</div>

<?php if($tab === 'send'): ?>
<div class="ac">
  <div class="ac-header"><h2>Nouvelle campagne</h2></div>
  <div class="ac-body">
    <form method="post">
      <?=Auth::csrfField()?>
      <div class="fg"><label>Objet *</label><input type="text" name="subject" placeholder="Ex: Actualités de juin 2025" required></div>
      <div class="fg">
        <label>Corps de l'email (HTML)</label>
        <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:.5rem .75rem;margin-bottom:.5rem;font-size:.78rem">
          Variables : <code>{firstname}</code> <code>{club_name}</code> <code>{unsubscribe_url}</code>
        </div>
        <textarea name="body" rows="15" style="font-family:monospace;font-size:.82rem" placeholder="<h2>Bonjour {firstname},</h2><p>...</p>"></textarea>
      </div>
      <div style="display:flex;align-items:center;justify-content:space-between">
        <span style="color:#64748b;font-size:.875rem">📤 Sera envoyé à <?=count(array_filter($subscribers,fn($s)=>$s['active']))?> abonnés actifs</span>
        <button type="submit" name="send_campaign" class="btn btn-primary" onclick="return confirm('Envoyer à tous les abonnés actifs ?')">📨 Envoyer</button>
      </div>
    </form>
  </div>
</div>

<?php elseif($tab === 'subscribers'): ?>
<div class="ac">
  <div class="ac-header"><h2>Abonnés newsletter</h2></div>
  <div class="at-wrap"><table class="at">
    <thead><tr><th>Email</th><th>Prénom</th><th>Statut</th><th>Inscrit le</th><th>Actions</th></tr></thead>
    <tbody>
      <?php foreach($subscribers as $s): ?>
      <tr>
        <td><?=Helpers::e($s['email'])?></td>
        <td><?=Helpers::e($s['firstname']??'—')?></td>
        <td><span class="badge badge-<?=$s['active']?'success':'muted'?>"><?=$s['active']?'Actif':'Désabonné'?></span></td>
        <td style="font-size:.78rem"><?=Helpers::dateFormat($s['created_at'])?></td>
        <td style="display:flex;gap:.35rem">
          <?php if($s['active']): ?>
          <form method="post"><<?=Auth::csrfField()?><input type="hidden" name="unsubscribe_id" value="<?=$s['id']?>"><button type="submit" class="btn btn-ghost btn-sm" onclick="return confirm('Désabonner ?')">Désabonner</button></form>
          <?php endif; ?>
          <form method="post"><?=Auth::csrfField()?><input type="hidden" name="delete_sub_id" value="<?=$s['id']?>"><button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Supprimer ?')">🗑️</button></form>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if(empty($subscribers)): ?><tr><td colspan="5" style="text-align:center;padding:2rem;color:#64748b">Aucun abonné</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>

<?php elseif($tab === 'history'): ?>
<div class="ac">
  <div class="ac-header"><h2>Campagnes envoyées</h2></div>
  <div class="at-wrap"><table class="at">
    <thead><tr><th>Objet</th><th>Envoyés</th><th>Date</th></tr></thead>
    <tbody>
      <?php foreach($campaigns as $c): ?>
      <tr>
        <td><?=Helpers::e($c['subject'])?></td>
        <td><?=$c['sent_count']?> abonnés</td>
        <td><?=Helpers::dateTimeFormat($c['sent_at'])?></td>
      </tr>
      <?php endforeach; ?>
      <?php if(empty($campaigns)): ?><tr><td colspan="3" style="text-align:center;padding:2rem;color:#64748b">Aucune campagne envoyée</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<?php $content=ob_get_clean(); include CC_ROOT.'/admin/layout.php';
