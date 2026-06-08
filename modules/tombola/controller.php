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
                    Database::run("INSERT INTO cc_tombola_participants (tombola_id,name,email) VALUES (?,?,?)",
                        [$tid, $guestName, $guestEmail]);
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
.confetto{position:fixed;border-radius:2px;animation:confettoFall linear forwards;pointer-events:none;z-index:9999}
@keyframes confettoFall{0%{transform:translateY(-20px) rotate(0deg);opacity:1}100%{transform:translateY(100vh) rotate(720deg);opacity:0}}
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

      <?php elseif($tombola['paid']): ?>
      <!-- Payant → boutique -->
      <?php if($linkedProduct): ?>
      <a href="<?=u('/boutique/produit/'.$linkedProduct['slug'])?>" class="tb-cta">
        🎟️ Acheter un ticket — <?=Helpers::price($tombola['price'] ?: $linkedProduct['price'])?>
      </a>
      <div style="color:rgba(255,255,255,.3);font-size:.72rem;margin-top:.5rem">Inscription automatique après l'achat</div>
      <?php else: ?>
      <div style="color:#f59e0b;font-size:.82rem">Contactez un administrateur pour participer.</div>
      <?php endif; ?>

      <?php elseif($isLogged): ?>
      <!-- Gratuit + connecté → inscription directe -->
      <form method="post">
        <?=Auth::csrfField()?>
        <input type="hidden" name="tombola_id" value="<?=$tombola['id']?>">
        <button type="submit" name="join_tombola" class="tb-cta">🎟️ Participer gratuitement</button>
      </form>

      <?php else: ?>
      <!-- Gratuit + non connecté → formulaire nom+email -->
      <form method="post" style="width:100%;text-align:left">
        <?=Auth::csrfField()?>
        <input type="hidden" name="tombola_id" value="<?=$tombola['id']?>">
        <input type="hidden" name="join_tombola" value="1">
        <div style="margin-bottom:.5rem">
          <input type="text" name="guest_name" required placeholder="Votre nom" style="width:100%;background:rgba(255,255,255,.08);border:1.5px solid rgba(255,255,255,.15);border-radius:8px;padding:.55rem .875rem;color:#fff;font-family:inherit;font-size:.875rem;box-sizing:border-box">
        </div>
        <div style="margin-bottom:.75rem">
          <input type="email" name="guest_email" placeholder="Votre email (optionnel)" style="width:100%;background:rgba(255,255,255,.08);border:1.5px solid rgba(255,255,255,.15);border-radius:8px;padding:.55rem .875rem;color:#fff;font-family:inherit;font-size:.875rem;box-sizing:border-box">
        </div>
        <button type="submit" class="tb-cta" style="width:100%;justify-content:center">🎟️ Participer gratuitement</button>
      </form>
      <?php endif; ?>
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

function startDraw() {
  console.log('startDraw called, NAMES:', NAMES.length, 'TOMBOLA_ID:', TOMBOLA_ID);
  if (spinning) { alert('Tirage en cours...'); return; }
  if (!NAMES.length) { alert('❌ Aucun participant dans la tombola.'); return; }
  spinning=true;
  var btn=document.getElementById('draw-btn'); if(btn) btn.disabled=true;
  playDrumroll();
  var ticker=startTicker();
  var dur=4500+Math.random()*2000, t0=performance.now(), a0=angle, tot=(Math.PI*2)*(8+Math.random()*6);
  function anim(now) {
    var p=Math.min((now-t0)/dur,1), ease=1-Math.pow(1-p,3);
    angle=a0+tot*ease; drawWheel(angle);
    if(p<1){requestAnimationFrame(anim);}
    else{clearInterval(ticker);document.getElementById('names-ticker').textContent='';fetchDraw();}
  }
  requestAnimationFrame(anim);
}

