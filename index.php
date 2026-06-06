<?php
/**
 * ClubCMS — Front Controller
 * Compatible XAMPP : toutes les URLs = index.php?route=xxx
 */
if (!defined('CC_ROOT'))    define('CC_ROOT', __DIR__);
if (!defined('CC_VERSION')) define('CC_VERSION', '1.4.0');

// ── Vérification config + BDD ────────────────────────────────
if (!file_exists(__DIR__ . '/config/config.php')) {
    header('Location: install.php'); exit;
}

// Charger la config — si la BDD plante, on redirige vers install
require_once __DIR__ . '/config/config.php';

// Test connexion BDD sans bloquer
try {
    Database::get();
} catch (Throwable $e) {
    // BDD inaccessible = installation incomplète ou mauvaise config
    // On redirige vers install.php pour reconfigurer
    header('Location: install.php?error=db'); exit;
}

Auth::startSession();

// ── Basepath (sous-dossier XAMPP ou racine) ───────────────────
$BASE = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
define('CC_BASE', $BASE);

// ── Fonctions URL ─────────────────────────────────────────────
function u(string $path): string {
    $p = ltrim($path, '/');
    if (empty($p) || $p === 'home') return CC_BASE . '/index.php?route=home';
    if (strpos($p, '?') !== false) {
        [$pp, $qs] = explode('?', $p, 2);
        return CC_BASE . '/index.php?route=' . $pp . '&' . $qs;
    }
    return CC_BASE . '/index.php?route=' . $p;
}

function asset(string $path): string {
    return CC_BASE . '/' . ltrim($path, '/');
}

// ── Route ─────────────────────────────────────────────────────
$route = trim($_GET['route'] ?? '', '/');
if ($route === '' || $route === 'index.php') $route = 'home';
if (preg_match('/\.(css|js|png|jpg|jpeg|gif|ico|woff|woff2|svg|map|txt)$/i', $route)) {
    http_response_code(404); exit;
}

$segments = explode('/', $route);
$module   = $segments[0];
$action   = $segments[1] ?? 'index';
$param    = $segments[2] ?? null;

if ($module === 'install') { header('Location: install.php'); exit; }

// Maintenance
if (Config::get('maintenance_mode') && !Auth::isSuperAdmin()) {
    http_response_code(503); include CC_ROOT . '/templates/maintenance.php'; exit;
}

function moduleEnabled(string $slug): bool {
    static $mods = null;
    if ($mods === null) {
        foreach (Database::all("SELECT slug,enabled,require_login FROM cc_modules") as $r)
            $mods[$r['slug']] = $r;
    }
    if (!isset($mods[$slug])) return true;
    if (!$mods[$slug]['enabled']) { http_response_code(404); include CC_ROOT.'/templates/404.php'; exit; }
    if ($mods[$slug]['require_login'] && !Auth::check()) { header('Location: '.u('/login')); exit; }
    return true;
}

