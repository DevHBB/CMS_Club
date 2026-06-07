<?php
/**
 * ClubCMS — Carte Membre PDF
 * Générée avec FPDF (inclus sans composer)
 * Hash HMAC-SHA256 vérifiable depuis le site
 */

// Vérification profil complet
$missingFields = [];
if (empty($user['firstname']))  $missingFields[] = 'Prénom';
if (empty($user['lastname']))   $missingFields[] = 'Nom';

if (!empty($missingFields)):
?>
<h2 class="page-heading">Carte membre</h2>
<div class="alert alert-warning">
  ⚠️ Complétez votre profil pour générer votre carte membre.<br>
  Champs manquants : <strong><?= implode(', ', $missingFields) ?></strong>
</div>
<a href="<?=u('/membre/profil')?>" class="btn btn-primary">Compléter mon profil</a>

<?php else:
// Génère ou récupère le hash
if (!$user['member_card_hash']) {
    $hash = Helpers::memberCardHash($user['id']);
    Database::run(
        "UPDATE cc_users SET member_card_hash = ?, member_card_generated_at = NOW() WHERE id = ?",
        [$hash, $user['id']]
    );
    $user['member_card_hash'] = $hash;
    $user['member_card_generated_at'] = date('Y-m-d H:i:s');
}

$club   = Config::get('club_name', 'Mon Club');
$sport  = Config::get('club_sport', '');
$color  = Config::get('primary_color', '#1d4ed8');
$logo   = Config::get('logo') ? CC_ROOT . '/' . Config::get('logo') : null;
$genDate= Helpers::dateFormat($user['member_card_generated_at'], 'd/m/Y');
$memberId = str_pad($user['id'], 6, '0', STR_PAD_LEFT);

// URL de vérification
$verifyUrl = CC_URL . '/verifier-carte?id=' . $user['id'] . '&hash=' . $user['member_card_hash'];
// QR Code URL (défini globalement pour usage dans HTML et PDF)
$qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=' . urlencode($verifyUrl);

