<?php
/**
 * ClubCMS — Module Vidéos
 * Routes : /videos, /videos?folder=X, /videos?v=X, /videos?dl=X
 */
// Enregistrer le module
try { Database::run("INSERT IGNORE INTO cc_modules (slug,label,enabled,require_login) VALUES ('videos','Vidéos',1,0)"); } catch(Exception $e) {}

// ── Téléchargement ────────────────────────────────────────────
if (isset($_GET['dl'])) {
    $vid = Database::one("SELECT * FROM cc_videos WHERE id=?", [(int)$_GET['dl']]);
    if ($vid && $vid['allow_download'] && $vid['filename']) {
        if ($vid['require_login'] && !Auth::check()) Helpers::redirect(u('/login'));
        $path = CC_ROOT.'/uploads/videos/'.$vid['filename'];
        if (file_exists($path)) {
            $ext  = pathinfo($vid['filename'], PATHINFO_EXTENSION);
            $name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $vid['title']).'.'.$ext;
            header('Content-Type: video/'.$ext);
            header('Content-Disposition: attachment; filename="'.  $name.'"'  );
            header('Content-Length: '.filesize($path));
            readfile($path); exit;
        }
    }
    Helpers::redirect(u('/videos'));
}

$folderId = (int)($_GET['folder'] ?? 0);
$videoId  = (int)($_GET['v']      ?? 0);

try { $folders = Database::all("SELECT * FROM cc_video_folders ORDER BY `order`,name"); } catch(Exception $e) { $folders=[]; }

if ($videoId) {
    $video   = Database::one("SELECT v.*,f.name AS folder_name FROM cc_videos v JOIN cc_video_folders f ON f.id=v.folder_id WHERE v.id=?", [$videoId]);
    if (!$video) Helpers::redirect(u('/videos'));
    if ($video['require_login'] && !Auth::check()) Helpers::redirect(u('/login?return=/videos?v='.$videoId));
    $related = Database::all("SELECT * FROM cc_videos WHERE folder_id=? AND id!=? ORDER BY created_at DESC LIMIT 6", [$video['folder_id'],$videoId]);
    $pageTitle = Helpers::e($video['title']).' — Vidéos';
} elseif ($folderId) {
    $folder = Database::one("SELECT * FROM cc_video_folders WHERE id=?", [$folderId]);
    if (!$folder) Helpers::redirect(u('/videos'));
    if ($folder['require_login'] && !Auth::check()) Helpers::redirect(u('/login'));
    $videos = Database::all("SELECT * FROM cc_videos WHERE folder_id=? ORDER BY `order`,created_at DESC", [$folderId]);
    $pageTitle = Helpers::e($folder['name']).' — Vidéos';
} else {
    $pageTitle = 'Médiathèque vidéos';
}

ob_start();

// En-tête personnalisable (comme galerie)
$ph_t = Config::get('ph_videos_title',    '');
$ph_s = Config::get('ph_videos_subtitle', '');
?>

<!-- Bannière hero -->
<div class="gallery-hero">
  <div class="container">
    <h1 class="forum-title"><?= $ph_t ? Helpers::e($ph_t) : '🎬 Médiathèque' ?></h1>
    <?php if($ph_s): ?>
    <p class="forum-subtitle"><?= Helpers::e($ph_s) ?></p>
    <?php else: ?>
    <p class="forum-subtitle">Vidéos et tutoriels du club</p>
    <?php endif; ?>
  </div>
</div>

<div class="container" style="padding:2rem 0;max-width:1100px;margin:0 auto">

<?php if($videoId && isset($video)): ?>
<!-- ── Vidéo individuelle ── -->
<nav style="font-size:.82rem;color:#94a3b8;margin-bottom:1.25rem">
  <a href="<?=u('/videos')?>" style="color:var(--color-primary);text-decoration:none">Vidéos</a>
  › <a href="<?=u('/videos?folder='.$video['folder_id'])?>" style="color:var(--color-primary);text-decoration:none"><?=Helpers::e($video['folder_name'])?></a>
  › <?=Helpers::e($video['title'])?>
</nav>

