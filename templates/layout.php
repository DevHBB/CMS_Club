<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="<?= Config::get('primary_color','#1d4ed8') ?>">
<title><?= Helpers::e($pageTitle ?? Config::get('club_name','ClubCMS')) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<?php
$fh = Config::get('font_heading','Bebas Neue');
$fb = Config::get('font_body','DM Sans');
$gf = urlencode($fh).':wght@400;700&family='.urlencode($fb).':wght@400;500;600;700';
?>
<link href="https://fonts.googleapis.com/css2?family=<?= $gf ?>&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('assets/css/main.css') ?>">
<style>
:root{
  --color-primary:<?= Config::get('primary_color','#1d4ed8') ?>;
  --color-secondary:<?= Config::get('secondary_color','#f59e0b') ?>;
  --scrollbar-color:<?= Config::get('scrollbar_color', Config::get('primary_color','#1d4ed8')) ?>;
  --font-heading:'<?= addslashes($fh) ?>',sans-serif;
  --font-body:'<?= addslashes($fb) ?>',sans-serif;
}
/* Scrollbar colorée */
::-webkit-scrollbar { width: 8px; height: 8px; }
::-webkit-scrollbar-track { background: #f1f5f9; }
::-webkit-scrollbar-thumb { background: var(--scrollbar-color); border-radius: 4px; }
::-webkit-scrollbar-thumb:hover { opacity: .8; }
* { scrollbar-width: thin; scrollbar-color: var(--scrollbar-color) #f1f5f9; }
<?= Config::get('custom_css','') ?>
</style>

<!-- Dropdown nav - style forcé inline pour garantir le masquage -->
<style>
.nav-item { position: relative !important; }
.nav-dropdown {
  position: absolute !important;
  top: 100% !important;
  left: 0 !important;
  z-index: 9999 !important;
  display: none !important;  /* FORCÉ : caché par défaut */
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-top: 3px solid var(--color-primary);
  border-radius: 0 0 10px 10px;
  box-shadow: 0 8px 24px rgba(0,0,0,.13);
  min-width: 200px;
  padding: .3rem 0;
  list-style: none !important;
  margin: 0 !important;
  padding-left: 0 !important;
}
/* UNIQUEMENT quand JS ajoute la classe .open */
.nav-dropdown.open {
  display: block !important;
}
.nav-dropdown > li {
  list-style: none !important;
  display: block !important;
  margin: 0 !important;
  padding: 0 !important;
}
.nav-dropdown > li::before, .nav-dropdown > li::marker { display:none !important; content:'' !important; }
.nav-dropdown-link {
  display: block !important;
  padding: .65rem 1.25rem !important;
  font-size: .875rem !important;
  font-weight: 500 !important;
  color: #374151 !important;
  text-decoration: none !important;
  white-space: nowrap !important;
  border-bottom: 1px solid #f1f5f9 !important;
  background: transparent !important;
  transition: background .12s, color .12s !important;
}
.nav-dropdown > li:last-child .nav-dropdown-link { border-bottom: none !important; }
.nav-dropdown-link:hover {
  background: #f8fafc !important;
  color: var(--color-primary) !important;
  text-decoration: none !important;
}
/* ── User dropdown ── */
.nav-user-menu { position:relative; }
.nav-avatar-btn { display:flex;align-items:center;gap:.4rem;background:none;border:none;cursor:pointer;padding:.35rem .6rem;border-radius:8px;font-family:inherit;font-size:.875rem;color:inherit;transition:background .15s; }
.nav-avatar-btn:hover { background:rgba(0,0,0,.06); }
.nav-avatar-initials { width:32px;height:32px;border-radius:50%;background:var(--color-primary);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.8rem;flex-shrink:0; }
.nav-avatar-img { width:32px;height:32px;border-radius:50%;object-fit:cover;flex-shrink:0; }
.nav-username { font-weight:600;font-size:.85rem;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap; }
.nav-chevron { font-size:.65rem;opacity:.5;transition:transform .2s; }
.user-dropdown { position:absolute;top:calc(100% + 6px);right:0;min-width:230px;background:#fff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:9999;display:none;overflow:hidden; }
.user-dropdown.open { display:block; }
.dropdown-header { padding:.875rem 1rem;border-bottom:1px solid #f1f5f9;background:#f8fafc; }
.dropdown-header strong { display:block;font-size:.875rem;color:#1e293b;margin-bottom:.25rem; }
.dropdown-item { display:block;padding:.55rem 1rem;font-size:.85rem;color:#374151;text-decoration:none;transition:background .12s; }
.dropdown-item:hover { background:#f1f5f9;color:var(--color-primary); }
.dropdown-divider { height:1px;background:#f1f5f9;margin:.25rem 0; }
.dropdown-danger { color:#dc2626 !important; }
.dropdown-danger:hover { background:#fff5f5 !important; }
.btn-nav-login{display:inline-flex;align-items:center;padding:.4rem .875rem;border-radius:8px;border:1.5px solid var(--color-primary);color:var(--color-primary);font-size:.875rem;font-weight:600;text-decoration:none;transition:all .15s}
.btn-nav-login:hover{background:var(--color-primary);color:#fff}
.btn-nav-register{display:inline-flex;align-items:center;padding:.4rem .875rem;border-radius:8px;background:var(--color-primary);color:#fff;font-size:.875rem;font-weight:600;text-decoration:none;transition:all .15s;border:1.5px solid var(--color-primary)}
.btn-nav-register:hover{background:var(--color-primary-dark,#4f46e5)}
</style>
<body class="<?= Auth::check() ? 'is-logged-in role-'.Auth::role() : 'is-guest' ?>">
<a href="<?= u('/') ?>#main-content" class="skip-nav">Aller au contenu</a>

<!-- ── NAVBAR ── -->
<header class="site-header" id="site-header">
  <nav class="navbar container">
    <a href="<?= u('/') ?>" class="nav-brand" style="text-decoration:none">
      <?php if (Config::get('logo')): ?>
        <img src="<?= asset(Config::get('logo')) ?>" alt="<?= Helpers::e(Config::get('club_name')) ?>" class="nav-logo">
      <?php else: ?>
        <?php
        $clubName = Config::get('club_name','Club');
        $words    = explode(' ', trim($clubName));
        $initials = mb_strtoupper(mb_substr($words[0],0,1) . (isset($words[1]) ? mb_substr($words[1],0,1) : ''));
        $color    = Config::get('primary_color','#1d4ed8');
        $fs       = strlen($initials) === 1 ? '18' : '14';
        ?>
        <svg width="36" height="36" viewBox="0 0 36 36" xmlns="http://www.w3.org/2000/svg" style="flex-shrink:0;border-radius:8px">
          <rect width="36" height="36" rx="8" fill="<?= Helpers::e($color) ?>"/>
          <text x="18" y="24" text-anchor="middle" font-family="Arial,sans-serif" font-size="<?= $fs ?>" font-weight="700" fill="#fff"><?= Helpers::e($initials) ?></text>
        </svg>
        <span style="font-family:var(--font-heading);font-size:1.3rem;letter-spacing:.05em;color:var(--color-primary);text-decoration:none;font-weight:700"><?= Helpers::e($clubName) ?></span>
      <?php endif; ?>
    </a>

    <ul class="nav-links" id="nav-links">
      <?php
      $navItems = json_decode(Config::get('menu_items','[]'), true);
      if (!is_array($navItems) || empty($navItems)) {
          $navItems = [['label'=>'Accueil','url'=>'/','visible'=>1]];
          foreach (Database::all("SELECT slug,label FROM cc_modules WHERE enabled=1") as $mod) {
              $navItems[] = [
                'label'   => $mod['label'],
                'url'     => match($mod['slug']) {
                    'forum'    => '/forum',
                    'shop'     => '/boutique',
                    'gallery'  => '/galerie',
                    'planning' => '/planning',
                    default    => '/'.$mod['slug'],
                },
                'visible' => 1,
              ];
          }
      }
      foreach ($navItems as $ni):
        if (!($ni['visible'] ?? 1)) continue;
        $niAccess = $ni['access_mode'] ?? ($ni['require_login'] ?? 0 ? 'members' : 'public');
        // 'members' = cacher aux non-connectés | 'teaser'/'public' = toujours visible
        if ($niAccess === 'members' && !Auth::check()) continue;
        $niUrl     = $ni['url'] ?? '#';
        $niLabel   = $ni['label'] ?? '';
        $niChildren = array_filter($ni['children'] ?? [], function($c) {
          if (!($c['visible']??1)) return false;
          $cAccess = $c['access_mode'] ?? ($c['require_login']??0 ? 'members' : 'public');
          return $cAccess !== 'members' || Auth::check();
        });
        $hasKids   = !empty($niChildren);
        $curRoute  = $_GET['route'] ?? 'home';
        $isActive  = (ltrim($niUrl,'/') === ltrim($curRoute,'/') || ($niUrl==='/' && $curRoute==='home')) ? 'active' : '';
      ?>
      <li class="nav-item<?= $hasKids ? ' has-dropdown' : '' ?>" style="list-style:none;padding:0;margin:0;position:relative">
        <?php $niTeaser = isset($niAccess) && $niAccess === 'teaser' && !Auth::check(); ?>
        <a href="<?= $niTeaser ? u('/login') : ($niUrl === '#' ? '#' : u($niUrl)) ?>" 
           class="nav-link <?= $isActive ?><?= $niTeaser ? ' nav-link-teaser' : '' ?>"
           style="display:inline-flex;align-items:center;gap:.2rem"
           <?= $niTeaser ? 'title="Réservé aux membres — cliquez pour vous connecter"' : '' ?>>
          <?= Helpers::e($niLabel) ?><?php if ($hasKids): ?><span class="nav-arrow">▾</span><?php endif; ?>
        </a>
        <?php if ($hasKids): ?>
        <ul class="nav-dropdown">
          <?php foreach ($niChildren as $ch): ?>
          <li style="list-style:none !important;padding:0;margin:0;display:block;width:100%">
            <a href="<?= u($ch['url'] ?? '#') ?>" class="nav-dropdown-link" style="display:block;padding:.6rem 1.25rem;color:#374151;text-decoration:none;white-space:nowrap"><?= Helpers::e($ch['label'] ?? '') ?></a>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>

    <div class="nav-actions">
      <?php if (Auth::check() && ($u = Auth::user())): ?>
        <div class="nav-user-menu" id="user-menu">
          <button class="nav-avatar-btn" onclick="toggleUserMenu()">
            <?php if ($u['avatar']): ?>
              <img src="<?=asset(Helpers::e($u['avatar']))?>" alt="" class="nav-avatar-img">
            <?php else: ?>
              <span class="nav-avatar-initials"><?= mb_strtoupper(mb_substr($u['firstname']??'M',0,1).mb_substr($u['lastname']??'',0,1)) ?></span>
            <?php endif; ?>
            <span class="nav-username"><?= Helpers::e($u['firstname']??'Membre') ?></span>
            <span class="nav-chevron">▾</span>
          </button>
          <div class="user-dropdown" id="user-dropdown">
            <div class="dropdown-header">
              <strong><?= Helpers::e(($u['firstname']??'').' '.($u['lastname']??'')) ?></strong>
              <span class="role-badge role-<?= $u['role'] ?>"><?= Auth::ROLE_LABELS[$u['role']] ?? ucfirst($u['role'] ?? '') ?? ucfirst($u['role']) ?></span>
            </div>
            <a href="<?= u('/membre') ?>" class="dropdown-item">👤 Mon profil</a>
            <a href="<?= u('/membre/carte') ?>" class="dropdown-item">🪪 Ma carte membre</a>
            <a href="<?= u('/membre/carte/telecharger') ?>" class="dropdown-item">⬇️ Télécharger ma carte PDF</a>
            <a href="<?= u('/membre/commandes') ?>" class="dropdown-item">📦 Mes commandes</a>
            <?php if (Auth::canAccessBenevole()): ?>
              <div class="dropdown-divider"></div>
              <a href="<?= u('/benevole') ?>" class="dropdown-item" style="color:#7c3aed;font-weight:600">🤝 Espace bénévoles</a>
            <?php endif; ?>
            <?php if (Auth::isCoach() && !Auth::isAdmin()): ?>
              <div class="dropdown-divider"></div>
              <a href="<?= u('/admin/planning') ?>" class="dropdown-item">📅 Gérer le planning</a>
              <a href="<?= u('/admin/licences') ?>" class="dropdown-item">📄 Licences membres</a>
            <?php endif; ?>
            <?php if (Auth::isAdmin()): ?>
              <div class="dropdown-divider"></div>
              <a href="<?= u('/admin') ?>" class="dropdown-item">⚙️ Administration</a>
            <?php endif; ?>
            <div class="dropdown-divider"></div>
            <a href="<?= u('/logout') ?>" class="dropdown-item dropdown-danger">🚪 Déconnexion</a>
          </div>
        </div>
      <?php else: ?>
        <?php if(Config::get('allow_login','1')): ?>
        <a href="<?= u('/login') ?>" class="btn-nav-login">Connexion</a>
        <?php endif; ?>
        <?php if(Config::get('allow_register','1')): ?>
        <a href="<?= u('/register') ?>" class="btn-nav-register" style="margin-left:.5rem">S'inscrire</a>
        <?php endif; ?>
      <?php endif; ?>
      <button class="nav-hamburger" id="nav-hamburger" onclick="toggleMobileNav()" aria-label="Menu">
        <span></span><span></span><span></span>
      </button>
    </div>
  </nav>
</header>

<main id="main-content"><?= $content ?? '' ?></main>

<!-- ── FOOTER ── -->
<footer class="site-footer">
  <div class="footer-newsletter">
    <div class="container">
      <div class="fn-inner">
        <div class="fn-text">
          <strong>📨 Restez informé</strong>
          <span>Newsletter du club</span>
        </div>
        <form class="fn-form" id="nl-form" onsubmit="subNl(event)">
          <input type="text"  name="firstname" placeholder="Prénom" class="fn-input">
          <input type="email" name="email" placeholder="Votre email" required class="fn-input">
          <button type="submit" class="fn-btn">S'inscrire</button>
        </form>
        <div id="nl-msg" style="display:none;color:#6ee7b7;font-weight:600;font-size:.875rem"></div>
      </div>
    </div>
  </div>
  <div class="container footer-main">
    <div class="footer-col">
      <?php if (Config::get('logo')): ?>
        <img src="<?= asset(Config::get('logo')) ?>" alt="<?= Helpers::e(Config::get('club_name')) ?>" style="height:36px;object-fit:contain;margin-bottom:.35rem;max-width:140px">
      <?php else: ?>
        <span style="font-family:var(--font-heading);font-size:1.2rem;color:#fff;letter-spacing:.05em"><?= Helpers::e(Config::get('club_name','ClubCMS')) ?></span>
      <?php endif; ?>
      <?php if (Config::get('club_sport')): ?><span style="color:var(--color-secondary);font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em"><?= Helpers::e(Config::get('club_sport')) ?></span><?php endif; ?>
      <?php if (Config::get('club_address')): ?><div style="font-size:.8rem;color:rgba(255,255,255,.5);margin-top:.25rem">📍 <?= Helpers::e(Config::get('club_address')) ?><?= Config::get('club_city') ? ', '.Helpers::e(Config::get('club_city')) : '' ?></div><?php endif; ?>
      <?php if (Config::get('club_email')): ?><a href="mailto:<?= Helpers::e(Config::get('club_email')) ?>" class="footer-contact-link">📧 <?= Helpers::e(Config::get('club_email')) ?></a><?php endif; ?>
      <?php if (Config::get('club_phone')): ?><a href="tel:<?= Helpers::e(Config::get('club_phone')) ?>" class="footer-contact-link">📞 <?= Helpers::e(Config::get('club_phone')) ?></a><?php endif; ?>
    </div>
    <div class="footer-col">
      <div class="footer-col-title">Navigation</div>
      <?php
      $fMenuItems = json_decode(Config::get('menu_items','[]'), true) ?? [];
      foreach ($fMenuItems as $fi):
        if (!($fi['visible']??1)) continue;
        $fiAccess = $fi['access_mode'] ?? ($fi['require_login']??0 ? 'members' : 'public');
        if ($fiAccess === 'members' && !Auth::check()) continue;
      ?>
      <a href="<?= u($fi['url']??'#') ?>" class="footer-nav-link"><?= Helpers::e($fi['label']??'') ?></a>
      <?php endforeach; ?>
    </div>
    <div class="footer-col">
      <div class="footer-col-title">Légal</div>
      <?php
      foreach (['mentions-legales'=>'Mentions légales','confidentialite'=>'Confidentialité','cgu'=>'CGU','cgv'=>'CGV','reglement'=>'Règlement','cookies'=>'Cookies'] as $sl=>$lb):
        $key = str_replace('-','_',$sl);
        if (!Config::get('legal_'.$key)) continue;
      ?>
      <a href="<?= u('/'.$sl) ?>" class="footer-nav-link"><?= $lb ?></a>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="footer-bottom">
    <div class="container">
      <?php
      $footerMention = Config::get('footer_mention','');
      if($footerMention) {
          echo Helpers::e($footerMention);
      } else {
          echo '© '.date('Y').' '.Helpers::e(Config::get('club_name','ClubCMS')).' — Propulsé par <strong>ClubCMS</strong>';
      }
      ?>
    </div>
  </div>
</footer>

<script src="<?= asset('assets/js/main.js') ?>"></script>
<script>
function toggleUserMenu(){document.getElementById('user-dropdown').classList.toggle('open')}
function toggleMobileNav(){document.getElementById('nav-links').classList.toggle('open');document.getElementById('nav-hamburger').classList.toggle('active')}

// Dropdown — ouverture via .open, aucun style inline, aucun reflow
(function() {
  function setup() {
    document.querySelectorAll('.nav-item.has-dropdown').forEach(function(item) {
      var dd = item.querySelector('.nav-dropdown');
      if (!dd) return;
      var timer;
      var arr = item.querySelector('.nav-arrow');
      function show() {
        clearTimeout(timer);
        document.querySelectorAll('.nav-dropdown.open').forEach(function(o){ o.classList.remove('open'); });
        dd.classList.add('open');
        if (arr) arr.style.transform = 'rotate(180deg)';
      }
      function hide() {
        timer = setTimeout(function(){
          dd.classList.remove('open');
          if (arr) arr.style.transform = '';
        }, 150);
      }
      item.addEventListener('mouseenter', show);
      item.addEventListener('mouseleave', hide);
      dd.addEventListener('mouseenter', function(){ clearTimeout(timer); });
      dd.addEventListener('mouseleave', hide);
    });
    document.addEventListener('click', function(e) {
      if (!e.target.closest('.has-dropdown'))
        document.querySelectorAll('.nav-dropdown.open').forEach(function(d){ d.classList.remove('open'); });
    });
  }
  document.readyState==='loading' ? document.addEventListener('DOMContentLoaded',setup) : setup();
})();
document.addEventListener('click',e=>{const m=document.getElementById('user-menu');if(m&&!m.contains(e.target))document.getElementById('user-dropdown')?.classList.remove('open')});
window.addEventListener('scroll',()=>document.getElementById('site-header').classList.toggle('scrolled',scrollY>20),{passive:true});
async function subNl(e){
  e.preventDefault();
  const f=e.target,d=new URLSearchParams(new FormData(f));
  const r=await fetch('<?= u('/api/newsletter/subscribe') ?>',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},body:d.toString()});
  const j=await r.json();
  const msg=document.getElementById('nl-msg');
  f.style.display='none'; msg.style.display='block';
  msg.textContent=j.success?'✅ Merci, vous êtes inscrit !':(j.error||'Erreur.');
  if(!j.success)msg.style.color='#fca5a5';
}
</script>
<?= $extraJs ?? '' ?>

<style>
/* Newsletter footer */
.footer-newsletter{background:color-mix(in srgb,var(--color-primary) 85%,#000);padding:1.25rem 0}
.fn-inner{display:flex;align-items:center;gap:1.5rem;flex-wrap:wrap}
.fn-text{display:flex;flex-direction:column;gap:.15rem;flex-shrink:0}
.fn-text strong{color:#fff;font-size:1rem}
.fn-text span{color:rgba(255,255,255,.7);font-size:.82rem}
.fn-form{display:flex;gap:.5rem;flex:1;flex-wrap:wrap}
.fn-input{flex:1;min-width:130px;padding:.5rem .875rem;border-radius:6px;border:2px solid rgba(255,255,255,.3);font-family:var(--font-body);font-size:.875rem;background:#fff;color:#1e293b;outline:none}
.fn-input::placeholder{color:#94a3b8}
.fn-input:focus{border-color:#fff;box-shadow:0 0 0 3px rgba(255,255,255,.25)}
.fn-btn{background:#fff;color:var(--color-primary);padding:.5rem 1.25rem;border-radius:6px;border:none;font-weight:700;font-size:.875rem;cursor:pointer;white-space:nowrap;font-family:var(--font-body)}
.site-footer{background:#0f172a;color:rgba(255,255,255,.65)}
.footer-main{display:grid;grid-template-columns:1.5fr 1fr 1fr;gap:3rem;padding:2.5rem 0}
.footer-col{display:flex;flex-direction:column;gap:.4rem}
.footer-col-title{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:rgba(255,255,255,.35);margin-bottom:.35rem}
.footer-contact-link{font-size:.82rem;color:rgba(255,255,255,.6);text-decoration:none}
.footer-nav-link{font-size:.82rem;color:rgba(255,255,255,.55);text-decoration:none;padding:.1rem 0}
.footer-nav-link:hover{color:#fff}
.footer-bottom{border-top:1px solid rgba(255,255,255,.08);padding:.875rem 0;font-size:.78rem;color:rgba(255,255,255,.3)}
.footer-bottom strong{color:rgba(255,255,255,.5)}
@media(max-width:768px){.fn-inner{flex-direction:column;align-items:flex-start}.fn-form{width:100%}.footer-main{grid-template-columns:1fr;gap:1.5rem;padding:1.5rem 0}}
</style>
<?php include CC_ROOT . '/templates/popup.php'; ?>

<?php if(Config::get('translation_enabled')): ?>
<!-- ── Système de traduction ── -->
<style>
#lang-widget{position:fixed;top:80px;right:0;z-index:9998}
#lang-widget-btn{display:flex;align-items:center;gap:.4rem;background:#fff;border:none;border-right:none;border-radius:10px 0 0 10px;padding:.5rem .75rem .5rem .875rem;cursor:pointer;box-shadow:-3px 2px 12px rgba(0,0,0,.12);font-family:inherit;font-size:.85rem;font-weight:600;color:#374151;transition:background .15s}
#lang-widget-btn:hover{background:#f8fafc}
#lang-widget-panel{display:none;position:absolute;top:0;right:100%;background:#fff;border-radius:12px 0 0 12px;box-shadow:-6px 4px 24px rgba(0,0,0,.13);overflow:hidden;min-width:180px;border:1.5px solid #e2e8f0;border-right:none}
#lang-widget-panel.open{display:block}
.lang-item{display:flex;align-items:center;gap:.625rem;width:100%;padding:.55rem .875rem;background:none;border:none;cursor:pointer;font-size:.85rem;font-family:inherit;text-align:left;color:#374151;transition:background .12s}
.lang-item:hover{background:#f1f5f9}
.lang-item.active{background:#eff6ff;color:var(--color-primary);font-weight:700}
.goog-te-banner-frame,.skiptranslate{display:none!important}
body{top:0!important}
</style>

<div id="lang-widget">
  <button id="lang-widget-btn" onclick="document.getElementById('lang-widget-panel').classList.toggle('open')">
    <span id="lw-flag" style="font-size:1.15rem">🌐</span>
    <span id="lw-code">FR</span>
    <span style="font-size:.6rem;opacity:.5">◀</span>
  </button>
  <div id="lang-widget-panel">
    <?php foreach([
      ['fr',  '🇫🇷', 'FR', 'Français'],
      ['en',  '🇬🇧', 'EN', 'English'],
      ['es',  '🇪🇸', 'ES', 'Español'],
      ['de',  '🇩🇪', 'DE', 'Deutsch'],
      ['it',  '🇮🇹', 'IT', 'Italiano'],
      ['pt',  '🇵🇹', 'PT', 'Português'],
      ['nl',  '🇳🇱', 'NL', 'Nederlands'],
      ['ar',  '🇸🇦', 'AR', 'العربية'],
      ['zh-CN','🇨🇳','ZH', '中文'],
      ['ru',  '🇷🇺', 'RU', 'Русский'],
    ] as [$code, $flag, $short, $name]): ?>
    <button class="lang-item" onclick="lwTranslate('<?=$code?>')">
      <span style="font-size:1.1rem"><?=$flag?></span>
      <span style="font-weight:700;width:26px;color:#64748b"><?=$short?></span>
      <span><?=$name?></span>
    </button>
    <?php endforeach; ?>
  </div>
</div>

<!-- Google Translate (invisible, piloté par cookie) -->
<div id="google_translate_element" style="display:none"></div>
<script>
function googleTranslateElementInit(){
  new google.translate.TranslateElement({pageLanguage:'fr',autoDisplay:false},'google_translate_element');
}
</script>
<script src="//translate.google.com/translate_a/element.js?cb=googleTranslateElementInit"></script>

<script>
var lwLangs = {
  'fr':['🇫🇷','FR'],'en':['🇬🇧','EN'],'es':['🇪🇸','ES'],'de':['🇩🇪','DE'],
  'it':['🇮🇹','IT'],'pt':['🇵🇹','PT'],'nl':['🇳🇱','NL'],'ar':['🇸🇦','AR'],
  'zh-CN':['🇨🇳','ZH'],'ru':['🇷🇺','RU']
};

function lwTranslate(lang) {
  if (lang === 'fr') {
    document.cookie = 'googtrans=; expires=Thu,01 Jan 1970 00:00:01 GMT; path=/';
    document.cookie = 'googtrans=; expires=Thu,01 Jan 1970 00:00:01 GMT; path=/; domain=.' + location.hostname;
  } else {
    document.cookie = 'googtrans=/fr/' + lang + '; path=/';
    document.cookie = 'googtrans=/fr/' + lang + '; path=/; domain=.' + location.hostname;
  }
  location.reload();
}

// Mettre à jour le bouton selon cookie actif
(function() {
  var c = document.cookie.match(/googtrans=\/fr\/([^;]+)/);
  if (c && c[1] && lwLangs[c[1]]) {
    document.getElementById('lw-flag').textContent = lwLangs[c[1]][0];
    document.getElementById('lw-code').textContent  = lwLangs[c[1]][1];
    // Marquer actif
    document.querySelectorAll('.lang-item').forEach(function(btn) {
      if (btn.getAttribute('onclick').indexOf("'" + c[1] + "'") > -1) {
        btn.classList.add('active');
      }
    });
  } else {
    // Marquer FR comme actif
    document.querySelectorAll('.lang-item').forEach(function(btn) {
      if (btn.getAttribute('onclick').indexOf("'fr'") > -1) btn.classList.add('active');
    });
  }
})();

// Fermer panel en cliquant ailleurs
document.addEventListener('click', function(e) {
  var panel = document.getElementById('lang-widget-panel');
  var btn   = document.getElementById('lang-widget-btn');
  if (panel && btn && !btn.contains(e.target) && !panel.contains(e.target)) {
    panel.classList.remove('open');
  }
});
</script>
<?php else: ?>
<script>
// Traduction désactivée : effacer le cookie
if (document.cookie.indexOf('googtrans') !== -1) {
  document.cookie = 'googtrans=; expires=Thu,01 Jan 1970 00:00:01 GMT; path=/';
  document.cookie = 'googtrans=; expires=Thu,01 Jan 1970 00:00:01 GMT; path=/; domain=.' + location.hostname;
}
</script>
<?php endif; ?>
<?php if(Config::get('cookie_banner_enabled', '1')): ?>
<!-- ── Bandeau cookies ── -->
<div id="cookie-banner" style="display:none;position:fixed;bottom:1.25rem;left:50%;transform:translateX(-50%);z-index:9989;width:min(520px,calc(100vw - 2rem));background:#1e293b;color:#e2e8f0;border-radius:14px;padding:1rem 1.25rem;box-shadow:0 8px 32px rgba(0,0,0,.25);display:flex;align-items:center;gap:1rem;flex-wrap:wrap">
  <span style="font-size:1.3rem;flex-shrink:0">🍪</span>
  <p style="flex:1;font-size:.82rem;line-height:1.5;margin:0;color:#cbd5e1">
    <?=Helpers::e(Config::get('cookie_text', 'Ce site utilise des cookies pour améliorer votre expérience. En continuant à naviguer, vous acceptez leur utilisation.'))?>
    <?php if(Config::get('cookie_link_url')): ?>
    <a href="<?=u(Config::get('cookie_link_url'))?>" style="color:#818cf8;white-space:nowrap"> <?=Helpers::e(Config::get('cookie_link_label','En savoir plus'))?></a>
    <?php endif; ?>
  </p>
  <button onclick="cookieAccept()" style="background:var(--color-primary);color:#fff;border:none;border-radius:8px;padding:.5rem 1.1rem;font-size:.82rem;font-weight:700;cursor:pointer;white-space:nowrap;flex-shrink:0">
    J'accepte
  </button>
</div>
<script>
(function(){
  if(!localStorage.getItem('cc_cookie_ok')){
    var b=document.getElementById('cookie-banner');
    if(b) setTimeout(function(){b.style.display='flex';},600);
  }
})();
function cookieAccept(){
  localStorage.setItem('cc_cookie_ok','1');
  var b=document.getElementById('cookie-banner');
  if(b){b.style.opacity='0';b.style.transition='opacity .3s';setTimeout(function(){b.style.display='none';},300);}
}
</script>
<?php endif; ?>
</body>
</html>
