<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Auth::verifyCsrf()) { adminFlash('error','CSRF'); Helpers::redirect(u('/admin/shop')); }

// Sauvegarde produit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_product'])) {
    $id = (int)($_POST['product_id'] ?? 0);
    $data = [
        'name'        => Helpers::sanitize($_POST['name'] ?? ''),
        'description' => Helpers::sanitize($_POST['description'] ?? ''),
        'price'       => (float)($_POST['price'] ?? 0),
        'stock'       => isset($_POST['unlimited']) ? -1 : (int)($_POST['stock'] ?? 0),
        'published'   => (int)($_POST['published'] ?? 0),
        'category_id' => (int)($_POST['category_id'] ?? 0) ?: null,
        // Options livraison
        'delivery_mode'   => in_array($_POST['delivery_mode']??'both',['shipping','pickup','both']) ? $_POST['delivery_mode'] : 'both',
        'shipping_price'  => (float)($_POST['shipping_price'] ?? 0),
        'pickup_info'     => Helpers::sanitize($_POST['pickup_info'] ?? ''),
    ];
    // Variants
    $variantGroups = [];
    foreach ($_POST['variant_label'] ?? [] as $i => $label) {
        if (trim($label)) {
            $opts = array_filter(array_map('trim', explode(',', $_POST['variant_options'][$i] ?? '')));
            if ($opts) $variantGroups[] = ['label' => Helpers::sanitize($label), 'options' => array_values($opts)];
        }
    }
    $data['variants'] = $variantGroups ? json_encode($variantGroups) : null;

    // Images upload
    $existing = $id ? (json_decode(Database::scalar("SELECT images FROM cc_shop_products WHERE id=?",[$id]) ?? '[]', true) ?? []) : [];
    if (!empty($_FILES['images']['tmp_name'])) {
        foreach ($_FILES['images']['tmp_name'] as $i => $tmp) {
            if ($tmp) {
                $file = ['name'=>$_FILES['images']['name'][$i],'type'=>$_FILES['images']['type'][$i],'tmp_name'=>$tmp,'error'=>$_FILES['images']['error'][$i],'size'=>$_FILES['images']['size'][$i]];
                $up = Helpers::uploadImage($file, CC_ROOT.'/assets/uploads/shop', 10);
                if ($up['success']) $existing[] = 'assets/uploads/shop/'.$up['filename'];
            }
        }
    }
    $data['images'] = json_encode($existing);

    if ($id) {
        $sets = implode(',',array_map(fn($k)=>"`$k`=?",array_keys($data)));
        Database::run("UPDATE cc_shop_products SET $sets WHERE id=?",[...array_values($data),$id]);
    } else {
        $data['slug'] = Helpers::uniqueSlug($data['name'],'cc_shop_products');
        $cols = implode(',',array_map(fn($k)=>"`$k`",array_keys($data)));
        $vals = implode(',',array_fill(0,count($data),'?'));
        Database::insert("INSERT INTO cc_shop_products ($cols,created_at) VALUES ($vals,NOW())",array_values($data));
    }
    adminFlash('success','Produit sauvegardé.');
    Helpers::redirect(u('/admin/shop'));
}

// Sauvegarde catégorie
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_category'])) {
    $cid   = (int)($_POST['cat_id'] ?? 0);
    $name  = Helpers::sanitize($_POST['cat_name'] ?? '');
    $desc  = Helpers::sanitize($_POST['cat_desc'] ?? '');
    $color = preg_match('/^#[0-9a-f]{6}$/i', $_POST['cat_color']??'') ? $_POST['cat_color'] : '#6366f1';
    $icon  = Helpers::sanitize($_POST['cat_icon'] ?? '');
    $order = (int)($_POST['cat_order'] ?? 0);
    if ($name) {
        if ($cid) {
            Database::run("UPDATE cc_shop_categories SET name=?,description=?,color=?,icon=?,`order`=? WHERE id=?",
                [$name,$desc,$color,$icon,$order,$cid]);
        } else {
            Database::insert("INSERT INTO cc_shop_categories (name,description,color,icon,`order`) VALUES (?,?,?,?,?)",
                [$name,$desc,$color,$icon,$order]);
        }
        adminFlash('success','Catégorie sauvegardée.');
    }
    Helpers::redirect(u('/admin/shop?tab=categories'));
}

