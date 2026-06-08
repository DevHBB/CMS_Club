<?php
/**
 * ClubCMS — Admin Layout
 */
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=Helpers::e($pageTitle??'Admin')?> — <?=Helpers::e(Config::get('club_name','ClubCMS'))?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=<?=urlencode(Config::get('font_heading','Bebas+Neue'))?>:wght@400;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('assets/css/main.css') ?>">
<style>
:root{--color-primary:<?=Config::get('primary_color','#1d4ed8')?>;--color-secondary:<?=Config::get('secondary_color','#f59e0b')?>;--font-heading:'<?=addslashes(Config::get('font_heading','Bebas Neue'))?>',sans-serif;--font-body:'<?=addslashes(Config::get('font_body','DM Sans'))?>',sans-serif}
*{box-sizing:border-box}body{margin:0;display:flex;min-height:100vh;font-family:var(--font-body);background:#f1f5f9}
.admin-sidebar{width:210px;background:#0f172a;color:#e2e8f0;display:flex;flex-direction:column;position:fixed;top:0;left:0;bottom:0;overflow-y:auto;z-index:100;flex-shrink:0}
.admin-brand{padding:.875rem 1.1rem;font-family:var(--font-heading);font-size:1.05rem;letter-spacing:.1em;border-bottom:1px solid rgba(255,255,255,.1);display:flex;align-items:center;gap:.5rem;color:#fff;flex-shrink:0}
.admin-brand img{height:22px;object-fit:contain;max-width:110px;flex-shrink:0}
.admin-nav{padding:.4rem .6rem;flex:1;display:flex;flex-direction:column}
.admin-nav-group{font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:rgba(255,255,255,.3);padding:.65rem .65rem .2rem;margin-top:.2rem}
.admin-nav-item{display:flex;align-items:center;gap:.45rem;padding:.48rem .65rem;border-radius:7px;font-size:.835rem;color:rgba(255,255,255,.6);transition:all .15s;text-decoration:none;margin-bottom:1px}
.admin-nav-item:hover,.admin-nav-item.active{background:rgba(255,255,255,.12);color:#fff !important;text-decoration:none}
.admin-nav-item.active{font-weight:600}
.admin-main{margin-left:210px;flex:1;display:flex;flex-direction:column;min-width:0}
.admin-topbar{background:#fff;border-bottom:1px solid #e2e8f0;padding:.65rem 1.5rem;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50}
.admin-topbar-left{font-family:var(--font-heading);font-size:1.2rem;letter-spacing:.05em;color:#1e293b}
.admin-topbar-right{display:flex;align-items:center;gap:.75rem;font-size:.82rem;color:#64748b}
.admin-topbar-right a{color:#64748b;text-decoration:none}
.admin-topbar-right a:hover{color:var(--color-primary)}
.admin-content{padding:1.25rem 1.5rem;flex:1}
/* Cards */
.ac{background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;margin-bottom:1.5rem}
.ac-header{padding:.75rem 1.25rem;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem}
.ac-header h2{font-size:.95rem;font-weight:700;color:#1e293b;margin:0}
.ac-body{padding:1.25rem}
/* Table */
.at{width:100%;border-collapse:collapse;font-size:.83rem}
.at th{text-align:left;padding:.5rem 1rem;background:#f8fafc;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#64748b;border-bottom:1px solid #e2e8f0}
.at td{padding:.58rem 1rem;border-bottom:1px solid #f1f5f9;vertical-align:middle}
.at tr:last-child td{border-bottom:none}
.at tr:hover td{background:#fafafa}
/* Btns */
.btn{display:inline-flex;align-items:center;gap:.35rem;padding:.48rem 1rem;border-radius:7px;font-weight:600;font-size:.82rem;cursor:pointer;border:none;font-family:var(--font-body);transition:all .18s;text-decoration:none;white-space:nowrap}
.btn-primary{background:var(--color-primary);color:#fff}.btn-primary:hover{opacity:.9;text-decoration:none}
.btn-sm{padding:.28rem .65rem;font-size:.75rem}
.btn-danger{background:#fee2e2;color:#dc2626;border:1px solid #fecaca}
.btn-ghost{background:#fff;color:#374151;border:1.5px solid #e2e8f0}.btn-ghost:hover{border-color:var(--color-primary);color:var(--color-primary)}
.btn-success{background:#dcfce7;color:#166534;border:1px solid #bbf7d0}
/* Form */
.fg{display:flex;flex-direction:column;gap:.3rem;margin-bottom:.75rem}
.fg label{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#64748b}
.fg input,.fg select,.fg textarea{width:100%;border:1.5px solid #e2e8f0;border-radius:7px;padding:.5rem .8rem;font-family:var(--font-body);font-size:.855rem;color:#1e293b;background:#fff;outline:none;transition:border-color .2s}
.fg input:focus,.fg select:focus,.fg textarea:focus{border-color:var(--color-primary);box-shadow:0 0 0 3px color-mix(in srgb,var(--color-primary) 12%,transparent)}
.fg textarea{resize:vertical;min-height:80px}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
.span2{grid-column:span 2}
/* Badge */
.badge{display:inline-block;padding:.12rem .45rem;border-radius:4px;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em}
.badge-success{background:#dcfce7;color:#166534}.badge-warning{background:#fef3c7;color:#92400e}
.badge-error{background:#fee2e2;color:#991b1b}.badge-muted{background:#f1f5f9;color:#64748b}
.badge-primary{background:color-mix(in srgb,var(--color-primary) 12%,transparent);color:var(--color-primary)}
/* Toggle */
.toggle{position:relative;display:inline-flex;align-items:center;cursor:pointer}
.toggle input{opacity:0;width:0;position:absolute}
.toggle-track{width:38px;height:20px;background:#cbd5e1;border-radius:10px;transition:background .2s;position:relative}
.toggle-track::after{content:'';position:absolute;top:3px;left:3px;width:14px;height:14px;border-radius:50%;background:#fff;transition:transform .2s;box-shadow:0 1px 3px rgba(0,0,0,.2)}
.toggle input:checked + .toggle-track{background:#22c55e}
.toggle input:checked + .toggle-track::after{transform:translateX(18px)}
/* Alert */
.alert{padding:.7rem 1rem;border-radius:8px;font-size:.855rem;margin-bottom:1rem;display:flex;align-items:center;gap:.5rem}
.alert-success{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534}
.alert-error{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
.alert-info{background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af}
.alert-warning{background:#fffbeb;border:1px solid #fde68a;color:#92400e}
/* Misc */
.page-head{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;margin-bottom:1.25rem}
.page-head h1{font-family:var(--font-heading);font-size:1.7rem;letter-spacing:.05em;color:#1e293b;margin:0}
.stats-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:1rem;margin-bottom:1.5rem}
.stat-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:1rem;text-align:center}
.stat-icon{font-size:1.5rem;margin-bottom:.3rem}
.stat-val{font-size:1.7rem;font-weight:700;font-family:var(--font-heading);letter-spacing:.05em;color:#1e293b}
.stat-lbl{font-size:.68rem;color:#64748b;text-transform:uppercase;letter-spacing:.05em}
.role-badge{display:inline-block;font-size:.63rem;font-weight:700;text-transform:uppercase;padding:.1rem .38rem;border-radius:4px;letter-spacing:.05em}
.role-member{background:#dbeafe;color:#1e40af}.role-benevole{background:#ede9fe;color:#7c3aed}.role-coach{background:#d1fae5;color:#065f46}
.role-admin{background:#fef3c7;color:#92400e}.role-superadmin{background:#ede9fe;color:#4c1d95}

/* ── Hamburger mobile ── */
.admin-hamburger{display:none;flex-direction:column;gap:5px;cursor:pointer;padding:.5rem;background:none;border:none;margin-left:auto}
.admin-hamburger span{display:block;width:22px;height:2px;background:#0f172a;border-radius:2px;transition:all .3s}
.admin-sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:99}

@media(max-width:900px){
  /* Layout de base */
  body{flex-direction:column}
  .admin-sidebar{
    position:fixed;top:0;left:-100%;width:80%;max-width:280px;
    height:100vh;z-index:100;transition:left .28s ease;
    overflow-y:auto;box-shadow:4px 0 20px rgba(0,0,0,.25)
  }
  .admin-sidebar.open{left:0}
  .admin-sidebar-overlay.open{display:block}
  .admin-main{margin-left:0;width:100%}
  .admin-topbar{padding:.65rem 1rem}
  .admin-content{padding:1rem .875rem}
  .admin-hamburger{display:flex}
  .admin-nav{flex-direction:column;padding:.5rem}
  .admin-nav-group{display:block;margin-top:.5rem}
  .admin-nav-item{padding:.55rem .75rem;font-size:.875rem}

  /* Grilles → colonne unique */
  .form-row{grid-template-columns:1fr}
  .span2{grid-column:span 1}
  .stats-grid{grid-template-columns:repeat(2,1fr)}

  /* Tableaux → défilement horizontal */
  .at-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;border-radius:8px}
  .at{min-width:520px}
  .at th,.at td{padding:.45rem .65rem;font-size:.78rem}

  /* Page-head empile sur mobile */
  .page-head{flex-direction:column;align-items:flex-start;gap:.5rem}
  .page-head h1{font-size:1.35rem}

  /* Cards */
  .ac-body{padding:.875rem}
  .ac-header{padding:.625rem .875rem}

  /* Boutons en pleine largeur sur petits écrans */
  .btn-block-mobile{width:100%;justify-content:center}

  /* QR scanner lisible sur mobile */
  #qr-reader-main{max-width:100% !important}
  #qr-scanner-wrap{padding:.5rem !important}
}

@media(max-width:480px){
  .stats-grid{grid-template-columns:1fr 1fr}
  .admin-content{padding:.75rem .625rem}
  .btn{font-size:.78rem;padding:.4rem .8rem}
  .btn-sm{font-size:.72rem;padding:.25rem .5rem}
}
</style>
</head>
<body>
<aside class="admin-sidebar">
  <div class="admin-brand">
    <?php if(Config::get('logo')): ?><img src="<?=asset(Config::get('logo'))?>" alt=""><?php endif; ?>
    ⚙️ Admin
  </div>
  <nav class="admin-nav">
    <div class="admin-nav-group">Général</div>
    <a href="<?=u('/admin')?>"          class="admin-nav-item <?=$section==='dashboard'?'active':''?>">🏠 Tableau de bord</a>
    <?php if(Auth::isAdmin()): ?>
    <a href="<?=u('/admin/users')?>"    class="admin-nav-item <?=$section==='users'?'active':''?>">👥 Membres</a>
    <?php endif; ?>
    <a href="<?=u('/admin/licences')?>" class="admin-nav-item <?=$section==='licences'?'active':''?>">📄 Licences</a>

    <?php if(Auth::isAdmin()): ?>
    <div class="admin-nav-group">Contenu</div>
    <a href="<?=u('/admin/pageheaders')?>" class="admin-nav-item <?=$section==='pageheaders'?'active':''?>">🏷️ En-têtes pages</a>
    <a href="<?=u('/admin/pages')?>"    class="admin-nav-item <?=$section==='pages'?'active':''?>">📝 Pages & accueil</a>
    <a href="<?=u('/admin/articles')?>" class="admin-nav-item <?=$section==='articles'?'active':''?>">📰 Articles</a>
    <a href="<?=u('/admin/menu')?>"     class="admin-nav-item <?=$section==='menu'?'active':''?>">🔗 Menu</a>
    <a href="<?=u('/admin/legal')?>"    class="admin-nav-item <?=$section==='legal'?'active':''?>">⚖️ Mentions légales</a>
    <a href="<?=u('/admin/contact')?>"  class="admin-nav-item <?=$section==='contact'?'active':''?>">📬 Contact</a>
    <?php endif; ?>

    <div class="admin-nav-group">Modules</div>
    <a href="<?=u('/admin/planning')?>" class="admin-nav-item <?=$section==='planning'?'active':''?>">📅 Planning</a>
    <?php if(Auth::isAdmin() || Auth::canAccessBenevole()): ?>
    <a href="<?=u('/admin/benevole')?>" class="admin-nav-item <?=$section==='benevole'?'active':''?>">🤝 Bénévoles</a>
    <?php endif; ?>
    <?php if(Auth::isAdmin()): ?>
    <a href="<?=u('/admin/forum')?>"    class="admin-nav-item <?=$section==='forum'?'active':''?>">💬 Forum</a>
    <a href="<?=u('/admin/shop')?>"     class="admin-nav-item <?=$section==='shop'?'active':''?>">🛒 Boutique</a>
    <a href="<?=u('/admin/invoices')?>" class="admin-nav-item <?=$section==='invoices'?'active':''?>">🧾 Factures</a>
    <a href="<?=u('/admin/results')?>"  class="admin-nav-item <?=$section==='results'?'active':''?>">🏆 Résultats</a>
    <a href="<?=u('/admin/videos')?>"   class="admin-nav-item <?=$section==='videos'?'active':''?>">🎬 Vidéos</a>
    <a href="<?=u('/admin/gallery')?>"  class="admin-nav-item <?=$section==='gallery'?'active':''?>">📸 Galerie</a>
    <a href="<?=u('/admin/tombola')?>"  class="admin-nav-item <?=$section==='tombola'?'active':''?>">🎰 Tombola</a>
    <div class="admin-nav-group">Communication</div>
    <a href="<?=u('/admin/mails')?>"    class="admin-nav-item <?=$section==='mails'?'active':''?>">✉️ Modèles emails</a>
    <a href="<?=u('/admin/newsletter')?>" class="admin-nav-item <?=$section==='newsletter'?'active':''?>">📨 Newsletter</a>
    <a href="<?=u('/admin/popup')?>"     class="admin-nav-item <?=$section==='popup'?'active':''?>">🎉 Pop-up annonces</a>
    <?php endif; ?>

    <?php if(Auth::isSuperAdmin()): ?>
    <div class="admin-nav-group">Système</div>
    <a href="<?=u('/admin/config')?>"   class="admin-nav-item <?=$section==='config'?'active':''?>">⚙️ Paramètres</a>
    <a href="<?=u('/admin/update')?>"   class="admin-nav-item <?=$section==='update'?'active':''?>">🔄 Mise à jour</a>
    <a href="<?=u('/admin/logs')?>"     class="admin-nav-item <?=$section==='logs'?'active':''?>">📋 Logs & Backups</a>
    <?php endif; ?>

    <div style="margin-top:auto;padding:.4rem .6rem;border-top:1px solid rgba(255,255,255,.08)">
      <a href="<?=u('/')?>" class="admin-nav-item" style="opacity:.5;font-size:.78rem">← Voir le site</a>
    </div>
  </nav>
</aside>

<div class="admin-main">
  <div class="admin-topbar">
    <button class="admin-hamburger" id="admin-hamburger" onclick="toggleAdminMenu()" aria-label="Menu">
      <span></span><span></span><span></span>
    </button>
    <span class="admin-topbar-left"><?=Helpers::e($pageTitle??'Admin')?></span>
    <div class="admin-topbar-right">
      <?php $au=Auth::user(); ?>
      <span class="role-badge role-<?=Helpers::e($au['role'])?>"><?=Auth::ROLE_LABELS[$au['role']] ?? ucfirst($au['role'])?></span>
      <strong><?=Helpers::e($au['firstname'].' '.$au['lastname'])?></strong>
      <a href="<?=u('/logout')?>">Déconnexion</a>
    </div>
  </div>
  <div class="admin-content">
    <?php if($flash): ?><div class="alert alert-<?=$flash['type']?>"><?=Helpers::e($flash['msg'])?></div><?php endif; ?>
    <?=$content??''?>
  </div>
</div>
<script src="<?= asset('assets/js/main.js') ?>"></script>
<script>window.csrfToken='<?=Auth::csrfToken()?>';
async function apiPost(url,data){data.csrf_token=window.csrfToken;const r=await fetch(url,{method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify(data)});return r.json();}</script>
<?=$extraJs??''?>
<div class="admin-sidebar-overlay" id="admin-overlay" onclick="closeAdminMenu()"></div>
<script>
function toggleAdminMenu(){
  document.querySelector('.admin-sidebar').classList.toggle('open');
  document.getElementById('admin-overlay').classList.toggle('open');
}
function closeAdminMenu(){
  document.querySelector('.admin-sidebar').classList.remove('open');
  document.getElementById('admin-overlay').classList.remove('open');
}
window.addEventListener('resize',function(){
  if(window.innerWidth>900) closeAdminMenu();
});
</script>
</body></html>
