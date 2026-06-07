<?php
/**
 * Éditeur de blocs universel — ClubCMS
 * Utilisé pour : Pages, Articles, Accueil
 * 
 * Variables attendues :
 *   $blocks      = tableau de blocs existants (peut être vide)
 *   $fieldPrefix = préfixe des champs input (ex: "blocks", "page_blocks")
 */
$blocks      = $blocks ?? [];
$fieldPrefix = $fieldPrefix ?? 'blocks';

// Catalogue complet des types de blocs
$blockCatalog = [
  'paragraph'      => ['label'=>'Paragraphe',              'icon'=>'P',   'color'=>'#6366f1','desc'=>'Texte riche avec mise en forme',                   'cat'=>'Texte'],
  'heading'        => ['label'=>'Titre de section',        'icon'=>'H',   'color'=>'#4f46e5','desc'=>'Titre H2/H3/H4 avec sous-titre optionnel',          'cat'=>'Texte'],
  'quote'          => ['label'=>'Citation',                'icon'=>'&#34;',   'color'=>'#7c3aed','desc'=>'Citation mise en valeur avec auteur',                'cat'=>'Texte'],
  'icon_list'      => ['label'=>'Liste avec icônes',       'icon'=>'v',   'color'=>'#0284c7','desc'=>'Liste de points avec emoji ou icône',                'cat'=>'Texte'],
  'table'          => ['label'=>'Tableau de données',      'icon'=>'T',   'color'=>'#374151','desc'=>'Tableau avec en-têtes et lignes personnalisées',     'cat'=>'Texte'],
  'info_boxes'     => ['label'=>"Boîtes d'info",           'icon'=>'B',   'color'=>'#0ea5e9','desc'=>"2, 3 ou 4 colonnes avec icône, titre et texte",     'cat'=>'Mise en page'],
  'two_columns'    => ['label'=>'Deux colonnes',           'icon'=>'||',  'color'=>'#0284c7','desc'=>'Texte à gauche et à droite',                         'cat'=>'Mise en page'],
  'cta'            => ['label'=>"Appel à l'action",        'icon'=>'CTA', 'color'=>'#f59e0b','desc'=>'Bandeau avec titre, texte et bouton',                'cat'=>'Mise en page'],
  'alert'          => ['label'=>'Alerte / Avertissement',  'icon'=>'!',   'color'=>'#ef4444','desc'=>'Message important en couleur',                       'cat'=>'Mise en page'],
  'highlight_box'  => ['label'=>'Encadré mis en valeur',   'icon'=>'*',   'color'=>'#f59e0b','desc'=>'Bloc coloré pour mettre en avant une info clé',     'cat'=>'Mise en page'],
  'stats_counter'  => ['label'=>'Chiffres clés',           'icon'=>'#',   'color'=>'#059669','desc'=>'Mise en valeur de chiffres importants',              'cat'=>'Mise en page'],
  'steps'          => ['label'=>"Étapes / Processus",      'icon'=>'123', 'color'=>'#2563eb','desc'=>"Étapes numérotées pour expliquer un processus",      'cat'=>'Mise en page'],
  'price_table'    => ['label'=>'Tableau de tarifs',       'icon'=>'€',   'color'=>'#16a34a','desc'=>"Offres d'adhésion avec prix et avantages",           'cat'=>'Mise en page'],
  'divider'        => ['label'=>'Séparateur',              'icon'=>'—',   'color'=>'#94a3b8','desc'=>'Ligne de séparation entre sections',                  'cat'=>'Mise en page'],
  'image'          => ['label'=>'Image',                   'icon'=>'IMG', 'color'=>'#10b981','desc'=>'Image avec légende optionnelle',                     'cat'=>'Médias'],
  'banner_image'   => ['label'=>'Bannière pleine largeur', 'icon'=>'BAN', 'color'=>'#7c3aed','desc'=>'Image en fond avec texte superposé',                 'cat'=>'Médias'],
  'video'          => ['label'=>'Vidéo YouTube / Vimeo',   'icon'=>'VID', 'color'=>'#dc2626','desc'=>'Intégrer une vidéo via URL',                         'cat'=>'Médias'],
  'gallery_grid'   => ['label'=>'Galerie photos',          'icon'=>'GAL', 'color'=>'#8b5cf6','desc'=>'Grille de photos du club',                           'cat'=>'Médias'],
  'partners'       => ['label'=>'Partenaires / Sponsors',  'icon'=>'PTN', 'color'=>'#64748b','desc'=>'Logos de partenaires en grille',                     'cat'=>'Médias'],
  'map'            => ['label'=>'Carte / Localisation',    'icon'=>'MAP', 'color'=>'#f97316','desc'=>'Google Maps intégré',                                'cat'=>'Interactif'],
  'faq'            => ['label'=>"FAQ — Questions / Réponses",'icon'=>'?', 'color'=>'#06b6d4','desc'=>'Accordéon de questions fréquentes',                  'cat'=>'Interactif'],
  'accordion'      => ['label'=>'Accordéon',               'icon'=>'ACC', 'color'=>'#d97706','desc'=>'Sections repliables avec titre et contenu',          'cat'=>'Interactif'],
  'schedule'       => ['label'=>"Planning / Créneaux",     'icon'=>'CAL', 'color'=>'#3b82f6','desc'=>'Prochains créneaux du club',                          'cat'=>'Interactif'],
  'team'           => ['label'=>"Équipe",                  'icon'=>'EQP', 'color'=>'#ec4899','desc'=>'Présentation des membres du staff',                  'cat'=>'Interactif'],
  'testimonials'   => ['label'=>'Témoignages',             'icon'=>'TEM', 'color'=>'#0891b2','desc'=>'Avis et témoignages de membres',                     'cat'=>'Interactif'],
  'countdown'      => ['label'=>"Compte à rebours",        'icon'=>'CDT', 'color'=>'#7c3aed','desc'=>'Compte à rebours vers une date importante',          'cat'=>'Interactif'],
  'latest_articles'=> ['label'=>'Derniers articles',       'icon'=>'ART', 'color'=>'#be185d','desc'=>'Affiche les derniers articles publiés',               'cat'=>'Interactif'],
  'contact_info'   => ['label'=>'Infos de contact',        'icon'=>'TEL', 'color'=>'#dc2626','desc'=>'Téléphone, email, adresse, réseaux sociaux',          'cat'=>'Interactif'],
  'social_links'   => ['label'=>'Réseaux sociaux',         'icon'=>'SOC', 'color'=>'#1d4ed8','desc'=>'Boutons vers les réseaux du club',                    'cat'=>'Interactif'],
  'newsletter_form'=> ['label'=>'Formulaire newsletter',   'icon'=>'NWS', 'color'=>'#22c55e','desc'=>"Inscription à la newsletter du club",                'cat'=>'Interactif'],
  'weather'        => ['label'=>'Widget météo',            'icon'=>'🌤',  'color'=>'#0369a1','desc'=>'Météo en temps réel pour une ville (wttr.in, RGPD friendly)', 'cat'=>'Widgets'],
  'html'           => ['label'=>'Code HTML libre',         'icon'=>'&lt;/&gt;','color'=>'#1e293b', 'desc'=>'Insérer du HTML personnalisé',                       'cat'=>'Avancé'],
  'embed'          => ['label'=>'Intégration externe',     'icon'=>'EMB', 'color'=>'#475569','desc'=>'Formulaire, widget, ou iframe externe',               'cat'=>'Avancé'],
];

