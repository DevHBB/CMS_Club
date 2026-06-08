<?php
/**
 * ClubCMS — Module Planning / Calendrier
 */

// ── Dates en français (sans setlocale, compatible XAMPP Windows) ──────
if (!function_exists('frDate')) {
    function frDate(DateTime $dt, string $fmt = 'l d F Y'): string {
        static $days   = ['Sunday'=>'Dimanche','Monday'=>'Lundi','Tuesday'=>'Mardi',
                          'Wednesday'=>'Mercredi','Thursday'=>'Jeudi','Friday'=>'Vendredi','Saturday'=>'Samedi'];
        static $months = ['January'=>'Janvier','February'=>'Février','March'=>'Mars',
                          'April'=>'Avril','May'=>'Mai','June'=>'Juin','July'=>'Juillet',
                          'August'=>'Août','September'=>'Septembre','October'=>'Octobre',
                          'November'=>'Novembre','December'=>'Décembre'];
        return str_replace(array_keys($days + $months), array_values($days + $months), $dt->format($fmt));
    }
}


$action = $segments[1] ?? 'index';
$param  = $segments[2] ?? null;

// ── POST : Réservation ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'reserver') {
    if (!Auth::verifyCsrf()) Helpers::json(['error' => 'CSRF'], 403);

    $slotId = (int)$param;
    $slot   = Database::one("SELECT * FROM cc_planning_slots WHERE id = ? AND published = 1", [$slotId]);
    if (!$slot) Helpers::json(['error' => 'Créneau introuvable'], 404);

    // Lire les paramètres de réservation
    $bookingMode  = Config::get('booking_mode', 'auto');
    $waitlistOn   = (int)Config::get('booking_waitlist', 0);
    $maxWaitlist  = (int)Config::get('booking_max_waitlist', 0);

    $userId     = Auth::id();
    $guestName  = $userId ? null : Helpers::sanitize($_POST['guest_name'] ?? '');
    $guestEmail = $userId ? null : Helpers::sanitize($_POST['guest_email'] ?? '');
    $formData   = [];
    if ($slot['custom_form_fields']) {
        $customFields = json_decode($slot['custom_form_fields'], true) ?? [];
        foreach ($customFields as $f) {
            $formData[$f['name']] = Helpers::sanitize($_POST[$f['name']] ?? '');
        }
    }

    // Capturer les critères d'inscription
    $slotCritIds = json_decode($slot['criteria_ids']??'[]',true)??[];
    $criteriaData = [];
    if (!empty($slotCritIds)) {
        try {
            $allC = Database::all("SELECT * FROM cc_planning_criteria WHERE id IN (".implode(',',array_map('intval',$slotCritIds)).")");
            // Critères obligatoires pour CE créneau (override du global)
            $slotReqIds = json_decode($slot['criteria_required']??'[]',true)??[];
            foreach ($allC as $cr) {
                $val = Helpers::sanitize($_POST['crit_'.$cr['id']] ?? '');
                if ($val === '__other__') $val = Helpers::sanitize($_POST['crit_'.$cr['id'].'_other'] ?? '');
                // Obligatoire si : required global OU required pour ce créneau
                $isRequired = $cr['required'] || in_array($cr['id'], $slotReqIds);
                if ($isRequired && $val === '') {
                    $bookingError = 'Le champ "'.htmlspecialchars($cr['name']).'" est obligatoire pour ce créneau.';
                    goto render_planning;
                }
                $criteriaData[$cr['id']] = ['label'=>$cr['name'],'value'=>$val];
                // Mémoriser pour les membres connectés
                if ($userId && $val !== '') {
                    try {
                        Database::run(
                            "INSERT INTO cc_planning_criteria_values (user_id,criteria_id,value) VALUES (?,?,?)
                             ON DUPLICATE KEY UPDATE value=VALUES(value)",
                            [$userId, $cr['id'], $val]
                        );
                    } catch(Exception $e) {}
                }
            }
        } catch(Exception $e) {}
    }
    $formData['_criteria'] = $criteriaData;

    $isWaitlist = false;

    if ($slot['max_participants']) {
        $taken = (int)Database::scalar(
            "SELECT COUNT(*) FROM cc_planning_bookings WHERE slot_id = ? AND status = 'confirmed'",
            [$slotId]
        );
        if ($taken >= $slot['max_participants']) {
            // Créneau complet → liste d'attente ?
            if (!$waitlistOn) {
                $bookingError = 'Ce créneau est complet.';
                goto render_planning;
            }
            // Vérifier limite liste d'attente
            if ($maxWaitlist > 0) {
                $waitCount = (int)Database::scalar(
                    "SELECT COUNT(*) FROM cc_planning_bookings WHERE slot_id = ? AND is_waitlist = 1",
                    [$slotId]
                );
                if ($waitCount >= $maxWaitlist) {
                    $bookingError = "Ce créneau est complet et la liste d'attente est pleine.";
                    goto render_planning;
                }
            }
            $isWaitlist = true;
        }
    }

    // Statut selon mode et waitlist
    $status = $isWaitlist ? 'pending' : ($bookingMode === 'manual' ? 'pending' : 'confirmed');

    Database::insert(
        "INSERT INTO cc_planning_bookings (slot_id, user_id, guest_name, guest_email, status, is_waitlist, form_data, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
        [$slotId, $userId, $guestName, $guestEmail, $status, $isWaitlist ? 1 : 0, json_encode($formData)]
    );

    // Email selon situation
    if ($userId) { $user = Auth::user(); $emailTo = $user['email']; $emailName = $user['firstname']; }
    else { $emailTo = $guestEmail; $emailName = $guestName; }

    $club      = Config::get('club_name', 'Mon Club');
    $slotTitle = htmlspecialchars($slot['title']);
    $slotDate  = Helpers::dateTimeFormat($slot['date_start']);

    if ($emailTo) {
        if ($isWaitlist) {
            $subject = "Liste d'attente — {$slot['title']}";
            $critRecap2 = '';
            foreach ($criteriaData as $cd) {
                $critRecap2 .= "<li><strong>".htmlspecialchars($cd['label'])."</strong> : ".htmlspecialchars($cd['value'])."</li>";
            }
            $critBlock2 = $critRecap2 ? "<p><strong>Vos informations enregistrées :</strong><ul>{$critRecap2}</ul></p>" : '';
            $body    = "<h2>📋 Vous êtes sur liste d'attente</h2>
                <p>Bonjour <strong>{$emailName}</strong>,</p>
                <p>Le créneau <strong>{$slotTitle}</strong> ({$slotDate}) est complet.</p>
                <p>Vous avez été ajouté(e) à la liste d'attente. Vous recevrez un email si une place se libère.</p>
                {$critBlock2}";
        } elseif ($bookingMode === 'manual') {
            $subject = "Demande d'inscription reçue — {$slot['title']}";
            $critRecap3 = '';
            foreach ($criteriaData as $cd) {
                $critRecap3 .= "<li><strong>".htmlspecialchars($cd['label'])."</strong> : ".htmlspecialchars($cd['value'])."</li>";
            }
            $critBlock3 = $critRecap3 ? "<p><strong>Vos informations :</strong><ul>{$critRecap3}</ul></p>" : '';
            $body    = "<h2>⏳ Demande en cours d'examen</h2>
                <p>Bonjour <strong>{$emailName}</strong>,</p>
                <p>Votre demande d'inscription pour <strong>{$slotTitle}</strong> ({$slotDate}) a bien été reçue.</p>
                <p>Elle sera examinée par un administrateur. Vous recevrez un email de confirmation ou de refus.</p>
                {$critBlock3}";
        } else {
            $subject = "Réservation confirmée — {$slot['title']}";
            $critRecap = '';
            foreach ($criteriaData as $cd) {
                $critRecap .= "<li><strong>".htmlspecialchars($cd['label'])."</strong> : ".htmlspecialchars($cd['value'])."</li>";
            }
            $critBlock = $critRecap ? "<p><strong>Vos informations :</strong><ul>{$critRecap}</ul></p>" : '';
            $body    = "<h2>✅ Réservation confirmée</h2>
                <p>Bonjour <strong>{$emailName}</strong>,</p>
                <p>Votre réservation pour <strong>{$slotTitle}</strong> est confirmée.</p>
                <p>📅 {$slotDate}</p>
                {$critBlock}";
        }
        Mailer::send($emailTo, $emailName, $subject, $body);
    }

    Helpers::redirect(u('/planning/reserver/' . $slotId . '?booked=1&waitlist=' . ($isWaitlist ? 1 : 0) . '&mode=' . $bookingMode));
}

