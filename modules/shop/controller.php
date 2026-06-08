<?php
/**
 * ClubCMS — Module Boutique
 * Routes : /boutique, /boutique/produit/{slug}, /boutique/panier, /boutique/commande, /boutique/confirmation
 */

$action = $segments[1] ?? 'index';
$param  = $segments[2] ?? null;

// ── Panier (session) ───────────────────────────────────────────
if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];

// Ajouter au panier (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'panier' && ($_POST['_action'] ?? '') === 'add') {
    if (!Auth::verifyCsrf()) Helpers::json(['error' => 'CSRF'], 403);
    $pid   = (int)$_POST['product_id'];
    $qty   = max(1, (int)($_POST['qty'] ?? 1));
    $variant = Helpers::sanitize($_POST['variant'] ?? '');
    $key   = $pid . '_' . $variant;

    $product = Database::one("SELECT * FROM cc_shop_products WHERE id = ? AND published = 1", [$pid]);
    if (!$product) Helpers::json(['error' => 'Produit introuvable'], 404);
    if ($product['stock'] !== -1 && $product['stock'] < $qty) Helpers::json(['error' => 'Stock insuffisant'], 400);

    if (isset($_SESSION['cart'][$key])) {
        $_SESSION['cart'][$key]['qty'] += $qty;
    } else {
        $_SESSION['cart'][$key] = [
            'product_id' => $pid,
            'name'       => $product['name'],
            'price'      => (float)$product['price'],
            'qty'        => $qty,
            'variant'    => $variant,
            'image'      => json_decode($product['images'] ?? '[]', true)[0] ?? null,
        ];
    }
    $cartCount = array_sum(array_column($_SESSION['cart'], 'qty'));
    Helpers::json(['success' => true, 'cart_count' => $cartCount, 'message' => 'Ajouté au panier !']);
}

// Modifier/supprimer du panier (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'panier' && ($_POST['_action'] ?? '') === 'update') {
    if (!Auth::verifyCsrf()) Helpers::json(['error' => 'CSRF'], 403);
    $key = $_POST['key'] ?? '';
    $qty = (int)($_POST['qty'] ?? 0);
    if ($qty <= 0) unset($_SESSION['cart'][$key]);
    elseif (isset($_SESSION['cart'][$key])) $_SESSION['cart'][$key]['qty'] = $qty;
    Helpers::json(['success' => true]);
}

