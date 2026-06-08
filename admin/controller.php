<?php
/**
 * ClubCMS — Admin Controller
 * Coach : accès planning + licences uniquement
 * Admin/SuperAdmin : accès complet
 */
if (!Auth::check()) { header('Location: '.u('/login')); exit; }
if (!Auth::hasRole('coach')) { http_response_code(403); include CC_ROOT.'/templates/403.php'; exit; }

$section = $segments[1] ?? 'dashboard';
$subact  = $segments[2] ?? 'index';
$itemId  = (int)($segments[3] ?? 0);

// Coach : accès restreint à planning et licences uniquement
if (Auth::isCoach() && !Auth::isAdmin()) {
    $allowedForCoach = ['planning','licences','dashboard'];
    if (!in_array($section, $allowedForCoach)) {
        $section = 'dashboard';
    }
}

$flash = $_SESSION['admin_flash'] ?? null;
unset($_SESSION['admin_flash']);

function adminFlash(string $type, string $msg): void {
    $_SESSION['admin_flash'] = compact('type','msg');
}

// AJAX rapide modules/users
if (Helpers::isAjax() && $_SERVER['REQUEST_METHOD'] === 'POST' && Auth::verifyCsrf()) {
    if ($section === 'modules') {
        Auth::require('superadmin');
        $slug  = Helpers::sanitize($_POST['slug'] ?? '');
        $field = in_array($_POST['field']??'',['enabled','require_login']) ? $_POST['field'] : null;
        $val   = (int)($_POST['value'] ?? 0);
        if ($slug && $field) Database::run("UPDATE cc_modules SET `$field`=? WHERE slug=?",[$val,$slug]);
        Helpers::json(['success'=>true]);
    }
    if ($section === 'users') {
        Auth::require('admin');
        $uid   = (int)($_POST['user_id'] ?? 0);
        $field = in_array($_POST['field']??'',['role','status']) ? $_POST['field'] : null;
        $val   = Helpers::sanitize($_POST['value'] ?? '');
        if ($uid && $field) {
            if ($field==='role' && !Auth::isSuperAdmin()) Helpers::json(['error'=>'Interdit'],403);
            Database::run("UPDATE cc_users SET `$field`=? WHERE id=?",[$val,$uid]);
        }
        Helpers::json(['success'=>true]);
    }
}

$map = [
    'dashboard'  => 'dashboard',
    'users'      => 'users',
    'licences'   => 'licences',
    'planning'   => 'planning_admin',
    'forum'      => 'forum_admin',
    'shop'       => 'shop_admin',
    'gallery'    => 'gallery_admin',
    'videos'     => 'videos_admin',
    'results'    => 'results_admin',
    'articles'   => 'articles_admin',
    'pages'      => 'pages_admin',
    'contact'    => 'contact_admin',
    'tombola'    => 'tombola_admin',
    'pageheaders'=> 'pageheaders_admin',
    'menu'       => 'menu_admin',
    'mails'      => 'mails_admin',
    'newsletter' => 'newsletter_admin',
    'legal'      => 'legal_admin',
    'modules'    => 'modules_admin',
    'config'     => 'config_admin',
    'update'     => 'update_admin',
    'popup'      => 'popup_admin',
    'benevole'   => 'benevole_admin',
];

$file = $map[$section] ?? 'dashboard';
$path = CC_ROOT . '/admin/sections/' . $file . '.php';
if (file_exists($path)) {
    include $path;
} else {
    adminFlash('error', "Section introuvable : $file");
    include CC_ROOT . '/admin/sections/dashboard.php';
}