// ── Export PDF / iCal ──────────────────────────────────────
// Migration : colonne waitlist
try { Database::run("ALTER TABLE cc_planning_bookings ADD COLUMN is_waitlist TINYINT(1) NOT NULL DEFAULT 0 AFTER status"); } catch(Exception $e) {}

if ($action === 'export') {
    $fmt      = $param; // 'pdf' ou 'ical'
    $access   = Config::get('planning_export_access', 'members');
    $canExp   = ($access === 'all') ||
                ($access === 'members' && Auth::check()) ||
                ($access === 'admin'   && Auth::isAdmin());
    if (!$canExp) { Helpers::redirect(u('/login')); exit; }

    // Lire view et date depuis GET (transmis par les boutons)
    $expView = in_array($_GET['view'] ?? '', ['month','week','list']) ? $_GET['view'] : 'month';
    $expDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-01');
    $base    = new DateTime($expDate);

    if ($expView === 'week') {
        $start = clone $base;
        $end   = clone $base;
        $end->modify('+6 days');
        $labelPeriode = 'Semaine du '.$start->format('d/m/Y').' au '.$end->format('d/m/Y');
    } elseif ($expView === 'list') {
        $start = new DateTime('today');
        $end   = new DateTime('+3 months');
        $labelPeriode = 'Du '.$start->format('d/m/Y').' au '.$end->format('d/m/Y');
    } else { // month
        $start = new DateTime($base->format('Y-m-01'));
        $end   = new DateTime($base->format('Y-m-t'));
        $labelPeriode = frDate($base, 'F Y');
    }

    // Ajouter colonnes si pas encore migrées
    try { Database::run("ALTER TABLE cc_planning_slots ADD COLUMN IF NOT EXISTS members_only TINYINT(1) DEFAULT 0"); } catch(Exception $e) {}
    try { Database::run("ALTER TABLE cc_planning_slots ADD COLUMN IF NOT EXISTS members_message TINYINT(1) DEFAULT 0"); } catch(Exception $e) {}
    try { Database::run("ALTER TABLE cc_planning_slots ADD COLUMN IF NOT EXISTS members_msg_text VARCHAR(500) DEFAULT 'Il faut être membre pour participer.'"); } catch(Exception $e) {}

    $isLoggedIn = Auth::check();

    $slots = Database::all(
        "SELECT s.*, u.firstname AS coach_firstname, u.lastname AS coach_lastname
         FROM cc_planning_slots s
         LEFT JOIN cc_users u ON s.coach_id = u.id
         WHERE s.published = 1
           AND DATE(s.date_start) BETWEEN ? AND ?
         ORDER BY s.date_start ASC",
        [$start->format('Y-m-d'), $end->format('Y-m-d')]
    );

    // Filtrer les créneaux membres_only si non connecté
    if (!$isLoggedIn) {
        $slots = array_filter($slots, fn($s) => empty($s['members_only']));
        $slots = array_values($slots);
    }

    $planningTypes = [];
    try {
        $rows = Database::all("SELECT slug, label, color FROM cc_planning_types ORDER BY sort_order");
        foreach ($rows as $r) $planningTypes[$r['slug']] = $r;
    } catch (Exception $e) {}
    if (empty($planningTypes)) {
        $planningTypes = [
            'open'        => ['label'=>'Libre',       'color'=>'#22c55e'],
            'training'    => ['label'=>'Entraînement','color'=>'#3b82f6'],
            'event'       => ['label'=>'Événement',   'color'=>'#f59e0b'],
            'maintenance' => ['label'=>'Fermé',       'color'=>'#6b7280'],
            'competition' => ['label'=>'Compétition', 'color'=>'#ef4444'],
        ];
    }

    $clubName = Config::get('club_name', 'Club');

    // ── iCal ──────────────────────────────────────────────
    if ($fmt === 'ical') {
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="planning-'.date('Y-m').'.ics"');
        echo "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//ClubCMS//Planning//FR\r\nCALSCALE:GREGORIAN\r\n";
        echo "X-WR-CALNAME:".addcslashes($clubName,'\\,;')." — Planning\r\nX-WR-TIMEZONE:Europe/Paris\r\n";
        foreach ($slots as $s) {
            $dtstart = (new DateTime($s['date_start']))->format('Ymd\THis');
            $dtend   = (new DateTime($s['date_end']))->format('Ymd\THis');
            $pt      = $planningTypes[$s['type']] ?? ['label'=>$s['type']];
            $summary = addcslashes($pt['label']. ': '.$s['title'],'\\,;');
            $desc    = addcslashes($s['description']??'','\\,;');
            $coach   = $s['coach_firstname'] ? addcslashes($s['coach_firstname'].' '.($s['coach_lastname']??''), '\\,;') : '';
            echo "BEGIN:VEVENT\r\nUID:slot-".$s['id']."@clubcms\r\nDTSTART:".$dtstart."\r\nDTEND:".$dtend."\r\nSUMMARY:".$summary."\r\n";
            if ($desc)  echo "DESCRIPTION:".$desc."\r\n";
            if ($coach) echo "LOCATION:Coach: ".$coach."\r\n";
            echo "END:VEVENT\r\n";
        }
        echo "END:VCALENDAR\r\n";
        exit;
    }

    // ── PDF ───────────────────────────────────────────────
    if ($fmt === 'pdf') {
        require_once CC_ROOT . '/pdf/fpdf/fpdf.php';
        if (!class_exists('PlanningPDF')) {
            class PlanningPDF extends FPDF {
                public $clubName = '';
                public $periode  = '';
                function Header() {
                    $this->SetFillColor(29,78,216);
                    $this->Rect(0,0,210,18,'F');
                    $this->SetTextColor(255,255,255);
                    $this->SetFont('Arial','B',13);
                    $this->SetXY(8,4);
                    $this->Cell(130,10,iconv('UTF-8','CP1252',$this->clubName.' — Planning'),0,0,'L');
                    $this->SetFont('Arial','',9);
                    $this->SetX(140);
                    $this->Cell(62,10,iconv('UTF-8','CP1252',$this->periode),0,0,'R');
                    $this->SetTextColor(0,0,0);
                    $this->Ln(20);
                }
                function Footer() {
                    $this->SetY(-12);
                    $this->SetFont('Arial','I',7);
                    $this->SetTextColor(148,163,184);
                    $this->Cell(0,8,iconv('UTF-8','CP1252','Page '.$this->PageNo().' — Généré le '.date('d/m/Y à H:i')),0,0,'C');
                }
            }
        }
        $pdf = new PlanningPDF('P','mm','A4');
        $pdf->clubName = $clubName;
        $pdf->periode  = $labelPeriode;
        $pdf->SetAutoPageBreak(true,16);
        $pdf->SetMargins(8,22,8);
        $pdf->AddPage();

        if (empty($slots)) {
            $pdf->SetFont('Arial','I',10);
            $pdf->SetTextColor(148,163,184);
            $pdf->Cell(0,10,iconv('UTF-8','CP1252','Aucun créneau sur cette période.'),0,1,'C');
        } else {
            $cols = [38,28,62,37,20];
            $heads = ['Date','Horaire','Titre','Coach','Places'];
            $pdf->SetFont('Arial','B',8);
            $pdf->SetFillColor(241,245,249);
            $pdf->SetTextColor(71,85,105);
            foreach ($heads as $i=>$h)
                $pdf->Cell($cols[$i],7,iconv('UTF-8','CP1252',$h),1,0,'C',true);
            $pdf->Ln();
            $prevDay=''; $rowEven=false;
            foreach ($slots as $s) {
                $dt  = new DateTime($s['date_start']);
                $de  = new DateTime($s['date_end']);
                $day = $dt->format('d/m/Y');
                $pt  = $planningTypes[$s['type']] ?? ['label'=>$s['type'],'color'=>'#94a3b8'];
                if ($day !== $prevDay) {
                    $pdf->SetFont('Arial','B',8);
                    $pdf->SetFillColor(239,246,255);
                    $pdf->SetTextColor(29,78,216);
                    $pdf->Cell(185,6,iconv('UTF-8','CP1252','  '.frDate($dt)),0,1,'L',true);
                    $prevDay=$day; $rowEven=false;
                }
                $hex=$pt['color']; $hex=ltrim($hex,'#');
                $r=hexdec(substr($hex,0,2));$g=hexdec(substr($hex,2,2));$b=hexdec(substr($hex,4,2));
                $bg=$rowEven?[248,250,252]:[255,255,255];
                $pdf->SetFillColor($bg[0],$bg[1],$bg[2]);
                $pdf->SetFont('Arial','',8);
                $pdf->SetTextColor(30,41,59);
                $x=$pdf->GetX(); $y=$pdf->GetY();
                $pdf->SetFillColor($r,$g,$b);
                $pdf->Rect($x,$y+1.5,3,4,'F');
                $pdf->SetFillColor($bg[0],$bg[1],$bg[2]);
                $pdf->SetX($x+3.5);
                $pdf->Cell($cols[0]-3.5,6,iconv('UTF-8','CP1252',$day),0,0,'L',false);
                $pdf->Cell($cols[1],6,iconv('UTF-8','CP1252',$dt->format('H:i').'–'.$de->format('H:i')),0,0,'C',false);
                $tit=mb_strlen($s['title'])>34?mb_substr($s['title'],0,32).'…':$s['title'];
                $pdf->Cell($cols[2],6,iconv('UTF-8','CP1252',$tit),0,0,'L',false);
                $coach=$s['coach_firstname']?$s['coach_firstname'].' '.($s['coach_lastname']??''):'—';
                $coach=mb_strlen($coach)>22?mb_substr($coach,0,20).'…':$coach;
                $pdf->Cell($cols[3],6,iconv('UTF-8','CP1252',$coach),0,0,'L',false);
                // Compter places restantes
$taken_pdf = (int)Database::scalar("SELECT COUNT(*) FROM cc_planning_bookings WHERE slot_id=? AND status='confirmed'", [$s['id']]);
$places_str = $s['max_participants']
    ? ($s['max_participants']-$taken_pdf).'/'.$s['max_participants']
    : '∞';
$pdf->Cell($cols[4],6,iconv('UTF-8','CP1252',$places_str),0,1,'C',false);
                $rowEven=!$rowEven;
            }
        }
        $fname = 'planning-'.$expView.'-'.str_replace('-','',$expDate).'.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="'.$fname.'"');
        echo $pdf->Output('S');
        exit;
    }
}