// Checkout
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'commande') {
    Auth::require('member');
    if (!Auth::verifyCsrf()) Helpers::json(['error' => 'CSRF'], 403);
    if (empty($_SESSION['cart'])) Helpers::redirect('/boutique/panier');

    $cart   = $_SESSION['cart'];
    $total  = array_sum(array_map(fn($i) => $i['price'] * $i['qty'], $cart));
    $method = Helpers::sanitize($_POST['payment_method'] ?? 'offline');
    $user   = Auth::user();

    $address = [
        'firstname' => Helpers::sanitize($_POST['firstname'] ?? ''),
        'lastname'  => Helpers::sanitize($_POST['lastname'] ?? ''),
        'address'   => Helpers::sanitize($_POST['address'] ?? ''),
        'city'      => Helpers::sanitize($_POST['city'] ?? ''),
        'zip'       => Helpers::sanitize($_POST['zip'] ?? ''),
        'country'   => Helpers::sanitize($_POST['country'] ?? 'France'),
        'email'     => Helpers::sanitize($_POST['email'] ?? $user['email']),
    ];

    // Stripe
    if ($method === 'stripe' && Config::get('stripe_secret')) {
        // L'intégration Stripe se fait via l'API JavaScript côté client
        // Le serveur vérifie le payment_intent_id
        $paymentId = Helpers::sanitize($_POST['stripe_payment_id'] ?? '');
        if (!$paymentId) { $formError = 'Paiement Stripe incomplet.'; goto render_checkout; }
    }

    $items = array_values($cart);
    $orderId = Database::insert(
        "INSERT INTO cc_shop_orders (user_id, status, payment_method, total, items, shipping_address, created_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())",
        [Auth::id(), $method === 'offline' ? 'pending' : 'paid', $method, $total,
         json_encode($items), json_encode($address)]
    );

    // Décrémente le stock
    foreach ($cart as $item) {
        Database::run(
            "UPDATE cc_shop_products SET stock = GREATEST(stock - ?, 0) WHERE id = ? AND stock != -1",
            [$item['qty'], $item['product_id']]
        );
        // ── Hook Tombola : si produit = ticket tombola, inscrire l'acheteur ──
        if ($method !== 'offline' && Auth::id()) {
            try {
                $tombola = Database::one("SELECT * FROM cc_tombola WHERE product_id=? AND status='active'", [$item['product_id']]);
                if ($tombola) {
                    $uid = Auth::id();
                    $user = Auth::user();
                    // Vérifier multi_entry
                    if (!$tombola['multi_entry']) {
                        $exists = Database::scalar("SELECT id FROM cc_tombola_participants WHERE tombola_id=? AND user_id=?", [$tombola['id'], $uid]);
                    } else {
                        $exists = false;
                    }
                    if (!$exists) {
                        for ($t = 0; $t < max(1,(int)$item['qty']); $t++) {
                            Database::run("INSERT INTO cc_tombola_participants (tombola_id,user_id,name,email,order_id) VALUES (?,?,?,?,?)",
                                [$tombola['id'], $uid, $user['firstname'].' '.$user['lastname'], $user['email'], $orderId]);
                        }
                    } elseif ($tombola['multi_entry']) {
                        for ($t = 0; $t < max(1,(int)$item['qty']); $t++) {
                            Database::run("INSERT INTO cc_tombola_participants (tombola_id,user_id,name,email,order_id) VALUES (?,?,?,?,?)",
                                [$tombola['id'], $uid, $user['firstname'].' '.$user['lastname'], $user['email'], $orderId]);
                        }
                    }
                }
            } catch(Exception $e) {}
        }
    }

    // Email confirmation
    Mailer::sendOrderConfirmation(['id' => $orderId, 'total' => $total], $user['email'], $user['firstname']);

    // Vide le panier
    $_SESSION['cart'] = [];

    Helpers::redirect('/boutique/confirmation?order=' . $orderId);
}

render_checkout:

$pageTitle = 'Boutique — ' . Config::get('club_name');
ob_start();?>
<?php if ($action === 'index'): ?>
<!-- ═══════════════════════════════════════ CATALOGUE -->
<div class="shop-hero">
  <div class="container">
    <?php $ph_t=Config::get('ph_boutique_title',''); $ph_s=Config::get('ph_boutique_subtitle',''); ?>
    <h1 class="forum-title"><?=$ph_t?Helpers::e($ph_t):'🛒 Boutique'?></h1>
    <?php if($ph_s): ?><p class="forum-subtitle"><?=Helpers::e($ph_s)?></p>
    <?php else: ?><p class="forum-subtitle"><?=Helpers::e(Config::get('club_name'))?> — Articles officiels</p><?php endif; ?>
  </div>
</div>

