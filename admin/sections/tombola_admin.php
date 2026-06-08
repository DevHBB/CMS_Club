<?php
/**
 * ClubCMS — Admin Tombola
 */
Auth::require('admin');

// Migrations
try { Database::run("CREATE TABLE IF NOT EXISTS cc_tombola (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(200) NOT NULL, description TEXT, status ENUM('draft','active','closed','done') DEFAULT 'draft', paid TINYINT(1) DEFAULT 0, price DECIMAL(10,2) DEFAULT 0.00, product_id INT DEFAULT NULL, multi_entry TINYINT(1) DEFAULT 0, visibility ENUM('all','members') DEFAULT 'all', close_at DATETIME DEFAULT NULL, msg_waiting VARCHAR(500) DEFAULT 'Le tirage au sort aura lieu prochainement !', winner_id INT DEFAULT NULL, winner_name VARCHAR(200) DEFAULT NULL, drawn_at DATETIME DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)"); } catch(Exception $e) {}
try { Database::run("ALTER TABLE cc_tombola MODIFY COLUMN status ENUM('draft','active','closed','done') DEFAULT 'draft'"); } catch(Exception $e) {}
try { Database::run("ALTER TABLE cc_tombola ADD COLUMN IF NOT EXISTS paid TINYINT(1) DEFAULT 0"); } catch(Exception $e) {}
try { Database::run("ALTER TABLE cc_tombola ADD COLUMN IF NOT EXISTS price DECIMAL(10,2) DEFAULT 0.00"); } catch(Exception $e) {}
try { Database::run("ALTER TABLE cc_tombola ADD COLUMN IF NOT EXISTS product_id INT DEFAULT NULL"); } catch(Exception $e) {}
try { Database::run("ALTER TABLE cc_tombola ADD COLUMN IF NOT EXISTS multi_entry TINYINT(1) DEFAULT 0"); } catch(Exception $e) {}
try { Database::run("ALTER TABLE cc_tombola ADD COLUMN IF NOT EXISTS visibility ENUM('all','members') DEFAULT 'all'"); } catch(Exception $e) {}
try { Database::run("ALTER TABLE cc_tombola ADD COLUMN IF NOT EXISTS close_at DATETIME DEFAULT NULL"); } catch(Exception $e) {}
try { Database::run("ALTER TABLE cc_tombola ADD COLUMN IF NOT EXISTS msg_waiting VARCHAR(500) DEFAULT 'Le tirage au sort aura lieu prochainement !'"); } catch(Exception $e) {}
try { Database::run("CREATE TABLE IF NOT EXISTS cc_tombola_participants (id INT AUTO_INCREMENT PRIMARY KEY, tombola_id INT NOT NULL, user_id INT DEFAULT NULL, name VARCHAR(200) NOT NULL, email VARCHAR(200) DEFAULT NULL, tickets INT DEFAULT 1, order_id INT DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)"); } catch(Exception $e) {}
try { Database::run("ALTER TABLE cc_tombola_participants ADD COLUMN IF NOT EXISTS order_id INT DEFAULT NULL"); } catch(Exception $e) {}
try { Database::run("ALTER TABLE cc_tombola ADD COLUMN IF NOT EXISTS participation ENUM('all','members','benevole','coach','admin') DEFAULT 'all'"); } catch(Exception $e) {}

$tab    = $_GET['tab'] ?? 'list';
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : -1;

