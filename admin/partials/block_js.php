<script>
var beCounters = {};

function beToggle(head) {
  head.closest('.be-block').classList.toggle('be-collapsed');
}
function beDelete(btn) {
  if (!confirm('Supprimer ce bloc ?')) return;
  btn.closest('.be-block').remove();
}
function beMove(btn, dir) {
  var block = btn.closest('.be-block');
  var list  = block.parentNode;
  var items = Array.from(list.querySelectorAll(':scope > .be-block'));
  var idx   = items.indexOf(block);
  var target = items[idx + dir];
  if (!target) return;
  if (dir === -1) list.insertBefore(block, target);
  else            list.insertBefore(target, block);
}
function beOpenModal(prefix) {
  document.getElementById('be-modal-' + prefix).classList.add('open');
}
function beCloseModal(prefix) {
  document.getElementById('be-modal-' + prefix).classList.remove('open');
}
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    document.querySelectorAll('.be-modal-overlay.open').forEach(function(m) {
      m.classList.remove('open');
    });
  }
});

/* ── WYSIWYG ─────────────────────────────────────────────── */
function beWysiwygSync(el) {
  var v = document.getElementById(el.id + '-val');
  if (v) v.value = el.innerHTML;
}
function beWysiwygCmd(btn) {
  var el = document.getElementById(btn.dataset.eid);
  if (el) { el.focus(); document.execCommand(btn.dataset.cmd, false, null); beWysiwygSync(el); }
}
function beWysiwygColor(input) {
  var el = document.getElementById(input.dataset.eid);
  if (el) { el.focus(); document.execCommand(input.dataset.cmd, false, input.value); beWysiwygSync(el); }
}
function beWysiwygSelect(select) {
  if (!select.value) return;
  var el = document.getElementById(select.dataset.eid);
  if (el) {
    el.focus();
    if (select.dataset.type === 'size') document.execCommand('fontSize', false, select.value);
    if (select.dataset.type === 'font') document.execCommand('fontName', false, select.value);
    beWysiwygSync(el);
  }
  select.value = '';
}
function beWysiwygFn(btn) {
  var eid = btn.dataset.eid;
  var fn  = btn.dataset.fn;
  var el  = document.getElementById(eid);
  if (!el) return;
  if (fn === 'link') {
    var url = prompt('URL du lien :');
    if (!url) return;
    el.focus();
    document.execCommand('createLink', false, url);
    el.querySelectorAll('a:not([target])').forEach(function(a) { a.target = '_blank'; });
  } else if (fn === 'image') {
    var src = prompt('URL de l\u0027image :');
    if (!src) return;
    el.focus();
    document.execCommand('insertImage', false, src);
  }
  beWysiwygSync(el);
}
document.querySelectorAll('.be-wysiwyg[contenteditable]').forEach(function(el) {
  var v = document.getElementById(el.id + '-val');
  if (v && !v.value) v.value = el.innerHTML;
  el.addEventListener('input', function() { beWysiwygSync(el); });
});

/* ── Barre richtext (textarea) ── */
function beFmt(btn, open, close) {
  var ta = btn.closest('.be-toolbar').nextElementSibling;
  if (!ta || ta.tagName !== 'TEXTAREA') return;
  var s = ta.selectionStart, e = ta.selectionEnd;
  var sel = ta.value.substring(s, e);
  ta.value = ta.value.substring(0, s) + open + sel + close + ta.value.substring(e);
  ta.focus(); ta.setSelectionRange(s + open.length, s + open.length + sel.length);
}
function beApplySize(select) {
  if (!select.value) return;
  var ta = select.closest('.be-toolbar').nextElementSibling;
  if (!ta || ta.tagName !== 'TEXTAREA') return;
  var s = ta.selectionStart, e = ta.selectionEnd, sel = ta.value.substring(s, e);
  var wrap = '<span style="font-size:' + select.value + '">';
  ta.value = ta.value.substring(0, s) + wrap + sel + '</span>' + ta.value.substring(e);
  ta.focus(); select.value = '';
}
function beApplyFont(select) {
  if (!select.value) return;
  var ta = select.closest('.be-toolbar').nextElementSibling;
  if (!ta || ta.tagName !== 'TEXTAREA') return;
  var s = ta.selectionStart, e = ta.selectionEnd, sel = ta.value.substring(s, e);
  ta.value = ta.value.substring(0, s) + '<span style="font-family:' + select.value + '">' + sel + '</span>' + ta.value.substring(e);
  ta.focus(); select.value = '';
}
function beApplyAlign(btn, align) {
  var ta = btn.closest('.be-toolbar').nextElementSibling;
  if (!ta || ta.tagName !== 'TEXTAREA') return;
  var s = ta.selectionStart, e = ta.selectionEnd;
  var sel = ta.value.substring(s, e) || ta.value;
  var wrap = '<div style="text-align:' + align + '">';
  if (sel === ta.value) ta.value = wrap + ta.value + '</div>';
  else ta.value = ta.value.substring(0, s) + wrap + sel + '</div>' + ta.value.substring(e);
  ta.focus();
}
function beApplyColor(input, prop) {
  var ta = input.closest('.be-toolbar').nextElementSibling;
  if (!ta || ta.tagName !== 'TEXTAREA') return;
  var s = ta.selectionStart, e = ta.selectionEnd, sel = ta.value.substring(s, e);
  ta.value = ta.value.substring(0, s) + '<span style="' + prop + ':' + input.value + '">' + sel + '</span>' + ta.value.substring(e);
  ta.focus();
}

