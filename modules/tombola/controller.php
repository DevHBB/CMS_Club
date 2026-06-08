<?php
/**
 * ClubCMS — Page Tombola
 */

// Migrations
try { Database::run("CREATE TABLE IF NOT EXISTS cc_tombola (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(200) NOT NULL, description TEXT, status ENUM('draft','active','closed','done') DEFAULT 'draft', paid TINYINT(1) DEFAULT 0, price DECIMAL(10,2) DEFAULT 0.00, product_id INT DEFAULT NULL, multi_entry TINYINT(1) DEFAULT 0, visibility ENUM('all','members') DEFAULT 'all', close_at DATETIME DEFAULT NULL, msg_waiting VARCHAR(500) DEFAULT 'Le tirage aura lieu prochainement !', winner_id INT DEFAULT NULL, winner_name VARCHAR(200) DEFAULT NULL, drawn_at DATETIME DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)"); } catch(Exception $e) {}
try { Database::run("CREATE TABLE IF NOT EXISTS cc_tombola_participants (id INT AUTO_INCREMENT PRIMARY KEY, tombola_id INT NOT NULL, user_id INT DEFAULT NULL, name VARCHAR(200) NOT NULL, email VARCHAR(200) DEFAULT NULL, tickets INT DEFAULT 1, order_id INT DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)"); } catch(Exception $e) {}
try { Database::run("ALTER TABLE cc_tombola ADD COLUMN IF NOT EXISTS paid TINYINT(1) DEFAULT 0"); } catch(Exception $e) {}
try { Database::run("ALTER TABLE cc_tombola ADD COLUMN IF NOT EXISTS price DECIMAL(10,2) DEFAULT 0.00"); } catch(Exception $e) {}
try { Database::run("ALTER TABLE cc_tombola ADD COLUMN IF NOT EXISTS product_id INT DEFAULT NULL"); } catch(Exception $e) {}
try { Database::run("ALTER TABLE cc_tombola ADD COLUMN IF NOT EXISTS multi_entry TINYINT(1) DEFAULT 0"); } catch(Exception $e) {}
try { Database::run("ALTER TABLE cc_tombola ADD COLUMN IF NOT EXISTS visibility ENUM('all','members') DEFAULT 'all'"); } catch(Exception $e) {}
try { Database::run("ALTER TABLE cc_tombola ADD COLUMN IF NOT EXISTS close_at DATETIME DEFAULT NULL"); } catch(Exception $e) {}
try { Database::run("ALTER TABLE cc_tombola ADD COLUMN IF NOT EXISTS msg_waiting VARCHAR(500) DEFAULT 'Le tirage aura lieu prochainement !'"); } catch(Exception $e) {}
try { Database::run("ALTER TABLE cc_tombola_participants ADD COLUMN IF NOT EXISTS order_id INT DEFAULT NULL"); } catch(Exception $e) {}
try { Database::run("ALTER TABLE cc_tombola ADD COLUMN IF NOT EXISTS guest_fields JSON DEFAULT NULL"); } catch(Exception $e) {}

$isAdmin   = Auth::isAdmin();
$isLogged  = Auth::check();
$userId    = Auth::id();

// ── Inscription gratuite ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['join_tombola']) && Auth::verifyCsrf()) {
    $tid = (int)($_POST['tombola_id'] ?? 0);
    $t   = Database::one("SELECT * FROM cc_tombola WHERE id=? AND status IN ('active','closed')", [$tid]);
    if ($t && !$t['paid'] && $t['status'] !== 'closed') {
        $partRestricted = ($t['participation'] ?? 'all') === 'members';
        $closeAt = $t['close_at'] ? strtotime($t['close_at']) : null;
        $isClosed2 = $closeAt && $closeAt < time();
        if (!$isClosed2) {
            if ($isLogged) {
                // Membre connecté
                if (!$partRestricted) {
                    $user = Auth::user();
                    $exists = !$t['multi_entry']
                        ? Database::scalar("SELECT id FROM cc_tombola_participants WHERE tombola_id=? AND user_id=?", [$tid, $userId])
                        : false;
                    if (!$exists) {
                        Database::run("INSERT INTO cc_tombola_participants (tombola_id,user_id,name,email) VALUES (?,?,?,?)",
                            [$tid, $userId, $user['firstname'].' '.$user['lastname'], $user['email']]);
                    } elseif ($t['multi_entry']) {
                        Database::run("INSERT INTO cc_tombola_participants (tombola_id,user_id,name,email) VALUES (?,?,?,?)",
                            [$tid, $userId, $user['firstname'].' '.$user['lastname'], $user['email']]);
                    }
                }
            } elseif (!$partRestricted) {
                // Visiteur non connecté + participation ouverte à tous
                $guestName  = Helpers::sanitize($_POST['guest_name'] ?? '');
                $guestEmail = Helpers::sanitize($_POST['guest_email'] ?? '');
                if ($guestName) {
                    // Sauvegarder les champs custom
                    $guestFields = json_decode($t['guest_fields']??'[]',true) ?: [];
                    $extraData   = [];
                    $missingRequired = false;
                    foreach ($guestFields as $gf) {
                        $key = 'gf_'.preg_replace('/[^a-z0-9]/', '_', strtolower($gf['label']));
                        $val = Helpers::sanitize($_POST[$key] ?? '');
                        if (!empty($gf['required']) && $val === '') { $missingRequired = true; break; }
                        $extraData[$gf['label']] = $val;
                    }
                    if (!$missingRequired) {
                        $extraJson = !empty($extraData) ? json_encode($extraData, JSON_UNESCAPED_UNICODE) : null;
                        Database::run(
                            "INSERT INTO cc_tombola_participants (tombola_id,name,email,tickets,extra_data) VALUES (?,?,?,1,?)",
                            [$tid, $guestName, $guestEmail, $extraJson]
                        );
                    }
                }
            }
        }
    }
    Helpers::redirect(u('/tombola/' . $tid));
}