render_planning:

$pageTitle = 'Planning — ' . Config::get('club_name');

// BUG FIX : évaluer $_GET['view'] proprement avant in_array
$viewRaw  = isset($_GET['view']) ? (string)$_GET['view'] : 'month';
$view     = in_array($viewRaw, ['month', 'week', 'list']) ? $viewRaw : 'month';
$dateStr  = isset($_GET['date']) ? (string)$_GET['date'] : date('Y-m-01');

// Sécurité : valider le format de date
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
    $dateStr = date('Y-m-01');
}
$baseDate = new DateTime($dateStr);

if ($view === 'week') {
    $start = clone $baseDate;
    $start->modify('monday this week');
    $end = clone $start;
    $end->modify('+6 days');
} else {
    $start = new DateTime($baseDate->format('Y-m-01'));
    $end   = new DateTime($baseDate->format('Y-m-t'));
}

// Migration colonnes membres
try { Database::run("ALTER TABLE cc_planning_slots ADD COLUMN IF NOT EXISTS members_only TINYINT(1) DEFAULT 0"); } catch(Exception $e) {}
try { Database::run("ALTER TABLE cc_planning_slots ADD COLUMN IF NOT EXISTS members_message TINYINT(1) DEFAULT 0"); } catch(Exception $e) {}
try { Database::run("ALTER TABLE cc_planning_slots ADD COLUMN IF NOT EXISTS members_msg_text VARCHAR(500) DEFAULT 'Il faut être membre pour participer.'"); } catch(Exception $e) {}

$isLoggedIn = Auth::check();

// Si visiteur non connecté → exclure les créneaux members_only
$membersOnlyClause = $isLoggedIn ? '' : 'AND (s.members_only = 0 OR s.members_only IS NULL)';

$slots = Database::all(
    "SELECT s.*, u.firstname AS coach_firstname, u.lastname AS coach_lastname
     FROM cc_planning_slots s
     LEFT JOIN cc_users u ON s.coach_id = u.id
     WHERE s.published = 1
       AND s.date_start <= ? AND s.date_end >= ?
       $membersOnlyClause
     ORDER BY s.date_start ASC",
    [$end->format('Y-m-d 23:59:59'), $start->format('Y-m-d 00:00:00')]
);