// ── Handlers ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && Auth::verifyCsrf()) {

    if (isset($_POST['save_tombola'])) {
        $paid       = isset($_POST['paid']) ? 1 : 0;
        $price      = max(0, (float)str_replace(',','.',($_POST['price']??'0')));
        $productId  = (int)($_POST['product_id']??0) ?: null;
        $multiEntry = isset($_POST['multi_entry']) ? 1 : 0;
        $visibility = in_array($_POST['visibility']??'',['all','members']) ? $_POST['visibility'] : 'all';
        $closeAt    = !empty($_POST['close_at']) ? $_POST['close_at'] : null;
        $msgWaiting = Helpers::sanitize($_POST['msg_waiting'] ?? 'Le tirage aura lieu prochainement !');
        $data = [
            'name'        => Helpers::sanitize($_POST['name']??''),
            'description' => Helpers::sanitize($_POST['description']??''),
            'status'      => in_array($_POST['status']??'',['draft','active','done']) ? $_POST['status'] : 'draft',
            'paid'        => $paid,
            'price'       => $price,
            'product_id'  => $productId,
            'multi_entry' => $multiEntry,
            'visibility'  => $visibility,
            'close_at'    => $closeAt,
            'msg_waiting'   => $msgWaiting,
            'participation' => in_array($_POST['participation']??'',['all','members','benevole','coach','admin']) ? $_POST['participation'] : 'all',
        ];
        if ($editId > 0) {
            $sets = implode(',', array_map(fn($k)=>"`$k`=?", array_keys($data)));
            Database::run("UPDATE cc_tombola SET $sets WHERE id=?", [...array_values($data), $editId]);
            adminFlash('success','Tombola mise à jour.');
            Helpers::redirect(u('/admin/tombola?tab=participants&edit='.$editId));
        } else {
            $newId = Database::insert("INSERT INTO cc_tombola (".implode(',',array_map(fn($k)=>"`$k`",array_keys($data))).") VALUES (".implode(',',array_fill(0,count($data),'?')).")", array_values($data));
            adminFlash('success','Tombola créée.');
            Helpers::redirect(u('/admin/tombola?tab=participants&edit='.$newId));
        }
    }

    if (isset($_POST['delete_tombola'])) {
        $tid = (int)$_POST['tombola_id'];
        Database::run("DELETE FROM cc_tombola_participants WHERE tombola_id=?", [$tid]);
        Database::run("DELETE FROM cc_tombola WHERE id=?", [$tid]);
        adminFlash('success','Tombola supprimée.');
        Helpers::redirect(u('/admin/tombola'));
    }

    if (isset($_POST['add_participant'])) {
        $tid     = (int)$_POST['tombola_id'];
        $name    = Helpers::sanitize($_POST['pname']??'');
        $email   = Helpers::sanitize($_POST['pemail']??'');
        $tickets = max(1,(int)($_POST['tickets']??1));
        if ($name) Database::run("INSERT INTO cc_tombola_participants (tombola_id,name,email,tickets) VALUES (?,?,?,?)",[$tid,$name,$email,$tickets]);
        Helpers::redirect(u('/admin/tombola?tab=participants&edit='.$tid));
    }

    if (isset($_POST['import_members'])) {
        $tid = (int)$_POST['tombola_id'];
        $t   = Database::one("SELECT multi_entry FROM cc_tombola WHERE id=?",[$tid]);
        foreach ($_POST['member_ids']??[] as $uid) {
            $uid = (int)$uid;
            $m = Database::one("SELECT id,firstname,lastname,email FROM cc_users WHERE id=?",[$uid]);
            if (!$m) continue;
            if (!($t['multi_entry']??0)) {
                $exists = Database::scalar("SELECT id FROM cc_tombola_participants WHERE tombola_id=? AND user_id=?",[$tid,$uid]);
                if ($exists) continue;
            }
            Database::run("INSERT INTO cc_tombola_participants (tombola_id,user_id,name,email) VALUES (?,?,?,?)",
                [$tid,$uid,$m['firstname'].' '.$m['lastname'],$m['email']]);
        }
        adminFlash('success','Membres importés.');
        Helpers::redirect(u('/admin/tombola?tab=participants&edit='.$tid));
    }

    if (isset($_POST['remove_participant'])) {
        $pid = (int)$_POST['participant_id'];
        $tid = (int)$_POST['tombola_id'];
        Database::run("DELETE FROM cc_tombola_participants WHERE id=?",[$pid]);
        Helpers::redirect(u('/admin/tombola?tab=participants&edit='.$tid));
    }

    if (isset($_POST['update_tickets'])) {
        Database::run("UPDATE cc_tombola_participants SET tickets=? WHERE id=?",
            [max(1,(int)$_POST['tickets']),(int)$_POST['participant_id']]);
        Helpers::redirect(u('/admin/tombola?tab=participants&edit='.$_POST['tombola_id']));
    }

    if (isset($_POST['reset_draw'])) {
        $tid = (int)$_POST['tombola_id'];
        Database::run("UPDATE cc_tombola SET winner_id=NULL,winner_name=NULL,drawn_at=NULL,status='active' WHERE id=?",[$tid]);
        adminFlash('success','Tirage réinitialisé.');
        Helpers::redirect(u('/admin/tombola?tab=participants&edit='.$tid));
    }
}