// Suppression catégorie
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_category'])) {
    $cid = (int)($_POST['cat_id'] ?? 0);
    if ($cid) {
        Database::run("UPDATE cc_shop_products SET category_id=NULL WHERE category_id=?",[$cid]);
        Database::run("DELETE FROM cc_shop_categories WHERE id=?",[$cid]);
        adminFlash('success','Catégorie supprimée.');
    }
    Helpers::redirect(u('/admin/shop?tab=categories'));
}

// Paramètres livraison globaux
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_delivery_settings'])) {
    Config::set('delivery_global_mode', $_POST['global_delivery_mode'] ?? 'both', 'shop');
    Config::set('delivery_global_shipping_price', (float)($_POST['global_shipping_price'] ?? 0), 'shop');
    Config::set('delivery_pickup_address', Helpers::sanitize($_POST['pickup_address'] ?? ''), 'shop');
    Config::set('delivery_free_above', (float)($_POST['free_above'] ?? 0), 'shop');
    adminFlash('success','Paramètres de livraison sauvegardés.');
    Helpers::redirect(u('/admin/shop'));
}

// Statut commande
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_order_status'])) {
    $oid = (int)($_POST['order_id'] ?? 0);
    $s   = in_array($_POST['new_status'],['pending','paid','shipped','cancelled','refunded']) ? $_POST['new_status'] : null;
    if ($oid && $s) Database::run("UPDATE cc_shop_orders SET status=? WHERE id=?",[$s,$oid]);
    adminFlash('success','Commande mise à jour.');
    Helpers::redirect(u('/admin/shop?tab=orders'));
}

// Migration auto colonnes catégories
try { Database::run("ALTER TABLE cc_shop_categories ADD COLUMN description TEXT"); } catch(Exception $e) {}
try { Database::run("ALTER TABLE cc_shop_categories ADD COLUMN color VARCHAR(7) NOT NULL DEFAULT '#6366f1'"); } catch(Exception $e) {}
try { Database::run("ALTER TABLE cc_shop_categories ADD COLUMN icon VARCHAR(10) NOT NULL DEFAULT ''"); } catch(Exception $e) {}
try { Database::run("ALTER TABLE cc_shop_categories ADD COLUMN `order` INT NOT NULL DEFAULT 0"); } catch(Exception $e) {}

$tab      = $_GET['tab'] ?? 'products';
$editId   = (int)($_GET['edit'] ?? 0);
$editProd = $editId ? Database::one("SELECT * FROM cc_shop_products WHERE id=?",[$editId]) : null;
$cats     = Database::all("SELECT * FROM cc_shop_categories ORDER BY name");

$pageTitle = 'Boutique — Administration';
ob_start();
?>
<div class="page-head">
  <h1>🛒 Boutique</h1>
  <div style="display:flex;gap:.5rem">
    <a href="<?=u('/admin/shop?tab=products')?>"   class="btn <?=$tab==='products'  ?'btn-primary':'btn-ghost'?>">🏷️ Produits</a>
    <a href="<?=u('/admin/shop?tab=categories')?>" class="btn <?=$tab==='categories'?'btn-primary':'btn-ghost'?>">📂 Catégories</a>
    <a href="<?=u('/admin/shop?tab=orders')?>"     class="btn <?=$tab==='orders'    ?'btn-primary':'btn-ghost'?>">📦 Commandes</a>
    <a href="<?=u('/admin/shop?tab=delivery')?>"   class="btn <?=$tab==='delivery'  ?'btn-primary':'btn-ghost'?>">🚚 Livraison</a>
  </div>
</div>