<div style="display:grid;grid-template-columns:1fr 300px;gap:1.5rem;align-items:start">
  <div>
    <div style="aspect-ratio:16/9;background:#000;border-radius:12px;overflow:hidden;margin-bottom:1rem">
      <?php if($video['embed_url']): ?>
      <iframe src="<?=Helpers::e($video['embed_url'])?>" style="width:100%;height:100%;border:none"
        allowfullscreen allow="accelerometer;autoplay;clipboard-write;encrypted-media;gyroscope;picture-in-picture"></iframe>
      <?php elseif($video['filename']): ?>
      <video controls style="width:100%;height:100%;background:#000"
        <?=$video['allow_download'] ? '' : 'controlslist="nodownload"' ?>
        <?php if($video['thumbnail']): ?>poster="<?=u('/uploads/videos/thumbs/'.$video['thumbnail'])?>"<?php endif; ?>>
        <source src="<?=u('/uploads/videos/'.$video['filename'])?>" type="video/<?=pathinfo($video['filename'],PATHINFO_EXTENSION)?>">
        Votre navigateur ne supporte pas la lecture vidéo.
      </video>
      <?php else: ?>
      <div style="display:flex;align-items:center;justify-content:center;height:100%;color:#fff;font-size:1.25rem">🎬 Aucune source</div>
      <?php endif; ?>
    </div>
    <h1 style="font-size:1.35rem;font-weight:800;margin-bottom:.5rem"><?=Helpers::e($video['title'])?></h1>
    <?php if($video['description']): ?>
    <p style="color:#64748b;line-height:1.7;margin-bottom:1rem"><?=Helpers::e($video['description'])?></p>
    <?php endif; ?>
    <div style="display:flex;gap:.75rem;flex-wrap:wrap">
      <?php if($video['allow_download'] && $video['filename']): ?>
      <a href="<?=u('/videos?dl='.$video['id'])?>" class="btn btn-primary">⬇️ Télécharger</a>
      <?php endif; ?>
      <a href="<?=u('/videos?folder='.$video['folder_id'])?>" class="btn btn-ghost">← Retour</a>
    </div>
  </div>
  <?php if(!empty($related)): ?>
  <div>
    <h3 style="font-size:.82rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.75rem">Dans ce dossier</h3>
    <div style="display:flex;flex-direction:column;gap:.625rem">
      <?php foreach($related as $r): ?>
      <a href="<?=u('/videos?v='.$r['id'])?>" style="display:flex;gap:.625rem;text-decoration:none;border:1.5px solid #e2e8f0;border-radius:8px;overflow:hidden;background:#fff;transition:border-color .15s"
        onmouseover="this.style.borderColor='var(--color-primary)'" onmouseout="this.style.borderColor='#e2e8f0'">
        <div style="width:90px;flex-shrink:0;aspect-ratio:16/9;background:#1e293b;display:flex;align-items:center;justify-content:center">
          <?php if($r['thumbnail']): ?><img src="<?=u('/uploads/videos/thumbs/'.$r['thumbnail'])?>" style="width:100%;height:100%;object-fit:cover">
          <?php else: ?><span style="font-size:1.25rem">🎬</span><?php endif; ?>
        </div>
        <div style="padding:.5rem;flex:1;min-width:0">
          <div style="font-weight:600;font-size:.8rem;color:#1e293b;line-height:1.3"><?=Helpers::e($r['title'])?></div>
          <?php if($r['allow_download']): ?><div style="font-size:.68rem;color:#16a34a;margin-top:.2rem">⬇️ Téléchargeable</div><?php endif; ?>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php elseif($folderId && isset($folder)): ?>
<!-- ── Vidéos d'un dossier ── -->
<nav style="font-size:.82rem;color:#94a3b8;margin-bottom:1.25rem">
  <a href="<?=u('/videos')?>" style="color:var(--color-primary);text-decoration:none">Vidéos</a> › <?=Helpers::e($folder['name'])?>
</nav>
<h2 style="font-size:1.35rem;font-weight:800;margin-bottom:1.25rem">🎬 <?=Helpers::e($folder['name'])?></h2>

<?php if(empty($videos)): ?>
<div style="text-align:center;padding:3rem;color:#94a3b8;background:#f8fafc;border-radius:12px;border:2px dashed #e2e8f0">
  Aucune vidéo dans ce dossier.