$tombolas    = Database::all("SELECT * FROM cc_tombola ORDER BY created_at DESC");
$editTombola = ($editId >= 0) ? Database::one("SELECT * FROM cc_tombola WHERE id=?",[$editId]) : null;
$allProducts = Database::all("SELECT id,name,price FROM cc_shop_products WHERE published=1 ORDER BY name");
$allMembers  = Database::all("SELECT id,firstname,lastname,email FROM cc_users ORDER BY firstname,lastname");

$pageTitle = '🎰 Tombola';
ob_start();
?>
<div class="page-head">
  <h1>🎰 Tombola</h1>
  <a href="<?=u('/tombola')?>" target="_blank" class="btn btn-ghost btn-sm">👁 Voir la page</a>
</div>

<?php if($editId < 0): ?>
<!-- ── Liste ── -->
<div style="display:flex;justify-content:flex-end;margin-bottom:1rem">
  <a href="<?=u('/admin/tombola?edit=0')?>" class="btn btn-primary">+ Nouvelle tombola</a>
</div>
<?php if(empty($tombolas)): ?>
<div style="background:#f8fafc;border-radius:12px;padding:3rem;text-align:center;color:#94a3b8">
  <div style="font-size:3rem;margin-bottom:.5rem">🎰</div>
  Aucune tombola. <a href="<?=u('/admin/tombola?edit=0')?>">Créer la première →</a>
</div>
<?php else: ?>
<div class="at-wrap"><table class="at">
  <thead><tr><th>#</th><th>Nom</th><th>Type</th><th>Visibilité</th><th>Statut</th><th>Participants</th><th>Gagnant</th><th>Actions</th></tr></thead>
  <tbody>
  <?php foreach($tombolas as $t):
    $nb = (int)Database::scalar("SELECT COUNT(*) FROM cc_tombola_participants WHERE tombola_id=?",[$t['id']]);
    $tickets = (int)Database::scalar("SELECT COALESCE(SUM(tickets),0) FROM cc_tombola_participants WHERE tombola_id=?",[$t['id']]);
    $colors=['draft'=>'#94a3b8','active'=>'#16a34a','closed'=>'#f59e0b','done'=>'#6366f1'];
    $labels=['draft'=>'Brouillon','active'=>'Active','closed'=>'Inscriptions closes','done'=>'Terminée'];
  ?>
  <tr>
    <td>#<?=$t['id']?></td>
    <td><strong><?=Helpers::e($t['name'])?></strong></td>
    <td><?=$t['paid']?'💰 Payante ('.Helpers::price($t['price']).')':'🆓 Gratuite'?></td>
    <td><?=$t['visibility']==='members'?'🔒 Membres':'🌐 Tout le monde'?></td>
    <td><span style="background:<?=$colors[$t['status']]?>;color:#fff;border-radius:99px;padding:.15rem .5rem;font-size:.72rem;font-weight:700"><?=$labels[$t['status']]?></span></td>
    <td><?=$nb?> pers. · <?=$tickets?> ticket<?=$tickets>1?'s':''?></td>
    <td><?=$t['winner_name']?Helpers::e($t['winner_name']):'—'?></td>
    <td style="display:flex;gap:.35rem;flex-wrap:wrap">
      <a href="<?=u('/admin/tombola?tab=participants&edit='.$t['id'])?>" class="btn btn-primary btn-sm">✏️ Gérer</a>
      <a href="<?=u('/tombola/'.$t['id'])?>" target="_blank" class="btn btn-ghost btn-sm">🎰</a>
      <form method="post" onsubmit="return confirm('Supprimer ?')" style="margin:0">
        <?=Auth::csrfField()?><input type="hidden" name="tombola_id" value="<?=$t['id']?>">
        <button type="submit" name="delete_tombola" class="btn btn-sm" style="background:#fee2e2;color:#dc2626;border:1.5px solid #fecaca">🗑</button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>

