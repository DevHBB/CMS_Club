<?php
/**
 * Menu de navigation — Style toggles comme la page Modules
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Auth::verifyCsrf()) {
    adminFlash('error','CSRF'); Helpers::redirect(u('/admin/menu'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = $_POST['items'] ?? [];
    $cleaned = [];
    foreach ($raw as $item) {
        $label = Helpers::sanitize($item['label'] ?? '');
        if ($label === '') continue;
        $entry = [
            'label'        => $label,
            'url'          => Helpers::sanitize($item['url'] ?? '/'),
            'visible'      => (int)($item['visible'] ?? 1),
            'access_mode'  => in_array($item['access_mode']??'public',['public','teaser','members']) ? $item['access_mode'] : 'public',
            'children'     => [],
        ];
        foreach ($item['children'] ?? [] as $child) {
            $cl = Helpers::sanitize($child['label'] ?? '');
            if ($cl === '') continue;
            $entry['children'][] = [
                'label'        => $cl,
                'url'          => Helpers::sanitize($child['url'] ?? '/'),
                'visible'      => (int)($child['visible'] ?? 1),
                'access_mode'  => in_array($child['access_mode']??'public',['public','teaser','members']) ? $child['access_mode'] : 'public',
            ];
        }
        $cleaned[] = $entry;
    }
    Config::set('menu_items', json_encode($cleaned, JSON_UNESCAPED_UNICODE), 'menu');
    adminFlash('success','Menu sauvegardé ✓');
    Helpers::redirect(u('/admin/menu'));
}

$menuItems = json_decode(Config::get('menu_items','[]'), true);
if (!is_array($menuItems)) $menuItems = [];

// Si le menu est vide, on le pré-remplit avec les modules actifs (synchronisation automatique)
if (empty($menuItems)) {
    $activeModules = Database::all("SELECT slug, label FROM cc_modules WHERE enabled=1 ORDER BY slug");
    $slugToUrl = [
        'forum'    => '/forum',
        'shop'     => '/boutique',
        'gallery'  => '/galerie',
        'videos'   => '/videos',
        'planning' => '/planning',
        'members'  => '/annuaire',
    ];
    $slugToLabel = [
        'forum'    => 'Forum',
        'shop'     => 'Boutique',
        'gallery'  => 'Galerie',
        'videos'   => 'Vidéos',
        'planning' => 'Planning',
        'members'  => 'Annuaire',
    ];
    $menuItems = [
        ['label'=>'Accueil', 'url'=>'/', 'visible'=>1, 'access_mode'=>'public', 'children'=>[]],
    ];
    foreach ($activeModules as $mod) {
        $s = $mod['slug'];
        if (!isset($slugToUrl[$s])) continue;
        $menuItems[] = [
            'label'        => $slugToLabel[$s] ?? $mod['label'],
            'url'          => $slugToUrl[$s],
            'visible'      => 1,
            'access_mode'  => 'public',
            'children'     => [],
        ];
    }
    // Sauvegarder immédiatement pour synchroniser
    Config::set('menu_items', json_encode($menuItems, JSON_UNESCAPED_UNICODE), 'menu');
}

// Pages et articles disponibles pour suggestion
$suggestions = [
    ['🏠', 'Accueil',     '/'],
    ['📰', 'Actualités',  '/actualites'],
    ['💬', 'Forum',       '/forum'],
    ['🛒', 'Boutique',    '/boutique'],
    ['📸', 'Galerie',     '/galerie'],
    ['🎬', 'Vidéos',      '/videos'],
    ['🏆', 'Résultats',  '/resultats'],
    ['📅', 'Planning',    '/planning'],
    ['👤', 'Mon compte',  '/membre'],
    ['📝', 'Inscription', '/register'],
    ['📬', 'Contact',     '/contact'],
    ['🎰', 'Tombola',     '/tombola'],
];
$pages = Database::all("SELECT title, slug, type FROM cc_articles WHERE published=1 ORDER BY type, title");

$pageTitle = 'Menu de navigation';
ob_start();
?>

<style>
/* ── Toggles style modules ── */
.menu-toggle {
  position: relative;
  width: 44px; height: 24px;
  border-radius: 12px;
  border: none;
  cursor: pointer;
  transition: background .2s;
  flex-shrink: 0;
}
.menu-toggle::after {
  content: '';
  position: absolute;
  top: 3px; left: 3px;
  width: 18px; height: 18px;
  border-radius: 50%;
  background: #fff;
  transition: transform .2s;
  box-shadow: 0 1px 4px rgba(0,0,0,.25);
}
.menu-toggle.on  { background: #22c55e; }
.menu-toggle.off { background: #cbd5e1; }
.menu-toggle.on::after  { transform: translateX(20px); }

/* ── Ligne menu ── */
.menu-row {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  margin-bottom: .5rem;
  overflow: hidden;
  transition: box-shadow .2s;
}
.menu-row:hover { box-shadow: 0 2px 10px rgba(0,0,0,.07); }
.menu-row-main {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: .875rem 1.25rem;
}
.menu-handle {
  cursor: grab;
  color: #cbd5e1;
  font-size: 1.2rem;
  flex-shrink: 0;
  user-select: none;
}
.menu-handle:active { cursor: grabbing; }
.menu-info { flex: 1; min-width: 0; }
.menu-label-edit {
  font-weight: 600;
  font-size: .9rem;
  color: #1e293b;
  border: none;
  outline: none;
  background: transparent;
  font-family: var(--font-body);
  width: 100%;
}
.menu-label-edit:focus {
  background: #f8fafc;
  border-radius: 4px;
  padding: .1rem .3rem;
  margin: -.1rem -.3rem;
}
.menu-url-edit {
  font-size: .78rem;
  color: #94a3b8;
  border: none;
  outline: none;
  background: transparent;
  font-family: var(--font-body);
  width: 100%;
  margin-top: .1rem;
}
.menu-url-edit:focus {
  color: #64748b;
  background: #f8fafc;
  border-radius: 3px;
  padding: .05rem .25rem;
  margin: -.05rem -.25rem;
}
.menu-toggles {
  display: flex;
  flex-direction: column;
  gap: .4rem;
  flex-shrink: 0;
  align-items: center;
}
.toggle-row {
  display: flex;
  align-items: center;
  gap: .5rem;
}
.toggle-label {
  font-size: .72rem;
  color: #64748b;
  white-space: nowrap;
  width: 80px;
}
.menu-actions-col {
  display: flex;
  flex-direction: column;
  gap: .3rem;
  flex-shrink: 0;
}
.btn-sub {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  padding: .25rem .65rem;
  font-size: .72rem;
  cursor: pointer;
  color: #64748b;
  font-family: var(--font-body);
  white-space: nowrap;
  transition: all .15s;
  text-align: left;
}
.btn-sub:hover { background: #eff6ff; border-color: #bfdbfe; color: #1d4ed8; }
.btn-del {
  background: none;
  border: none;
  cursor: pointer;
  color: #e2e8f0;
  font-size: 1.2rem;
  padding: 0;
  transition: color .15s;
  line-height: 1;
}
.btn-del:hover { color: #dc2626; }

/* Sous-menus */
.menu-children {
  display: none;
  border-top: 1px dashed #e2e8f0;
  background: #fafafa;
  padding: .6rem 1.25rem .6rem 3.5rem;
}
.menu-children.open { display: block; }
.child-row {
  display: flex;
  align-items: center;
  gap: .6rem;
  padding: .4rem 0;
  border-bottom: 1px solid #f1f5f9;
}
.child-row:last-of-type { border-bottom: none; }
.child-input {
  border: 1px solid #f1f5f9;
  border-radius: 6px;
  padding: .3rem .5rem;
  font-size: .84rem;
  font-family: var(--font-body);
  background: #fff;
  outline: none;
  transition: border-color .15s;
}
.child-input:focus { border-color: var(--color-primary); }
.child-input.lbl { flex: 1; font-weight: 500; color: #374151; }
.child-input.url { flex: 1.2; color: #94a3b8; font-size: .8rem; }
.btn-add-child {
  display: flex; align-items: center; gap: .4rem;
  margin-top: .5rem;
  background: none;
  border: 1px dashed #e2e8f0;
  border-radius: 6px;
  padding: .4rem .875rem;
  font-size: .8rem;
  color: #94a3b8;
  cursor: pointer;
  font-family: var(--font-body);
  transition: all .15s;
  width: 100%;
}
.btn-add-child:hover { border-color: var(--color-primary); color: var(--color-primary); }

/* Modal ajout de lien */
.add-modal-overlay {
  display: none; position: fixed; inset: 0;
  background: rgba(15,23,42,.55);
  backdrop-filter: blur(3px);
  -webkit-backdrop-filter: blur(3px);
  z-index: 9999;
  align-items: center; justify-content: center;
  padding: 1.5rem;
}
.add-modal-overlay.open { display: flex; }

.add-modal {
  background: #fff;
  border-radius: 20px;
  width: 100%; max-width: 560px;
  max-height: 85vh;
  display: flex; flex-direction: column;
  box-shadow: 0 32px 80px rgba(0,0,0,.22), 0 0 0 1px rgba(0,0,0,.04);
  overflow: hidden;
  animation: modalIn .18s ease;
}
@keyframes modalIn {
  from { opacity:0; transform: scale(.96) translateY(8px); }
  to   { opacity:1; transform: scale(1)  translateY(0); }
}

.add-modal-head {
  padding: 1.25rem 1.5rem 1rem;
  display: flex; align-items: flex-start; justify-content: space-between;
  flex-shrink: 0;
}
.add-modal-head-title {
  font-family: var(--font-heading);
  font-size: 1.15rem;
  font-weight: 700;
  letter-spacing: .03em;
  color: #1e293b;
}
.add-modal-head-sub {
  font-size: .78rem;
  color: #94a3b8;
  margin-top: .15rem;
}
.add-modal-close {
  background: #f1f5f9;
  border: none;
  border-radius: 8px;
  width: 32px; height: 32px;
  display: flex; align-items: center; justify-content: center;
  cursor: pointer;
  font-size: 1rem;
  color: #64748b;
  flex-shrink: 0;
  margin-left: .75rem;
  transition: background .15s, color .15s;
}
.add-modal-close:hover { background: #e2e8f0; color: #1e293b; }

/* Barre de recherche */
.add-modal-search-wrap {
  padding: 0 1.25rem .75rem;
  flex-shrink: 0;
}
.add-modal-search {
  display: block; width: 100%;
  border: 1.5px solid #e2e8f0; border-radius: 10px;
  padding: .65rem 1rem .65rem 2.5rem;
  font-size: .92rem;
  font-family: var(--font-body);
  outline: none;
  background: #f8fafc url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2'%3E%3Ccircle cx='11' cy='11' r='8'/%3E%3Cpath d='m21 21-4.35-4.35'/%3E%3C/svg%3E") no-repeat .75rem center;
  background-size: 16px;
  color: #1e293b;
  box-sizing: border-box;
  transition: border-color .15s, box-shadow .15s;
}
.add-modal-search:focus {
  border-color: var(--color-primary);
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-primary) 15%, transparent);
  background-color: #fff;
}
.add-modal-search::placeholder { color: #94a3b8; }

/* Liste des items */
.add-modal-list {
  flex: 1;
  overflow-y: auto;
  padding: 0 .5rem;
  margin: 0 .75rem;
  scrollbar-width: thin;
  scrollbar-color: #e2e8f0 transparent;
}
.add-modal-section {
  font-size: .68rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: .1em;
  color: #94a3b8;
  padding: .75rem .75rem .3rem;
  display: flex; align-items: center; gap: .5rem;
}
.add-modal-section::after {
  content: '';
  flex: 1;
  height: 1px;
  background: #f1f5f9;
}
.add-modal-item {
  display: flex; align-items: center; gap: .875rem;
  padding: .65rem .875rem;
  cursor: pointer;
  border-radius: 10px;
  transition: background .12s;
  margin-bottom: .1rem;
}
.add-modal-item:hover {
  background: color-mix(in srgb, var(--color-primary) 6%, #f8fafc);
}
.add-modal-item:hover .add-modal-item-label {
  color: var(--color-primary);
}
.add-modal-item-icon {
  font-size: 1.15rem;
  flex-shrink: 0;
  width: 36px; height: 36px;
  background: #f1f5f9;
  border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
}
.add-modal-item-label {
  flex: 1;
  font-weight: 600;
  font-size: .9rem;
  color: #1e293b;
  transition: color .12s;
}
.add-modal-item-url {
  font-size: .75rem;
  color: #94a3b8;
  background: #f1f5f9;
  padding: .15rem .5rem;
  border-radius: 5px;
  font-family: monospace;
}

/* Lien personnalisé */
.add-modal-custom {
  padding: 1rem 1.25rem 1.25rem;
  border-top: 1.5px solid #f1f5f9;
  background: #fafafa;
  flex-shrink: 0;
}
.add-modal-custom-title {
  font-size: .72rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: .08em;
  color: #64748b; margin-bottom: .65rem;
  display: flex; align-items: center; gap: .4rem;
}
.add-modal-custom-title::before {
  content: '✏️';
  font-size: .85rem;
}
.add-modal-custom-row {
  display: flex; gap: .5rem;
}
.add-modal-custom-row input {
  flex: 1; border: 1.5px solid #e2e8f0; border-radius: 8px;
  padding: .6rem .875rem; font-size: .875rem;
  font-family: var(--font-body); outline: none;
  background: #fff;
  transition: border-color .15s, box-shadow .15s;
}
.add-modal-custom-row input:focus {
  border-color: var(--color-primary);
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-primary) 15%, transparent);
}

.menu-row.dragging { opacity: .4; }

/* Sélecteur d'accès menu */
.menu-access-select {
  width: 100%;
  border: 1.5px solid #e2e8f0;
  border-radius: 8px;
  padding: .3rem .5rem;
  font-size: .75rem;
  font-family: var(--font-body);
  color: #374151;
  background: #f8fafc;
  cursor: pointer;
  outline: none;
  transition: border-color .15s;
}
.menu-access-select:focus { border-color: var(--color-primary); }
.menu-access-select option { font-size: .82rem; }
</style>

<div class="page-head">
  <h1>🔗 Menu de navigation</h1>
</div>

<form method="post" id="mform">
  <?= Auth::csrfField() ?>

  <div class="ac">
    <!-- En-tête colonnes -->
    <div style="display:grid;grid-template-columns:20px 1fr 180px 90px;gap:1rem;padding:.6rem 1.25rem;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;border-bottom:1px solid #f1f5f9">
      <span></span>
      <span>Lien</span>
      <span style="text-align:center">Visible / Accès</span>
      <span></span>
    </div>

    <div id="mlist" style="padding:.5rem .75rem">
      <?php foreach ($menuItems as $mi => $item):
        $vis = (int)($item['visible'] ?? 1);
        $req = (int)($item['require_login'] ?? 0);
        $hasChildren = !empty($item['children']);
      ?>
      <div class="menu-row" data-mi="<?=$mi?>">
        <div class="menu-row-main">
          <span class="menu-handle mh">⠿</span>

          <div class="menu-info">
            <input type="text" class="menu-label-edit"
              name="items[<?=$mi?>][label]"
              value="<?=Helpers::e($item['label']??'')?>"
              placeholder="Libellé">
            <input type="text" class="menu-url-edit"
              name="items[<?=$mi?>][url]"
              value="<?=Helpers::e($item['url']??'/')?>"
              placeholder="/url">
          </div>

          <!-- Toggle Visible + Sélecteur accès -->
          <div class="menu-toggles">
            <div class="toggle-row">
              <span class="toggle-label">Visible</span>
              <button type="button"
                class="menu-toggle <?=$vis?'on':'off'?>"
                data-field="items[<?=$mi?>][visible]"
                onclick="doToggle(this)">
              </button>
              <input type="hidden" name="items[<?=$mi?>][visible]" value="<?=$vis?>">
            </div>
            <div class="toggle-row" style="margin-top:.35rem">
              <select name="items[<?=$mi?>][access_mode]" class="menu-access-select">
                <option value="public"  <?=($item['access_mode']??'public')==='public'?'selected':''?>>🌍 Tout le monde</option>
                <option value="teaser"  <?=($item['access_mode']??'public')==='teaser'?'selected':''?>>👁 Visible, accès membres</option>
                <option value="members" <?=($item['access_mode']??'public')==='members'?'selected':''?>>🔒 Membres seulement</option>
              </select>
            </div>
          </div>

          <!-- Actions -->
          <div class="menu-actions-col">
            <button type="button" class="btn-sub" onclick="toggleSub(this)">
              <?=$hasChildren?'▼ Sous-menu ('.count($item['children']).')':'▶ Sous-menu'?>
            </button>
            <button type="button" class="btn-del" onclick="this.closest('.menu-row').remove()" title="Supprimer">×</button>
          </div>
        </div>

        <!-- Bloc sous-menus -->
        <div class="menu-children <?=$hasChildren?'open':''?>">
          <?php foreach ($item['children'] ?? [] as $ci => $child): ?>
          <div class="child-row">
            <span style="color:#cbd5e1;font-size:.9rem;flex-shrink:0">└</span>
            <input type="text" class="child-input lbl"
              name="items[<?=$mi?>][children][<?=$ci?>][label]"
              value="<?=Helpers::e($child['label']??'')?>"
              placeholder="Libellé">
            <input type="text" class="child-input url"
              name="items[<?=$mi?>][children][<?=$ci?>][url]"
              value="<?=Helpers::e($child['url']??'/')?>"
              placeholder="/url">
            <input type="hidden" name="items[<?=$mi?>][children][<?=$ci?>][visible]" value="1">
            <button type="button" onclick="this.closest('.child-row').remove()"
              style="background:none;border:none;cursor:pointer;color:#cbd5e1;font-size:1rem;flex-shrink:0;padding:0"
              onmouseover="this.style.color='#dc2626'" onmouseout="this.style.color='#cbd5e1'">×</button>
          </div>
          <?php endforeach; ?>
          <button type="button" class="btn-add-child" onclick="addChild(this,<?=$mi?>)">
            + Ajouter un lien enfant
          </button>
        </div>
      </div>
      <?php endforeach; ?>

      <?php if (empty($menuItems)): ?>
      <div id="empty-hint" style="text-align:center;padding:2rem;color:#94a3b8">
        Aucun élément. Cliquez sur <strong>+ Ajouter un lien</strong> ci-dessous.
      </div>
      <?php endif; ?>
    </div>

    <!-- Pied -->
    <div style="display:flex;align-items:center;justify-content:space-between;padding:.875rem 1.25rem;border-top:1px solid #f1f5f9">
      <button type="button" onclick="openModal()" class="btn btn-ghost">
        + Ajouter un lien
      </button>
      <button type="submit" class="btn btn-primary" style="padding:.65rem 2rem">
        💾 Sauvegarder
      </button>
    </div>
  </div>
</form>

<!-- ══ MODAL ══ -->
<div class="add-modal-overlay" id="add-modal" onclick="if(event.target===this)closeModal()">
  <div class="add-modal">
    <div class="add-modal-head">
      <div>
        <div class="add-modal-head-title">Ajouter un lien au menu</div>
        <div class="add-modal-head-sub">Choisissez une page ou saisissez un lien personnalisé</div>
      </div>
      <button type="button" class="add-modal-close" onclick="closeModal()" title="Fermer">×</button>
    </div>
    <div class="add-modal-search-wrap">
      <input type="text" class="add-modal-search" id="modal-search"
        placeholder="Rechercher une page…" oninput="filterModal(this.value)">
    </div>
    <div class="add-modal-list" id="modal-list">
      <div class="add-modal-section">Modules &amp; Pages</div>
      <?php foreach ($suggestions as [$icon,$label,$url]): ?>
      <div class="add-modal-item" onclick="addFromModal('<?=addslashes($label)?>','<?=addslashes($url)?>')">
        <span class="add-modal-item-icon"><?=$icon?></span>
        <span class="add-modal-item-label"><?=htmlspecialchars($label)?></span>
        <span class="add-modal-item-url"><?=htmlspecialchars($url)?></span>
      </div>
      <?php endforeach; ?>
      <?php if ($pages): ?>
      <div class="add-modal-section">Pages &amp; Articles créés</div>
      <?php foreach ($pages as $p): ?>
      <div class="add-modal-item" onclick="addFromModal('<?=addslashes(htmlspecialchars($p['title'],ENT_QUOTES))?>','/<?=addslashes($p['slug'])?>')">
        <span class="add-modal-item-icon"><?=$p['type']==='article'?'📰':'📄'?></span>
        <span class="add-modal-item-label"><?=Helpers::e($p['title'])?></span>
        <span class="add-modal-item-url">/<?=Helpers::e($p['slug'])?></span>
        <span style="font-size:.65rem;background:<?=$p['type']==='article'?'#eff6ff':'#f0fdf4'?>;color:<?=$p['type']==='article'?'#3b82f6':'#16a34a'?>;padding:.15rem .4rem;border-radius:5px;font-weight:700;margin-left:auto"><?=$p['type']==='article'?'Article':'Page'?></span>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <div class="add-modal-custom">
      <div class="add-modal-custom-title">Lien personnalisé</div>
      <div class="add-modal-custom-row">
        <input type="text" id="custom-lbl" placeholder="Libellé (ex: Contact)">
        <input type="text" id="custom-url" placeholder="/url ou https://...">
        <button type="button" onclick="addCustom()" class="btn btn-primary btn-sm">Ajouter</button>
      </div>
    </div>
  </div>
</div>

<script>
let mIdx = <?= count($menuItems) ?>;
let subMap = <?= json_encode(array_map(fn($i,$v)=>count($v['children']??[]),array_keys($menuItems),$menuItems)) ?>;

// ── Toggle on/off ─────────────────────────────────────────────
function doToggle(btn) {
  const isOn = btn.classList.contains('on');
  btn.classList.toggle('on', !isOn);
  btn.classList.toggle('off', isOn);
  const hidden = btn.nextElementSibling;
  hidden.value = isOn ? 0 : 1;
}

// ── Sous-menus ────────────────────────────────────────────────
function toggleSub(btn) {
  const row  = btn.closest('.menu-row');
  const kids = row.querySelector('.menu-children');
  const open = kids.classList.toggle('open');
  const cnt  = kids.querySelectorAll('.child-row').length;
  btn.textContent = open ? '▼ Sous-menu' + (cnt ? ' ('+cnt+')' : '') : '▶ Sous-menu' + (cnt ? ' ('+cnt+')' : '');
}

function addChild(btn, mi) {
  if (!subMap[mi]) subMap[mi] = 0;
  const ci   = subMap[mi]++;
  const list = btn.closest('.menu-children');
  const div  = document.createElement('div');
  div.className = 'child-row';
  div.innerHTML = `
    <span style="color:#cbd5e1;font-size:.9rem;flex-shrink:0">└</span>
    <input type="text" class="child-input lbl" name="items[${mi}][children][${ci}][label]" placeholder="Libellé">
    <input type="text" class="child-input url" name="items[${mi}][children][${ci}][url]"   placeholder="/url">
    <input type="hidden" name="items[${mi}][children][${ci}][visible]" value="1">
    <button type="button" onclick="this.closest('.child-row').remove()"
      style="background:none;border:none;cursor:pointer;color:#cbd5e1;font-size:1rem;flex-shrink:0;padding:0"
      onmouseover="this.style.color='#dc2626'" onmouseout="this.style.color='#cbd5e1'">×</button>`;
  btn.insertAdjacentElement('beforebegin', div);
  div.querySelector('input').focus();
  // Mettre à jour compteur
  const subBtn = btn.closest('.menu-row').querySelector('.btn-sub');
  const newCnt = btn.closest('.menu-children').querySelectorAll('.child-row').length;
  subBtn.textContent = '▼ Sous-menu (' + newCnt + ')';
}

// ── Modal ─────────────────────────────────────────────────────
function openModal() {
  document.getElementById('add-modal').classList.add('open');
  setTimeout(() => document.getElementById('modal-search').focus(), 60);
}
function closeModal() {
  document.getElementById('add-modal').classList.remove('open');
  document.getElementById('modal-search').value = '';
  filterModal('');
}
function filterModal(q) {
  document.querySelectorAll('.add-modal-item').forEach(el => {
    el.style.display = (!q || el.textContent.toLowerCase().includes(q.toLowerCase())) ? 'flex' : 'none';
  });
}
function addFromModal(label, url) { addRow(label, url); closeModal(); }
function addCustom() {
  const l = document.getElementById('custom-lbl').value.trim();
  const u = document.getElementById('custom-url').value.trim() || '/';
  if (!l) { document.getElementById('custom-lbl').focus(); return; }
  addRow(l, u); closeModal();
}

// ── Ajouter une ligne ─────────────────────────────────────────
function addRow(label, url) {
  const hint = document.getElementById('empty-hint');
  if (hint) hint.remove();
  const mi = mIdx++;
  subMap[mi] = 0;
  const div = document.createElement('div');
  div.className = 'menu-row';
  div.dataset.mi = mi;
  div.innerHTML = `
    <div class="menu-row-main">
      <span class="menu-handle mh">⠿</span>
      <div class="menu-info">
        <input type="text" class="menu-label-edit" name="items[${mi}][label]" value="${label.replace(/"/g,'&quot;')}" placeholder="Libellé">
        <input type="text" class="menu-url-edit"   name="items[${mi}][url]"   value="${url}" placeholder="/url">
      </div>
      <div class="menu-toggles">
        <div class="toggle-row">
          <span class="toggle-label">Visible</span>
          <button type="button" class="menu-toggle on" onclick="doToggle(this)"></button>
          <input type="hidden" name="items[${mi}][visible]" value="1">
        </div>
        <div class="toggle-row" style="margin-top:.35rem">
          <select name="items[${mi}][access_mode]" class="menu-access-select">
            <option value="public" selected>🌍 Tout le monde</option>
            <option value="teaser">👁 Visible, accès membres</option>
            <option value="members">🔒 Membres seulement</option>
          </select>
        </div>
      </div>
      <div class="menu-actions-col">
        <button type="button" class="btn-sub" onclick="toggleSub(this)">▶ Sous-menu</button>
        <button type="button" class="btn-del" onclick="this.closest('.menu-row').remove()" title="Supprimer">×</button>
      </div>
    </div>
    <div class="menu-children">
      <button type="button" class="btn-add-child" onclick="addChild(this,${mi})">+ Ajouter un lien enfant</button>
    </div>`;
  document.getElementById('mlist').appendChild(div);
  initDrag(div);
  div.querySelector('.menu-label-edit').focus();
}

// ── Drag & drop ───────────────────────────────────────────────
function initDrag(row) {
  const handle = row.querySelector('.mh');
  if (!handle) return;
  handle.addEventListener('mousedown', () => row.setAttribute('draggable','true'));
  handle.addEventListener('mouseup',   () => row.setAttribute('draggable','false'));
  row.addEventListener('dragstart', e => { row.classList.add('dragging'); e.dataTransfer.effectAllowed='move'; });
  row.addEventListener('dragend',   () => { row.classList.remove('dragging'); row.setAttribute('draggable','false'); });
}
document.querySelectorAll('.menu-row').forEach(initDrag);

const mlist = document.getElementById('mlist');
mlist.addEventListener('dragover', e => {
  e.preventDefault();
  const drag  = mlist.querySelector('.menu-row.dragging');
  if (!drag) return;
  const rows  = [...mlist.querySelectorAll('.menu-row:not(.dragging)')];
  const after = rows.find(r => e.clientY < r.getBoundingClientRect().top + r.getBoundingClientRect().height / 2);
  if (after) mlist.insertBefore(drag, after); else mlist.appendChild(drag);
});

// Renuméroter avant submit
document.getElementById('mform').addEventListener('submit', () => {
  document.querySelectorAll('#mlist .menu-row').forEach((row, ni) => {
    row.querySelectorAll('[name]').forEach(el => {
      el.name = el.name.replace(/^items\[\d+\]/, `items[${ni}]`);
    });
  });
});

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });
</script>
<?php
$content = ob_get_clean();
include CC_ROOT . '/admin/layout.php';