// Génération PDF (action)
if ((defined('CARD_DOWNLOAD_MODE') && CARD_DOWNLOAD_MODE) || ($_GET['dl'] ?? '') === '1'):
    require_once CC_ROOT . '/pdf/fpdf/fpdf.php';

    class MemberCardPDF extends FPDF {
        public string $color;
        public function Header() {}
        public function Footer() {}
        function hexToRgb(string $hex): array {
            $hex = ltrim($hex, '#');
            return [hexdec(substr($hex,0,2)), hexdec(substr($hex,2,2)), hexdec(substr($hex,4,2))];
        }
    }

    $pdf = new MemberCardPDF('L', 'mm', [85.6, 54]); // Format carte bancaire
    $pdf->color = $color;

    // Embarquer le hash COMPLET dans les métadonnées du PDF
    // Ce hash sera lu lors de la vérification — impossible à deviner sans accès BDD
    $fullHash = $user['member_card_hash'];
    $pdf->SetAuthor('CLUBCMS-HASH:' . $fullHash);
    $pdf->SetKeywords('clubcms-verify:' . $fullHash . ':member:' . $memberId);
    $pdf->SetTitle('Carte Membre - ' . $memberId);
    $pdf->SetCreator('ClubCMS');

    $pdf->AddPage();
    $pdf->SetAutoPageBreak(false);

    $rgb = $pdf->hexToRgb($color);

    // Fond dégradé (couleur club)
    $pdf->SetFillColor(...$rgb);
    $pdf->Rect(0, 0, 85.6, 54, 'F');

    // On utilise une couleur plus sombre du thème au lieu de transparence
    $darkR = max(0, $rgb[0] - 40);
    $darkG = max(0, $rgb[1] - 40);
    $darkB = max(0, $rgb[2] - 60);
    $pdf->SetFillColor($darkR, $darkG, $darkB);
    $pdf->Rect(0, 28, 85.6, 26, 'F');

    // Logo
    if ($logo && file_exists($logo)) {
        $ext = strtolower(pathinfo($logo, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png'])) {
            try { $pdf->Image($logo, 5, 4, 22); } catch(Exception $e) {}
        }
    }

    // Nom du club
    $pdf->SetFont('Helvetica', 'B', 11);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetXY(30, 5);
    $pdf->Cell(50, 6, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $club), 0, 0, 'L');

    // Sport
    if ($sport) {
        $pdf->SetFont('Helvetica', '', 7);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY(30, 11);
        $pdf->Cell(50, 4, strtoupper(iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $sport)), 0, 0, 'L');
    }

    // CARTE MEMBRE
    $pdf->SetFont('Helvetica', 'B', 7);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetXY(30, 17);
    $pdf->Cell(50, 4, 'CARTE DE MEMBRE', 0, 0, 'L');

    // Nom complet
    $fullname = trim(($user['firstname'] ?? '') . ' ' . strtoupper($user['lastname'] ?? ''));
    $pdf->SetFont('Helvetica', 'B', 13);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetXY(5, 30);
    $pdf->Cell(75, 7, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $fullname), 0, 0, 'L');

    // N° membre
    $pdf->SetFont('Helvetica', '', 8);
    $pdf->SetXY(5, 37);
    $pdf->Cell(40, 5, 'N° ' . $memberId, 0, 0, 'L');

    // Date de génération
    $pdf->SetFont('Helvetica', '', 7);
    $pdf->SetXY(5, 42);
    $pdf->Cell(40, 5, 'Emis le ' . $genDate, 0, 0, 'L');

    // Expiration licence
    if ($user['license_expiry']) {
        $pdf->SetFont('Helvetica', '', 7);
        $pdf->SetXY(5, 47);
        $pdf->Cell(40, 5, 'Licence exp. ' . Helpers::dateFormat($user['license_expiry']), 0, 0, 'L');
    }

    // Hash court (4 derniers chars pour vérif visuelle)
    $shortHash = strtoupper(substr($user['member_card_hash'], -8));
    $pdf->SetFont('Helvetica', 'B', 7);
    $pdf->SetXY(45, 47);
    $pdf->Cell(35, 5, 'REF: ' . chunk_split($shortHash, 4, '-'), 0, 0, 'R');

    // QR Code défini globalement, utilisé aussi en HTML

    // Vider tout buffer de sortie avant d'envoyer les headers PDF
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/pdf');
    if (defined('CARD_DOWNLOAD_MODE') && CARD_DOWNLOAD_MODE) {
        header('Content-Disposition: attachment; filename="carte-membre-' . $memberId . '.pdf"');
        $pdf->Output('D', 'carte-membre-' . $memberId . '.pdf');
    } else {
        header('Content-Disposition: inline; filename="carte-membre-' . $memberId . '.pdf"');
        $pdf->Output('I', 'carte-membre-' . $memberId . '.pdf');
    }
    exit;

endif; // fin génération PDF
?>

<h2 class="page-heading">Ma carte membre</h2>