<?php else: ?>
<!-- ── Édition ── -->
<div style="display:flex;gap:.35rem;margin-bottom:1.5rem;flex-wrap:wrap">
  <a href="<?=u('/admin/tombola?edit='.$editId)?>"                 class="btn <?=$tab!=='participants'?'btn-primary':'btn-ghost'?>">⚙️ Paramètres</a>
  <a href="<?=u('/admin/tombola?tab=participants&edit='.$editId)?>" class="btn <?=$tab==='participants'?'btn-primary':'btn-ghost'?>">
    👥 Participants
    <?php if($editId>0): $nb2=(int)Database::scalar("SELECT COUNT(*) FROM cc_tombola_participants WHERE tombola_id=?",[$editId]); ?>
    <span style="background:rgba(0,0,0,.15);border-radius:99px;padding:.05rem .4rem;font-size:.72rem;margin-left:.25rem"><?=$nb2?></span>
    <?php endif; ?>
  </a>
  <?php if($editId>0): ?>
  <a href="<?=u('/tombola/'.$editId)?>" target="_blank" class="btn btn-ghost">🎰 Voir →</a>
  <?php endif; ?>
  <a href="<?=u('/admin/tombola')?>" class="btn btn-ghost" style="margin-left:auto">← Retour</a>
</div>

<?php if($tab !== 'participants'): ?>
<!-- Paramètres -->
<div class="ac" style="max-width:680px">
  <div class="ac-header"><h2><?=$editId>0?'✏️ Modifier la tombola':'+ Nouvelle tombola'?></h2></div>
  <div class="ac-body">
    <form method="post" id="tombola-form">
      <?=Auth::csrfField()?>
      <!-- Infos de base -->
      <div class="fg"><label>Nom de la tombola *</label>
        <input type="text" name="name" class="input-std" required value="<?=Helpers::e($editTombola['name']??'')?>"></div>
      <div class="fg"><label>Description</label>
        <textarea name="description" class="input-std" rows="2"><?=Helpers::e($editTombola['description']??'')?></textarea></div>
      <div class="form-row">
        <div class="fg"><label>Statut</label>
          <select name="status" class="input-std">
            <option value="draft"  <?=($editTombola['status']??'')==='draft' ?'selected':''?>>📝 Brouillon</option>
            <option value="active" <?=($editTombola['status']??'')==='active'?'selected':''?>>✅ Active</option>
            <option value="closed" <?=($editTombola['status']??'')==='closed'?'selected':''?>>🔒 Inscriptions closes (tirage à venir)</option>
            <option value="done"   <?=($editTombola['status']??'')==='done'  ?'selected':''?>>🏆 Terminée</option>
          </select></div>
        <div class="fg"><label>Visibilité de la page</label>
          <select name="visibility" class="input-std">
            <option value="all"     <?=($editTombola['visibility']??'all')==='all'    ?'selected':''?>>🌐 Tout le monde</option>
            <option value="members" <?=($editTombola['visibility']??'')==='members'   ?'selected':''?>>🔒 Membres connectés uniquement</option>
          </select></div>
        <div class="fg"><label>Qui peut participer</label>
          <select name="participation" class="input-std">
            <option value="all"      <?=($editTombola['participation']??'all')==='all'     ?'selected':''?>>🌐 Tout le monde (nom + email suffisent)</option>
            <option value="members"  <?=($editTombola['participation']??'')==='members'    ?'selected':''?>>👤 Membres connectés uniquement</option>
            <option value="benevole" <?=($editTombola['participation']??'')==='benevole'   ?'selected':''?>>🤝 Bénévoles et plus</option>
            <option value="coach"    <?=($editTombola['participation']??'')==='coach'      ?'selected':''?>>🏅 Coachs et plus</option>
            <option value="admin"    <?=($editTombola['participation']??'')==='admin'      ?'selected':''?>>🔒 Admins uniquement</option>
          </select></div>
      </div>

      <!-- Participation -->
      <div style="border:1.5px solid #e2e8f0;border-radius:10px;padding:1rem;margin-bottom:1rem">
        <div style="font-weight:700;font-size:.82rem;text-transform:uppercase;letter-spacing:.05em;color:#64748b;margin-bottom:.875rem">🎟️ Participation</div>
        <div style="display:flex;flex-direction:column;gap:.75rem">
          <!-- Gratuit / Payant -->
          <div style="display:flex;gap:1rem">
            <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.875rem">
              <input type="radio" name="paid" value="0" id="paid-no" <?=!($editTombola['paid']??0)?'checked':''?> onchange="togglePaid(false)">
              <span>🆓 <strong>Gratuite</strong> — inscription libre</span>
            </label>
            <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.875rem">
              <input type="radio" name="paid" value="1" id="paid-yes" <?=($editTombola['paid']??0)?'checked':''?> onchange="togglePaid(true)">
              <span>💰 <strong>Payante</strong> — via la boutique</span>
            </label>
          </div>
          <!-- Options payant -->
          <div id="paid-opts" style="display:<?=($editTombola['paid']??0)?'flex':'none'?>;flex-direction:column;gap:.75rem;background:#f8fafc;border-radius:8px;padding:.875rem;border:1px solid #e2e8f0">
            <div class="form-row" style="margin-bottom:0">
              <div class="fg"><label>Prix du ticket (€)</label>
                <input type="number" name="price" class="input-std" min="0" step="0.01" value="<?=($editTombola['price']??'0.00')?>"></div>
              <div class="fg"><label>Produit boutique lié</label>
                <select name="product_id" class="input-std">
                  <option value="">— Sélectionner un produit —</option>
                  <?php foreach($allProducts as $p): ?>
                  <option value="<?=$p['id']?>" <?=($editTombola['product_id']??0)==$p['id']?'selected':''?>><?=Helpers::e($p['name'])?> (<?=Helpers::price($p['price'])?>)</option>
                  <?php endforeach; ?>
                </select>
                <small style="color:#64748b;font-size:.73rem">Créez d'abord un produit "Ticket tombola" dans la boutique</small>
              </div>
            </div>
          </div>
          <!-- Inscriptions multiples -->
          <label style="display:flex;align-items:flex-start;gap:.5rem;cursor:pointer;font-size:.875rem">
            <input type="checkbox" name="multi_entry" value="1" style="margin-top:2px" <?=($editTombola['multi_entry']??0)?'checked':''?>>
            <span><strong>Inscriptions multiples autorisées</strong><br>
            <span style="font-size:.78rem;color:#64748b">Si payant : chaque achat ajoute un ticket supplémentaire. Si gratuit : un membre peut s'inscrire plusieurs fois.</span></span>
          </label>
        </div>
      </div>

      <!-- Date de clôture & message -->
      <div class="form-row">
        <div class="fg"><label>Date de clôture des inscriptions (optionnel)</label>
          <input type="datetime-local" name="close_at" class="input-std"
            value="<?=$editTombola['close_at']?date('Y-m-d\TH:i',strtotime($editTombola['close_at'])):'';?>"></div>
        <div class="fg"><label>Message affiché aux visiteurs</label>
          <input type="text" name="msg_waiting" class="input-std"
            value="<?=Helpers::e($editTombola['msg_waiting']??'Le tirage aura lieu prochainement !')?>"
            placeholder="Inscrivez-vous avant le tirage !"></div>
      </div>

      <button type="submit" name="save_tombola" class="btn btn-primary">💾 Sauvegarder</button>
    </form>
  </div>