</div>
<?php else: ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1.25rem">
  <?php foreach($videos as $v): ?>
  <a href="<?=u('/videos?v='.$v['id'])?>" style="display:block;text-decoration:none;border:1.5px solid #e2e8f0;border-radius:12px;overflow:hidden;background:#fff;transition:all .15s"
    onmouseover="this.style.borderColor='var(--color-primary)';this.style.transform='translateY(-2px)'"
    onmouseout="this.style.borderColor='#e2e8f0';this.style.transform='none'">
    <div style="aspect-ratio:16/9;background:#1e293b;position:relative;overflow:hidden">
      <?php if($v['thumbnail']): ?><img src="<?=u('/uploads/videos/thumbs/'.$v['thumbnail'])?>" style="width:100%;height:100%;object-fit:cover">
      <?php else: ?><div style="display:flex;align-items:center;justify-content:center;height:100%;font-size:2.5rem">🎬</div><?php endif; ?>
      <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center">
        <div style="width:44px;height:44px;background:rgba(255,255,255,.9);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.1rem">▶</div>
      </div>
    </div>
    <div style="padding:.875rem">
      <div style="font-weight:700;font-size:.9rem;color:#1e293b;margin-bottom:.25rem"><?=Helpers::e($v['title'])?></div>
      <?php if($v['description']): ?><div style="font-size:.78rem;color:#64748b"><?=Helpers::e(mb_substr($v['description'],0,80)).(mb_strlen($v['description'])>80?'…':'')?></div><?php endif; ?>
      <div style="display:flex;gap:.35rem;margin-top:.5rem;flex-wrap:wrap">
        <?php if($v['allow_download']): ?><span style="font-size:.68rem;background:#f0fdf4;color:#16a34a;padding:.15rem .4rem;border-radius:4px">⬇️ Téléchargeable</span><?php endif; ?>
        <?php if($v['require_login']): ?><span style="font-size:.68rem;background:#eff6ff;color:#2563eb;padding:.15rem .4rem;border-radius:4px">🔒 Membres</span><?php endif; ?>
      </div>
    </div>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php else: ?>
<!-- ── Liste des dossiers (page principale) ── -->
<?php if(empty($folders)): ?>
<div style="text-align:center;padding:4rem;color:#94a3b8;background:#f8fafc;border-radius:12px;border:2px dashed #e2e8f0">
  <div style="font-size:3rem;margin-bottom:.75rem">🎬</div>
  <div style="font-weight:600;font-size:1rem">Aucune vidéo disponible pour le moment.</div>
</div>
<?php else: ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1.25rem">
  <?php foreach($folders as $f):
    if($f['require_login'] && !Auth::check()) continue;
    $count      = (int)Database::scalar("SELECT COUNT(*) FROM cc_videos WHERE folder_id=?", [$f['id']]);
    $firstThumb = Database::one("SELECT thumbnail FROM cc_videos WHERE folder_id=? AND thumbnail IS NOT NULL LIMIT 1", [$f['id']]);
  ?>
  <a href="<?=u('/videos?folder='.$f['id'])?>" style="display:block;text-decoration:none;border:1.5px solid #e2e8f0;border-radius:12px;overflow:hidden;background:#fff;transition:all .15s"
    onmouseover="this.style.borderColor='var(--color-primary)';this.style.transform='translateY(-2px)'"
    onmouseout="this.style.borderColor='#e2e8f0';this.style.transform='none'">
    <div style="aspect-ratio:16/9;background:linear-gradient(135deg,#1e293b,#334155);position:relative;overflow:hidden">
      <?php if($firstThumb): ?><img src="<?=u('/uploads/videos/thumbs/'.$firstThumb['thumbnail'])?>" style="width:100%;height:100%;object-fit:cover;opacity:.5"><?php endif; ?>
      <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;color:#fff">
        <span style="font-size:2.25rem">🎬</span>
        <span style="font-size:.8rem;margin-top:.4rem;background:rgba(0,0,0,.45);padding:.2rem .7rem;border-radius:99px">
          <?=$count?> vidéo<?=$count!=1?'s':''?>
        </span>
      </div>
    </div>
    <div style="padding:.875rem">
      <div style="font-weight:700;font-size:.95rem;color:#1e293b"><?=Helpers::e($f['name'])?></div>
      <?php if($f['description']): ?><div style="font-size:.78rem;color:#64748b;margin-top:.2rem"><?=Helpers::e($f['description'])?></div><?php endif; ?>
      <?php if($f['require_login']): ?>
      <span style="font-size:.68rem;background:#eff6ff;color:#2563eb;padding:.15rem .4rem;border-radius:4px;margin-top:.4rem;display:inline-block">🔒 Membres</span>
      <?php endif; ?>
    </div>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>

</div>

<?php
$content = ob_get_clean();
include CC_ROOT . '/templates/layout.php';