// ── Tirage AJAX (admin only) ──────────────────────────────────
if (isset($_POST['draw'])) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    if (!$isAdmin)          { echo json_encode(['error' => 'Accès refusé']); exit; }
    if (!Auth::verifyCsrf()){ echo json_encode(['error' => 'Token invalide, rechargez la page']); exit; }
    $tid = (int)($_POST['tombola_id'] ?? 0);
    $t   = Database::one("SELECT * FROM cc_tombola WHERE id=? AND status IN ('active','closed')", [$tid]);
    if (!$t) { echo json_encode(['error' => 'Tombola introuvable ou pas active']); exit; }
    $parts = Database::all("SELECT * FROM cc_tombola_participants WHERE tombola_id=?", [$tid]);
    $pool  = [];
    foreach ($parts as $p) {
        for ($i = 0; $i < max(1, (int)$p['tickets']); $i++) $pool[] = $p;
    }
    if (empty($pool)) { echo json_encode(['error' => 'Aucun participant']); exit; }
    $winner = $pool[array_rand($pool)];
    Database::run("UPDATE cc_tombola SET winner_id=?,winner_name=?,drawn_at=NOW(),status='done' WHERE id=?",
        [$winner['user_id'], $winner['name'], $tid]);
    if (!empty($winner['email'])) {
        try {
            Mailer::send($winner['email'], $winner['name'],
                '🎉 Vous avez gagné la tombola — ' . $t['name'],
                '<h2>🎉 Félicitations ' . htmlspecialchars($winner['name']) . ' !</h2><p>Vous avez remporté la tombola <strong>' . htmlspecialchars($t['name']) . '</strong>. Contactez-nous pour récupérer votre lot !</p>'
            );
        } catch(Exception $e) {}
    }
    echo json_encode(['winner' => $winner['name']]);
    exit;
}

// ── Charger la tombola ────────────────────────────────────────
$tombolaId = (int)($segments[1] ?? 0);
$tombola   = $tombolaId
    ? Database::one("SELECT * FROM cc_tombola WHERE id=?", [$tombolaId])
    : Database::one("SELECT * FROM cc_tombola WHERE status IN ('active','closed','done') ORDER BY created_at DESC LIMIT 1");

// Vérifier visibilité
if ($tombola && $tombola['visibility'] === 'members' && !$isLogged && !$isAdmin) {
    Helpers::redirect(u('/login'));
}

$tombolas = Database::all("SELECT * FROM cc_tombola WHERE status IN ('active','closed','done') ORDER BY created_at DESC");
$parts    = $tombola ? Database::all("SELECT name FROM cc_tombola_participants WHERE tombola_id=?", [$tombola['id']]) : [];
$names    = array_column($parts, 'name');
$isDone   = ($tombola['status'] ?? '') === 'done';
$isClosed_status = ($tombola['status'] ?? '') === 'closed';
$winner   = $tombola['winner_name'] ?? null;
$isClosed = $tombola && $tombola['close_at'] && strtotime($tombola['close_at']) < time();

// Participation du membre connecté
$userTickets  = 0;
$userJoined   = false;
if ($isLogged && $tombola) {
    $userTickets = (int)Database::scalar("SELECT COALESCE(SUM(tickets),0) FROM cc_tombola_participants WHERE tombola_id=? AND user_id=?", [$tombola['id'], $userId]);
    $userJoined  = $userTickets > 0;
}

// Produit boutique lié
$linkedProduct = ($tombola && $tombola['product_id'])
    ? Database::one("SELECT id,name,price,slug FROM cc_shop_products WHERE id=?", [$tombola['product_id']])
    : null;