// ── Routage ────────────────────────────────────────────────────
switch ($module) {
    case 'home':               include CC_ROOT.'/modules/home/controller.php'; break;
    case 'login':              include CC_ROOT.'/modules/auth/login.php'; break;
    case 'logout':             Auth::logout(); header('Location: '.u('/')); exit;
    case 'register':           include CC_ROOT.'/modules/auth/register.php'; break;
    case 'verify-email':       include CC_ROOT.'/modules/auth/verify.php'; break;
    case 'forgot-password':
    case 'reset-password':     include CC_ROOT.'/modules/auth/password.php'; break;
    case 'membre':
    case 'profile':            Auth::require('member'); include CC_ROOT.'/modules/members/controller.php'; break;
    case 'verifier-carte':     include CC_ROOT.'/modules/members/verify_card.php'; break;
    case 'actualites':
    case 'articles':           include CC_ROOT.'/modules/articles/controller.php'; break;
    case 'forum':              moduleEnabled('forum');   include CC_ROOT.'/modules/forum/controller.php'; break;
    case 'boutique':
    case 'shop':               moduleEnabled('shop');    include CC_ROOT.'/modules/shop/controller.php'; break;
    case 'galerie':
    case 'gallery':            moduleEnabled('gallery'); include CC_ROOT.'/modules/gallery/controller.php'; break;
    case 'videos':
    case 'video':              include CC_ROOT.'/modules/videos/controller.php'; break;
    case 'planning':
    case 'agenda':             moduleEnabled('planning');include CC_ROOT.'/modules/planning/controller.php'; break;
    case 'benevole':           include CC_ROOT.'/modules/benevole/controller.php'; break;
    case 'admin':
        if (!Auth::check())          { header('Location: '.u('/login')); exit; }
        if (!Auth::hasRole('coach')) { http_response_code(403); include CC_ROOT.'/templates/403.php'; exit; }
        include CC_ROOT.'/admin/controller.php'; break;
    case 'api':
        header('Content-Type: application/json');
        include CC_ROOT.'/modules/api/router.php'; break;
    case 'newsletter':
        if ($action === 'unsubscribe') {
            $token = Helpers::sanitize($_GET['token'] ?? '');
            if ($token) Database::run("UPDATE cc_newsletter_subscribers SET active=0 WHERE token=?", [$token]);
            $pageTitle = 'Désabonnement'; ob_start();
            echo '<div style="text-align:center;padding:5rem 2rem"><h1 style="font-family:var(--font-heading)">Désabonnement effectué</h1><a href="'.u('/').'">← Accueil</a></div>';
            $content = ob_get_clean(); include CC_ROOT.'/templates/layout.php';
        }
        break;
    case 'mentions-legales': case 'confidentialite': case 'cgu':
    case 'cgv': case 'reglement': case 'cookies':
        $lkey = str_replace('-','_',$module);
        $lt   = ['mentions_legales'=>'Mentions légales','confidentialite'=>'Politique de confidentialité','cgu'=>'CGU','cgv'=>'CGV','reglement'=>'Règlement intérieur','cookies'=>'Politique de cookies'];
        $pageTitle = ($lt[$lkey]??$module).' — '.Config::get('club_name');
        ob_start();
        echo '<div class="container" style="padding:3rem 1.5rem;max-width:860px">';
        echo '<h1 style="font-family:var(--font-heading);font-size:2.5rem;letter-spacing:.06em;margin-bottom:2rem">'.Helpers::e($lt[$lkey]??$module).'</h1>';
        $lc = Config::get('legal_'.$lkey,'');
        echo $lc ? '<div style="line-height:1.85">'.$lc.'</div>' : '<p style="color:var(--color-muted)">Page en cours de rédaction.</p>';
        echo '</div>';
        $content=ob_get_clean(); include CC_ROOT.'/templates/layout.php';
        break;
    default:
        if (!class_exists('BlockRenderer')) require_once CC_ROOT . '/core/BlockRenderer.php';
require_once CC_ROOT . '/core/CriteriaRenderer.php';
        $article = Database::one("SELECT * FROM cc_articles WHERE slug=? AND (published=1 OR ?=1)", [$route, Auth::isAdmin()?1:0]);
        if ($article) {
            if (!$article['published'] && !Auth::isAdmin()) { http_response_code(404); include CC_ROOT.'/templates/404.php'; break; }
            // Gestion accès : public / teaser (visible mais message) / members (redirect)
            $access = $article['access_mode'] ?? ($article['require_login'] ? 'members' : 'public');
            if ($access === 'members' && !Auth::check()) {
                header('Location: '.u('/login')); exit;
            }
            $showAccessTeaser = ($access === 'teaser' && !Auth::check());
            $pageTitle = Helpers::e($article['title']).' — '.Config::get('club_name');
            ob_start(); ?>
<div class="container" style="padding:3rem 1.5rem;max-width:860px">
  <?php if(!$article['published']&&Auth::isAdmin()):?><div style="background:#fef3c7;border:1px solid #fde68a;border-radius:8px;padding:.75rem 1rem;margin-bottom:1.5rem;font-size:.875rem;color:#92400e">⚠️ <strong>Non publié</strong> — visible admins seulement. <a href="<?=u('/admin/articles?edit='.$article['id'])?>">✏️ Modifier</a></div><?php endif;?>
  <?php if($article['type']==='article'&&$article['cover']):?><img src="<?=asset($article['cover'])?>" alt="" style="width:100%;max-height:420px;object-fit:cover;border-radius:12px;margin-bottom:2rem"><?php endif;?>
  <?php if($article['type']==='article'):?><div style="color:var(--color-muted);font-size:.875rem;margin-bottom:.5rem"><?=Helpers::dateFormat($article['created_at'])?></div><?php endif;?>
  <h1 style="font-family:var(--font-heading);font-size:clamp(1.8rem,4vw,3rem);letter-spacing:.05em;margin-bottom:1.75rem;line-height:1.1"><?=Helpers::e($article['title'])?></h1>
  <?php if ($showAccessTeaser ?? false): ?>
  <div style="background:#fef3c7;border:2px solid #fde68a;border-radius:14px;padding:2rem 2.5rem;text-align:center;margin:2rem 0">
    <div style="font-size:2rem;margin-bottom:.75rem">🔒</div>
    <h3 style="font-family:var(--font-heading);font-size:1.4rem;margin-bottom:.5rem;color:#92400e">
      <?=Helpers::e($article['access_message'] ?: 'Contenu réservé aux membres')?>
    </h3>
    <p style="color:#b45309;margin-bottom:1.5rem;font-size:.95rem">Pour accéder à cette partie du site, merci de vous connecter.</p>
    <a href="<?=u('/login')?>" class="btn btn-primary" style="font-size:1rem;padding:.75rem 2rem">🔑 Se connecter</a>
    <a href="<?=u('/register')?>" style="display:block;margin-top:.75rem;font-size:.875rem;color:#b45309">Pas encore membre ? S'inscrire →</a>
  </div>
  <?php else: ?>
  <div><?=BlockRenderer::render($article['content'])?></div>
  <?php endif; ?>
  <div style="margin-top:2rem;padding-top:1.5rem;border-top:1px solid var(--color-border)">
    <a href="<?=u($article['type']==='article'?'/actualites':'/')?>" style="color:var(--color-muted);font-size:.875rem">← <?=$article['type']==='article'?'Toutes les actualités':'Accueil'?></a>
  </div>
</div>
            <?php
            $content=ob_get_clean(); include CC_ROOT.'/templates/layout.php';
        } else {
            http_response_code(404); include CC_ROOT.'/templates/404.php';
        }
}