<?php if($tab === 'products'): ?>
<div style="display:grid;grid-template-columns:1fr 380px;gap:1.5rem;align-items:start">
  <!-- Liste produits -->
  <div class="ac">
    <div class="ac-header"><h2>Produits (<?=Database::scalar("SELECT COUNT(*) FROM cc_shop_products")?>)</h2>
      <a href="<?=u('/admin/shop?tab=products&edit=0')?>" class="btn btn-primary btn-sm">+ Nouveau</a>
    </div>
    <div class="at-wrap"><table class="at">
      <thead><tr><th>Produit</th><th>Prix</th><th>Stock</th><th>Livraison</th><th>Publié</th><th></th></tr></thead>
      <tbody>
        <?php foreach(Database::all("SELECT * FROM cc_shop_products ORDER BY id DESC LIMIT 50") as $p):
          $imgs = json_decode($p['images']??'[]',true);
        ?>
        <tr>
          <td>
            <div style="display:flex;align-items:center;gap:.5rem">
              <?php if($imgs[0]??null): ?><img src="<?=asset(Helpers::e($imgs[0]))?>" style="width:36px;height:36px;border-radius:6px;object-fit:cover"><?php endif; ?>
              <strong><?=Helpers::e($p['name'])?></strong>
            </div>
          </td>
          <td><?=Helpers::price($p['price'])?></td>
          <td><?=$p['stock']===-1?'∞':$p['stock']?></td>
          <td><span class="badge badge-muted"><?=$p['delivery_mode']??'both'?></span></td>
          <td><?=$p['published']?'✅':'⭕'?></td>
          <td><a href="<?=u('/admin/shop?tab=products&edit='.$p['id'])?>" class="btn btn-ghost btn-sm">✏️</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>

  <!-- Formulaire produit -->
  <div class="ac">
    <div class="ac-header"><h2><?=$editProd?'Modifier':'Nouveau produit'?></h2></div>
    <div class="ac-body">
      <form method="post" enctype="multipart/form-data">
        <?=Auth::csrfField()?>
        <?php if($editProd): ?><input type="hidden" name="product_id" value="<?=$editProd['id']?>"><?php endif; ?>
        <div class="fg"><label>Nom *</label><input type="text" name="name" value="<?=Helpers::e($editProd['name']??'')?>" required></div>
        <div class="fg"><label>Description</label><textarea name="description"><?=Helpers::e($editProd['description']??'')?></textarea></div>
        <div class="form-row">
          <div class="fg"><label>Prix (€)</label><input type="number" step="0.01" name="price" value="<?=Helpers::e($editProd['price']??'0')?>"></div>
          <div class="fg"><label>Stock</label><input type="number" name="stock" value="<?=($editProd['stock']??0)===-1?0:($editProd['stock']??0)?>"><label style="display:flex;align-items:center;gap:.35rem;margin-top:.25rem;text-transform:none;font-size:.8rem"><input type="checkbox" name="unlimited" <?=($editProd['stock']??0)===-1?'checked':''?>> Stock illimité</label></div>
        </div>
        <div class="fg"><label>Catégorie</label><select name="category_id"><option value="">Aucune</option><?php foreach($cats as $c): ?><option value="<?=$c['id']?>" <?=($editProd['category_id']??0)==$c['id']?'selected':''?>><?=Helpers::e($c['name'])?></option><?php endforeach; ?></select></div>
        <div class="fg"><label>Mode de livraison</label>
          <select name="delivery_mode">
            <option value="both"     <?=($editProd['delivery_mode']??'both')==='both'    ?'selected':''?>>Envoi + Retrait</option>
            <option value="shipping" <?=($editProd['delivery_mode']??'')==='shipping'    ?'selected':''?>>Envoi uniquement</option>
            <option value="pickup"   <?=($editProd['delivery_mode']??'')==='pickup'      ?'selected':''?>>Retrait uniquement</option>
          </select>
        </div>
        <div class="form-row">
          <div class="fg"><label>Frais envoi (€)</label><input type="number" step="0.01" name="shipping_price" value="<?=Helpers::e($editProd['shipping_price']??'0')?>"></div>
          <div class="fg"><label>Info retrait</label><input type="text" name="pickup_info" value="<?=Helpers::e($editProd['pickup_info']??'')?>" placeholder="ex: Hall du gymnase"></div>
        </div>

        <!-- Variants -->
        <div class="fg"><label>Variantes (taille, couleur...)</label>
          <div id="variants-list">
            <?php
            $variants = json_decode($editProd['variants']??'[]',true)??[];
            foreach($variants as $i => $vg):
            ?>
            <div class="variant-row" style="border:1px solid #e2e8f0;border-radius:8px;padding:.75rem;margin-bottom:.5rem">
              <input type="text" name="variant_label[]" value="<?=Helpers::e($vg['label'])?>" placeholder="Ex: Taille" style="width:100%;margin-bottom:.35rem">
              <input type="text" name="variant_options[]" value="<?=Helpers::e(implode(', ',$vg['options']))?>" placeholder="XS, S, M, L, XL (séparés par virgule)" style="width:100%">
              <button type="button" onclick="this.closest('.variant-row').remove()" style="margin-top:.35rem;background:none;border:none;cursor:pointer;color:#dc2626;font-size:.78rem">✕ Supprimer</button>
            </div>
            <?php endforeach; ?>
          </div>
          <button type="button" onclick="addVariant()" class="btn btn-ghost btn-sm" style="margin-top:.35rem">+ Ajouter une variante</button>
        </div>

        <div class="fg"><label>Photos (multiple)</label><input type="file" name="images[]" multiple accept="image/*"></div>
        <?php if($editProd && ($imgs=json_decode($editProd['images']??'[]',true))): ?>
          <div style="display:flex;flex-wrap:wrap;gap:.35rem;margin-bottom:.75rem">
            <?php foreach($imgs as $img): ?><img src="<?=asset(Helpers::e($img))?>" style="height:48px;width:48px;object-fit:cover;border-radius:6px;border:1px solid #e2e8f0"><?php endforeach; ?>
          </div>
        <?php endif; ?>

        <div class="fg"><label style="display:flex;align-items:center;gap:.5rem;text-transform:none;font-size:.875rem"><input type="checkbox" name="published" value="1" <?=($editProd['published']??1)?'checked':''?>> Publié</label></div>
        <button type="submit" name="save_product" class="btn btn-primary" style="width:100%">💾 Sauvegarder</button>
      </form>
    </div>
  </div>