/* ── FAQ / Steps / Accordion ─────────────────────────────── */
var faqCounters = {};
function beAddFaq(bi, prefix) {
  if (!faqCounters[bi]) faqCounters[bi] = document.querySelectorAll('#faq-items-' + bi + ' .faq-item').length;
  var fi = faqCounters[bi]++;
  var c = document.getElementById('faq-items-' + bi);
  var d = document.createElement('div');
  d.className = 'be-ibox faq-item'; d.style.marginBottom = '.5rem';
  d.innerHTML = '<input type="text" name="' + prefix + '[faqs][' + fi + '][q]" class="be-input" placeholder="Question..." style="width:100%;margin-bottom:.35rem">'
    + '<textarea name="' + prefix + '[faqs][' + fi + '][a]" class="be-textarea" style="min-height:70px;width:100%"></textarea>'
    + '<button type="button" onclick="this.closest(\'.faq-item\').remove()" style="background:none;border:none;color:#dc2626;cursor:pointer;font-size:.78rem;margin-top:.4rem;padding:0">Supprimer</button>';
  c.appendChild(d);
}

var stepCounters = {};
function beAddStep(bi, prefix) {
  if (!stepCounters[bi]) stepCounters[bi] = document.querySelectorAll('#steps-' + bi + ' .step-item').length;
  var si = stepCounters[bi]++;
  var num = si + 1;
  var container = document.getElementById('steps-' + bi);
  var div = document.createElement('div');
  div.className = 'be-ibox step-item'; div.style.cssText = 'margin-bottom:.5rem;display:flex;gap:.75rem;align-items:flex-start';
  div.innerHTML = '<div style="background:var(--color-primary);color:#fff;border-radius:50%;width:28px;height:28px;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.85rem;flex-shrink:0;margin-top:.35rem">' + num + '</div>'
    + '<div style="flex:1"><div class="be-field" style="margin-bottom:.4rem"><label class="be-label">Titre</label>'
    + '<input type="text" name="' + prefix + '[steps][' + si + '][title]" class="be-input"></div>'
    + '<div class="be-field" style="margin-bottom:0"><label class="be-label">Description</label>'
    + '<textarea name="' + prefix + '[steps][' + si + '][content]" class="be-textarea" style="min-height:60px"></textarea></div></div>'
    + '<button type="button" onclick="this.closest(\'.step-item\').remove()" style="background:none;border:none;color:#cbd5e1;cursor:pointer;font-size:1rem;margin-top:.3rem">x</button>';
  container.appendChild(div);
}

var accCounters = {};
function beAddAccordion(bi, prefix) {
  if (!accCounters[bi]) accCounters[bi] = document.querySelectorAll('#accordion-items-' + bi + ' .acc-item').length;
  var ai = accCounters[bi]++;
  var container = document.getElementById('accordion-items-' + bi);
  var div = document.createElement('div');
  div.className = 'be-ibox acc-item'; div.style.marginBottom = '.4rem';
  div.innerHTML = '<div class="be-field"><label class="be-label">Titre</label><input type="text" name="' + prefix + '[items][' + ai + '][title]" class="be-input"></div>'
    + '<div class="be-field" style="margin-bottom:.3rem"><label class="be-label">Contenu</label>'
    + '<textarea name="' + prefix + '[items][' + ai + '][content]" class="be-textarea" style="min-height:80px"></textarea></div>'
    + '<button type="button" onclick="this.closest(\'.acc-item\').remove()" style="background:none;border:none;color:#dc2626;cursor:pointer;font-size:.78rem;padding:0">Supprimer</button>';
  container.appendChild(div);
}