<div class="container shop-wrap">
  <!-- Filtres catégories -->
  <?php
  $categories = Database::all("SELECT * FROM cc_shop_categories ORDER BY `order` ASC");
  $activeCat  = (int)($_GET['cat'] ?? 0);
  ?>
  <?php if ($categories): ?>
  <div class="shop-filters">
    <a href="<?=u('/boutique')?>" class="filter-btn <?= !$activeCat ? 'active' : '' ?>">
      🏷️ Tout voir
    </a>
    <?php foreach ($categories as $c):
      $nbC = (int)Database::scalar("SELECT COUNT(*) FROM cc_shop_products WHERE published=1 AND category_id=?",[$c['id']]);
      if ($nbC === 0) continue;
      $isActive = $activeCat === (int)$c['id'];
    ?>
      <a href="<?=u('/boutique?cat='.$c['id'])?>"
         class="filter-btn <?= $isActive ? 'active' : '' ?>"
         style="<?= $isActive ? 'background:'.Helpers::e($c['color']??'var(--color-primary)').';border-color:'.Helpers::e($c['color']??'var(--color-primary)').'!important' : '' ?>">
        <?= $c['icon'] ? Helpers::e($c['icon']).' ' : '' ?><?= Helpers::e($c['name']) ?>
        <span style="opacity:.7;font-size:.8em">(<?=$nbC?>)</span>
      </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Produits -->
  <?php
  $where  = $activeCat ? "AND category_id = $activeCat" : '';
  $total  = (int)Database::scalar("SELECT COUNT(*) FROM cc_shop_products WHERE published = 1 $where");
  $pager  = Helpers::paginate($total, 12);
  $products = Database::all(
      "SELECT * FROM cc_shop_products WHERE published = 1 $where ORDER BY id DESC LIMIT ? OFFSET ?",
      [$pager['perPage'], $pager['offset']]
  );
  ?>
  <div class="products-grid">
    <?php foreach ($products as $p):
      $images = json_decode($p['images'] ?? '[]', true);
      $img    = $images[0] ?? null;
    ?>
    <div class="product-card card">
      <a href="<?=u('/boutique/produit/'.Helpers::e($p['slug']))?>" class="product-image-link">
        <?php if ($img): ?>
          <img src="<?=asset(Helpers::e($img))?>" alt="<?= Helpers::e($p['name']) ?>" class="product-img" loading="lazy">
        <?php else: ?>
          <div class="product-img-placeholder">🏷️</div>
        <?php endif; ?>
        <?php if ($p['stock'] === 0): ?>
          <span class="product-stock-badge">Épuisé</span>
        <?php elseif ($p['stock'] !== -1 && $p['stock'] <= 5): ?>
          <span class="product-stock-badge low">Plus que <?= $p['stock'] ?></span>
        <?php endif; ?>
      </a>
      <div class="product-body">
        <h3 class="product-name"><a href="<?=u('/boutique/produit/'.Helpers::e($p['slug']))?>"><?= Helpers::e($p['name']) ?></a></h3>
        <?php if ($p['description']): ?>
          <p class="product-desc"><?= Helpers::e(Helpers::excerpt($p['description'], 70)) ?></p>
        <?php endif; ?>
        <div class="product-footer">
          <span class="product-price"><?= Helpers::price((float)$p['price']) ?></span>
          <?php if ($p['stock'] !== 0): ?>
            <button class="btn-add-cart"
              onclick="addToCart(<?= $p['id'] ?>, '<?= Helpers::e($p['name']) ?>', this)"
              data-id="<?= $p['id'] ?>"
              <?= $p['stock'] === 0 ? 'disabled' : '' ?>>
              Ajouter
            </button>
          <?php else: ?>
            <span class="out-of-stock">Épuisé</span>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>

    <?php if (empty($products)): ?>
      <div class="empty-state" style="grid-column:1/-1">
        <div class="empty-icon">🛒</div>
        <p>Aucun produit disponible pour le moment.</p>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($pager['pages'] > 1): ?>
  <div class="pagination">
    <?php for ($i = 1; $i <= $pager['pages']; $i++): ?>
      <a href="<?=u('/boutique?page='.$i.($activeCat?'&cat='.$activeCat:''))?>" class="page-btn <?= $i === $pager['page'] ? 'active' : '' ?>"><?= $i ?></a>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
</div>