$slotsByDay = [];
foreach ($slots as $s) {
    $day = (new DateTime($s['date_start']))->format('Y-m-d');
    $slotsByDay[$day][] = $s;
}

// Charger les types pour couleurs et labels dynamiques
$planningTypes = [];
try {
    $rows = Database::all("SELECT slug, label, color FROM cc_planning_types ORDER BY sort_order");
    foreach ($rows as $r) $planningTypes[$r['slug']] = $r;
} catch (Exception $e) {}
// Fallback si table pas encore créée
if (empty($planningTypes)) {
    $planningTypes = [
        'open'        => ['label'=>'Libre',        'color'=>'#22c55e'],
        'training'    => ['label'=>'Entraînement',  'color'=>'#3b82f6'],
        'event'       => ['label'=>'Événement',     'color'=>'#f59e0b'],
        'maintenance' => ['label'=>'Fermé',         'color'=>'#6b7280'],
        'competition' => ['label'=>'Compétition',   'color'=>'#ef4444'],
    ];
}

// Navigation prev/next
$prev = clone $baseDate;
$next = clone $baseDate;
if ($view === 'week') { $prev->modify('-7 days'); $next->modify('+7 days'); }
else { $prev->modify('-1 month'); $next->modify('+1 month'); }

$periodLabel = $view === 'week'
    ? $start->format('d') . '–' . $end->format('d M Y')
    : ucfirst($baseDate->format('F Y'));

ob_start();?>

<!-- HEADER PLANNING -->
<div class="planning-header">
  <div class="container planning-top">
    <div class="planning-left">
      <?php $ph_t=Config::get('ph_planning_title',''); $ph_s=Config::get('ph_planning_subtitle',''); ?>
      <h1 class="planning-title"><?=$ph_t?Helpers::e($ph_t):'📅 Planning'?></h1>
      <?php if($ph_s): ?><p style="color:rgba(255,255,255,.75);font-size:.875rem;margin:.2rem 0 0"><?=Helpers::e($ph_s)?></p><?php endif; ?>
      <span class="planning-period-label"><?= $periodLabel ?></span>
    </div>
    <div class="planning-controls">
      <a href="<?=u('/planning?view='.$view.'&date='.$prev->format('Y-m-d'))?>" class="pnav-btn" title="Précédent">‹</a>
      <a href="<?=u('/planning?view='.$view.'&date='.date('Y-m-01'))?>" class="pnav-today">Aujourd'hui</a>
      <a href="<?=u('/planning?view='.$view.'&date='.$next->format('Y-m-d'))?>" class="pnav-btn" title="Suivant">›</a>
      <div class="view-sw">
        <a href="<?=u('/planning?view=month&date='.$baseDate->format('Y-m-d'))?>" class="vsw-btn <?= $view==='month'?'active':'' ?>">Mois</a>
        <a href="<?=u('/planning?view=week&date='.$baseDate->format('Y-m-d'))?>"  class="vsw-btn <?= $view==='week' ?'active':'' ?>">Semaine</a>
        <a href="<?=u('/planning?view=list&date='.$baseDate->format('Y-m-d'))?>"  class="vsw-btn <?= $view==='list' ?'active':'' ?>">Liste</a>
      </div>
    </div>
  </div>
</div>

<div class="container planning-body">

  <!-- Boutons export -->
  <?php
  $exportAccess = Config::get('planning_export_access', 'members');
  $canExport = ($exportAccess === 'all') ||
               ($exportAccess === 'members' && Auth::check()) ||
               ($exportAccess === 'admin'   && Auth::isAdmin());
  ?>
  <?php if ($canExport): ?>
  <div class="plan-export-btns">
    <a href="<?=u('/planning/export/pdf?view='.$view.'&date='.$baseDate->format('Y-m-d'))?>"
       class="plan-export-btn" title="Télécharger cette période en PDF">
      📄 PDF <?=($view==='month'?'du mois':($view==='week'?'de la semaine':'— liste'))?>
    </a>
    <a href="<?=u('/planning/export/ical?view='.$view.'&date='.$baseDate->format('Y-m-d'))?>"
       class="plan-export-btn" title="Exporter vers Google Calendar, Apple Calendar, Outlook…">
      📅 Exporter iCal
    </a>
  </div>
  <?php endif; ?>

  <!-- Légende compacte dynamique -->
  <div class="plan-legend">
    <?php foreach ($planningTypes as $slug => $pt): ?>
    <span class="pl-item" style="background:<?=Helpers::e($pt['color'])?>;color:#fff"><?=Helpers::e($pt['label'])?></span>
    <?php endforeach; ?>
  </div>

<?php if ($view === 'list'): ?>
<!-- ════ VUE LISTE ════ -->
<div class="plan-list">
  <?php
  $grouped = [];
  foreach ($slots as $s) {
      $day = (new DateTime($s['date_start']))->format('Y-m-d');
      $grouped[$day][] = $s;
  }
  if (empty($grouped)): ?>
    <div class="plan-empty">📅 Aucun créneau ce mois-ci.</div>
  <?php endif;
  foreach ($grouped as $day => $daySlots):
    $dt = new DateTime($day);
  ?>
  <div class="plist-day-head"><?= frDate($dt) ?></div>
  <?php foreach ($daySlots as $s):
    $taken = (int)Database::scalar("SELECT COUNT(*) FROM cc_planning_bookings WHERE slot_id=? AND status='confirmed'", [$s['id']]);
    $full  = $s['max_participants'] && $taken >= $s['max_participants'];
  ?>
  <?php $pt = $planningTypes[$s['type']] ?? ['color'=>'#94a3b8','label'=>$s['type']]; ?>
  <div class="plist-slot">
    <div class="pls-bar" style="background:<?=Helpers::e($pt['color'])?>"></div>
    <div class="pls-time"><?= (new DateTime($s['date_start']))->format('H:i') ?> – <?= (new DateTime($s['date_end']))->format('H:i') ?></div>
    <div class="pls-info">
      <div class="pls-title"><?= Helpers::e($s['title']) ?></div>
      <?php if ($s['coach_firstname']): ?><div class="pls-meta">👤 <?= Helpers::e($s['coach_firstname'] . ' ' . $s['coach_lastname']) ?></div><?php endif; ?>
    </div>
    <div class="pls-right">
      <?php if ($s['max_participants']): ?>
        <span class="pls-spots <?= $full?'full':'' ?>"><?= $full ? 'Complet' : ($s['max_participants']-$taken).' place'.($s['max_participants']-$taken>1?'s':'') ?></span>
      <?php endif; ?>
      <?php
      // Message membres : visiteur non connecté + option cochée
      if (!$isLoggedIn && !empty($s['members_message'])):
      ?>
        <span style="font-size:.75rem;color:#f59e0b;font-weight:600;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:.25rem .6rem;display:inline-block;max-width:140px;text-align:center;line-height:1.3">
          🔒 <?=Helpers::e($s['members_msg_text'] ?? 'Il faut être membre pour participer.')?>
        </span>
      <?php elseif ($s['require_booking'] && !$full): ?>
        <?php if ($s['booking_form']==='external' && $s['external_url']): ?>
          <a href="<?= Helpers::e($s['external_url']) ?>" target="_blank" class="pls-book">S'inscrire ↗</a>
        <?php else: ?>
          <a href="<?=u('/planning/reserver/'.$s['id'])?>" class="pls-book">Réserver</a>
        <?php endif; ?>
      <?php elseif (!$s['require_booking']): ?>
        <button type="button" class="pls-book pls-info-btn"
          onclick="showSlotInfo(this)"
          data-title="<?=Helpers::e($s['title'])?>"
          data-desc="<?=Helpers::e($s['description']??'')?>"
          data-time="<?=(new DateTime($s['date_start']))->format('H:i')?> – <?=(new DateTime($s['date_end']))->format('H:i')?>"
          data-coach="<?=Helpers::e(($s['coach_firstname']??'').($s['coach_firstname']?' '.($s['coach_lastname']??''):''))?>"
          >Voir</button>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; endforeach; ?>