/* ── Templates de blocs ──────────────────────────────────── */
var beTemplates = {

  paragraph: function(p) {
    var eid = 'be-ed-' + p.replace(/[^a-z0-9]/gi, '-');
    var s = '<span style="width:1px;background:#e2e8f0;align-self:stretch;margin:0 .1rem"></span>';
    var tb = '<div class="be-toolbar" style="gap:.2rem;flex-wrap:wrap;border-radius:8px 8px 0 0;border-bottom:none">'
      + '<button type="button" class="be-tool" data-cmd="bold" data-eid="' + eid + '" onclick="beWysiwygCmd(this)" title="Gras"><b>G</b></button>'
      + '<button type="button" class="be-tool" data-cmd="italic" data-eid="' + eid + '" onclick="beWysiwygCmd(this)" title="Italique"><i>I</i></button>'
      + '<button type="button" class="be-tool" data-cmd="underline" data-eid="' + eid + '" onclick="beWysiwygCmd(this)" title="Souligne"><u>S</u></button>'
      + '<button type="button" class="be-tool" data-cmd="strikeThrough" data-eid="' + eid + '" onclick="beWysiwygCmd(this)" title="Barre"><s>S</s></button>'
      + s
      + '<label class="be-tool" style="display:inline-flex;align-items:center;gap:.15rem;cursor:pointer;padding:.18rem .4rem" title="Couleur">A'
      + '<input type="color" value="#e74c3c" data-cmd="foreColor" data-eid="' + eid + '" onchange="beWysiwygColor(this)" style="width:18px;height:18px;border:none;border-radius:3px;cursor:pointer;padding:0"></label>'
      + '<label class="be-tool" style="display:inline-flex;align-items:center;gap:.15rem;cursor:pointer;padding:.18rem .4rem" title="Surligneur">&#9619;'
      + '<input type="color" value="#fff176" data-cmd="hiliteColor" data-eid="' + eid + '" onchange="beWysiwygColor(this)" style="width:18px;height:18px;border:none;border-radius:3px;cursor:pointer;padding:0"></label>'
      + s
      + '<select class="be-tool" data-type="size" data-eid="' + eid + '" onchange="beWysiwygSelect(this)" style="padding:.2rem .3rem;font-size:.78rem;height:28px">'
      + '<option value="">Taille</option><option value="1">Petit</option><option value="3">Normal</option><option value="4">Grand</option><option value="5">Tres grand</option><option value="7">Enorme</option></select>'
      + '<select class="be-tool" data-type="font" data-eid="' + eid + '" onchange="beWysiwygSelect(this)" style="padding:.2rem .3rem;font-size:.78rem;height:28px">'
      + '<option value="">Police</option><option value="Arial">Arial</option><option value="Georgia">Georgia</option><option value="Times New Roman">Times</option><option value="Courier New">Courier</option><option value="Verdana">Verdana</option></select>'
      + s
      + '<button type="button" class="be-tool" data-cmd="justifyLeft" data-eid="' + eid + '" onclick="beWysiwygCmd(this)" title="Gauche">&#9664;</button>'
      + '<button type="button" class="be-tool" data-cmd="justifyCenter" data-eid="' + eid + '" onclick="beWysiwygCmd(this)" title="Centre">&#9644;</button>'
      + '<button type="button" class="be-tool" data-cmd="justifyRight" data-eid="' + eid + '" onclick="beWysiwygCmd(this)" title="Droite">&#9654;</button>'
      + '<button type="button" class="be-tool" data-cmd="justifyFull" data-eid="' + eid + '" onclick="beWysiwygCmd(this)" title="Justifie">&#9776;</button>'
      + s
      + '<button type="button" class="be-tool" data-fn="link" data-eid="' + eid + '" onclick="beWysiwygFn(this)" title="Lien">&#128279;</button>'
      + '<button type="button" class="be-tool" data-cmd="insertUnorderedList" data-eid="' + eid + '" onclick="beWysiwygCmd(this)" title="Liste">Liste</button>'
      + '<button type="button" class="be-tool" data-cmd="insertOrderedList" data-eid="' + eid + '" onclick="beWysiwygCmd(this)" title="Numerotee">1.Liste</button>'
      + '<button type="button" class="be-tool" data-fn="image" data-eid="' + eid + '" onclick="beWysiwygFn(this)" title="Image">&#128444;</button>'
      + '<button type="button" class="be-tool" data-cmd="removeFormat" data-eid="' + eid + '" onclick="beWysiwygCmd(this)" style="color:#94a3b8" title="Effacer">&#215;fmt</button>'
      + '</div>';
    var ed = '<div id="' + eid + '" contenteditable="true" class="be-wysiwyg"'
      + ' style="min-height:120px;border:1.5px solid #e2e8f0;border-top:none;border-radius:0 0 8px 8px;padding:.75rem;font-size:.9rem;line-height:1.75;outline:none;background:#fff;cursor:text"'
      + ' oninput="var v=document.getElementById(this.id+\'-val\');if(v)v.value=this.innerHTML;"'
      + '></div>';
    var hid = '<input type="hidden" name="' + p + '[content]" id="' + eid + '-val" value="">';
    return '<div class="be-field"><label class="be-label">Texte</label>' + tb + ed + hid + '</div>';
  },

  heading: function(p) {
    return '<div class="be-row"><div class="be-field"><label class="be-label">Titre</label><input type="text" name="' + p + '[title]" class="be-input" placeholder="Mon titre"></div>'
      + '<div class="be-field"><label class="be-label">Niveau</label><select name="' + p + '[level]" class="be-select"><option value="h2">H2</option><option value="h3">H3</option><option value="h4">H4</option></select></div></div>'
      + '<div class="be-field"><label class="be-label">Sous-titre</label><input type="text" name="' + p + '[subtitle]" class="be-input" placeholder="Texte complementaire"></div>';
  },

  quote: function(p) {
    return '<div class="be-field"><label class="be-label">Citation</label><textarea name="' + p + '[content]" class="be-richtext" style="min-height:80px"></textarea></div>'
      + '<div class="be-row"><div class="be-field"><label class="be-label">Auteur</label><input type="text" name="' + p + '[author]" class="be-input" placeholder="Jean Dupont"></div>'
      + '<div class="be-field"><label class="be-label">Couleur</label><input type="color" name="' + p + '[color]" value="#3b82f6" style="height:40px;width:60px;border-radius:6px;border:1px solid #e2e8f0;cursor:pointer;padding:2px"></div></div>';
  },

  info_boxes: function(p) {
    var boxes = [1,2,3].map(function(c) {
      return '<div class="be-ibox"><div style="font-size:.72rem;font-weight:700;text-transform:uppercase;color:#64748b;margin-bottom:.6rem">Colonne ' + c + '</div>'
        + '<div class="be-row three"><div class="be-field" style="margin:0"><label class="be-label">Emoji</label><input type="text" name="' + p + '[box' + c + '_icon]" class="be-input" style="font-size:1.3rem;text-align:center"></div>'
        + '<div class="be-field" style="margin:0;grid-column:span 2"><label class="be-label">Titre</label><input type="text" name="' + p + '[box' + c + '_title]" class="be-input"></div></div>'
        + '<div class="be-field" style="margin-top:.6rem;margin-bottom:0"><label class="be-label">Description</label><textarea name="' + p + '[box' + c + '_text]" class="be-textarea" style="min-height:60px"></textarea></div></div>';
    }).join('');
    return '<div class="be-field"><label class="be-label">Titre de section</label><input type="text" name="' + p + '[title]" class="be-input"></div>' + boxes + '<input type="hidden" name="' + p + '[cols]" value="3">';
  },

  two_columns: function(p) {
    return '<div class="be-row"><div class="be-field"><label class="be-label">Colonne gauche</label><textarea name="' + p + '[col_left]" class="be-richtext"></textarea></div>'
      + '<div class="be-field"><label class="be-label">Colonne droite</label><textarea name="' + p + '[col_right]" class="be-richtext"></textarea></div></div>';
  },

  cta: function(p) {
    return '<div class="be-row"><div class="be-field"><label class="be-label">Titre</label><input type="text" name="' + p + '[title]" class="be-input"></div>'
      + '<div class="be-field"><label class="be-label">Bouton</label><input type="text" name="' + p + '[btn_label]" class="be-input"></div></div>'
      + '<div class="be-row"><div class="be-field"><label class="be-label">Accroche</label><input type="text" name="' + p + '[subtitle]" class="be-input"></div>'
      + '<div class="be-field"><label class="be-label">Lien</label><input type="text" name="' + p + '[btn_url]" class="be-input" value="/register"></div></div>';
  },

  alert: function(p) {
    return '<div class="be-row"><div class="be-field"><label class="be-label">Type</label><select name="' + p + '[alert_type]" class="be-select"><option value="info">Information</option><option value="success">Succes</option><option value="warning">Avertissement</option><option value="error">Urgent</option></select></div>'
      + '<div class="be-field"><label class="be-label">Titre</label><input type="text" name="' + p + '[title]" class="be-input"></div></div>'
      + '<div class="be-field"><label class="be-label">Message</label><textarea name="' + p + '[content]" class="be-textarea" style="min-height:80px"></textarea></div>';
  },

  divider: function(p) {
    return '<div class="be-row"><div class="be-field"><label class="be-label">Style</label><select name="' + p + '[style]" class="be-select"><option value="line">Ligne fine</option><option value="thick">Ligne epaisse</option><option value="dots">Points</option><option value="space">Espace</option></select></div>'
      + '<div class="be-field"><label class="be-label">Espacement</label><select name="' + p + '[spacing]" class="be-select"><option value="sm">Petit</option><option value="md" selected>Normal</option><option value="lg">Grand</option></select></div></div>';
  },

  image: function(p) {
    return '<div class="be-row"><div class="be-field"><label class="be-label">Uploader</label><input type="file" name="' + p + '[image_upload]" accept="image/*"></div>'
      + '<div class="be-field"><label class="be-label">URL externe</label><input type="text" name="' + p + '[image_url]" class="be-input" placeholder="https://..."></div></div>'
      + '<div class="be-row"><div class="be-field"><label class="be-label">Legende</label><input type="text" name="' + p + '[caption]" class="be-input"></div>'
      + '<div class="be-field"><label class="be-label">Largeur</label><select name="' + p + '[width]" class="be-select"><option value="full">Pleine</option><option value="large">Grande</option><option value="medium">Moyenne</option></select></div></div>';
  },

  video: function(p) {
    return '<div class="be-field"><label class="be-label">URL video (YouTube, Vimeo)</label><input type="text" name="' + p + '[url]" class="be-input" placeholder="https://www.youtube.com/watch?v=..."></div>'
      + '<div class="be-row"><div class="be-field"><label class="be-label">Titre</label><input type="text" name="' + p + '[title]" class="be-input"></div>'
      + '<div class="be-field"><label class="be-label">Format</label><select name="' + p + '[ratio]" class="be-select"><option value="16-9">16:9</option><option value="4-3">4:3</option></select></div></div>';
  },

  gallery_grid: function(p) {
    return '<div class="be-row"><div class="be-field"><label class="be-label">Titre</label><input type="text" name="' + p + '[title]" class="be-input" value="Galerie photos"></div>'
      + '<div class="be-field"><label class="be-label">Nombre de photos</label><input type="number" name="' + p + '[count]" class="be-input" value="6" min="3" max="24"></div></div>';
  },

  map: function(p) {
    return '<div class="be-field"><label class="be-label">Titre</label><input type="text" name="' + p + '[title]" class="be-input" value="Nous trouver"></div>'
      + '<div class="be-field"><label class="be-label">Adresse</label><input type="text" name="' + p + '[address]" class="be-input" placeholder="12 rue des Sports, 69000 Lyon"></div>'
      + '<div class="be-row"><div class="be-field"><label class="be-label">Hauteur</label><select name="' + p + '[height]" class="be-select"><option value="300">300px</option><option value="400" selected>400px</option><option value="500">500px</option></select></div>'
      + '<div class="be-field"><label class="be-label">Info complementaire</label><input type="text" name="' + p + '[extra_info]" class="be-input"></div></div>';
  },

  faq: function(p, bi) {
    return '<div class="be-field"><label class="be-label">Titre</label><input type="text" name="' + p + '[title]" class="be-input" value="Questions frequentes"></div>'
      + '<div id="faq-items-' + bi + '"><div class="be-ibox faq-item" style="margin-bottom:.5rem">'
      + '<input type="text" name="' + p + '[faqs][0][q]" class="be-input" placeholder="Question..." style="width:100%;margin-bottom:.35rem">'
      + '<textarea name="' + p + '[faqs][0][a]" class="be-textarea" style="min-height:70px;width:100%"></textarea></div></div>'
      + '<button type="button" onclick="beAddFaq(\'' + bi + '\',\'' + p + '\')" style="background:#f8fafc;border:1px dashed #e2e8f0;border-radius:8px;padding:.5rem 1rem;cursor:pointer;font-size:.82rem;color:#64748b;width:100%;margin-top:.5rem;font-family:inherit">+ Ajouter une question</button>';
  },

  schedule: function(p) {
    return '<div class="be-row"><div class="be-field"><label class="be-label">Titre</label><input type="text" name="' + p + '[title]" class="be-input" value="Prochains creneaux"></div>'
      + '<div class="be-field"><label class="be-label">Nb creneaux</label><input type="number" name="' + p + '[count]" class="be-input" value="5" min="1" max="20"></div></div>';
  },

  team: function(p) {
    return '<div class="be-field"><label class="be-label">Titre</label><input type="text" name="' + p + '[title]" class="be-input" value="Notre equipe"></div>'
      + '<div class="be-field"><label class="be-label">Introduction</label><textarea name="' + p + '[content]" class="be-textarea" style="min-height:80px"></textarea></div>';
  },

  newsletter_form: function(p) {
    return '<div class="be-row"><div class="be-field"><label class="be-label">Titre</label><input type="text" name="' + p + '[title]" class="be-input" value="Restez informe"></div>'
      + '<div class="be-field"><label class="be-label">Sous-titre</label><input type="text" name="' + p + '[subtitle]" class="be-input" value="Newsletter du club"></div></div>';
  },

  html: function(p) {
    return '<div class="be-field"><label class="be-label">Code HTML</label><textarea name="' + p + '[html]" class="be-textarea code" placeholder="Votre HTML ici"></textarea>'
      + '<div style="font-size:.75rem;color:#f59e0b;margin-top:.3rem">Affiche tel quel.</div></div>';
  },

  icon_list: function(p) {
    return '<div class="be-field"><label class="be-label">Titre</label><input type="text" name="' + p + '[title]" class="be-input"></div>'
      + '<div class="be-field"><label class="be-label">Elements (un par ligne, commencer par emoji)</label><textarea name="' + p + '[items]" class="be-textarea" style="min-height:120px" placeholder="Premier avantage&#10;Deuxieme avantage"></textarea></div>';
  },

  table: function(p) {
    return '<div class="be-field"><label class="be-label">Titre</label><input type="text" name="' + p + '[title]" class="be-input"></div>'
      + '<div class="be-field"><label class="be-label">En-tetes (separes par |)</label><input type="text" name="' + p + '[headers]" class="be-input" placeholder="Col 1 | Col 2 | Col 3"></div>'
      + '<div class="be-field"><label class="be-label">Donnees (une ligne par rangee, | entre cellules)</label><textarea name="' + p + '[rows]" class="be-textarea" style="min-height:100px"></textarea></div>';
  },

  highlight_box: function(p) {
    return '<div class="be-row"><div class="be-field"><label class="be-label">Emoji</label><input type="text" name="' + p + '[icon]" class="be-input" value="star" style="max-width:80px;font-size:1.4rem;text-align:center"></div>'
      + '<div class="be-field"><label class="be-label">Titre</label><input type="text" name="' + p + '[title]" class="be-input"></div></div>'
      + '<div class="be-field"><label class="be-label">Contenu</label><textarea name="' + p + '[content]" class="be-textarea" style="min-height:80px"></textarea></div>'
      + '<div class="be-row"><div class="be-field"><label class="be-label">Fond</label><input type="color" name="' + p + '[bg_color]" value="#fef3c7" style="height:40px;width:60px;border-radius:6px;border:1px solid #e2e8f0;cursor:pointer;padding:2px"></div>'
      + '<div class="be-field"><label class="be-label">Bordure</label><input type="color" name="' + p + '[border_color]" value="#f59e0b" style="height:40px;width:60px;border-radius:6px;border:1px solid #e2e8f0;cursor:pointer;padding:2px"></div></div>';
  },

  stats_counter: function(p) {
    var rows = [1,2,3,4].map(function(s) {
      return '<div class="be-ibox"><div style="font-size:.72rem;font-weight:700;text-transform:uppercase;color:#64748b;margin-bottom:.5rem">Chiffre ' + s + '</div>'
        + '<div class="be-row three"><div class="be-field" style="margin:0"><label class="be-label">Nombre</label><input type="text" name="' + p + '[stat' + s + '_num]" class="be-input"></div>'
        + '<div class="be-field" style="margin:0"><label class="be-label">Unite</label><input type="text" name="' + p + '[stat' + s + '_unit]" class="be-input"></div>'
        + '<div class="be-field" style="margin:0"><label class="be-label">Description</label><input type="text" name="' + p + '[stat' + s + '_label]" class="be-input"></div></div></div>';
    }).join('');
    return '<div class="be-field"><label class="be-label">Titre de section</label><input type="text" name="' + p + '[title]" class="be-input"></div>' + rows;
  },

  steps: function(p, bi) {
    return '<div class="be-field"><label class="be-label">Titre</label><input type="text" name="' + p + '[title]" class="be-input" value="Comment nous rejoindre ?"></div>'
      + '<div id="steps-' + bi + '"><div class="be-ibox step-item" style="margin-bottom:.5rem;display:flex;gap:.75rem;align-items:flex-start">'
      + '<div style="background:var(--color-primary);color:#fff;border-radius:50%;width:28px;height:28px;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.85rem;flex-shrink:0;margin-top:.35rem">1</div>'
      + '<div style="flex:1"><div class="be-field" style="margin-bottom:.4rem"><label class="be-label">Titre</label><input type="text" name="' + p + '[steps][0][title]" class="be-input"></div>'
      + '<div class="be-field" style="margin-bottom:0"><label class="be-label">Description</label><textarea name="' + p + '[steps][0][content]" class="be-textarea" style="min-height:60px"></textarea></div></div></div></div>'
      + '<button type="button" onclick="beAddStep(\'' + bi + '\',\'' + p + '\')" style="background:#f8fafc;border:1px dashed #e2e8f0;border-radius:8px;padding:.3rem 1rem;cursor:pointer;font-size:.82rem;color:#64748b;width:100%;margin-top:.5rem;font-family:inherit">+ Ajouter une etape</button>';
  },

  price_table: function(p) {
    var cols = '<div class="be-field"><label class="be-label">Titre</label><input type="text" name="' + p + '[title]" class="be-input" value="Nos tarifs"></div>';
    [1,2].forEach(function(pc) {
      cols += '<div class="be-ibox"><div style="font-size:.72rem;font-weight:700;text-transform:uppercase;color:#64748b;margin-bottom:.5rem">Offre ' + pc + '</div>'
        + '<div class="be-row"><div class="be-field"><label class="be-label">Nom</label><input type="text" name="' + p + '[plan' + pc + '_name]" class="be-input"></div>'
        + '<div class="be-field"><label class="be-label">Prix</label><input type="text" name="' + p + '[plan' + pc + '_price]" class="be-input"></div></div>'
        + '<div class="be-field"><label class="be-label">Avantages (un par ligne)</label><textarea name="' + p + '[plan' + pc + '_features]" class="be-textarea" style="min-height:80px"></textarea></div></div>';
    });
    return cols + '<input type="hidden" name="' + p + '[cols]" value="2">';
  },

  testimonials: function(p) {
    var rows = [0,1].map(function(ti) {
      return '<div class="be-ibox" style="margin-bottom:.5rem"><div class="be-row">'
        + '<div class="be-field"><label class="be-label">Nom</label><input type="text" name="' + p + '[items][' + ti + '][name]" class="be-input"></div>'
        + '<div class="be-field"><label class="be-label">Role</label><input type="text" name="' + p + '[items][' + ti + '][role]" class="be-input"></div></div>'
        + '<div class="be-field" style="margin-bottom:0"><label class="be-label">Temoignage</label><textarea name="' + p + '[items][' + ti + '][text]" class="be-textarea" style="min-height:70px"></textarea></div></div>';
    }).join('');
    return '<div class="be-field"><label class="be-label">Titre</label><input type="text" name="' + p + '[title]" class="be-input" value="Ce que nos membres disent"></div>' + rows;
  },

  countdown: function(p) {
    return '<div class="be-row"><div class="be-field"><label class="be-label">Titre</label><input type="text" name="' + p + '[title]" class="be-input"></div>'
      + '<div class="be-field"><label class="be-label">Date cible</label><input type="datetime-local" name="' + p + '[target_date]" class="be-input"></div></div>'
      + '<div class="be-field"><label class="be-label">Nom evenement</label><input type="text" name="' + p + '[event_name]" class="be-input"></div>';
  },

  latest_articles: function(p) {
    return '<div class="be-row"><div class="be-field"><label class="be-label">Titre</label><input type="text" name="' + p + '[title]" class="be-input" value="Dernieres actualites"></div>'
      + '<div class="be-field"><label class="be-label">Nombre</label><input type="number" name="' + p + '[count]" class="be-input" value="3" min="1" max="9" style="max-width:80px"></div></div>'
      + '<div class="be-field"><label class="be-label">Disposition</label><select name="' + p + '[layout]" class="be-select" style="max-width:200px"><option value="grid">Grille</option><option value="list">Liste</option></select></div>';
  },

  contact_info: function(p) {
    return '<div class="be-field"><label class="be-label">Titre</label><input type="text" name="' + p + '[title]" class="be-input" value="Contactez-nous"></div>'
      + '<div class="be-row"><div class="be-field"><label class="be-label">Telephone</label><input type="text" name="' + p + '[phone]" class="be-input"></div>'
      + '<div class="be-field"><label class="be-label">Email</label><input type="email" name="' + p + '[email]" class="be-input"></div></div>'
      + '<div class="be-row"><div class="be-field"><label class="be-label">Adresse</label><input type="text" name="' + p + '[address]" class="be-input"></div>'
      + '<div class="be-field"><label class="be-label">Horaires</label><input type="text" name="' + p + '[hours]" class="be-input"></div></div>';
  },

  social_links: function(p) {
    var networks = [['Facebook','facebook'],['Instagram','instagram'],['YouTube','youtube'],['Twitter / X','twitter'],['TikTok','tiktok'],['LinkedIn','linkedin'],['Snapchat','snapchat'],['Discord','discord'],['Twitch','twitch'],['WhatsApp','whatsapp']];
    var rows = '';
    for (var i = 0; i < networks.length; i += 2) {
      rows += '<div class="be-row"><div class="be-field"><label class="be-label">' + networks[i][0] + '</label><input type="url" name="' + p + '[' + networks[i][1] + ']" class="be-input" placeholder="https://..."></div>';
      if (networks[i+1]) rows += '<div class="be-field"><label class="be-label">' + networks[i+1][0] + '</label><input type="url" name="' + p + '[' + networks[i+1][1] + ']" class="be-input" placeholder="https://..."></div>';
      rows += '</div>';
    }
    rows += '<div style="font-size:.72rem;font-weight:700;text-transform:uppercase;color:#94a3b8;margin:.875rem 0 .4rem">Autre(s) reseau libre</div>';
    for (var sn = 1; sn <= 3; sn++) {
      rows += '<div class="be-ibox" style="margin-bottom:.4rem"><div class="be-row"><div class="be-field" style="margin:0"><label class="be-label">Nom reseau ' + sn + '</label><input type="text" name="' + p + '[custom' + sn + '_label]" class="be-input"></div><div class="be-field" style="margin:0"><label class="be-label">URL</label><input type="url" name="' + p + '[custom' + sn + '_url]" class="be-input" placeholder="https://..."></div></div></div>';
    }
    return '<div class="be-field"><label class="be-label">Titre</label><input type="text" name="' + p + '[title]" class="be-input" placeholder="Suivez-nous"></div>' + rows;
  },

  accordion: function(p, bi) {
    return '<div class="be-field"><label class="be-label">Titre de section</label><input type="text" name="' + p + '[title]" class="be-input"></div>'
      + '<div id="accordion-items-' + bi + '"><div class="be-ibox acc-item" style="margin-bottom:.4rem">'
      + '<div class="be-field"><label class="be-label">Titre</label><input type="text" name="' + p + '[items][0][title]" class="be-input"></div>'
      + '<div class="be-field" style="margin-bottom:.3rem"><label class="be-label">Contenu</label><textarea name="' + p + '[items][0][content]" class="be-textarea" style="min-height:80px"></textarea></div></div></div>'
      + '<button type="button" onclick="beAddAccordion(\'' + bi + '\',\'' + p + '\')" style="background:#f8fafc;border:1px dashed #e2e8f0;border-radius:8px;padding:.3rem 1rem;cursor:pointer;font-size:.82rem;color:#64748b;width:100%;margin-top:.35rem;font-family:inherit">+ Ajouter une section</button>';
  },

  banner_image: function(p) {
    return '<div class="be-row"><div class="be-field"><label class="be-label">URL image</label><input type="url" name="' + p + '[image_url]" class="be-input" placeholder="https://..."></div>'
      + '<div class="be-field"><label class="be-label">Hauteur</label><select name="' + p + '[height]" class="be-select"><option value="300">300px</option><option value="400" selected>400px</option><option value="500">500px</option></select></div></div>'
      + '<div class="be-row"><div class="be-field"><label class="be-label">Titre superpose</label><input type="text" name="' + p + '[title]" class="be-input"></div>'
      + '<div class="be-field"><label class="be-label">Sous-titre</label><input type="text" name="' + p + '[subtitle]" class="be-input"></div></div>';
  },

  partners: function(p) {
    var sizeSelect = '<div class="be-row"><div class="be-field"><label class="be-label">Titre</label><input type="text" name="' + p + '[title]" class="be-input" value="Nos partenaires"></div>'
      + '<div class="be-field"><label class="be-label">Taille logos</label><select name="' + p + '[logo_size]" class="be-select"><option value="sm">Petite (48px)</option><option value="md" selected>Normale (80px)</option><option value="lg">Grande (120px)</option></select></div></div>';
    var rows = [1,2,3,4,5,6].map(function(pp) {
      return '<div class="be-ibox" style="margin-bottom:.5rem"><div style="font-size:.7rem;font-weight:700;text-transform:uppercase;color:#94a3b8;margin-bottom:.5rem">Partenaire ' + pp + '</div>'
        + '<div class="be-row"><div class="be-field" style="margin:0"><label class="be-label">Nom</label><input type="text" name="' + p + '[partner' + pp + '_name]" class="be-input"></div>'
        + '<div class="be-field" style="margin:0"><label class="be-label">Site web</label><input type="url" name="' + p + '[partner' + pp + '_url]" class="be-input" placeholder="https://..."></div></div>'
        + '<div class="be-field" style="margin-top:.5rem;margin-bottom:0"><label class="be-label">URL logo</label><input type="url" name="' + p + '[partner' + pp + '_logo_url]" class="be-input" placeholder="https://example.com/logo.png"></div></div>';
    }).join('');
    return sizeSelect + rows;
  },

  embed: function(p) {
    return '<div class="be-field"><label class="be-label">Titre</label><input type="text" name="' + p + '[title]" class="be-input"></div>'
      + '<div class="be-field"><label class="be-label">Code integration</label><textarea name="' + p + '[html]" class="be-textarea code" placeholder="Votre iframe ici"></textarea></div>'
      + '<div class="be-field"><label class="be-label">Hauteur</label><input type="number" name="' + p + '[height]" class="be-input" value="400" style="max-width:100px"> px</div>';
  }

};