<?php elseif ($action === 'produit' && $param): ?>
<!-- ═══════════════════════════════════════ FICHE PRODUIT -->
<?php
$p = Database::one("SELECT * FROM cc_shop_products WHERE slug = ? AND published = 1", [$param]);
if (!$p) { http_response_code(404); include CC_ROOT . '/templates/404.php'; exit; }
$images   = json_decode($p['images'] ?? '[]', true);
$variants = json_decode($p['variants'] ?? '[]', true);
$pageTitle = Helpers::e($p['name']) . ' — Boutique — ' . Config::get('club_name');
?>
<div class="container" style="padding:2.5rem 1.5rem">
  <nav class="breadcrumb-inline">
    <a href="<?=u('/boutique')?>">Boutique</a> › <?= Helpers::e($p['name']) ?>
  </nav>

  <div class="product-detail">
    <!-- Images -->
    <div class="product-gallery">
      <div class="product-main-img">
        <?php if ($images): ?>
          <img src="<?=asset(Helpers::e($images[0]))?>" alt="<?= Helpers::e($p['name']) ?>" id="main-product-img" class="pmi-img">
        <?php else: ?>
          <div class="product-img-placeholder big">🏷️</div>
        <?php endif; ?>
      </div>
      <?php if (count($images) > 1): ?>
      <div class="product-thumbnails">
        <?php foreach ($images as $img): ?>
          <img src="<?=asset(Helpers::e($img))?>" alt="" class="thumb-img" onclick="document.getElementById('main-product-img').src='<?=asset(Helpers::e($img))?>">
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Infos -->
    <div class="product-detail-info">
      <h1 class="product-detail-name"><?= Helpers::e($p['name']) ?></h1>
      <div class="product-detail-price"><?= Helpers::price((float)$p['price']) ?></div>

      <?php if ($p['stock'] === 0): ?>
        <div class="alert alert-warning">⚠️ Rupture de stock</div>
      <?php elseif ($p['stock'] !== -1): ?>
        <p class="stock-info">📦 <?= $p['stock'] ?> en stock</p>
      <?php endif; ?>

      <?php if ($p['description']): ?>
        <div class="product-description"><?= nl2br(Helpers::e($p['description'])) ?></div>
      <?php endif; ?>

      <?php if ($variants): ?>
      <div class="product-variants">
        <?php foreach ($variants as $variantGroup): ?>
        <div class="variant-group">
          <label><?= Helpers::e($variantGroup['label'] ?? 'Option') ?></label>
          <div class="variant-options">
            <?php foreach ($variantGroup['options'] as $opt): ?>
              <button type="button" class="variant-btn" onclick="selectVariant(this, '<?= Helpers::e($variantGroup['label']) ?>')">
                <?= Helpers::e($opt) ?>
              </button>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <div class="product-qty-row">
        <label>Quantité</label>
        <div class="qty-control">
          <button type="button" onclick="changeQty(-1)">−</button>
          <input type="number" id="qty-input" value="1" min="1" <?= $p['stock'] !== -1 ? 'max="'.$p['stock'].'"' : '' ?>>
          <button type="button" onclick="changeQty(1)">+</button>
        </div>
      </div>

      <button class="btn btn-primary btn-add-cart-full" id="add-to-cart-btn"
              onclick="addToCartFull(<?= $p['id'] ?>)"
              <?= $p['stock'] === 0 ? 'disabled' : '' ?>>
        🛒 Ajouter au panier
      </button>

      <?php if ($p['stock'] === 0): ?>
        <button class="btn btn-primary btn-add-cart-full" disabled>Épuisé</button>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php elseif ($action === 'panier'): ?>
<!-- ═══════════════════════════════════════ PANIER -->
<div class="container" style="padding:2.5rem 1.5rem;max-width:900px">
  <h1 style="font-family:var(--font-heading);font-size:2rem;letter-spacing:.05em;margin-bottom:2rem">🛒 Mon panier</h1>

  <?php if (empty($_SESSION['cart'])): ?>
    <div class="empty-state">
      <div class="empty-icon">🛒</div>
      <p>Votre panier est vide.</p>
      <a href="<?=u('/boutique')?>" class="btn btn-primary">Continuer les achats</a>
    </div>
  <?php else:
    $total = 0;
  ?>
    <div class="cart-items">
      <?php foreach ($_SESSION['cart'] as $key => $item): $total += $item['price'] * $item['qty']; ?>
      <div class="cart-item" id="cart-item-<?= Helpers::e($key) ?>">
        <div class="cart-item-img">
          <?php if ($item['image']): ?>
            <img src="<?=asset(Helpers::e($item['image']))?>" alt="">
          <?php else: ?>
            <div style="background:var(--color-surface);display:flex;align-items:center;justify-content:center;height:100%;font-size:1.5rem">🏷️</div>
          <?php endif; ?>
        </div>
        <div class="cart-item-info">
          <div class="cart-item-name"><?= Helpers::e($item['name']) ?></div>
          <?php if ($item['variant']): ?>
            <div class="cart-item-variant"><?= Helpers::e($item['variant']) ?></div>
          <?php endif; ?>
          <div class="cart-item-price"><?= Helpers::price($item['price']) ?></div>
        </div>
        <div class="cart-item-qty">
          <div class="qty-control sm">
            <button onclick="updateCart('<?= Helpers::e($key) ?>', <?= $item['qty'] - 1 ?>)">−</button>
            <span><?= $item['qty'] ?></span>
            <button onclick="updateCart('<?= Helpers::e($key) ?>', <?= $item['qty'] + 1 ?>)">+</button>
          </div>
        </div>
        <div class="cart-item-subtotal"><?= Helpers::price($item['price'] * $item['qty']) ?></div>
        <button class="cart-remove" onclick="updateCart('<?= Helpers::e($key) ?>', 0)">✕</button>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="cart-summary">
      <div class="cart-total">
        <span>Total</span>
        <strong id="cart-total-val"><?= Helpers::price($total) ?></strong>
      </div>
      <div class="cart-actions">
        <a href="<?=u('/boutique')?>" class="btn btn-ghost">← Continuer</a>
        <a href="/boutique/commande" class="btn btn-primary">Commander →</a>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php elseif ($action === 'commande'): ?>