function fetchDraw() {
  var fd=new FormData();
  fd.append('draw','1'); fd.append('tombola_id',TOMBOLA_ID); fd.append('csrf_token','<?=Auth::getCsrfToken()?>');
  fetch(DRAW_URL,{method:'POST',body:fd})
    .then(function(r){
      var clone=r.clone();
      return r.json().catch(function(){
        return clone.text().then(function(t){
          console.error('Réponse non-JSON:', t.substring(0,300));
          throw new Error('Réponse invalide du serveur');
        });
      });
    })
    .then(function(d){
      if(d.error){alert('❌ '+d.error);spinning=false;var b=document.getElementById('draw-btn');if(b)b.disabled=false;return;}
      showWinner(d.winner);
    }).catch(function(e){alert('Erreur : '+e);spinning=false;});
}

function showWinner(name) {
  document.getElementById('winner-name').textContent=name;
  document.getElementById('winner-card').style.display='block';
  stopDrumroll(); playWinnerSound(); launchFireworks();
}

function startTicker(){var i=0,el=document.getElementById('names-ticker');return setInterval(function(){if(el)el.textContent=NAMES[i++%NAMES.length];},80);}

// Sons
var audioCtx;
function playDrumroll(){try{audioCtx=new(window.AudioContext||window.webkitAudioContext)();var t=audioCtx.currentTime;for(var i=0;i<60;i++){var osc=audioCtx.createOscillator(),g=audioCtx.createGain();osc.connect(g);g.connect(audioCtx.destination);osc.frequency.value=150+Math.random()*80;osc.type='sawtooth';var ti=t+i*0.12*(1+i*.008);g.gain.setValueAtTime(.18+i*.003,ti);g.gain.exponentialRampToValueAtTime(.001,ti+.05);osc.start(ti);osc.stop(ti+.06);}}catch(e){}}
function stopDrumroll(){try{if(audioCtx)audioCtx.suspend();}catch(e){}}
function playWinnerSound(){try{var c=new(window.AudioContext||window.webkitAudioContext)();[523.25,659.25,783.99,1046.50,1318.51].forEach(function(f,i){var o=c.createOscillator(),g=c.createGain();o.connect(g);g.connect(c.destination);o.frequency.value=f;o.type='sine';var t=c.currentTime+i*.18;g.gain.setValueAtTime(.3,t);g.gain.exponentialRampToValueAtTime(.001,t+.5);o.start(t);o.stop(t+.5);});}catch(e){}}
function launchFireworks(){var colors=['#ffd700','#ff4444','#44ff44','#4444ff','#ff44ff','#44ffff','#ff8800'];for(var b=0;b<5;b++){(function(d){setTimeout(function(){var cx=20+Math.random()*60,cy=10+Math.random()*40;for(var i=0;i<28;i++){var el=document.createElement('div');el.className='confetto';el.style.cssText='left:'+cx+'vw;top:'+cy+'vh;width:'+(6+Math.random()*8)+'px;height:'+(6+Math.random()*8)+'px;background:'+colors[Math.floor(Math.random()*colors.length)]+';border-radius:'+(Math.random()>.5?'50%':'2px')+';animation-duration:'+(2+Math.random()*2)+'s;animation-delay:'+(Math.random()*.5)+'s';document.body.appendChild(el);setTimeout(function(e){e.remove();},5000,el);}},d);})(b*400);}}

// Countdown
(function(){var el=document.getElementById('tb-countdown');if(!el)return;var end=parseInt(el.dataset.end)*1000;function upd(){var diff=end-Date.now();if(diff<=0){el.innerHTML='<div style="color:#f59e0b;font-size:.85rem">⏰ Inscriptions closes</div>';return;}var d=Math.floor(diff/86400000),h=Math.floor(diff%86400000/3600000),m=Math.floor(diff%3600000/60000),s=Math.floor(diff%60000/1000);document.getElementById('cd-d').textContent=d;document.getElementById('cd-h').textContent=String(h).padStart(2,'0');document.getElementById('cd-m').textContent=String(m).padStart(2,'0');document.getElementById('cd-s').textContent=String(s).padStart(2,'0');setTimeout(upd,1000);}upd();})();

document.addEventListener('DOMContentLoaded', initWheel);
</script>

<?php
$content = ob_get_clean();
include CC_ROOT . '/templates/layout.php';