</div>

<?php elseif ($view === 'month'): ?>
<!-- ════ VUE MOIS (grille compacte) ════ -->
<div class="cal-month">
  <div class="cal-head">
    <?php foreach (['L','M','M','J','V','S','D'] as $d): ?>
      <div class="cal-hd"><?= $d ?></div>
    <?php endforeach; ?>
  </div>
  <div class="cal-grid">
    <?php
    $firstDay    = new DateTime($baseDate->format('Y-m-01'));
    $startDow    = (int)$firstDay->format('N') - 1;
    $daysInMonth = (int)$baseDate->format('t');
    $today       = date('Y-m-d');
    for ($i = 0; $i < $startDow; $i++): ?>
      <div class="cal-cell empty"></div>
    <?php endfor;
    for ($d = 1; $d <= $daysInMonth; $d++):
      $dayStr   = $baseDate->format('Y-m-') . str_pad($d, 2, '0', STR_PAD_LEFT);
      $isToday  = $dayStr === $today;
      $daySlots = $slotsByDay[$dayStr] ?? [];
    ?>
    <div class="cal-cell <?= $isToday ? 'today' : '' ?>">
      <div class="cal-num <?= $isToday ? 'today-num' : '' ?>"><?= $d ?></div>
      <div class="cal-events">
        <?php foreach (array_slice($daySlots, 0, 3) as $s): ?>
          <?php
          $pt = $planningTypes[$s['type']] ?? ['color'=>'#94a3b8','label'=>$s['type']];
          ?>
          <?php if ($s['require_booking']): ?>
          <a href="<?=u('/planning/reserver/'.$s['id'])?>" class="cal-ev" style="background:<?=Helpers::e($pt['color'])?>" title="<?= Helpers::e($s['title']) ?>">
            <?= (new DateTime($s['date_start']))->format('H:i') ?> <?= Helpers::e(Helpers::excerpt($s['title'], 16)) ?>
          </a>
          <?php else: ?>
          <span class="cal-ev" style="background:<?=Helpers::e($pt['color']??'#94a3b8')?>;cursor:pointer"
            onclick="showSlotPopup(<?=json_encode(['title'=>$s['title'],'time'=>(new DateTime($s['date_start']))->format('H:i').' – '.(new DateTime($s['date_end']))->format('H:i'),'coach'=>($s['coach_firstname']??'').($s['coach_firstname']?' '.($s['coach_lastname']??''):''),'desc'=>$s['description']??''])?>)"
            title="<?= Helpers::e($s['title']) ?>">
            <?= (new DateTime($s['date_start']))->format('H:i') ?> <?= Helpers::e(Helpers::excerpt($s['title'], 16)) ?>
          </span>
          <?php endif; ?>
        <?php endforeach; ?>
        <?php if (count($daySlots) > 3): ?>
          <span class="cal-more">+<?= count($daySlots)-3 ?></span>
        <?php endif; ?>
      </div>
    </div>
    <?php endfor; ?>
  </div>
</div>

<?php else: ?>
<!-- ════ VUE SEMAINE (scrollable, heures compactes) ════ -->
<?php
$hours = range(7, 22);
$days  = [];
$d = clone $start;
while ($d <= $end) { $days[] = clone $d; $d->modify('+1 day'); }
?>
<div class="cal-week-wrap">
  <div class="cal-week">
    <div class="cw-head">
      <div class="cw-gutter"></div>
      <?php foreach ($days as $day): ?>
        <div class="cw-day-head <?= $day->format('Y-m-d')===date('Y-m-d')?'today':'' ?>">
          <span class="cwh-name"><?= $day->format('D') ?></span>
          <span class="cwh-num"><?= $day->format('j') ?></span>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="cw-body">
      <?php foreach ($hours as $hour): ?>
      <div class="cw-row">
        <div class="cw-time"><?= $hour ?>h</div>
        <?php foreach ($days as $day):
          $dayStr = $day->format('Y-m-d');
        ?>
        <div class="cw-cell">
          <?php foreach ($slotsByDay[$dayStr] ?? [] as $s):
            $sh = (int)(new DateTime($s['date_start']))->format('G');
            if ($sh === $hour):
              $durMin = ((new DateTime($s['date_end']))->getTimestamp() - (new DateTime($s['date_start']))->getTimestamp()) / 60;
              $h = max(24, round($durMin * 32/60)); // 32px/heure
          ?>
            <a href="/planning/reserver/<?= $s['id'] ?>" class="cw-ev <?= $s['type'] ?>" style="height:<?= $h ?>px" title="<?= Helpers::e($s['title']) ?>">
              <span class="cwe-time"><?= (new DateTime($s['date_start']))->format('H:i') ?></span>
              <span class="cwe-title"><?= Helpers::e(Helpers::excerpt($s['title'],20)) ?></span>
            </a>
          <?php endif; endforeach; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

</div><!-- .planning-body -->