</div>
<script>
function togglePaid(on){
  document.getElementById('paid-opts').style.display=on?'flex':'none';
}
</script>

<?php else: ?>
<!-- ── Participants ── -->
<?php
$tombola     = Database::one("SELECT * FROM cc_tombola WHERE id=?",[$editId]);
$participants= Database::all("SELECT * FROM cc_tombola_participants WHERE tombola_id=? ORDER BY name",[$editId]);
$totalTickets= array_sum(array_column($participants,'tickets'));
$existingUids= array_column($participants,'user_id');
?>
<div style="display:grid;grid-template-columns:1fr 320px;gap:1.25rem;align-items:start">
  <div class="ac">
    <div class="ac-header"><h2>👥 Participants (<?=count($participants)?> · <?=$totalTickets?> ticket<?=$totalTickets>1?'s':''?>)</h2></div>
    <?php if(empty($participants)): ?>
    <div class="ac-body" style="text-align:center;color:#94a3b8;padding:2rem">Aucun participant.</div>
    <?php else: ?>
    <div class="at-wrap"><table class="at">
      <thead><tr><th>Nom</th><th>Email</th><th>Tickets</th><th>Source</th><th></th></tr></thead>
      <tbody>
      <?php foreach($participants as $p): ?>
      <tr>
        <td><strong><?=Helpers::e($p['name'])?></strong><?php if($p['user_id']): ?><span style="font-size:.7rem;color:#6366f1;margin-left:.35rem">membre</span><?php endif; ?></td>
        <td style="font-size:.78rem;color:#64748b"><?=Helpers::e($p['email']??'')?></td>
        <td>
          <form method="post" style="display:flex;align-items:center;gap:.25rem;margin:0">
            <?=Auth::csrfField()?>
            <input type="hidden" name="participant_id" value="<?=$p['id']?>">
            <input type="hidden" name="tombola_id" value="<?=$editId?>">
            <input type="number" name="tickets" value="<?=$p['tickets']?>" min="1" max="99"
              style="width:52px;border:1px solid #e2e8f0;border-radius:6px;padding:.2rem .4rem;font-size:.82rem;text-align:center">
            <button type="submit" name="update_tickets" class="btn btn-ghost btn-sm">✓</button>
          </form>
        </td>
        <td style="font-size:.75rem;color:#64748b"><?=$p['order_id']?'Boutique #'.$p['order_id']:'Manuel'?></td>
        <td>
          <form method="post" style="margin:0" onsubmit="return confirm('Retirer ce participant ?')">
            <?=Auth::csrfField()?>
            <input type="hidden" name="participant_id" value="<?=$p['id']?>">
            <input type="hidden" name="tombola_id" value="<?=$editId?>">
            <button type="submit" name="remove_participant" class="btn btn-sm" style="background:#fee2e2;color:#dc2626;border:1.5px solid #fecaca">✕</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
    <?php if($tombola['winner_name']??false): ?>
    <div style="background:#f0fdf4;border-top:1px solid #bbf7d0;padding:1rem;display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap">
      <div><strong style="color:#166534">🏆 <?=Helpers::e($tombola['winner_name'])?></strong>
        <span style="color:#64748b;font-size:.78rem;margin-left:.5rem">le <?=(new DateTime($tombola['drawn_at']))->format('d/m/Y à H:i')?></span></div>
      <form method="post" style="margin:0">
        <?=Auth::csrfField()?><input type="hidden" name="tombola_id" value="<?=$editId?>">
        <button type="submit" name="reset_draw" class="btn btn-ghost btn-sm" onclick="return confirm('Réinitialiser le tirage ?')">🔄 Réinitialiser</button>
      </form>
    </div>
    <?php endif; ?>
  </div>

  <div style="display:flex;flex-direction:column;gap:1rem">
    <!-- Import membres -->
    <div class="ac">
      <div class="ac-header"><h2>👤 Importer des membres</h2></div>
      <div class="ac-body">
        <form method="post">
          <?=Auth::csrfField()?>
          <input type="hidden" name="tombola_id" value="<?=$editId?>">
          <div style="max-height:200px;overflow-y:auto;border:1.5px solid #e2e8f0;border-radius:8px;padding:.5rem;margin-bottom:.75rem">
            <?php foreach($allMembers as $m):
              $already = in_array($m['id'], $existingUids) && !($tombola['multi_entry']??0);
            ?>
            <label style="display:flex;align-items:center;gap:.5rem;padding:.25rem .3rem;font-size:.82rem;cursor:<?=$already?'default':'pointer'?>;opacity:<?=$already?.5:1?>">
              <input type="checkbox" name="member_ids[]" value="<?=$m['id']?>" <?=$already?'disabled':''?>>
              <?=Helpers::e($m['firstname'].' '.$m['lastname'])?>
            </label>
            <?php endforeach; ?>
          </div>
          <button type="submit" name="import_members" class="btn btn-primary btn-sm" style="width:100%">⬇️ Importer</button>
        </form>
      </div>
    </div>
    <!-- Ajout manuel -->
    <div class="ac">
      <div class="ac-header"><h2>✍️ Ajouter manuellement</h2></div>
      <div class="ac-body">
        <form method="post">
          <?=Auth::csrfField()?>
          <input type="hidden" name="tombola_id" value="<?=$editId?>">
          <div class="fg"><label>Nom *</label><input type="text" name="pname" class="input-std" required placeholder="Prénom Nom"></div>
          <div class="fg"><label>Email</label><input type="email" name="pemail" class="input-std" placeholder="email@..."></div>
          <div class="fg"><label>Tickets</label><input type="number" name="tickets" class="input-std" value="1" min="1" max="99"></div>
          <button type="submit" name="add_participant" class="btn btn-primary btn-sm" style="width:100%">+ Ajouter</button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php
$content = ob_get_clean();
include CC_ROOT . '/admin/layout.php';