$pageTitle = '🎰 ' . ($tombola ? Helpers::e($tombola['name']) : 'Tombola');
ob_start();
?>
<style>
.tb-page{min-height:100vh;background:radial-gradient(ellipse at 50% 0%,#1a0533 0%,#0d0117 60%);display:flex;flex-direction:column;align-items:center;justify-content:center;padding:2rem;position:relative;overflow:hidden;isolation:isolate}
.tb-stars{position:absolute;inset:0;pointer-events:none;z-index:0;pointer-events:none;background-image:radial-gradient(1px 1px at 20% 30%,#fff,transparent),radial-gradient(1px 1px at 60% 15%,#fff,transparent),radial-gradient(1.5px 1.5px at 80% 50%,rgba(255,255,255,.8),transparent),radial-gradient(1px 1px at 40% 70%,rgba(255,255,255,.6),transparent),radial-gradient(1px 1px at 10% 80%,rgba(255,255,255,.5),transparent),radial-gradient(1px 1px at 90% 25%,rgba(255,255,255,.7),transparent);animation:twinkle 4s ease-in-out infinite alternate}
@keyframes twinkle{from{opacity:.6}to{opacity:1}}
.tb-title{font-family:'Georgia',serif;font-size:clamp(1.75rem,5vw,3.5rem);font-weight:700;color:#ffd700;text-align:center;text-shadow:0 0 40px rgba(255,215,0,.6);margin-bottom:.4rem;letter-spacing:.02em}
.tb-subtitle{color:rgba(255,255,255,.55);font-size:.95rem;text-align:center;margin-bottom:1.75rem;max-width:500px}
.wheel-wrap{position:relative;z-index:5;width:min(380px,85vw);height:min(380px,85vw);margin:0 auto 1.75rem}
.wheel-canvas{width:100%;height:100%;border-radius:50%;box-shadow:0 0 60px rgba(255,215,0,.3),0 0 120px rgba(255,215,0,.1)}
.wheel-pointer{position:absolute;top:-16px;left:50%;transform:translateX(-50%);width:0;height:0;border-left:13px solid transparent;border-right:13px solid transparent;border-top:30px solid #ffd700;filter:drop-shadow(0 3px 6px rgba(255,215,0,.5));z-index:10}
.wheel-center{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:48px;height:48px;border-radius:50%;background:radial-gradient(circle at 35% 35%,#fff8d0,#ffd700,#b8860b);box-shadow:0 4px 12px rgba(0,0,0,.5);z-index:10;display:flex;align-items:center;justify-content:center;font-size:1.3rem}
.tb-draw-btn{position:relative;z-index:10;background:linear-gradient(135deg,#ffd700,#ff8c00);color:#1a0533;border:none;border-radius:99px;padding:.9rem 2.5rem;font-size:1.1rem;font-weight:800;cursor:pointer;font-family:inherit;box-shadow:0 4px 24px rgba(255,140,0,.5);transition:transform .15s,box-shadow .15s;letter-spacing:.02em;margin-bottom:1rem}
.tb-draw-btn:hover{transform:translateY(-2px) scale(1.03);box-shadow:0 8px 32px rgba(255,140,0,.6)}
.tb-draw-btn:disabled{opacity:.5;cursor:not-allowed;transform:none}
.tb-action-box{position:relative;z-index:10;background:rgba(255,255,255,.06);border:1.5px solid rgba(255,255,255,.12);border-radius:16px;padding:1.25rem 1.5rem;text-align:center;max-width:440px;width:100%;margin-bottom:1rem}
.tb-action-box.member-only{border-color:rgba(255,215,0,.2);background:rgba(255,215,0,.04)}
.tb-cta{position:relative;z-index:10;display:inline-block;background:linear-gradient(135deg,#ffd700,#ff8c00);color:#1a0533;border:none;border-radius:99px;padding:.7rem 2rem;font-size:.95rem;font-weight:700;cursor:pointer;font-family:inherit;text-decoration:none;transition:transform .15s;margin-top:.75rem}
.tb-cta:hover{transform:translateY(-2px)}
.tb-badge{display:inline-flex;align-items:center;gap:.35rem;background:rgba(255,215,0,.15);border:1px solid rgba(255,215,0,.3);color:#ffd700;border-radius:99px;padding:.3rem .875rem;font-size:.82rem;font-weight:600}
.tb-names-ticker{height:28px;overflow:hidden;font-family:monospace;font-size:.9rem;color:rgba(255,255,255,.45);text-align:center;margin-bottom:1rem}
.tb-winner-card{display:none;background:linear-gradient(135deg,rgba(255,215,0,.15),rgba(255,140,0,.08));border:2px solid rgba(255,215,0,.5);border-radius:20px;padding:1.75rem 2rem;text-align:center;max-width:440px;margin:.75rem auto 0;animation:winReveal .6s cubic-bezier(.34,1.56,.64,1)}
@keyframes winReveal{from{transform:scale(.5);opacity:0}to{transform:scale(1);opacity:1}}
.tb-winner-name{font-size:clamp(1.4rem,4vw,2.25rem);font-weight:800;color:#ffd700;margin:.4rem 0;text-shadow:0 0 30px rgba(255,215,0,.8)}
.tb-selector{display:flex;gap:.5rem;flex-wrap:wrap;justify-content:center;margin-bottom:1.5rem}
.tb-sel-btn{background:rgba(255,255,255,.07);border:1.5px solid rgba(255,255,255,.12);color:rgba(255,255,255,.65);border-radius:99px;padding:.35rem .9rem;font-size:.8rem;cursor:pointer;text-decoration:none;transition:all .2s}
.tb-sel-btn.active,.tb-sel-btn:hover{background:rgba(255,215,0,.12);border-color:rgba(255,215,0,.4);color:#ffd700}
.tb-countdown{display:flex;gap:.75rem;justify-content:center;margin:.75rem 0}
.tb-cd-box{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:10px;padding:.5rem .75rem;text-align:center;min-width:56px}
.tb-cd-num{font-size:1.5rem;font-weight:800;color:#ffd700;line-height:1}
.tb-cd-lbl{font-size:.62rem;color:rgba(255,255,255,.4);text-transform:uppercase;letter-spacing:.06em}
.confetto{position:fixed;pointer-events:none;z-index:9999;border-radius:2px;}
@keyframes confettoFallLeft{0%{transform:translateY(-20px) rotate(0deg) scaleX(1);opacity:1}50%{scaleX:-1}100%{transform:translateY(105vh) translateX(-120px) rotate(-540deg) scaleX(-1);opacity:0}}
@keyframes confettoFallRight{0%{transform:translateY(-20px) rotate(0deg) scaleX(1);opacity:1}50%{scaleX:-1}100%{transform:translateY(105vh) translateX(120px) rotate(540deg) scaleX(-1);opacity:0}}
@keyframes confettoFallStraight{0%{transform:translateY(-20px) rotate(0deg);opacity:1}30%{transform:translateY(30vh) translateX(40px) rotate(200deg)}60%{transform:translateY(60vh) translateX(-30px) rotate(420deg)}100%{transform:translateY(105vh) translateX(20px) rotate(660deg);opacity:0}}
@keyframes confettoFallWiggle{0%{transform:translateY(-20px) rotate(0deg);opacity:1}25%{transform:translateY(25vh) translateX(-60px) rotate(180deg)}50%{transform:translateY(50vh) translateX(50px) rotate(360deg)}75%{transform:translateY(75vh) translateX(-40px) rotate(540deg)}100%{transform:translateY(105vh) translateX(30px) rotate(720deg);opacity:0}}
</style>

<div class="tb-page">
  <div class="tb-stars"></div>
  <div style="position:relative;z-index:1;width:100%;display:flex;flex-direction:column;align-items:center">

  <?php if(count($tombolas) > 1): ?>
  <div class="tb-selector">
    <?php foreach($tombolas as $tb): ?>
    <a href="<?=u('/tombola/'.$tb['id'])?>" class="tb-sel-btn <?=($tombola&&$tombola['id']==$tb['id'])?'active':''?>"><?=Helpers::e($tb['name'])?></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if(!$tombola): ?>
  <div style="text-align:center;color:rgba(255,255,255,.4)">
    <div style="font-size:4rem;margin-bottom:1rem">🎰</div>
    <p>Aucune tombola active pour le moment.</p>
    <?php if($isAdmin): ?><a href="<?=u('/admin/tombola')?>" class="tb-sel-btn">Gérer les tombolas →</a><?php endif; ?>
  </div>

  <?php else: ?>
  <h1 class="tb-title">🎰 <?=Helpers::e($tombola['name'])?></h1>
  <?php if($tombola['description']): ?><p class="tb-subtitle"><?=Helpers::e($tombola['description'])?></p><?php endif; ?>
  <?php if($isClosed_status): ?>
  <div style="background:rgba(245,158,11,.15);border:1.5px solid rgba(245,158,11,.4);border-radius:99px;padding:.4rem 1.25rem;color:#fbbf24;font-size:.82rem;font-weight:700;margin-bottom:1rem;position:relative;z-index:10">
    🔒 Inscriptions closes — tirage à venir
  </div>
  <?php endif; ?>

  <?php if($isDone && $winner): ?>
  <!-- Tombola terminée -->
  <div class="tb-winner-card" style="display:block">
    <div style="font-size:2.5rem;margin-bottom:.5rem">🏆</div>
    <div style="color:rgba(255,255,255,.6);font-size:.82rem">GAGNANT</div>
    <div class="tb-winner-name"><?=Helpers::e($winner)?></div>
    <div style="color:rgba(255,255,255,.35);font-size:.75rem;margin-top:.4rem">Tiré le <?=(new DateTime($tombola['drawn_at']))->format('d/m/Y à H:i')?></div>
  </div>

  <?php elseif(empty($names)): ?>
  <div style="text-align:center;color:rgba(255,255,255,.35)"><p>Aucun participant encore.</p></div>

  <?php else: ?>
  <!-- Roue -->
  <div class="wheel-wrap">
    <div class="wheel-pointer"></div>
    <canvas id="wheel-canvas" class="wheel-canvas" width="380" height="380"></canvas>
    <div class="wheel-center">🎰</div>
  </div>
  <div class="tb-names-ticker" id="names-ticker"></div>

  <!-- Bouton tirage (admin uniquement) -->
  <?php if($isAdmin): ?>
  <button id="draw-btn" class="tb-draw-btn" onclick="startDraw()">🎰 Lancer le tirage !</button>
  <?php endif; ?>

  <!-- Zone action visiteurs/membres -->
  <?php if(!$isAdmin): ?>
  <?php
  // Vérifier si la participation est restreinte aux membres
  $partRestricted = ($tombola['participation'] ?? 'all') === 'members';
  $canParticipate = !$partRestricted || $isLogged;
  ?>
  <div class="tb-action-box">
    <div style="color:rgba(255,255,255,.7);font-size:.9rem;margin-bottom:.75rem">
      <?=Helpers::e($tombola['msg_waiting'] ?? 'Le tirage aura lieu prochainement !')?>
    </div>

    <?php if($tombola['close_at'] && !$isClosed): ?>
    <div id="tb-countdown" class="tb-countdown" data-end="<?=strtotime($tombola['close_at'])?>">
      <div class="tb-cd-box"><div class="tb-cd-num" id="cd-d">--</div><div class="tb-cd-lbl">Jours</div></div>
      <div class="tb-cd-box"><div class="tb-cd-num" id="cd-h">--</div><div class="tb-cd-lbl">Heures</div></div>
      <div class="tb-cd-box"><div class="tb-cd-num" id="cd-m">--</div><div class="tb-cd-lbl">Min</div></div>
      <div class="tb-cd-box"><div class="tb-cd-num" id="cd-s">--</div><div class="tb-cd-lbl">Sec</div></div>
    </div>
    <?php elseif($isClosed): ?>
    <div style="color:#f59e0b;font-size:.85rem;margin:.5rem 0">⏰ Les inscriptions sont closes.</div>
    <?php endif; ?>

    <?php if($userJoined): ?>
    <div class="tb-badge" style="margin:.75rem 0">✅ Vous participez (<?=$userTickets?> ticket<?=$userTickets>1?'s':''?>)</div>
    <?php if($tombola['multi_entry'] && !$isClosed): ?>
    <div style="color:rgba(255,255,255,.4);font-size:.72rem;margin-bottom:.5rem">Vous pouvez prendre des tickets supplémentaires</div>
    <?php endif; ?>
    <?php endif; ?>

    <?php if(!$isClosed && (!$userJoined || $tombola['multi_entry'])): ?>
      <?php if(!$canParticipate): ?>
      <!-- Participation réservée aux membres -->
      <div style="color:rgba(255,255,255,.5);font-size:.85rem;margin-bottom:.75rem">🔒 Réservé aux membres du club</div>
      <a href="<?=u('/login')?>" class="tb-cta" style="margin-right:.5rem">Se connecter</a>
      <a href="<?=u('/register')?>" class="tb-cta" style="background:rgba(255,255,255,.1);color:#fff">S'inscrire</a>

      <?php elseif($tombola['paid'] && $linkedProduct): ?>
      <!-- Payant avec produit boutique -->
      <a href="<?=u('/boutique/produit/'.$linkedProduct['slug'])?>" class="tb-cta">
        🎟️ Acheter un ticket — <?=Helpers::price($tombola['price'] ?: $linkedProduct['price'])?>
      </a>
      <div style="color:rgba(255,255,255,.3);font-size:.72rem;margin-top:.5rem">Inscription automatique après l'achat</div>

      <?php elseif($isLogged): ?>
      <!-- Connecté → inscription directe -->
      <form method="post">
        <?=Auth::csrfField()?>
        <input type="hidden" name="tombola_id" value="<?=$tombola['id']?>">
        <button type="submit" name="join_tombola" class="tb-cta">🎟️ <?=($tombola['paid']&&!$linkedProduct)?'Participer (contacter admin pour paiement)':'Participer gratuitement'?></button>
      </form>

      <?php else: ?>
      <!-- Non connecté → popup inscription -->
      <?php $guestFields = json_decode($tombola['guest_fields']??'[]', true) ?: []; ?>
      <button type="button" class="tb-cta" onclick="openJoinPopup()" style="position:relative;z-index:10">🎟️ Participer gratuitement</button>
      <?php endif; ?>

      <!-- ── Popup inscription visiteur ── -->
      <div id="join-popup" style="display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.75);backdrop-filter:blur(6px);align-items:center;justify-content:center;padding:1rem">
        <div style="background:#1a0533;border:1.5px solid rgba(255,215,0,.3);border-radius:20px;padding:2rem;width:100%;max-width:420px;position:relative;box-shadow:0 20px 60px rgba(0,0,0,.6)">
          <button onclick="closeJoinPopup()" style="position:absolute;top:1rem;right:1rem;background:rgba(255,255,255,.1);border:none;border-radius:50%;width:32px;height:32px;cursor:pointer;color:#fff;font-size:1rem;display:flex;align-items:center;justify-content:center">✕</button>
          <div style="text-align:center;margin-bottom:1.5rem">
            <div style="font-size:2rem;margin-bottom:.35rem">🎟️</div>
            <h3 style="color:#ffd700;font-family:Georgia,serif;font-size:1.3rem;margin:0 0 .35rem">Participer à la tombola</h3>
            <p style="color:rgba(255,255,255,.5);font-size:.82rem;margin:0"><?=Helpers::e($tombola['name'])?></p>
          </div>
          <form method="post" id="join-form">
            <?=Auth::csrfField()?>
            <input type="hidden" name="tombola_id" value="<?=$tombola['id']?>">
            <input type="hidden" name="join_tombola" value="1">
            <!-- Nom -->
            <div style="margin-bottom:.75rem">
              <label style="display:block;font-size:.78rem;font-weight:600;color:rgba(255,215,0,.8);margin-bottom:.3rem">Votre nom *</label>
              <input type="text" name="guest_name" required placeholder="Prénom Nom"
                style="width:100%;background:rgba(255,255,255,.07);border:1.5px solid rgba(255,255,255,.15);border-radius:8px;padding:.65rem .875rem;color:#fff;font-family:inherit;font-size:.875rem;box-sizing:border-box"
                onfocus="this.style.borderColor='rgba(255,215,0,.5)'" onblur="this.style.borderColor='rgba(255,255,255,.15)'">
            </div>
            <!-- Email -->
            <div style="margin-bottom:.75rem">
              <label style="display:block;font-size:.78rem;font-weight:600;color:rgba(255,215,0,.8);margin-bottom:.3rem">Votre email</label>
              <input type="email" name="guest_email" placeholder="exemple@email.fr"
                style="width:100%;background:rgba(255,255,255,.07);border:1.5px solid rgba(255,255,255,.15);border-radius:8px;padding:.65rem .875rem;color:#fff;font-family:inherit;font-size:.875rem;box-sizing:border-box"
                onfocus="this.style.borderColor='rgba(255,215,0,.5)'" onblur="this.style.borderColor='rgba(255,255,255,.15)'">
            </div>
            <!-- Champs custom -->
            <?php foreach($guestFields as $gf):
              $key = 'gf_'.preg_replace('/[^a-z0-9]/', '_', strtolower($gf['label']));
            ?>
            <div style="margin-bottom:.75rem">
              <label style="display:block;font-size:.78rem;font-weight:600;color:rgba(255,215,0,.8);margin-bottom:.3rem">
                <?=Helpers::e($gf['label'])?> <?=!empty($gf['required'])?'*':''?>
              </label>
              <input type="text" name="<?=Helpers::e($key)?>"
                <?=!empty($gf['required'])?'required':''?>
                placeholder="<?=Helpers::e($gf['label'])?>"
                style="width:100%;background:rgba(255,255,255,.07);border:1.5px solid rgba(255,255,255,.15);border-radius:8px;padding:.65rem .875rem;color:#fff;font-family:inherit;font-size:.875rem;box-sizing:border-box"
                onfocus="this.style.borderColor='rgba(255,215,0,.5)'" onblur="this.style.borderColor='rgba(255,255,255,.15)'">
            </div>
            <?php endforeach; ?>
            <button type="submit" style="width:100%;background:linear-gradient(135deg,#ffd700,#ff8c00);color:#1a0533;border:none;border-radius:99px;padding:.85rem;font-size:1rem;font-weight:800;cursor:pointer;font-family:inherit;margin-top:.5rem;position:relative;z-index:10">
              🎟️ Confirmer ma participation
            </button>
            <p style="color:rgba(255,255,255,.3);font-size:.72rem;text-align:center;margin-top:.75rem">
              Ou <a href="<?=u('/login')?>" style="color:rgba(255,215,0,.5)">connectez-vous</a> pour participer avec votre compte
            </p>
          </form>
        </div>
      </div>
      <script>
      function openJoinPopup(){document.getElementById('join-popup').style.display='flex';}
      function closeJoinPopup(){document.getElementById('join-popup').style.display='none';}
      document.getElementById('join-popup').addEventListener('click',function(e){if(e.target===this)closeJoinPopup();});
      </script>
    <?php endif; ?>
  </div>
  <?php endif; // !isAdmin ?>

  <div id="winner-card" class="tb-winner-card">
    <div style="font-size:2.5rem;margin-bottom:.5rem">🏆</div>
    <div style="color:rgba(255,255,255,.65);font-size:.82rem">ET LE GAGNANT EST...</div>
    <div class="tb-winner-name" id="winner-name"></div>
    <div style="color:rgba(255,215,0,.6);font-size:.9rem;margin-top:.5rem">🎉 Félicitations !</div>
  </div>
  <?php endif; ?>
  <?php endif; ?>
  </div><!-- fin wrapper z-index:1 -->
</div>

<script>
var NAMES      = <?=json_encode($names, JSON_UNESCAPED_UNICODE)?>;
var TOMBOLA_ID = <?=(int)($tombola['id']??0)?>;
var DRAW_URL   = "<?=u('/tombola')?>";
var COLORS     = ['#6366f1','#ec4899','#f59e0b','#10b981','#3b82f6','#ef4444','#8b5cf6','#06b6d4','#84cc16','#f97316'];
var canvas, ctx, angle = 0, spinning = false;

function initWheel() {
  canvas = document.getElementById('wheel-canvas');
  if (!canvas || !NAMES.length) return;
  ctx = canvas.getContext('2d');
  drawWheel(angle);
}

function drawWheel(rot) {
  if (!canvas) return;
  var n=NAMES.length, arc=(Math.PI*2)/n, cx=canvas.width/2, cy=canvas.height/2, r=cx-4;
  ctx.clearRect(0,0,canvas.width,canvas.height);
  for (var i=0;i<n;i++) {
    var s=rot+i*arc, e=s+arc;
    ctx.beginPath(); ctx.moveTo(cx,cy); ctx.arc(cx,cy,r,s,e); ctx.closePath();
    ctx.fillStyle=COLORS[i%COLORS.length]; ctx.fill();
    ctx.strokeStyle='rgba(255,255,255,.2)'; ctx.lineWidth=1.5; ctx.stroke();
    ctx.save(); ctx.translate(cx,cy); ctx.rotate(s+arc/2); ctx.textAlign='right';
    ctx.fillStyle='#fff'; ctx.font='bold '+Math.max(10,Math.min(15,Math.floor(260/n)))+'px Georgia,serif';
    ctx.shadowColor='rgba(0,0,0,.5)'; ctx.shadowBlur=3;
    var lbl=NAMES[i].length>13?NAMES[i].substring(0,12)+'…':NAMES[i];
    ctx.fillText(lbl,r-10,5); ctx.restore();
  }
  ctx.beginPath(); ctx.arc(cx,cy,r,0,Math.PI*2); ctx.strokeStyle='#ffd700'; ctx.lineWidth=5; ctx.stroke();
}

// Calcule l'angle final pour que la flèche pointe sur le nom donné
function angleForName(winnerName) {
  var idx = NAMES.indexOf(winnerName);
  if (idx === -1) idx = 0;
  var n   = NAMES.length;
  var arc = (Math.PI * 2) / n;
  // Le centre du secteur gagnant doit être en haut (angle -Math.PI/2)
  // La flèche est en haut → on veut que le secteur idx soit pointé vers le haut
  // Secteur i commence à: angle + i*arc
  // Centre secteur i: angle + i*arc + arc/2
  // On veut: angle + idx*arc + arc/2 = -Math.PI/2 (mod 2PI)
  // → angle = -Math.PI/2 - idx*arc - arc/2
  var target = -Math.PI/2 - idx * arc - arc/2;
  // Normaliser entre 0 et 2PI
  return ((target % (Math.PI*2)) + Math.PI*2) % (Math.PI*2);
}

function startDraw() {
  if (spinning) return;
  if (!NAMES.length) { alert('❌ Aucun participant.'); return; }
  spinning = true;
  var btn = document.getElementById('draw-btn');
  if (btn) btn.disabled = true;

  // 1. Tirer au sort côté serveur EN PREMIER
  var fd = new FormData();
  fd.append('draw','1');
  fd.append('tombola_id', TOMBOLA_ID);
  fd.append('csrf_token', '<?=Auth::getCsrfToken()?>');

  fetch(DRAW_URL, {method:'POST', body:fd})
    .then(function(r){
      return r.json().catch(function(){
        return r.clone().text().then(function(t){
          throw new Error('Réponse invalide: ' + t.substring(0,200));
        });
      });
    })
    .then(function(d) {
      if (d.error) {
        alert('❌ ' + d.error);
        spinning = false;
        if (btn) btn.disabled = false;
        return;
      }
      // 2. Calculer l'angle final pour pointer sur le gagnant
      var winnerName  = d.winner;
      var targetAngle = angleForName(winnerName);
      // Ajouter des tours complets pour l'effet visuel (8-12 tours)
      var extraTurns  = (8 + Math.floor(Math.random() * 4)) * Math.PI * 2;
      var finalAngle  = extraTurns + targetAngle;

      // 3. Lancer l'animation
      playDrumroll();
      var ticker = startTicker();
      var dur    = 5000 + Math.random() * 2000;
      var t0     = performance.now();
      var a0     = angle % (Math.PI * 2); // angle actuel normalisé

      function anim(now) {
        var p    = Math.min((now - t0) / dur, 1);
        var ease = 1 - Math.pow(1 - p, 4); // ease out quart
        angle    = a0 + finalAngle * ease;
        drawWheel(angle);
        if (p < 1) {
          requestAnimationFrame(anim);
        } else {
          // Animation terminée → la roue pointe exactement sur le gagnant
          clearInterval(ticker);
          document.getElementById('names-ticker').textContent = '';
          stopDrumroll();
          playWinnerSound();
          setTimeout(function() { showWinner(winnerName); }, 300);
        }
      }
      requestAnimationFrame(anim);
    })
    .catch(function(e) {
      alert('Erreur : ' + e.message);
      spinning = false;
      if (btn) btn.disabled = false;
    });
}

function showWinner(name) {
  document.getElementById('winner-name').textContent = name;
  document.getElementById('winner-card').style.display = 'block';
  launchFireworks();
}

function startTicker(){
  var i=0, el=document.getElementById('names-ticker');
  return setInterval(function(){ if(el) el.textContent=NAMES[i++%NAMES.length]; }, 80);
}

// Sons
var audioCtx;
function playDrumroll(){try{audioCtx=new(window.AudioContext||window.webkitAudioContext)();var t=audioCtx.currentTime;for(var i=0;i<60;i++){var osc=audioCtx.createOscillator(),g=audioCtx.createGain();osc.connect(g);g.connect(audioCtx.destination);osc.frequency.value=150+Math.random()*80;osc.type='sawtooth';var ti=t+i*0.12*(1+i*.008);g.gain.setValueAtTime(.18+i*.003,ti);g.gain.exponentialRampToValueAtTime(.001,ti+.05);osc.start(ti);osc.stop(ti+.06);}}catch(e){}}
function stopDrumroll(){try{if(audioCtx)audioCtx.suspend();}catch(e){}}
function playWinnerSound(){
  try {
    var ac = new (window.AudioContext || window.webkitAudioContext)();
    var master = ac.createGain();
    var reverb = ac.createConvolver();
    var reverbGain = ac.createGain();
    var dryGain = ac.createGain();

    // Créer une réverbération de salle
    (function buildReverb(){
      var len = ac.sampleRate * 2.5;
      var buf = ac.createBuffer(2, len, ac.sampleRate);
      for(var ch=0;ch<2;ch++){
        var d=buf.getChannelData(ch);
        for(var i=0;i<len;i++) d[i]=(Math.random()*2-1)*Math.pow(1-i/len,2.5);
      }
      reverb.buffer=buf;
    })();

    master.connect(ac.destination);
    master.gain.value = 0.85;
    reverbGain.gain.value = 0.35;
    dryGain.gain.value = 0.65;
    reverb.connect(reverbGain);
    reverbGain.connect(master);
    dryGain.connect(master);

    var t = ac.currentTime;

    // Fonction pour créer un instrument chaleureux (sine + harmoniques)
    function warmNote(freq, startT, durT, vol, vibrato) {
      var fundamental = ac.createOscillator();
      var h2 = ac.createOscillator();
      var h3 = ac.createOscillator();
      var mix = ac.createGain();
      var env = ac.createGain();
      var lp  = ac.createBiquadFilter();

      fundamental.type = 'sine';  fundamental.frequency.value = freq;
      h2.type = 'sine';           h2.frequency.value = freq * 2;
      h3.type = 'sine';           h3.frequency.value = freq * 3;

      var gF=ac.createGain(), gH2=ac.createGain(), gH3=ac.createGain();
      gF.gain.value=0.7; gH2.gain.value=0.2; gH3.gain.value=0.08;

      fundamental.connect(gF); gF.connect(mix);
      h2.connect(gH2);         gH2.connect(mix);
      h3.connect(gH3);         gH3.connect(mix);

      lp.type='lowpass'; lp.frequency.value=3500; lp.Q.value=0.8;
      mix.connect(lp); lp.connect(env);
      env.connect(dryGain); env.connect(reverb);

      // Vibrato léger
      if(vibrato){
        var lfo=ac.createOscillator(), lfoG=ac.createGain();
        lfo.frequency.value=5.5; lfoG.gain.value=freq*0.012;
        lfo.connect(lfoG); lfoG.connect(fundamental.frequency);
        lfoG.connect(h2.frequency); lfoG.connect(h3.frequency);
        lfo.start(startT); lfo.stop(startT+durT+0.3);
      }

      // Enveloppe ADSR douce
      env.gain.setValueAtTime(0, startT);
      env.gain.linearRampToValueAtTime(vol, startT + 0.04);
      env.gain.setValueAtTime(vol, startT + durT - 0.08);
      env.gain.exponentialRampToValueAtTime(0.001, startT + durT + 0.18);

      fundamental.start(startT); fundamental.stop(startT+durT+0.25);
      h2.start(startT);          h2.stop(startT+durT+0.25);
      h3.start(startT);          h3.stop(startT+durT+0.25);
    }

    // Fonction caisse claire douce (bruit filtré)
    function snare(startT, vol) {
      var buf = ac.createBuffer(1, ac.sampleRate*0.12, ac.sampleRate);
      var d = buf.getChannelData(0);
      for(var i=0;i<d.length;i++) d[i]=(Math.random()*2-1)*Math.pow(1-i/d.length,1.5);
      var src=ac.createBufferSource(), hp=ac.createBiquadFilter(), g=ac.createGain();
      hp.type='highpass'; hp.frequency.value=2000;
      src.buffer=buf;
      src.connect(hp); hp.connect(g); g.connect(dryGain);
      g.gain.setValueAtTime(0,startT);
      g.gain.linearRampToValueAtTime(vol,startT+0.005);
      g.gain.exponentialRampToValueAtTime(0.001,startT+0.1);
      src.start(startT); src.stop(startT+0.15);
    }

    // Fonction grosse caisse
    function kick(startT, vol) {
      var osc=ac.createOscillator(), g=ac.createGain();
      osc.type='sine'; osc.frequency.setValueAtTime(160,startT);
      osc.frequency.exponentialRampToValueAtTime(40,startT+0.15);
      g.gain.setValueAtTime(0,startT);
      g.gain.linearRampToValueAtTime(vol,startT+0.01);
      g.gain.exponentialRampToValueAtTime(0.001,startT+0.2);
      osc.connect(g); g.connect(dryGain);
      osc.start(startT); osc.stop(startT+0.25);
    }

    // ── INTRO : roulement de tambour ─────────────────────────
    var speeds=[0,.06,.12,.16,.20,.23,.26,.28,.30,.32];
    speeds.forEach(function(s,i){
      snare(t+s, 0.06+i*0.008);
    });
    kick(t+0.01, 0.5);

    // ── MÉLODIE "Da da da DUM da da DUM" ─────────────────────
    // Gamme de Do majeur, mélodie triomphale
    // Style "podium / remise de prix" : montée progressive
    var melody = [
      // montée introductive
      {f:261.6, s:.38, d:.10, v:.22},  // do
      {f:293.7, s:.49, d:.10, v:.22},  // ré
      {f:329.6, s:.60, d:.10, v:.22},  // mi
      // pause + accent
      {f:392.0, s:.72, d:.25, v:.28},  // sol ← montée
      // petite descente
      {f:349.2, s:.98, d:.10, v:.22},  // fa
      {f:329.6, s:1.09,d:.10, v:.22},  // mi
      // final triomphal
      {f:392.0, s:1.22,d:.18, v:.30},  // sol
      {f:440.0, s:1.42,d:.18, v:.30},  // la
      {f:523.3, s:1.62,d:.55, v:.38},  // do aigu (final tenu + vibrato)
    ];

    melody.forEach(function(n,i){
      warmNote(n.f, t+n.s, n.d, n.v, i >= melody.length-1);
    });

    // Accord de soutien (basse + harmonie)
    warmNote(130.8, t+0.38, 1.8, 0.12, false); // do grave
    warmNote(196.0, t+0.38, 1.8, 0.09, false); // sol grave
    warmNote(261.6, t+0.72, 1.5, 0.10, false); // do médium harmonie
    warmNote(329.6, t+0.72, 1.5, 0.08, false); // mi harmonie

    // Grosse caisse sur les temps forts
    kick(t+0.38, 0.4);
    kick(t+0.72, 0.45);
    kick(t+1.22, 0.4);
    kick(t+1.62, 0.5);

    // Cloches finales (triangle / glockenspiel)
    [[1047,.14],[1319,.11],[1568,.09],[2093,.07]].forEach(function(n,i){
      warmNote(n[0], t+1.62+i*0.08, 1.2, n[1], false);
    });

  } catch(e) { console.warn('Audio:', e); }
}
function launchFireworks(){
  var colors=['#ffd700','#ff3d00','#00e676','#2979ff','#f50057','#00e5ff','#ff6d00','#d500f9','#76ff03','#ff1744','#ffea00','#69f0ae'];
  var anims=['confettoFallLeft','confettoFallRight','confettoFallStraight','confettoFallWiggle'];
  var shapes=['50%','2px','4px','0'];
  // 8 vagues de confettis
  for(var b=0;b<8;b++){(function(delay){
    setTimeout(function(){
      // Spawn de plusieurs zones en même temps
      var zones=[[10,0],[30,0],[50,0],[70,0],[90,0],[20,20],[60,20],[80,10]];
      var zone=zones[Math.floor(Math.random()*zones.length)];
      var count=30+Math.floor(Math.random()*25);
      for(var i=0;i<count;i++){
        var el=document.createElement('div');
        el.className='confetto';
        var w=5+Math.random()*10, h=5+Math.random()*12;
        var anim=anims[Math.floor(Math.random()*anims.length)];
        var dur=2.5+Math.random()*2.5;
        var del=Math.random()*0.8;
        var left=zone[0]+Math.random()*15-5;
        var top=zone[1];
        el.style.cssText=[
          'left:'+left+'vw',
          'top:'+top+'vh',
          'width:'+w+'px',
          'height:'+h+'px',
          'background:'+colors[Math.floor(Math.random()*colors.length)],
          'border-radius:'+shapes[Math.floor(Math.random()*shapes.length)],
          'animation:'+anim+' '+dur+'s '+del+'s ease-in forwards',
          'opacity:1',
        ].join(';');
        document.body.appendChild(el);
        setTimeout(function(e){if(e.parentNode)e.remove();},( dur+del+0.5)*1000,el);
      }
    },delay);
  })(b*350);}
  // Confettis latéraux qui rentrent des côtés
  for(var s=0;s<3;s++){(function(d){
    setTimeout(function(){
      for(var i=0;i<20;i++){
        var el=document.createElement('div');
        el.className='confetto';
        el.style.cssText='left:'+(Math.random()>0.5?'-2':'102')+'vw;top:'+(Math.random()*50)+'vh;width:'+(8+Math.random()*6)+'px;height:'+(8+Math.random()*6)+'px;background:'+colors[Math.floor(Math.random()*colors.length)]+';border-radius:'+(Math.random()>0.5?'50%':'2px')+';animation:confettoFallStraight '+(2+Math.random()*2)+'s '+(Math.random()*0.3)+'s ease-in forwards';
        document.body.appendChild(el);
        setTimeout(function(e){if(e.parentNode)e.remove();},5000,el);
      }
    },d);
  })(s*500+200);}
}

// Countdown
(function(){var el=document.getElementById('tb-countdown');if(!el)return;var end=parseInt(el.dataset.end)*1000;function upd(){var diff=end-Date.now();if(diff<=0){el.innerHTML='<div style="color:#f59e0b;font-size:.85rem">⏰ Inscriptions closes</div>';return;}var d=Math.floor(diff/86400000),h=Math.floor(diff%86400000/3600000),m=Math.floor(diff%3600000/60000),s=Math.floor(diff%60000/1000);document.getElementById('cd-d').textContent=d;document.getElementById('cd-h').textContent=String(h).padStart(2,'0');document.getElementById('cd-m').textContent=String(m).padStart(2,'0');document.getElementById('cd-s').textContent=String(s).padStart(2,'0');setTimeout(upd,1000);}upd();})();

document.addEventListener('DOMContentLoaded', initWheel);
</script>

<?php
$content = ob_get_clean();
include CC_ROOT . '/templates/layout.php';