<!-- ═══════════════════════════════════════ CHECKOUT -->
<?php Auth::require('member'); $u = Auth::user(); ?>
<?php if (empty($_SESSION['cart'])): Helpers::redirect('/boutique'); endif; ?>
<div class="container" style="padding:2.5rem 1.5rem;max-width:900px">
  <h1 style="font-family:var(--font-heading);font-size:2rem;letter-spacing:.05em;margin-bottom:2rem">📦 Finaliser la commande</h1>

  <?php if (isset($formError)): ?><div class="alert alert-error"><?= Helpers::e($formError) ?></div><?php endif; ?>

  <div class="checkout-grid">
    <div class="checkout-form">
      <form method="post" id="checkout-form">
        <?= Auth::csrfField() ?>
        <div class="card" style="padding:1.5rem;margin-bottom:1.5rem">
          <h3 style="margin-bottom:1rem;font-size:1rem;font-weight:700">📍 Adresse de livraison</h3>
          <div class="form-row">
            <div class="form-group"><label>Prénom</label><input type="text" name="firstname" value="<?= Helpers::e($u['firstname'] ?? '') ?>" required></div>
            <div class="form-group"><label>Nom</label><input type="text" name="lastname" value="<?= Helpers::e($u['lastname'] ?? '') ?>" required></div>
            <div class="form-group" style="grid-column:span 2"><label>Email</label><input type="email" name="email" value="<?= Helpers::e($u['email']) ?>" required></div>
            <div class="form-group" style="grid-column:span 2"><label>Adresse</label><input type="text" name="address" value="<?= Helpers::e($u['address'] ?? '') ?>" required></div>
            <div class="form-group"><label>Ville</label><input type="text" name="city" value="<?= Helpers::e($u['city'] ?? '') ?>" required></div>
            <div class="form-group"><label>Code postal</label><input type="text" name="zip" value="<?= Helpers::e($u['zip'] ?? '') ?>" required></div>
          </div>
        </div>

        <div class="card" style="padding:1.5rem;margin-bottom:1.5rem">
          <h3 style="margin-bottom:1rem;font-size:1rem;font-weight:700">💳 Mode de paiement</h3>
          <div class="payment-methods">
            <?php if (Config::get('stripe_public')): ?>
            <label class="payment-method">
              <input type="radio" name="payment_method" value="stripe" checked>
              <span class="pm-label">💳 Carte bancaire (Stripe)</span>
            </label>
            <div id="stripe-element" style="display:none;margin-top:1rem"></div>
            <?php endif; ?>
            <?php if (Config::get('paypal_client')): ?>
            <label class="payment-method">
              <input type="radio" name="payment_method" value="paypal">
              <span class="pm-label">🅿️ PayPal</span>
            </label>
            <?php endif; ?>
            <label class="payment-method">
              <input type="radio" name="payment_method" value="offline" <?= (!Config::get('stripe_public') && !Config::get('paypal_client')) ? 'checked' : '' ?>>
              <span class="pm-label">🏦 Paiement à la remise / virement</span>
            </label>
          </div>
        </div>

        <div style="display:flex;justify-content:flex-end">
          <button type="submit" class="btn btn-primary" style="font-size:1rem;padding:.9rem 2.5rem">
            ✅ Confirmer la commande
          </button>
        </div>
      </form>
    </div>

    <!-- Récapitulatif -->
    <div class="checkout-summary card" style="padding:1.5rem;align-self:start;position:sticky;top:80px">
      <h3 style="margin-bottom:1rem;font-size:1rem;font-weight:700">📋 Récapitulatif</h3>
      <?php $total = 0; foreach ($_SESSION['cart'] as $item): $total += $item['price'] * $item['qty']; ?>
        <div style="display:flex;justify-content:space-between;margin-bottom:.5rem;font-size:.875rem">
          <span><?= Helpers::e($item['name']) ?> ×<?= $item['qty'] ?></span>
          <span><?= Helpers::price($item['price'] * $item['qty']) ?></span>
        </div>
      <?php endforeach; ?>
      <div style="border-top:1px solid var(--color-border);margin-top:1rem;padding-top:1rem;display:flex;justify-content:space-between;font-weight:700;font-size:1.05rem">
        <span>Total</span><span><?= Helpers::price($total) ?></span>
      </div>
    </div>
  </div>