<!-- Aperçu carte -->
<div class="card-preview-wrap">
  <div class="member-card-preview" style="--card-color: <?= Helpers::e($color) ?>">
    <div class="card-left">
      <?php if (Config::get('logo')): ?>
        <img src="<?=asset(Config::get('logo'))?>" alt="" class="card-logo">
      <?php endif; ?>
      <div class="card-club"><?= Helpers::e($club) ?></div>
      <?php if ($sport): ?><div class="card-sport"><?= Helpers::e(strtoupper($sport)) ?></div><?php endif; ?>
      <div class="card-type-label">CARTE DE MEMBRE</div>
    </div>
    <div class="card-right">
      <div class="card-member-name"><?= Helpers::e(($user['firstname'] ?? '') . ' ' . strtoupper($user['lastname'] ?? '')) ?></div>
      <div class="card-member-id">N° <?= $memberId ?></div>
      <div class="card-issued">Émis le <?= $genDate ?></div>
      <?php if ($user['license_expiry']): ?>
        <div class="card-licence">Licence exp. <?= Helpers::dateFormat($user['license_expiry']) ?></div>
      <?php endif; ?>
      <div class="card-hash">REF: <?= strtoupper(chunk_split(substr($user['member_card_hash'], -8), 4, '-')) ?></div>
      <div style="margin-top:.5rem">
        <img src="<?= $qrUrl ?>" width="70" height="70" alt="QR Code" style="border-radius:6px;border:2px solid rgba(255,255,255,.3)">
      </div>
    </div>
  </div>
  <div class="card-actions">
    <a href="<?=u('/membre/carte/telecharger')?>" class="btn btn-primary">⬇️ Télécharger ma carte PDF</a>
  </div>
</div>

<div class="card" style="margin-top:1.5rem">
  <h3 style="margin-bottom:.75rem;font-size:1rem">ℹ️ À propos de votre carte</h3>
  <p style="color:var(--color-muted);font-size:.875rem;line-height:1.7">
    Votre carte membre est générée et signée numériquement par le site du club. Elle contient une référence unique 
    (<code><?= strtoupper(substr($user['member_card_hash'], -8)) ?></code>) qui permet au staff de vérifier son authenticité 
    en quelques secondes. Toute carte modifiée sera détectée comme invalide.
  </p>
</div>

<style>
.card-preview-wrap{display:flex;flex-direction:column;align-items:flex-start;gap:1.5rem}
.member-card-preview{
  width:420px;max-width:100%;
  background:linear-gradient(135deg, var(--card-color), color-mix(in srgb, var(--card-color) 60%, #000));
  border-radius:14px;padding:0;
  box-shadow:0 20px 50px rgba(0,0,0,.25);
  display:flex;overflow:hidden;
  aspect-ratio:1.586;
  position:relative;
}
.card-left{
  padding:1.1rem 1rem;display:flex;flex-direction:column;
  border-right:1px solid rgba(255,255,255,.15);
  min-width:130px;
}
.card-logo{height:30px;object-fit:contain;margin-bottom:.5rem;filter:brightness(0) invert(1)}
.card-club{color:#fff;font-weight:700;font-size:.85rem;line-height:1.2;margin-bottom:.15rem}
.card-sport{color:rgba(255,255,255,.7);font-size:.65rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;margin-bottom:auto}
.card-type-label{color:rgba(255,255,255,.5);font-size:.6rem;letter-spacing:.12em;margin-top:auto}
.card-right{
  background:rgba(0,0,0,.35);
  padding:1.1rem 1rem;
  flex:1;display:flex;flex-direction:column;justify-content:flex-end;
}
.card-member-name{color:#fff;font-size:1.05rem;font-weight:700;letter-spacing:.02em;margin-bottom:.2rem}
.card-member-id{color:rgba(255,255,255,.8);font-size:.75rem;margin-bottom:.15rem}
.card-issued{color:rgba(255,255,255,.6);font-size:.65rem}
.card-licence{color:rgba(255,255,255,.6);font-size:.65rem}
.card-hash{color:rgba(255,255,255,.4);font-size:.6rem;margin-top:.25rem;font-family:monospace}
.card-actions{display:flex;gap:1rem}
.btn-ghost{display:inline-flex;align-items:center;gap:.4rem;padding:.65rem 1.5rem;border-radius:var(--radius-sm);font-weight:600;font-size:.9rem;cursor:pointer;border:1.5px solid var(--color-border);background:#fff;color:var(--color-text);font-family:var(--font-body);transition:all .2s;text-decoration:none}
.btn-ghost:hover{border-color:var(--color-primary);color:var(--color-primary)}
</style>

<?php endif; ?>