<!-- MODALE RÉSERVATION -->
<?php if ($action === 'reserver' && $param): ?>
<?php
$slotId = (int)$param;
$slot   = Database::one(
    "SELECT s.*, u.firstname AS cn, u.lastname AS cl FROM cc_planning_slots s
     LEFT JOIN cc_users u ON s.coach_id = u.id WHERE s.id = ? AND s.published = 1",
    [$slotId]
);
if (!$slot) { http_response_code(404); exit; }
// Bloquer si members_only et non connecté
if (!empty($slot['members_only']) && !Auth::check()) {
    Helpers::redirect(u('/login'));
    exit;
}
// Bloquer si members_message et non connecté (pas de réservation)
$showMembersMsg = !empty($slot['members_message']) && !Auth::check();
$booked = (bool)($_GET['booked'] ?? false);
$taken  = (int)Database::scalar("SELECT COUNT(*) FROM cc_planning_bookings WHERE slot_id=? AND status='confirmed'", [$slotId]);
$full   = $slot['max_participants'] && $taken >= $slot['max_participants'];
$alreadyBooked = Auth::id() && Database::scalar(
    "SELECT id FROM cc_planning_bookings WHERE slot_id=? AND user_id=? AND status='confirmed'",
    [$slotId, Auth::id()]
);
$customFields = json_decode($slot['custom_form_fields'] ?? '[]', true) ?? [];
?>
<div class="plan-modal-overlay" onclick="if(event.target===this) window.location='/planning'">
  <div class="plan-modal">
    <a href="<?=u('/planning')?>" class="plan-modal-close">✕</a>
    <h2 class="plan-modal-title"><?= Helpers::e($slot['title']) ?></h2>
    <div class="plan-modal-meta">
      <span>📅 <?= Helpers::dateTimeFormat($slot['date_start']) ?> – <?= (new DateTime($slot['date_end']))->format('H:i') ?></span>
      <?php if ($slot['cn']): ?><span>👤 <?= Helpers::e($slot['cn'].' '.$slot['cl']) ?></span><?php endif; ?>
      <?php if ($slot['max_participants']): ?><span>🎯 <?= $taken ?>/<?= $slot['max_participants'] ?> inscrits</span><?php endif; ?>
    </div>

    <?php if (!$slot['require_booking']): ?>
      <!-- Créneau sans inscription : affichage info seulement -->
      <?php if ($slot['description']): ?>
        <p style="color:#475569;line-height:1.7;margin-top:.5rem"><?= nl2br(Helpers::e($slot['description'])) ?></p>
      <?php else: ?>
        <p style="color:#94a3b8;font-style:italic">Aucune inscription requise pour ce créneau.</p>
      <?php endif; ?>
    <?php elseif ($booked): ?>
      <div class="alert alert-success">✅ Inscription confirmée ! Un email de confirmation vous a été envoyé.</div>
    <?php elseif ($showMembersMsg): ?>
      <div style="background:#fffbeb;border:1.5px solid #fde68a;border-radius:10px;padding:1.25rem;text-align:center">
        <div style="font-size:1.5rem;margin-bottom:.5rem">🔒</div>
        <div style="font-weight:700;color:#92400e;margin-bottom:.5rem"><?=Helpers::e($slot['members_msg_text'] ?? 'Il faut être membre pour participer.')?></div>
        <a href="<?=u('/login')?>" class="btn btn-primary" style="margin-top:.5rem">Se connecter</a>
        <a href="<?=u('/register')?>" class="btn btn-ghost" style="margin-top:.5rem">S'inscrire</a>
      </div>
    <?php elseif (isset($bookingError)): ?>
      <div class="alert alert-error"><?= Helpers::e($bookingError) ?></div>
    <?php elseif ($alreadyBooked): ?>
      <div class="alert alert-success">✅ Vous êtes déjà inscrit à ce créneau.</div>
    <?php elseif ($full && !(int)Config::get('booking_waitlist',0)): ?>
      <div class="alert alert-warning">🔴 Ce créneau est complet.</div>
    <?php elseif ($slot['booking_form']==='external' && $slot['external_url']): ?>
      <a href="<?= Helpers::e($slot['external_url']) ?>" target="_blank" class="btn btn-primary" style="display:block;text-align:center;margin-top:1rem">S'inscrire via le formulaire ↗</a>
    <?php else:
      // Charger les critères de ce créneau
      if(!class_exists('CriteriaRenderer'))require_once CC_ROOT.'/core/CriteriaRenderer.php';
      $slotCritIds  = json_decode($slot['criteria_ids']??'[]',true)??[];
      $slotReqIds2  = json_decode($slot['criteria_required']??'[]',true)??[];
      $slotCriteria = [];
      if (!empty($slotCritIds)) {
          try {
              $allC = Database::all("SELECT * FROM cc_planning_criteria WHERE id IN (".implode(',',array_map('intval',$slotCritIds)).") ORDER BY sort_order,name");
              foreach ($allC as $c) {
                  $c['is_required_here'] = $c['required'] || in_array((int)$c['id'], $slotReqIds2);
                  $slotCriteria[] = $c;
              }
          } catch(Exception $e) {}
      }
      // Valeurs mémorisées pour ce membre (value + value2 pour range)
      $memberCritValues  = [];
      $memberCritValues2 = [];
      if (Auth::check()) {
          try {
              $rows = Database::all("SELECT criteria_id, value, value2 FROM cc_planning_criteria_values WHERE user_id=?", [Auth::id()]);
              foreach ($rows as $r) {
                  $memberCritValues[$r['criteria_id']]  = $r['value'];
                  $memberCritValues2[$r['criteria_id']] = $r['value2'] ?? '';
              }
          } catch(Exception $e) {}
      }
      $waitlistMsg2 = $full ? " (liste d'attente)" : '';
    ?>

    <?php if ($full): ?>
    <div class="alert alert-warning" style="background:#fef3c7;border:1.5px solid #fde68a;border-radius:10px;padding:.875rem 1rem;margin-bottom:1rem">
      📋 Ce créneau est complet — vous serez inscrit(e) sur <strong>liste d'attente</strong>.
    </div>
    <?php endif; ?>

    <?php if (!Auth::check()): ?>
      <div class="alert alert-info" style="background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:10px;padding:.875rem 1rem;margin-bottom:1rem">
        <a href="<?=u('/login?return=/planning/reserver/'.$slotId)?>">Connectez-vous</a> pour pré-remplir vos informations, ou remplissez le formulaire :
      </div>
      <form method="post" action="<?=u('/planning/reserver/'.$slotId)?>">
        <?= Auth::csrfField() ?>
        <div class="form-group"><label>Votre nom *</label><input type="text" name="guest_name" required></div>
        <div class="form-group"><label>Votre email *</label><input type="email" name="guest_email" required></div>
        <?php foreach ($slotCriteria as $cr): ?>
        <div class="form-group" style="margin-top:.875rem">
          <label style="font-weight:700;display:block;margin-bottom:.35rem">
            <?=Helpers::e($cr['name'])?><?=($cr['is_required_here']??$cr['required'])?' *':''?>
          </label>
          <?=CriteriaRenderer::field($cr, '', '')?>
        </div>
        <?php endforeach;?>
        <?php foreach ($customFields as $f): ?>
          <div class="form-group"><label><?= Helpers::e($f['label']) ?></label><input type="text" name="<?= Helpers::e($f['name']) ?>" <?= !empty($f['required'])?'required':'' ?>></div>
        <?php endforeach; ?>
        <button type="submit" class="btn btn-primary" style="width:100%;margin-top:.75rem">✅ Confirmer<?=$waitlistMsg2?></button>
      </form>
    <?php else: // Membre connecté ?>
      <form method="post" action="<?=u('/planning/reserver/'.$slotId)?>">
        <?= Auth::csrfField() ?>
        <?php foreach ($slotCriteria as $cr):
          $savedVal  = $memberCritValues[$cr['id']]  ?? null;
          $savedVal2 = $memberCritValues2[$cr['id']] ?? '';
          $isLocked  = ($savedVal !== null && $savedVal !== '');
        ?>
        <div class="form-group" style="margin-top:.875rem">
          <label style="font-weight:700;display:block;margin-bottom:.35rem">
            <?=Helpers::e($cr['name'])?><?=($cr['is_required_here']??$cr['required'])?' *':''?>
          </label>
          <?php if($isLocked): ?>
          <?=CriteriaRenderer::field($cr, $savedVal, $savedVal2, true)?>
          <?php else: ?>
          <?=CriteriaRenderer::field($cr, '', '', false)?>
          <?php endif; ?>
        </div>
        <?php endforeach;?>
        <?php foreach ($customFields as $f): ?>
          <div class="form-group"><label><?= Helpers::e($f['label']) ?></label><input type="text" name="<?= Helpers::e($f['name']) ?>" <?= !empty($f['required'])?'required':'' ?>></div>
        <?php endforeach; ?>
        <button type="submit" class="btn btn-primary" style="width:100%;margin-top:.75rem">✅ Confirmer l'inscription<?=$waitlistMsg2?></button>
      </form>
    <?php endif; ?>
    <?php endif; // require_booking ?>
  </div>