</div>

<?php elseif($tab === 'categories'): ?>
<?php
$editCatId = (int)($_GET['edit_cat'] ?? 0);
$editCat   = $editCatId ? Database::one("SELECT * FROM cc_shop_categories WHERE id=?",[$editCatId]) : null;
?>

<!-- Formulaire ajout/édition catégorie -->
<div class="ac" style="margin-bottom:1.5rem">
  <div class="ac-header">
    <h2><?=$editCat ? '✏️ Modifier la catégorie' : '➕ Nouvelle catégorie'?></h2>
    <?php if($editCat): ?>
    <a href="<?=u('/admin/shop?tab=categories')?>" class="btn btn-ghost btn-sm">← Annuler</a>
    <?php endif; ?>
  </div>
  <div class="ac-body">
    <form method="post">
      <?=Auth::csrfField()?>
      <input type="hidden" name="cat_id" value="<?=$editCat['id']??0?>">
      <div class="form-row" style="grid-template-columns:1fr 1fr">
        <div class="fg">
          <label>Nom de la catégorie *</label>
          <input type="text" name="cat_name" value="<?=Helpers::e($editCat['name']??'')?>" required placeholder="Ex: Vêtements, Accessoires…">
        </div>
        <div class="fg">
          <label>Description (optionnelle)</label>
          <input type="text" name="cat_desc" value="<?=Helpers::e($editCat['description']??'')?>" placeholder="Courte description de la catégorie">
        </div>
      </div>
      <div class="form-row" style="grid-template-columns:100px 1fr 80px">
        <div class="fg">
          <label>Couleur</label>
          <input type="color" name="cat_color" value="<?=Helpers::e($editCat['color']??'#6366f1')?>" style="height:42px;width:100%;border-radius:8px;border:1.5px solid #e2e8f0;cursor:pointer;padding:3px">
        </div>
        <div class="fg">
          <label>Emoji / Icône</label>
          <input type="text" name="cat_icon" value="<?=Helpers::e($editCat['icon']??'')?>" placeholder="🏷️" style="font-size:1.3rem">
        </div>
        <div class="fg">
          <label>Ordre</label>
          <input type="number" name="cat_order" value="<?=$editCat['order']??0?>" min="0">
        </div>
      </div>
      <button type="submit" name="save_category" class="btn btn-primary"><?=$editCat?'💾 Modifier':'➕ Créer la catégorie'?></button>
    </form>
  </div>