// Grouper par catégorie
$catalogByCat = [];
foreach ($blockCatalog as $type => $def) {
    $catalogByCat[$def['cat']][$type] = $def;
}
?>

<style>
/* ── Éditeur de blocs ── */
.be-wrap { display: flex; flex-direction: column; gap: .75rem; }

.be-block {
  background: #fff;
  border: 1.5px solid #e2e8f0;
  border-radius: 12px;
  overflow: hidden;
  transition: box-shadow .2s;
}
.be-block:hover { box-shadow: 0 4px 16px rgba(0,0,0,.08); }
.be-block.be-collapsed .be-body { display: none; }

.be-head {
  display: flex; align-items: center; gap: .75rem;
  padding: .75rem 1rem;
  background: #f8fafc;
  border-bottom: 1px solid #e2e8f0;
  cursor: pointer;
  user-select: none;
}
.be-handle { cursor: grab; color: #cbd5e1; font-size: 1.2rem; flex-shrink: 0; }
.be-handle:active { cursor: grabbing; }
.be-badge {
  display: inline-flex; align-items: center; gap: .3rem;
  padding: .2rem .6rem;
  border-radius: 20px;
  font-size: .7rem; font-weight: 700;
  color: #fff;
  flex-shrink: 0;
}
.be-preview { flex: 1; font-size: .82rem; color: #64748b; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.be-actions { display: flex; gap: .3rem; flex-shrink: 0; }
.be-btn {
  background: #fff; border: 1px solid #e2e8f0; border-radius: 6px;
  padding: .2rem .45rem; font-size: .8rem; cursor: pointer;
  transition: all .15s; line-height: 1.4;
}
.be-btn:hover { background: #f1f5f9; }
.be-btn.del:hover { background: #fee2e2; border-color: #fca5a5; color: #dc2626; }
.be-body { padding: 1.25rem; }

/* Champs dans les blocs */
.be-field { margin-bottom: 1rem; }
.be-field:last-child { margin-bottom: 0; }
.be-label {
  display: block; margin-bottom: .4rem;
  font-size: .75rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: .05em; color: #64748b;
}
.be-input, .be-textarea, .be-select {
  width: 100%;
  border: 1.5px solid #e2e8f0; border-radius: 8px;
  padding: .55rem .75rem;
  font-size: .9rem; font-family: var(--font-body);
  color: #1e293b; background: #fff;
  transition: border-color .15s;
}
.be-input:focus, .be-textarea:focus, .be-select:focus {
  outline: none; border-color: var(--color-primary);
}
.be-textarea { resize: vertical; min-height: 120px; }
.be-textarea.code { font-family: monospace; font-size: .82rem; min-height: 150px; }
.be-row { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; }
.be-row.three { grid-template-columns: 1fr 1fr 1fr; }

/* Barre d'outils richtext */
.be-toolbar {
  display: flex; flex-wrap: wrap; gap: .3rem;
  padding: .5rem .65rem;
  background: #f8fafc;
  border: 1.5px solid #e2e8f0; border-bottom: none;
  border-radius: 8px 8px 0 0;
}
.be-tool {
  background: #fff; border: 1px solid #e2e8f0; border-radius: 5px;
  padding: .2rem .55rem; font-size: .82rem; cursor: pointer;
  font-family: inherit; transition: background .12s;
}
.be-tool:hover { background: #eff6ff; }
.be-richtext {
  width: 100%; border: 1.5px solid #e2e8f0;
  border-top: none; border-radius: 0 0 8px 8px;
  padding: .75rem; font-size: .9rem;
  font-family: var(--font-body); resize: vertical;
  min-height: 140px; color: #1e293b;
}
.be-richtext:focus { outline: none; border-color: var(--color-primary); }
/* Éditeur WYSIWYG contenteditable */
.be-wysiwyg {
  width: 100%; border: 1.5px solid #e2e8f0;
  border-top: none; border-radius: 0 0 8px 8px;
  padding: .75rem; font-size: .9rem;
  font-family: var(--font-body);
  min-height: 140px; color: #1e293b;
  outline: none; cursor: text;
  line-height: 1.7; white-space: pre-wrap; word-wrap: break-word;
  box-sizing: border-box;
}
.be-wysiwyg:focus { border-color: var(--color-primary); }
.be-wysiwyg:empty::before { content: attr(data-placeholder); color: #94a3b8; pointer-events: none; }

/* Bloc info-box */
.be-ibox { border: 1px solid #e2e8f0; border-radius: 8px; padding: .875rem; background: #fafafa; }
.be-ibox + .be-ibox { margin-top: .6rem; }

/* Ajout de bloc */
.be-add-bar {
  display: flex; justify-content: center;
  padding: .75rem;
  border: 2px dashed #e2e8f0; border-radius: 12px;
  margin-top: .25rem;
}
.be-add-btn {
  display: inline-flex; align-items: center; gap: .4rem;
  padding: .6rem 1.5rem;
  background: var(--color-primary); color: #fff;
  border: none; border-radius: 8px;
  font-size: .9rem; font-weight: 600; cursor: pointer;
  font-family: inherit; transition: opacity .15s;
}
.be-add-btn:hover { opacity: .9; }

/* Modal catalogue */
.be-modal-overlay {
  display: none; position: fixed; inset: 0;
  background: rgba(15,23,42,.5); z-index: 9999;
  align-items: flex-start; justify-content: center;
  padding: 5vh 1rem;
}
.be-modal-overlay.open { display: flex; }
.be-modal {
  background: #fff; border-radius: 16px;
  width: 100%; max-width: 700px;
  max-height: 85vh; display: flex; flex-direction: column;
  box-shadow: 0 24px 64px rgba(0,0,0,.2);
}
.be-modal-head {
  display: flex; align-items: center; justify-content: space-between;
  padding: 1.25rem 1.5rem;
  border-bottom: 1px solid #f1f5f9;
  flex-shrink: 0;
}
.be-modal-body { overflow-y: auto; padding: 1rem 1.5rem 1.5rem; }
.be-cat-title {
  font-size: .7rem; font-weight: 700; text-transform: uppercase;
  letter-spacing: .08em; color: #94a3b8;
  margin: 1rem 0 .5rem; padding-bottom: .25rem;
  border-bottom: 1px solid #f1f5f9;
}
.be-cat-title:first-child { margin-top: 0; }
.be-catalog-grid {
  display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: .6rem;
}
.be-catalog-item {
  display: flex; align-items: flex-start; gap: .65rem;
  padding: .875rem; border: 1.5px solid #e2e8f0; border-radius: 10px;
  cursor: pointer; transition: all .15s; background: #fff;
  text-align: left; font-family: inherit;
}
.be-catalog-item:hover {
  border-color: var(--color-primary);
  background: color-mix(in srgb, var(--color-primary) 5%, #fff);
  box-shadow: 0 2px 8px rgba(0,0,0,.08);
}
.be-catalog-icon {
  width: 36px; height: 36px; border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
  font-size: .9rem; font-weight: 700; color: #fff;
  flex-shrink: 0;
}
.be-catalog-info { flex: 1; min-width: 0; }
.be-catalog-name { font-size: .85rem; font-weight: 600; color: #1e293b; }
.be-catalog-desc { font-size: .72rem; color: #64748b; margin-top: .15rem; line-height: 1.4; }
</style>

<!-- Liste des blocs -->
<div id="be-list-<?=$fieldPrefix?>" class="be-wrap">
  <?php foreach ($blocks as $bi => $block):
    $type = $block['type'] ?? 'paragraph';
    $def  = $blockCatalog[$type] ?? $blockCatalog['paragraph'];
  ?>
  <?php include __DIR__ . '/block_item.php'; ?>
  <?php endforeach; ?>

  <?php if (empty($blocks)): ?>
  <div id="be-empty-<?=$fieldPrefix?>" style="text-align:center;padding:2.5rem;color:#94a3b8;background:#fafafa;border:2px dashed #e2e8f0;border-radius:12px">
    <div style="font-size:2.5rem;margin-bottom:.75rem">✍️</div>
    <div style="font-weight:600;margin-bottom:.25rem">Aucun bloc pour l'instant</div>
    <div style="font-size:.82rem">Cliquez sur le bouton ci-dessous pour ajouter votre premier bloc</div>
  </div>
  <?php endif; ?>
</div>

<!-- Bouton ajouter -->
<div class="be-add-bar" style="margin-top:.75rem">
  <button type="button" class="be-add-btn" onclick="beOpenModal('<?=$fieldPrefix?>')">
    + Ajouter un bloc
  </button>
</div>

<!-- Modal catalogue de blocs -->
<div class="be-modal-overlay" id="be-modal-<?=$fieldPrefix?>" onclick="if(event.target===this)beCloseModal('<?=$fieldPrefix?>')">
  <div class="be-modal">
    <div class="be-modal-head">
      <div>
        <div style="font-size:1.05rem;font-weight:700">Choisir un type de bloc</div>
        <div style="font-size:.78rem;color:#94a3b8;margin-top:.15rem">Cliquez sur un bloc pour l'ajouter</div>
      </div>
      <button type="button" onclick="beCloseModal('<?=$fieldPrefix?>')" style="background:none;border:none;cursor:pointer;font-size:1.4rem;color:#94a3b8;line-height:1">×</button>
    </div>
    <div class="be-modal-body">
      <?php foreach ($catalogByCat as $catName => $catItems): ?>
      <div class="be-cat-title"><?=$catName?></div>
      <div class="be-catalog-grid">
        <?php foreach ($catItems as $type => $def): ?>
        <button type="button" class="be-catalog-item" onclick="beAddBlock('<?=$fieldPrefix?>','<?=$type?>')">
          <div class="be-catalog-icon" style="background:<?=$def['color']?>"><?=$def['icon']?></div>
          <div class="be-catalog-info">
            <div class="be-catalog-name"><?=$def['label']?></div>
            <div class="be-catalog-desc"><?=$def['desc']?></div>
          </div>
        </button>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