</div>
<?php endif; ?>

<style>
/* ── HEADER ── */
.planning-header{background:linear-gradient(135deg,var(--color-primary),color-mix(in srgb,var(--color-primary) 65%,#000));padding:1.25rem 0;color:#fff}
.planning-top{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem}
.planning-left{display:flex;align-items:center;gap:1rem}
.planning-title{font-family:var(--font-heading);font-size:1.6rem;letter-spacing:.08em}
.planning-period-label{font-size:.95rem;opacity:.8;font-weight:500}
.planning-controls{display:flex;align-items:center;gap:.5rem}
.pnav-btn{background:rgba(255,255,255,.2);color:#fff;width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;text-decoration:none;font-size:1rem;transition:background .2s}
.pnav-btn:hover{background:rgba(255,255,255,.35)}
.pnav-today{background:rgba(255,255,255,.15);color:#fff;padding:.25rem .75rem;border-radius:99px;font-size:.8rem;font-weight:600;text-decoration:none;transition:background .2s}
.pnav-today:hover{background:rgba(255,255,255,.3)}
.view-sw{display:flex;background:rgba(0,0,0,.2);border-radius:6px;overflow:hidden;margin-left:.25rem}
.vsw-btn{padding:.25rem .65rem;font-size:.78rem;color:rgba(255,255,255,.75);text-decoration:none;transition:all .2s}
.vsw-btn:hover,.vsw-btn.active{background:rgba(255,255,255,.25);color:#fff}

/* ── BODY ── */
.planning-body{padding:1.25rem 1.5rem 3rem}
.plan-legend{display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:1rem}
.pl-item{font-size:.72rem;font-weight:600;padding:.2rem .6rem;border-radius:4px;color:#fff}
.pl-item.open{background:#22c55e}
.pl-item.training{background:var(--color-primary)}
.pl-item.event{background:var(--color-secondary)}
.pl-item.maintenance{background:#6b7280}

/* ── VUE LISTE ── */
.plan-list{display:flex;flex-direction:column;gap:2px}
.plan-empty{text-align:center;padding:3rem;color:var(--color-muted);font-size:1rem}
.plist-day-head{font-family:var(--font-heading);font-size:.85rem;letter-spacing:.08em;text-transform:uppercase;color:var(--color-muted);padding:.5rem 0;margin-top:1rem;border-bottom:2px solid var(--color-border)}
.plist-slot{display:flex;align-items:center;gap:.75rem;background:#fff;border:1px solid var(--color-border);border-radius:6px;padding:.6rem .875rem;position:relative;overflow:hidden;margin-top:3px}
.pls-bar{position:absolute;left:0;top:0;bottom:0;width:3px}
.plist-slot.open .pls-bar{background:#22c55e}
.plist-slot.training .pls-bar{background:var(--color-primary)}
.plist-slot.event .pls-bar{background:var(--color-secondary)}
.plist-slot.maintenance .pls-bar{background:#6b7280}
.pls-time{font-size:.78rem;font-weight:700;color:var(--color-muted);min-width:85px;flex-shrink:0}
.pls-info{flex:1;min-width:0}
.pls-title{font-weight:600;font-size:.875rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.pls-meta{font-size:.72rem;color:var(--color-muted)}
.pls-right{display:flex;align-items:center;gap:.5rem;flex-shrink:0}
.pls-spots{font-size:.72rem;font-weight:700;color:var(--color-success)}
.pls-spots.full{color:var(--color-error)}
.pls-book{background:var(--color-primary);color:#fff;padding:.25rem .7rem;border-radius:4px;font-size:.78rem;font-weight:600;text-decoration:none}
.pls-book:hover{opacity:.9}

/* ── VUE MOIS ── */
.cal-month{background:#fff;border:1px solid var(--color-border);border-radius:var(--radius-md);overflow:hidden}
.cal-head{display:grid;grid-template-columns:repeat(7,1fr);background:var(--color-surface);border-bottom:1px solid var(--color-border)}
.cal-hd{text-align:center;padding:.4rem;font-size:.72rem;font-weight:700;text-transform:uppercase;color:var(--color-muted)}
.cal-grid{display:grid;grid-template-columns:repeat(7,1fr)}
.cal-cell{border-right:1px solid var(--color-border);border-bottom:1px solid var(--color-border);min-height:80px;padding:.3rem .35rem}
.cal-cell.empty{background:var(--color-surface)}
.cal-num{font-size:.78rem;font-weight:600;width:22px;height:22px;display:flex;align-items:center;justify-content:center;border-radius:50%;margin-bottom:.15rem}
.cal-num.today-num{background:var(--color-primary);color:#fff}
.cal-events{display:flex;flex-direction:column;gap:1px}
.cal-ev{display:block;font-size:.62rem;padding:.1rem .25rem;border-radius:2px;color:#fff;text-decoration:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:500;line-height:1.4}
.cal-ev:hover{opacity:.85}
.cal-ev.open{background:#22c55e}
.cal-ev.training{background:var(--color-primary)}
.cal-ev.event{background:var(--color-secondary)}
.cal-ev.maintenance{background:#6b7280}
.cal-more{font-size:.6rem;color:var(--color-muted);padding:.1rem .15rem}

/* ── VUE SEMAINE ── */
.cal-week-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
.cal-week{min-width:600px;background:#fff;border:1px solid var(--color-border);border-radius:var(--radius-md);overflow:hidden}
.cw-head{display:grid;grid-template-columns:40px repeat(7,1fr);border-bottom:1px solid var(--color-border);background:var(--color-surface);position:sticky;top:0;z-index:5}
.cw-gutter{border-right:1px solid var(--color-border)}
.cw-day-head{padding:.4rem .2rem;text-align:center;border-right:1px solid var(--color-border)}
.cw-day-head.today{background:color-mix(in srgb,var(--color-primary) 8%,transparent)}
.cwh-name{display:block;font-size:.65rem;text-transform:uppercase;color:var(--color-muted);font-weight:700}
.cwh-num{display:block;font-size:.9rem;font-weight:700}
.cw-body{max-height:480px;overflow-y:auto}
.cw-row{display:grid;grid-template-columns:40px repeat(7,1fr);border-bottom:1px solid var(--color-border);min-height:32px}
.cw-time{font-size:.65rem;color:var(--color-muted);padding:.2rem .3rem;text-align:right;border-right:1px solid var(--color-border);line-height:1;padding-top:.4rem}
.cw-cell{border-right:1px solid var(--color-border);position:relative;min-height:32px}
.cw-ev{display:flex;flex-direction:column;border-radius:3px;padding:.15rem .3rem;color:#fff;position:absolute;left:1px;right:1px;overflow:hidden;font-size:.65rem;text-decoration:none;min-height:20px;z-index:2}
.cw-ev.open{background:#22c55e}
.cw-ev.training{background:var(--color-primary)}
.cw-ev.event{background:var(--color-secondary)}
.cw-ev.maintenance{background:#6b7280}
.cw-ev:hover{opacity:.9;z-index:3}
.cwe-time{font-weight:700;font-size:.6rem;line-height:1}
.cwe-title{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;line-height:1.3}

/* ── MODALE ── */
.plan-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:500;display:flex;align-items:center;justify-content:center;padding:1rem}
.plan-modal{background:#fff;border-radius:var(--radius-lg);padding:2rem;max-width:460px;width:100%;position:relative;max-height:90vh;overflow-y:auto}
.plan-modal-close{position:absolute;top:.875rem;right:.875rem;color:var(--color-muted);text-decoration:none;font-size:1rem;line-height:1;padding:.25rem}
.plan-modal-close:hover{color:var(--color-text)}
.plan-modal-title{font-family:var(--font-heading);font-size:1.4rem;letter-spacing:.05em;margin-bottom:.75rem}
.plan-modal-meta{display:flex;flex-direction:column;gap:.25rem;font-size:.82rem;color:var(--color-muted);background:var(--color-surface);border-radius:var(--radius-sm);padding:.75rem 1rem;margin-bottom:1.25rem}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:.4rem;padding:.65rem 1.5rem;border-radius:var(--radius-sm);font-weight:600;font-size:.9rem;cursor:pointer;border:none;font-family:var(--font-body);transition:all .2s;text-decoration:none}
.btn-primary{background:var(--color-primary);color:#fff}
.btn-primary:hover{opacity:.9}
@media(max-width:600px){.planning-top{flex-direction:column;align-items:flex-start}.cal-cell{min-height:56px}.cw-body{max-height:360px}}
.pls-book{background:var(--color-primary);color:#fff;border:none;padding:.35rem .875rem;border-radius:6px;font-size:.8rem;font-weight:600;cursor:pointer;text-decoration:none;white-space:nowrap;font-family:inherit}
.pls-info-btn{background:#64748b}
.pls-info-btn:hover{background:#475569}
/* Modal info créneau */
.slot-modal-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.5);backdrop-filter:blur(3px);z-index:9999;align-items:center;justify-content:center;padding:1rem}
.slot-modal-overlay.open{display:flex}
.slot-modal{background:#fff;border-radius:16px;width:100%;max-width:480px;box-shadow:0 24px 64px rgba(0,0,0,.2);overflow:hidden;animation:modalIn .18s ease}
.slot-modal-head{background:var(--color-primary);color:#fff;padding:1.25rem 1.5rem;display:flex;justify-content:space-between;align-items:flex-start}
.slot-modal-title{font-family:var(--font-heading);font-size:1.2rem;letter-spacing:.04em}
.slot-modal-close{background:rgba(255,255,255,.2);border:none;color:#fff;border-radius:6px;width:28px;height:28px;cursor:pointer;font-size:1rem;display:flex;align-items:center;justify-content:center}
.slot-modal-body{padding:1.5rem}
.slot-modal-row{display:flex;align-items:center;gap:.75rem;padding:.6rem 0;border-bottom:1px solid #f1f5f9;font-size:.9rem}
.slot-modal-row:last-child{border-bottom:none}
.slot-modal-icon{font-size:1.1rem;width:24px;text-align:center;flex-shrink:0}
.slot-modal-desc{margin-top:1rem;padding:1rem;background:#f8fafc;border-radius:8px;font-size:.875rem;line-height:1.7;color:#475569}
/* Export buttons */
.plan-export-btns{display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:.75rem}
.plan-export-btn{display:inline-flex;align-items:center;gap:.4rem;padding:.5rem 1rem;border-radius:8px;font-size:.82rem;font-weight:700;text-decoration:none;background:#fff;color:var(--color-primary);border:2px solid rgba(255,255,255,.8);transition:all .15s;box-shadow:0 2px 8px rgba(0,0,0,.15)}
.plan-export-btn:hover{background:var(--color-primary);color:#fff;border-color:var(--color-primary)}
</style>

<!-- Modal info créneau (sans inscription requise) -->
<div class="slot-modal-overlay" id="slot-info-modal" onclick="if(event.target===this)closeSlotModal()">
  <div class="slot-modal">
    <div class="slot-modal-head">
      <div class="slot-modal-title" id="smi-title"></div>
      <button type="button" class="slot-modal-close" onclick="closeSlotModal()">×</button>
    </div>
    <div class="slot-modal-body">
      <div class="slot-modal-row" id="smi-time-row">
        <span class="slot-modal-icon">🕐</span>
        <span id="smi-time"></span>
      </div>
      <div class="slot-modal-row" id="smi-coach-row">
        <span class="slot-modal-icon">👤</span>
        <span id="smi-coach"></span>
      </div>
      <div id="smi-desc" class="slot-modal-desc" style="display:none"></div>
    </div>
  </div>
</div>
<script>
function showSlotInfo(btn) {
  showSlotPopup({
    title: btn.dataset.title,
    time:  btn.dataset.time,
    coach: btn.dataset.coach,
    desc:  btn.dataset.desc
  });
}
function showSlotPopup(data) {
  document.getElementById('smi-title').textContent = data.title || '';
  var timeEl = document.getElementById('smi-time');
  var timeRow = document.getElementById('smi-time-row');
  timeEl.textContent = data.time || '';
  timeRow.style.display = data.time ? '' : 'none';
  var coachEl = document.getElementById('smi-coach');
  var coachRow = document.getElementById('smi-coach-row');
  coachEl.textContent = data.coach || '';
  coachRow.style.display = data.coach ? '' : 'none';
  var descEl = document.getElementById('smi-desc');
  if (data.desc) {
    descEl.textContent = data.desc;
    descEl.style.display = '';
  } else {
    descEl.style.display = 'none';
  }
  document.getElementById('slot-info-modal').classList.add('open');
}
function closeSlotModal() {
  document.getElementById('slot-info-modal').classList.remove('open');
}
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') closeSlotModal();
});
</script>

<?php
$content = ob_get_clean();
include CC_ROOT . '/templates/layout.php';