</div>

<!-- Liste des catégories -->
<div class="ac">
  <div class="ac-header"><h2>Catégories (<?=count($cats)?>)</h2></div>
  <?php if(empty($cats)): ?>
  <div class="ac-body" style="text-align:center;color:#94a3b8;padding:2rem">
    Aucune catégorie. Créez-en une ci-dessus pour organiser vos produits.
  </div>
  <?php else: ?>
  <div class="at-wrap"><table class="at">
    <thead><tr><th>Catégorie</th><th>Description</th><th>Produits</th><th>Ordre</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach($cats as $c): ?>
    <?php $nbProds = (int)Database::scalar("SELECT COUNT(*) FROM cc_shop_products WHERE category_id=?",[$c['id']]); ?>
    <tr>
      <td>
        <div style="display:flex;align-items:center;gap:.6rem">
          <div style="width:32px;height:32px;border-radius:8px;background:<?=Helpers::e($c['color']??'#6366f1')?>;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0">
            <?=$c['icon']?:''?>
          </div>
          <strong><?=Helpers::e($c['name'])?></strong>
        </div>
      </td>
      <td style="color:#64748b;font-size:.85rem"><?=Helpers::e($c['description']??'—')?></td>
      <td>
        <span style="background:#eff6ff;color:#3b82f6;padding:.2rem .6rem;border-radius:99px;font-size:.78rem;font-weight:700">
          <?=$nbProds?> produit<?=$nbProds>1?'s':''?>
        </span>
      </td>
      <td><?=$c['order']??0?></td>
      <td style="display:flex;gap:.4rem;align-items:center">
        <a href="<?=u('/admin/shop?tab=categories&edit_cat='.$c['id'])?>" class="btn btn-ghost btn-sm">✏️ Modifier</a>
        <form method="post" onsubmit="return confirm('Supprimer cette catégorie ? Les produits seront déplacés dans "Sans catégorie".')">
          <?=Auth::csrfField()?>
          <input type="hidden" name="cat_id" value="<?=$c['id']?>">
          <button type="submit" name="delete_category" class="btn btn-danger btn-sm">🗑️</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php elseif($tab === 'orders'): ?>