/* ── beMeta (catalogue pour les badges) ──────────────────── */
var beMeta = <?php
  $meta = [];
  foreach ($blockCatalog as $type => $def) {
    $meta[$type] = ['label'=>$def['label'],'icon'=>$def['icon'],'color'=>$def['color'],'cat'=>$def['cat']??'Autres','desc'=>$def['desc']??''];
  }
  echo json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG);
?>;

/* ── beAddBlock ──────────────────────────────────────────── */
function beAddBlock(prefix, type) {
  beCloseModal(prefix);
  if (!beCounters[prefix]) beCounters[prefix] = document.querySelectorAll('#be-list-' + prefix + ' > .be-block').length;
  var bi   = beCounters[prefix]++;
  var p    = prefix + '[' + bi + ']';
  var meta = beMeta[type] || {label: type, icon: 'B', color: '#94a3b8'};
  var tplFn = beTemplates[type] || beTemplates.html;
  var body  = tplFn(p, bi);

  var emptyHint = document.getElementById('be-empty-' + prefix);
  if (emptyHint) emptyHint.remove();

  var div = document.createElement('div');
  div.className = 'be-block';
  div.dataset.bi = bi;
  div.innerHTML =
    '<div class="be-head" onclick="beToggle(this)">'
    + '<span class="be-handle" onclick="event.stopPropagation()">&#8801;</span>'
    + '<input type="hidden" name="' + p + '[type]" value="' + type + '">'
    + '<span class="be-badge" style="background:' + meta.color + '">' + meta.icon + ' ' + meta.label + '</span>'
    + '<span class="be-preview">Nouveau bloc</span>'
    + '<div class="be-actions" onclick="event.stopPropagation()">'
    + '<button type="button" class="be-btn" onclick="beMove(this,-1)">&#8593;</button>'
    + '<button type="button" class="be-btn" onclick="beMove(this,1)">&#8595;</button>'
    + '<button type="button" class="be-btn del" onclick="beDelete(this)">&#215;</button>'
    + '</div></div>'
    + '<div class="be-body">' + body + '</div>';

  document.getElementById('be-list-' + prefix).appendChild(div);

  setTimeout(function() {
    div.scrollIntoView({behavior: 'smooth', block: 'nearest'});
    var first = div.querySelector('input[type=text], textarea, [contenteditable]');
    if (first) first.focus();
  }, 100);
}

/* ── Renumérotation avant submit ─────────────────────────── */
document.querySelectorAll('form').forEach(function(form) {
  form.addEventListener('submit', function() {
    document.querySelectorAll('.be-wrap').forEach(function(list) {
      var prefix = list.id.replace('be-list-', '');
      var esc = prefix.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
      list.querySelectorAll(':scope > .be-block').forEach(function(block, newIdx) {
        block.querySelectorAll('[name]').forEach(function(el) {
          el.name = el.name.replace(new RegExp('^' + esc + '\\[\\d+\\]'), prefix + '[' + newIdx + ']');
        });
        // Sync WYSIWYG editors
        block.querySelectorAll('[contenteditable]').forEach(function(ed) {
          var v = document.getElementById(ed.id + '-val');
          if (v) v.value = ed.innerHTML;
        });
      });
    });
  });
});
</script>