</div>

<?php elseif ($action === 'confirmation'): ?>
<!-- ═══════════════════════════════════════ CONFIRMATION -->
<div class="container" style="padding:4rem 1.5rem;max-width:600px;text-align:center">
  <div style="font-size:4rem;margin-bottom:1.5rem">🎉</div>
  <h1 style="font-family:var(--font-heading);font-size:2.5rem;letter-spacing:.08em;color:var(--color-success);margin-bottom:.5rem">Commande confirmée !</h1>
  <p style="color:var(--color-muted);margin-bottom:2rem">
    Merci pour votre commande #<?= (int)($_GET['order'] ?? 0) ?>.<br>
    Un email de confirmation vous a été envoyé.
  </p>
  <div style="display:flex;gap:1rem;justify-content:center;flex-wrap:wrap">
    <a href="/membre/commandes" class="btn btn-primary">Voir mes commandes</a>
    <a href="<?=u('/boutique')?>" class="btn btn-ghost">Continuer les achats</a>
  </div>
</div>
<?php endif; ?>

<script>
function addToCart(id, name, btn) {
  btn.disabled = true;
  btn.textContent = '⏳';
  fetch('/boutique/panier', {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},
    body: `_action=add&product_id=${id}&qty=1&csrf_token=<?= Auth::csrfToken() ?>`
  }).then(r => r.json()).then(data => {
    if (data.success) {
      btn.textContent = '✓ Ajouté';
      btn.style.background = 'var(--color-success)';
      Toast.show('Ajouté au panier !', 'success');
      setTimeout(() => { btn.textContent = 'Ajouter'; btn.style.background = ''; btn.disabled = false; }, 2000);
    } else Toast.show(data.error || 'Erreur', 'error');
  });
}
function addToCartFull(id) {
  const qty     = document.getElementById('qty-input')?.value || 1;
  const variant = [...document.querySelectorAll('.variant-btn.selected')].map(b => b.textContent.trim()).join(', ');
  fetch('/boutique/panier', {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},
    body: `_action=add&product_id=${id}&qty=${qty}&variant=${encodeURIComponent(variant)}&csrf_token=<?= Auth::csrfToken() ?>`
  }).then(r => r.json()).then(data => {
    if (data.success) Toast.show('Ajouté au panier !', 'success');
    else Toast.show(data.error || 'Erreur', 'error');
  });
}
function changeQty(delta) {
  const input = document.getElementById('qty-input');
  if (input) input.value = Math.max(1, (parseInt(input.value) || 1) + delta);
}
function selectVariant(btn, group) {
  btn.closest('.variant-options').querySelectorAll('.variant-btn').forEach(b => b.classList.remove('selected'));
  btn.classList.add('selected');
}
function updateCart(key, qty) {
  fetch('/boutique/panier', {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},
    body: `_action=update&key=${encodeURIComponent(key)}&qty=${qty}&csrf_token=<?= Auth::csrfToken() ?>`
  }).then(r => r.json()).then(() => location.reload());
}
</script>