<div class="ac">
  <div class="ac-header"><h2>Commandes</h2></div>
  <div class="at-wrap"><table class="at">
    <thead><tr><th>#</th><th>Client</th><th>Total</th><th>Paiement</th><th>Statut</th><th>Date</th><th>Actions</th></tr></thead>
    <tbody>
      <?php foreach(Database::all("SELECT o.*, u.firstname, u.lastname FROM cc_shop_orders o LEFT JOIN cc_users u ON o.user_id=u.id ORDER BY o.created_at DESC LIMIT 100") as $o):
        $addr = json_decode($o['shipping_address']??'{}',true)??[];
      ?>
      <tr>
        <td>#<?=$o['id']?></td>
        <td><?=Helpers::e(($o['firstname']??$addr['firstname']??'').' '.($o['lastname']??$addr['lastname']??''))?><br><small style="color:#64748b"><?=Helpers::e($addr['email']??'')?></small></td>
        <td><?=Helpers::price($o['total'])?></td>
        <td><span class="badge badge-muted"><?=$o['payment_method']?></span></td>
        <td>
          <form method="post" style="display:inline-flex;gap:.35rem;align-items:center">
            <?=Auth::csrfField()?>
            <input type="hidden" name="order_id" value="<?=$o['id']?>">
            <select name="new_status" style="padding:.2rem .4rem;border:1px solid #e2e8f0;border-radius:6px;font-size:.78rem">
              <?php foreach(['pending'=>'En attente','paid'=>'Payée','shipped'=>'Expédiée','cancelled'=>'Annulée','refunded'=>'Remboursée'] as $s=>$l): ?>
                <option value="<?=$s?>" <?=$o['status']===$s?'selected':''?>><?=$l?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" name="update_order_status" class="btn btn-ghost btn-sm">✓</button>
          </form>
        </td>
        <td style="font-size:.78rem"><?=Helpers::dateFormat($o['created_at'])?></td>
        <td>
          <?php $items=json_decode($o['items']??'[]',true); ?>
          <details><summary class="btn btn-ghost btn-sm">Articles</summary>
            <?php foreach($items as $it): ?><div style="font-size:.75rem;padding:.2rem 0"><?=Helpers::e($it['name'])?> ×<?=$it['qty']?> — <?=Helpers::price($it['price']*$it['qty'])?></div><?php endforeach; ?>
          </details>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<?php elseif($tab === 'delivery'): ?>
<div class="ac">
  <div class="ac-header"><h2>⚙️ Paramètres de livraison globaux</h2></div>
  <div class="ac-body">
    <form method="post">
      <?=Auth::csrfField()?>
      <p style="color:#64748b;font-size:.875rem;margin-bottom:1.25rem">Ces paramètres s'appliquent à tous les produits qui n'ont pas de réglage spécifique.</p>
      <div class="form-row">
        <div class="fg"><label>Mode de livraison global</label>
          <select name="global_delivery_mode">
            <option value="both"     <?=Config::get('delivery_global_mode')==='both'    ?'selected':''?>>Envoi + Retrait disponibles</option>
            <option value="shipping" <?=Config::get('delivery_global_mode')==='shipping'?'selected':''?>>Envoi postal uniquement</option>
            <option value="pickup"   <?=Config::get('delivery_global_mode')==='pickup'  ?'selected':''?>>Retrait sur place uniquement</option>
          </select>
        </div>
        <div class="fg"><label>Frais de port globaux (€)</label><input type="number" step="0.01" name="global_shipping_price" value="<?=Helpers::e(Config::get('delivery_global_shipping_price','5'))?>"></div>
        <div class="fg span2"><label>Adresse de retrait</label><input type="text" name="pickup_address" value="<?=Helpers::e(Config::get('delivery_pickup_address',''))?>" placeholder="Ex: Gymnase municipal, 12 rue des Sports, 69000 Lyon"></div>
        <div class="fg"><label>Livraison gratuite à partir de (€, 0 = désactivé)</label><input type="number" step="0.01" name="free_above" value="<?=Helpers::e(Config::get('delivery_free_above','0'))?>"></div>
      </div>
      <button type="submit" name="save_delivery_settings" class="btn btn-primary">💾 Sauvegarder</button>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
function addVariant() {
  const div = document.createElement('div');
  div.className = 'variant-row';
  div.style.cssText = 'border:1px solid #e2e8f0;border-radius:8px;padding:.75rem;margin-bottom:.5rem';
  div.innerHTML = `<input type="text" name="variant_label[]" placeholder="Ex: Taille" style="width:100%;margin-bottom:.35rem"><input type="text" name="variant_options[]" placeholder="XS, S, M, L, XL" style="width:100%"><button type="button" onclick="this.closest('.variant-row').remove()" style="margin-top:.35rem;background:none;border:none;cursor:pointer;color:#dc2626;font-size:.78rem">✕ Supprimer</button>`;
  document.getElementById('variants-list').appendChild(div);
}
</script>
<?php
$content = ob_get_clean();
include CC_ROOT . '/admin/layout.php';