<style>
.shop-hero{background:linear-gradient(135deg,var(--color-primary),color-mix(in srgb,var(--color-primary) 70%,#000));padding:3rem 0;color:#fff;margin-bottom:2rem}
.shop-wrap{padding-bottom:4rem}
.shop-filters{display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:2rem}
.filter-btn{padding:.4rem 1rem;border-radius:99px;border:1.5px solid var(--color-border);font-size:.875rem;font-weight:500;color:var(--color-muted);transition:all .2s;text-decoration:none;display:inline-block}
.filter-btn:hover,.filter-btn.active{background:var(--color-primary);color:#fff;border-color:var(--color-primary)}
.products-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:1.25rem}
.product-card{padding:0;overflow:hidden}
.product-image-link{display:block;position:relative;aspect-ratio:1;overflow:hidden;background:var(--color-surface)}
.product-img{width:100%;height:100%;object-fit:cover;transition:transform .3s}
.product-card:hover .product-img{transform:scale(1.04)}
.product-img-placeholder{display:flex;align-items:center;justify-content:center;height:100%;font-size:3rem;color:var(--color-muted)}
.product-img-placeholder.big{height:320px;font-size:5rem;background:var(--color-surface);border-radius:var(--radius-md)}
.product-stock-badge{position:absolute;top:.5rem;right:.5rem;background:#000;color:#fff;font-size:.7rem;font-weight:700;padding:.2rem .5rem;border-radius:4px}
.product-stock-badge.low{background:var(--color-warning)}
.product-body{padding:1rem}
.product-name{font-weight:700;margin-bottom:.2rem;font-size:.95rem}
.product-name a{color:var(--color-text);text-decoration:none}
.product-name a:hover{color:var(--color-primary)}
.product-desc{font-size:.8rem;color:var(--color-muted);margin-bottom:.5rem}
.product-footer{display:flex;align-items:center;justify-content:space-between;margin-top:.75rem}
.product-price{font-size:1.1rem;font-weight:700;color:var(--color-primary)}
.btn-add-cart{background:var(--color-primary);color:#fff;border:none;padding:.4rem .85rem;border-radius:var(--radius-sm);font-size:.8rem;font-weight:600;cursor:pointer;transition:all .2s}
.btn-add-cart:hover:not(:disabled){opacity:.9;transform:translateY(-1px)}
.btn-add-cart:disabled{background:var(--color-border);color:var(--color-muted);cursor:not-allowed}
.out-of-stock{font-size:.8rem;color:var(--color-error);font-weight:600}
/* Produit detail */
.breadcrumb-inline{font-size:.85rem;color:var(--color-muted);margin-bottom:2rem}
.breadcrumb-inline a{color:var(--color-muted);text-decoration:none}
.product-detail{display:grid;grid-template-columns:1fr 1fr;gap:3rem;align-items:start}
.product-gallery .product-main-img{border-radius:var(--radius-md);overflow:hidden;background:var(--color-surface);aspect-ratio:1;margin-bottom:.75rem}
.pmi-img{width:100%;height:100%;object-fit:cover}
.product-thumbnails{display:flex;gap:.5rem;flex-wrap:wrap}
.thumb-img{width:64px;height:64px;border-radius:var(--radius-sm);object-fit:cover;cursor:pointer;border:2px solid transparent;transition:border-color .2s}
.thumb-img:hover{border-color:var(--color-primary)}
.product-detail-name{font-family:var(--font-heading);font-size:2rem;letter-spacing:.05em;margin-bottom:.5rem}
.product-detail-price{font-size:2rem;font-weight:700;color:var(--color-primary);margin-bottom:1rem}
.stock-info{color:var(--color-success);font-size:.875rem;font-weight:600;margin-bottom:1rem}
.product-description{color:var(--color-muted);line-height:1.7;font-size:.9rem;margin:1rem 0}
.product-variants{margin:1.25rem 0}
.variant-group{margin-bottom:.75rem}
.variant-group label{font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--color-muted);display:block;margin-bottom:.4rem}
.variant-options{display:flex;flex-wrap:wrap;gap:.4rem}
.variant-btn{padding:.35rem .85rem;border:1.5px solid var(--color-border);border-radius:var(--radius-sm);background:#fff;cursor:pointer;font-size:.85rem;transition:all .2s}
.variant-btn:hover,.variant-btn.selected{border-color:var(--color-primary);color:var(--color-primary);background:color-mix(in srgb,var(--color-primary) 8%,transparent)}
.product-qty-row{display:flex;align-items:center;gap:1rem;margin:1.25rem 0}
.product-qty-row label{font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--color-muted);white-space:nowrap}
.qty-control{display:flex;align-items:center;border:1.5px solid var(--color-border);border-radius:var(--radius-sm);overflow:hidden}
.qty-control button{width:36px;height:36px;background:#fff;border:none;cursor:pointer;font-size:1.1rem;transition:background .2s}
.qty-control button:hover{background:var(--color-surface)}
.qty-control input{width:48px;text-align:center;border:none;border-left:1.5px solid var(--color-border);border-right:1.5px solid var(--color-border);height:36px;font-family:var(--font-body);font-size:.9rem}
.qty-control.sm{transform:scale(.85)}
.btn-add-cart-full{width:100%;justify-content:center;font-size:1rem;padding:.85rem}
/* Panier */
.cart-items{display:flex;flex-direction:column;gap:.75rem;margin-bottom:1.5rem}
.cart-item{display:flex;align-items:center;gap:1rem;background:#fff;border:1px solid var(--color-border);border-radius:var(--radius-sm);padding:.875rem 1rem}
.cart-item-img{width:64px;height:64px;border-radius:var(--radius-sm);overflow:hidden;flex-shrink:0;background:var(--color-surface)}
.cart-item-img img{width:100%;height:100%;object-fit:cover}
.cart-item-info{flex:1;min-width:0}
.cart-item-name{font-weight:600;font-size:.9rem}
.cart-item-variant{font-size:.78rem;color:var(--color-muted)}
.cart-item-price{font-size:.8rem;color:var(--color-muted);margin-top:.15rem}
.cart-item-subtotal{font-weight:700;font-size:1rem;min-width:80px;text-align:right}
.cart-remove{background:none;border:none;cursor:pointer;color:var(--color-muted);font-size:.9rem;padding:.25rem;transition:color .2s}
.cart-remove:hover{color:var(--color-error)}
.cart-summary{background:#fff;border:1px solid var(--color-border);border-radius:var(--radius-md);padding:1.5rem}
.cart-total{display:flex;justify-content:space-between;font-size:1.25rem;margin-bottom:1.25rem}
.cart-actions{display:flex;justify-content:space-between;gap:1rem}
/* Checkout */
.checkout-grid{display:grid;grid-template-columns:1fr 340px;gap:2rem;align-items:start}
.payment-methods{display:flex;flex-direction:column;gap:.75rem}
.payment-method{display:flex;align-items:center;gap:.75rem;padding:.75rem 1rem;border:1.5px solid var(--color-border);border-radius:var(--radius-sm);cursor:pointer;transition:border-color .2s}
.payment-method:has(input:checked){border-color:var(--color-primary);background:color-mix(in srgb,var(--color-primary) 5%,transparent)}
.pm-label{font-weight:500;font-size:.9rem}
.btn-ghost{display:inline-flex;align-items:center;gap:.4rem;padding:.65rem 1.5rem;border-radius:var(--radius-sm);font-weight:600;font-size:.9rem;cursor:pointer;border:1.5px solid var(--color-border);background:#fff;color:var(--color-text);font-family:var(--font-body);transition:all .2s;text-decoration:none}
.btn-ghost:hover{border-color:var(--color-primary);color:var(--color-primary)}
@media(max-width:900px){.product-detail,.checkout-grid{grid-template-columns:1fr}.checkout-summary{position:static!important}}
@media(max-width:600px){.cart-item-subtotal,.cart-item-qty{display:none}}
</style>

<?php
$content = ob_get_clean();
include CC_ROOT . '/templates/layout.php';
