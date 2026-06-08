<?php
Auth::require('coach');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Auth::verifyCsrf()) { adminFlash('error','CSRF'); Helpers::redirect(u('/admin/planning')); }

// ── Création tables si inexistantes ────────────────────────
try { Database::run("CREATE TABLE IF NOT EXISTS cc_planning_criteria (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    field_type  VARCHAR(20)  NOT NULL DEFAULT 'text',
    options     TEXT         DEFAULT NULL,
    use_color   TINYINT(1)   NOT NULL DEFAULT 0,
    color       VARCHAR(7)   NOT NULL DEFAULT '#6366f1',
    range_min   INT          DEFAULT NULL,
    range_max   INT          DEFAULT NULL,
    range_unit  VARCHAR(30)  NOT NULL DEFAULT '',
    required    TINYINT(1)   NOT NULL DEFAULT 1,
    allow_other TINYINT(1)   NOT NULL DEFAULT 0,
    sort_order  INT          NOT NULL DEFAULT 0,
    active      TINYINT(1)   NOT NULL DEFAULT 1
)"); } catch(Exception $e) {}
// ALTER pour tables existantes (si colonnes manquantes)
try { Database::run("ALTER TABLE cc_planning_criteria ADD COLUMN field_type VARCHAR(20) NOT NULL DEFAULT 'text' AFTER name"); } catch(Exception $e) {}
try { Database::run("ALTER TABLE cc_planning_criteria ADD COLUMN use_color TINYINT(1) NOT NULL DEFAULT 0"); } catch(Exception $e) {}
try { Database::run("ALTER TABLE cc_planning_criteria ADD COLUMN color VARCHAR(7) NOT NULL DEFAULT '#6366f1'"); } catch(Exception $e) {}
try { Database::run("ALTER TABLE cc_planning_criteria ADD COLUMN range_min INT DEFAULT NULL"); } catch(Exception $e) {}
try { Database::run("ALTER TABLE cc_planning_criteria ADD COLUMN range_max INT DEFAULT NULL"); } catch(Exception $e) {}
try { Database::run("ALTER TABLE cc_planning_criteria ADD COLUMN range_unit VARCHAR(30) NOT NULL DEFAULT ''"); } catch(Exception $e) {}

try { Database::run("CREATE TABLE IF NOT EXISTS cc_planning_criteria_values (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    criteria_id INT NOT NULL,
    value       VARCHAR(255) NOT NULL DEFAULT '',
    value2      VARCHAR(255) NOT NULL DEFAULT '',
    UNIQUE KEY uq_user_crit (user_id, criteria_id)
)"); } catch(Exception $e) {}
try { Database::run("ALTER TABLE cc_planning_criteria_values ADD COLUMN value2 VARCHAR(255) NOT NULL DEFAULT ''"); } catch(Exception $e) {}

try { Database::run("ALTER TABLE cc_planning_slots ADD COLUMN criteria_ids TEXT DEFAULT NULL"); } catch(Exception $e) {}
try { Database::run("ALTER TABLE cc_planning_slots ADD COLUMN criteria_required TEXT DEFAULT NULL"); } catch(Exception $e) {}
try { Database::run("ALTER TABLE cc_planning_bookings ADD COLUMN criteria_data TEXT DEFAULT NULL"); } catch(Exception $e) {}
try { Database::run("ALTER TABLE cc_planning_bookings ADD COLUMN is_waitlist TINYINT(1) NOT NULL DEFAULT 0 AFTER status"); } catch(Exception $e) {}

// ── Handler : sauvegarder un critère ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_criteria'])) {
    $cid       = (int)($_POST['criteria_id'] ?? 0);
    $name      = Helpers::sanitize($_POST['criteria_name'] ?? '');
    $ftype     = in_array($_POST['field_type']??'',['text','number','range','select','radio']) ? $_POST['field_type'] : 'text';
    $required  = isset($_POST['criteria_required'])    ? 1 : 0;
    $allowOth  = isset($_POST['criteria_allow_other']) ? 1 : 0;
    $useColor  = isset($_POST['use_color'])            ? 1 : 0;
    $color     = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['color']??'') ? $_POST['color'] : '#6366f1';
    $order     = (int)($_POST['criteria_order'] ?? 0);
    $rangeMin  = $_POST['range_min'] !== '' ? (int)$_POST['range_min'] : null;
    $rangeMax  = $_POST['range_max'] !== '' ? (int)$_POST['range_max'] : null;
    $rangeUnit = Helpers::sanitize($_POST['range_unit'] ?? '');
    // Options pour select/radio
    $options = [];
    foreach ($_POST['opt_label'] ?? [] as $i => $lbl) {
        $lbl = trim($lbl);
        if ($lbl) $options[] = ['label'=>$lbl, 'color'=>$_POST['opt_color'][$i]??'#6366f1'];
    }
    if ($name) {
        $data = [$name,$ftype,json_encode($options,JSON_UNESCAPED_UNICODE),$useColor,$color,$rangeMin,$rangeMax,$rangeUnit,$required,$allowOth,$order];
        if ($cid) {
            Database::run("UPDATE cc_planning_criteria SET name=?,field_type=?,options=?,use_color=?,color=?,range_min=?,range_max=?,range_unit=?,required=?,allow_other=?,sort_order=? WHERE id=?",
                [...$data, $cid]);
        } else {
            Database::run("INSERT INTO cc_planning_criteria (name,field_type,options,use_color,color,range_min,range_max,range_unit,required,allow_other,sort_order,active) VALUES (?,?,?,?,?,?,?,?,?,?,?,1)",
                $data);
        }
        adminFlash('success', 'Critère sauvegardé.');
    }
    Helpers::redirect(u('/admin/planning?tab=criteria'));
}

// ── Handler : supprimer un critère ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_criteria'])) {
    $cid = (int)($_POST['criteria_id'] ?? 0);
    if ($cid) {
        Database::run("DELETE FROM cc_planning_criteria WHERE id=?", [$cid]);
        Database::run("DELETE FROM cc_planning_criteria_values WHERE criteria_id=?", [$cid]);
        adminFlash('success', 'Critère supprimé.');
    }
    Helpers::redirect(u('/admin/planning?tab=criteria'));
}

// ── Handler : modifier statut inscription ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_booking_status'])) {
    $bid    = (int)($_POST['booking_id'] ?? 0);
    $status = in_array($_POST['status']??'', ['confirmed','cancelled','pending']) ? $_POST['status'] : 'confirmed';
    $sid    = (int)($_POST['slot_id'] ?? 0);
    if ($bid) {
        $oldBooking = Database::one("SELECT b.*, u.email AS user_email, u.firstname, s.title, s.date_start
            FROM cc_planning_bookings b
            LEFT JOIN cc_users u ON b.user_id = u.id
            LEFT JOIN cc_planning_slots s ON b.slot_id = s.id
            WHERE b.id=?", [$bid]);
        Database::run("UPDATE cc_planning_bookings SET status=? WHERE id=?", [$status, $bid]);

        // Envoyer email si statut change vers confirmed ou cancelled
        $emailTo   = $oldBooking['user_email'] ?? $oldBooking['guest_email'] ?? '';
        $emailName = $oldBooking['firstname']   ?? $oldBooking['guest_name']  ?? 'Membre';
        $club      = Config::get('club_name', 'Mon Club');
        $slotTitle = htmlspecialchars($oldBooking['title'] ?? '');
        $slotDate  = Helpers::dateTimeFormat($oldBooking['date_start'] ?? '');

        if ($emailTo && $status === 'confirmed') {
            $body = "<h2>✅ Inscription confirmée</h2>
                <p>Bonjour <strong>{$emailName}</strong>,</p>
                <p>Votre inscription pour <strong>{$slotTitle}</strong> ({$slotDate}) a été <strong>confirmée</strong> par un administrateur.</p>";
            Mailer::send($emailTo, $emailName, "Inscription confirmée — {$slotTitle}", $body);

            // Promouvoir automatiquement depuis la liste d'attente si besoin
        } elseif ($emailTo && $status === 'cancelled') {
            $body = "<h2>❌ Inscription annulée</h2>
                <p>Bonjour <strong>{$emailName}</strong>,</p>
                <p>Votre inscription pour <strong>{$slotTitle}</strong> ({$slotDate}) a été <strong>annulée</strong>.</p>
                <p>Si vous pensez qu'il s'agit d'une erreur, contactez le club.</p>";
            Mailer::send($emailTo, $emailName, "Inscription annulée — {$slotTitle}", $body);

            // Si une place se libère, promouvoir le premier en liste d'attente
            if ($oldBooking && $status === 'cancelled') {
                $slot = Database::one("SELECT * FROM cc_planning_slots WHERE id=?", [$sid]);
                if ($slot && $slot['max_participants']) {
                    $taken = (int)Database::scalar(
                        "SELECT COUNT(*) FROM cc_planning_bookings WHERE slot_id=? AND status='confirmed'", [$sid]);
                    if ($taken < $slot['max_participants']) {
                        $next = Database::one(
                            "SELECT b.*, u.email AS ue, u.firstname AS fn
                             FROM cc_planning_bookings b LEFT JOIN cc_users u ON b.user_id=u.id
                             WHERE b.slot_id=? AND b.is_waitlist=1 AND b.status='pending'
                             ORDER BY b.created_at ASC LIMIT 1", [$sid]);
                        if ($next) {
                            Database::run("UPDATE cc_planning_bookings SET status='confirmed', is_waitlist=0 WHERE id=?", [$next['id']]);
                            $ne = $next['ue'] ?? $next['guest_email'] ?? '';
                            $nn = $next['fn'] ?? $next['guest_name'] ?? 'Membre';
                            if ($ne) {
                                $prBody = "<h2>✅ Une place s'est libérée !</h2>
                                    <p>Bonjour <strong>{$nn}</strong>,</p>
                                    <p>Bonne nouvelle ! Une place s'est libérée pour <strong>{$slotTitle}</strong> ({$slotDate}).</p>
                                    <p>Votre inscription est maintenant <strong>confirmée</strong>.</p>";
                                Mailer::send($ne, $nn, "Place libérée — {$slotTitle}", $prBody);
                            }
                        }
                    }
                }
            }
        }
        adminFlash('success', 'Statut mis à jour' . ($emailTo ? ' et email envoyé.' : '.'));
    }
    Helpers::redirect(u('/admin/planning?tab=inscriptions&slot='.$sid));
}

// ── Handler : supprimer inscription ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_booking'])) {
    $bid = (int)($_POST['booking_id'] ?? 0);
    $sid = (int)($_POST['slot_id'] ?? 0);
    if ($bid) Database::run("DELETE FROM cc_planning_bookings WHERE id=?", [$bid]);
    adminFlash('success', 'Inscription supprimée.');
    Helpers::redirect(u('/admin/planning?tab=inscriptions&slot='.$sid));
}

// ── Handler : paramètres de réservation ────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_booking_settings'])) {
    $mode       = in_array($_POST['booking_mode']??'', ['auto','manual']) ? $_POST['booking_mode'] : 'auto';
    $waitlist   = isset($_POST['enable_waitlist']) ? 1 : 0;
    $maxWait    = max(0, (int)($_POST['max_waitlist'] ?? 0));
    Config::set('booking_mode',         $mode,    'planning');
    Config::set('booking_waitlist',     $waitlist,'planning');
    Config::set('booking_max_waitlist', $maxWait, 'planning');
    adminFlash('success', 'Paramètres de réservation sauvegardés.');
    Helpers::redirect(u('/admin/planning?tab=booking_settings'));
}

// ── Handler : sauvegarde paramètres export ─────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_export_settings'])) {
    Config::set('planning_export_access', in_array($_POST['export_access']??'',['all','members','admin']) ? $_POST['export_access'] : 'members', 'planning');
    adminFlash('success', "Paramètres d'export sauvegardés.");
    Helpers::redirect(u('/admin/planning?tab=export'));
}

// ── Handler : créer / modifier un type ─────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_slot_type'])) {
    // S'assurer que la table existe
    try {
        Database::run("CREATE TABLE IF NOT EXISTS cc_planning_types (
            id INT AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(50) NOT NULL UNIQUE,
            label VARCHAR(100) NOT NULL,
            color VARCHAR(7) NOT NULL DEFAULT '#6366f1',
            is_system TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0
        )");
    } catch (Exception $e) {}

    $tid   = (int)($_POST['type_id'] ?? 0);
    $label = Helpers::sanitize($_POST['type_label'] ?? '');
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['type_color']??'') ? $_POST['type_color'] : '#6366f1';
    if ($label) {
        try {
            if ($tid) {
                $sys = (int)Database::scalar("SELECT is_system FROM cc_planning_types WHERE id=?", [$tid]);
                if ($sys) {
                    Database::run("UPDATE cc_planning_types SET color=? WHERE id=?", [$color, $tid]);
                } else {
                    Database::run("UPDATE cc_planning_types SET label=?, color=? WHERE id=?", [$label, $color, $tid]);
                }
            } else {
                $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $label));
                Database::run(
                    "INSERT INTO cc_planning_types (slug, label, color, is_system, sort_order)
                     VALUES (?,?,?,0,99)
                     ON DUPLICATE KEY UPDATE label=VALUES(label), color=VALUES(color)",
                    [$slug, $label, $color]
                );
            }
            adminFlash('success', 'Type sauvegardé.');
        } catch (Exception $e) {
            adminFlash('error', 'Erreur : ' . $e->getMessage());
        }
    }
    Helpers::redirect(u('/admin/planning?tab=types'));
}

// ── Handler : supprimer un type personnalisé ───────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_slot_type'])) {
    $tid = (int)($_POST['type_id'] ?? 0);
    if ($tid) {
        $t = Database::one("SELECT is_system, slug FROM cc_planning_types WHERE id=?", [$tid]);
        if ($t && !$t['is_system']) {
            Database::run("UPDATE cc_planning_slots SET type='open' WHERE type=?", [$t['slug']]);
            Database::run("DELETE FROM cc_planning_types WHERE id=? AND is_system=0", [$tid]);
            adminFlash('success', 'Type supprimé.');
        }
    }
    Helpers::redirect(u('/admin/planning?tab=types'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_slot'])) {
    $id = (int)($_POST['slot_id'] ?? 0);
    $data = [
        'title'           => Helpers::sanitize($_POST['title']??''),
        'type'            => (function($t) {
            $valid = Database::all("SELECT slug FROM cc_planning_types");
            $slugs = array_column($valid,'slug');
            return in_array($t, $slugs) ? $t : 'open';
        })($_POST['type']??'open'),
        'coach_id'        => (int)($_POST['coach_id']??0)?:null,
        'description'     => Helpers::sanitize($_POST['description']??''),
        'date_start'      => $_POST['date_start']??'',
        'date_end'        => $_POST['date_end']??'',
        'max_participants'=> (int)($_POST['max_participants']??0)?:null,
        'require_booking' => (int)($_POST['require_booking']??0),
        'booking_mode'    => in_array($_POST['booking_mode']??'auto',['auto','manual'])?$_POST['booking_mode']:'auto',
        'booking_form'    => in_array($_POST['booking_form']??'internal',['internal','external'])?$_POST['booking_form']:'internal',
        'criteria_ids'      => json_encode(array_map('intval',$_POST['criteria_ids']??[]),JSON_UNESCAPED_UNICODE),
        'criteria_required' => json_encode(array_map('intval',$_POST['criteria_required']??[]),JSON_UNESCAPED_UNICODE),
        'external_url'    => Helpers::sanitize($_POST['external_url']??''),
        'color'           => preg_match('/^#[0-9a-fA-F]{6}$/',$_POST['color']??'') ? $_POST['color'] : '#3b82f6',
        'recurrence'      => in_array($_POST['recurrence']??'none',['none','daily','weekly','monthly'])?$_POST['recurrence']:'none',
        'recurrence_end'  => $_POST['recurrence_end']??null,
        'published'       => 1,
        'members_only'    => isset($_POST['members_only'])    ? 1 : 0,
        'members_message' => isset($_POST['members_message']) ? 1 : 0,
        'members_msg_text'=> Helpers::sanitize($_POST['members_msg_text'] ?? 'Il faut être membre pour participer.'),
    ];
    if ($id) {
        $sets = implode(',',array_map(fn($k)=>"`$k`=?",array_keys($data)));
        Database::run("UPDATE cc_planning_slots SET $sets WHERE id=?",[...array_values($data),$id]);
        adminFlash('success','Créneau modifié.');
    } else {
        $cols = implode(',',array_map(fn($k)=>"`$k`",array_keys($data)));
        $vals = implode(',',array_fill(0,count($data),'?'));
        Database::insert("INSERT INTO cc_planning_slots ($cols,created_at) VALUES ($vals,NOW())",array_values($data));
        adminFlash('success','Créneau créé.');
    }
    Helpers::redirect(u('/admin/planning'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_slot'])) {
    $id = (int)($_POST['slot_id']??0);
    Database::run("DELETE FROM cc_planning_bookings WHERE slot_id=?",[$id]);
    Database::run("DELETE FROM cc_planning_slots WHERE id=?",[$id]);
    adminFlash('success','Créneau supprimé.'); Helpers::redirect(u('/admin/planning'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_booking'])) {
    $bid = (int)($_POST['booking_id']??0);
    $st  = in_array($_POST['booking_status']??'',['confirmed','cancelled','waitlist'])?$_POST['booking_status']:null;
    if ($bid && $st) {
        Database::run("UPDATE cc_planning_bookings SET status=? WHERE id=?",[$st,$bid]);
        $booking = Database::one("SELECT b.*, u.email, u.firstname FROM cc_planning_bookings b LEFT JOIN cc_users u ON b.user_id=u.id WHERE b.id=?",[$bid]);
        $slot    = Database::one("SELECT title, date_start FROM cc_planning_slots WHERE id=?",[$booking['slot_id']??0]);
        if ($booking && $slot) {
            $email = $booking['email'] ?? $booking['guest_email'] ?? null;
            $name  = $booking['firstname'] ?? $booking['guest_name'] ?? '';
            if ($email) {
                $tplKey  = $st === 'confirmed' ? 'booking_ok' : 'booking_wait';
                $defBody = $st === 'confirmed'
                    ? "<p>Bonjour {firstname}, votre réservation pour {slot_title} le {slot_date} est confirmée.</p>"
                    : "<p>Bonjour {firstname}, vous êtes en liste d'attente pour {slot_title}.</p>";
                $body  = str_replace(['{firstname}','{slot_title}','{slot_date}','{club_name}'],
                    [$name, $slot['title'], Helpers::dateTimeFormat($slot['date_start']), Config::get('club_name')],
                    Config::get('mail_tpl_body_'.$tplKey, $defBody));
                $subj  = str_replace(['{club_name}'],[Config::get('club_name')],
                    Config::get('mail_tpl_subject_'.$tplKey, 'Réservation — '.Config::get('club_name')));
                Mailer::send($email, $name, $subj, $body);
            }
        }
        adminFlash('success','Réservation mise à jour.');
    }
    $slotId = $booking['slot_id'] ?? 0;
    Helpers::redirect('/admin/planning?view_slot='.$slotId);
}

$editId   = (int)($_GET['edit']??0);
$viewSlot = (int)($_GET['view_slot']??0);
$editSlot = $editId ? Database::one("SELECT * FROM cc_planning_slots WHERE id=?",[$editId]) : null;
$coaches  = Database::all("SELECT id,firstname,lastname FROM cc_users WHERE role IN ('coach','admin','superadmin') AND status='active'");

$tab       = $_GET['tab'] ?? '';
$allCriteria = [];
try { $allCriteria = Database::all("SELECT * FROM cc_planning_criteria ORDER BY sort_order,name"); } catch(Exception $e) {}

// Créer la table si elle n'existe pas encore
try { Database::run("CREATE TABLE IF NOT EXISTS cc_planning_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(50) NOT NULL UNIQUE,
    label VARCHAR(100) NOT NULL,
    color VARCHAR(7) NOT NULL DEFAULT '#6366f1',
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0
)"); } catch (Exception $e) {}

// Insérer les types par défaut si la table est vide
try {
    $nb = (int)Database::scalar("SELECT COUNT(*) FROM cc_planning_types");
    if ($nb === 0) {
        $defs = [
            ['open','Libre','#22c55e',1,1],
            ['training','Entraînement','#3b82f6',1,2],
            ['event','Événement','#f59e0b',1,3],
            ['maintenance','Fermé','#6b7280',1,4],
            ['competition','Compétition','#ef4444',1,5],
        ];
        foreach ($defs as $d) {
            try { Database::run("INSERT INTO cc_planning_types (slug,label,color,is_system,sort_order) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE color=VALUES(color)", $d); } catch(Exception $e) {}
        }
    }
} catch (Exception $e) {}

$slotTypes = [];
try {
    $slotTypes = Database::all("SELECT * FROM cc_planning_types ORDER BY sort_order, label");
} catch (Exception $e) { $slotTypes = []; }
$typeMap   = array_column($slotTypes, null, 'slug');

$pageTitle = 'Planning — Administration';

// ── Export PDF inscriptions (avant ob_start pour éviter corruption) ──
if (($tab??'') === 'inscriptions' && isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $expSlot = (int)($_GET['slot'] ?? 0);
    if ($expSlot) {
        $expSlotDetail = Database::one("SELECT * FROM cc_planning_slots WHERE id=?", [$expSlot]);
        $expBookings   = Database::all(
            "SELECT b.*, u.firstname, u.lastname, u.email AS user_email
             FROM cc_planning_bookings b LEFT JOIN cc_users u ON b.user_id=u.id
             WHERE b.slot_id=? ORDER BY b.created_at ASC", [$expSlot]);
        $expTaken = count(array_filter($expBookings, fn($b)=>$b['status']==='confirmed'));
        $expMax   = (int)($expSlotDetail['max_participants'] ?? 0);

        require_once CC_ROOT . '/pdf/fpdf/fpdf.php';
        $clubName = Config::get('club_name', 'Club');
        $dtS = new DateTime($expSlotDetail['date_start']);

        if (!class_exists('InscriptionPDF')) {
            class InscriptionPDF extends FPDF {
                public string $title = '';
                public string $subtitle = '';
                function Header(): void {
                    $this->SetFillColor(29,78,216); $this->Rect(0,0,210,20,'F');
                    $this->SetTextColor(255,255,255);
                    $this->SetFont('Arial','B',13); $this->SetXY(8,5);
                    $this->Cell(150,10,iconv('UTF-8','CP1252',$this->title),0,0,'L');
                    $this->SetFont('Arial','',8); $this->SetXY(8,14);
                    $this->Cell(150,5,iconv('UTF-8','CP1252',$this->subtitle),0,1,'L');
                    $this->SetTextColor(0,0,0); $this->Ln(4);
                }
                function Footer(): void {
                    $this->SetY(-12); $this->SetFont('Arial','I',7);
                    $this->SetTextColor(148,163,184);
                    $this->Cell(0,8,iconv('UTF-8','CP1252','Page '.$this->PageNo().' — Genere le '.date('d/m/Y H:i')),0,0,'C');
                }
            }
        }

        $pdf = new InscriptionPDF('P','mm','A4');
        $pdf->title    = $clubName.' - Inscriptions';
        $pdf->subtitle = mb_substr($expSlotDetail['title'],0,40)
            .' - '.$dtS->format('d/m/Y H:i')
            .' - '.$expTaken.' inscrit'.($expTaken>1?'s':'')
            .($expMax?' / '.$expMax.' places':'');
        $pdf->SetAutoPageBreak(true,16);
        $pdf->SetMargins(10,28,10);
        $pdf->AddPage();

        // Charger les critères du créneau pour le PDF
        $pdfCritIds = json_decode($expSlotDetail['criteria_ids']??'[]',true)??[];
        $pdfCriteria = [];
        if (!empty($pdfCritIds)) {
            try {
                $pdfCriteria = Database::all("SELECT * FROM cc_planning_criteria WHERE id IN (".implode(',',array_map('intval',$pdfCritIds)).")");
            } catch(Exception $e) {}
        }
        $hasCrit = !empty($pdfCriteria);

        // Largeurs colonnes adaptées
        $nameW  = 45; $emailW = 50; $dateW = 22; $statW = 20;
        $critW  = $hasCrit ? min(40, (int)(185 - 8 - $nameW - $emailW - $dateW - $statW)) : 0;
        $cols2  = array_filter([8, $nameW, $emailW, $hasCrit?$critW:0, $dateW, $statW]);

        $pdf->SetFont('Arial','B',8);
        $pdf->SetFillColor(241,245,249); $pdf->SetTextColor(71,85,105);
        $heads = ['#','Nom','Email'];
        if ($hasCrit) $heads[] = 'Criteres';
        $heads[] = 'Date'; $heads[] = 'Statut';
        foreach(array_values($heads) as $ci=>$ch)
            $pdf->Cell(array_values($cols2)[$ci],7,iconv('UTF-8','CP1252',$ch),1,0,'C',true);
        $pdf->Ln();

        $pdf->SetFont('Arial','',8); $even=false;
        foreach($expBookings as $ii=>$b2) {
            $nm   = $b2['firstname'] ? $b2['firstname'].' '.$b2['lastname'] : ($b2['guest_name']??'Visiteur');
            $em   = $b2['user_email'] ?? $b2['guest_email'] ?? '-';
            $st   = ['confirmed'=>'Confirme','cancelled'=>'Annule','pending'=>'Attente'][$b2['status']] ?? $b2['status'];
            $bg2  = $even ? [248,250,252] : [255,255,255];
            $pdf->SetFillColor($bg2[0],$bg2[1],$bg2[2]);
            $pdf->SetTextColor(30,41,59);
            $pdf->Cell(8,6,(string)($ii+1),0,0,'C',true);
            $pdf->Cell($nameW,6,iconv('UTF-8','CP1252',mb_substr($nm,0,22)),0,0,'L',true);
            $pdf->Cell($emailW,6,iconv('UTF-8','CP1252',mb_substr($em,0,28)),0,0,'L',true);
            if ($hasCrit) {
                $bCritData = json_decode($b2['form_data']??'{}',true)['_criteria']??[];
                $critParts = [];
                foreach ($pdfCriteria as $pc) {
                    $cv = $bCritData[$pc['id']] ?? null;
                    if ($cv && !empty($cv['value'])) {
                        $critParts[] = mb_substr($cv['value'],0,12);
                    }
                }
                $critStr = implode(' | ', $critParts) ?: '-';
                // Couleur fond selon use_color du premier critère avec valeur
                $bgCrit = [255,255,255];
                foreach ($pdfCriteria as $pc) {
                    $cv = $bCritData[$pc['id']] ?? null;
                    if ($cv && !empty($cv['value']) && ($pc['use_color']??0)) {
                        $hex = ltrim($pc['color']??'#e2e8f0','#');
                        if (strlen($hex)===6) {
                            $bgCrit = [hexdec(substr($hex,0,2)),hexdec(substr($hex,2,2)),hexdec(substr($hex,4,2))];
                            $pdf->SetFillColor($bgCrit[0],$bgCrit[1],$bgCrit[2]);
                            $pdf->SetTextColor(255,255,255);
                        }
                    }
                }
                $pdf->Cell($critW,6,iconv('UTF-8','CP1252',mb_substr($critStr,0,20)),0,0,'C',true);
                $pdf->SetFillColor($bg2[0],$bg2[1],$bg2[2]);
                $pdf->SetTextColor(30,41,59);
            }
            $pdf->Cell($dateW,6,(new DateTime($b2['created_at']))->format('d/m/Y'),0,0,'C',true);
            $pdf->Cell($statW,6,iconv('UTF-8','CP1252',$st),0,1,'C',true);
            $even = !$even;
        }

        // Envoyer AVANT tout buffer HTML
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="inscriptions-'.$expSlot.'.pdf"');
        header('Cache-Control: no-cache');
        echo $pdf->Output('S');
        exit;
    }
}

ob_start();
?>
<div class="page-head">
  <h1>📅 Planning</h1>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap">
    <a href="<?=u('/admin/planning')?>" class="btn <?=($tab??'')==='types'||($tab??'')==='export'?'btn-ghost':'btn-primary'?>">📋 Créneaux</a>
    <a href="<?=u('/admin/planning?tab=types')?>" class="btn <?=($tab??'')==='types'?'btn-primary':'btn-ghost'?>">🏷️ Types</a>
    <a href="<?=u('/admin/planning?tab=inscriptions')?>" class="btn <?=($tab??'')==='inscriptions'?'btn-primary':'btn-ghost'?>">📋 Inscriptions</a>
    <a href="<?=u('/admin/planning?tab=criteria')?>" class="btn <?=($tab??'')==='criteria'?'btn-primary':'btn-ghost'?>">🏷 Critères</a>
    <a href="<?=u('/admin/planning?tab=booking_settings')?>" class="btn <?=($tab??'')==='booking_settings'?'btn-primary':'btn-ghost'?>">⚙️ Réservations</a>
    <a href="<?=u('/admin/planning?tab=export')?>" class="btn <?=($tab??'')==='export'?'btn-primary':'btn-ghost'?>">📥 Export</a>
    <?php if(($tab??'')===''):?>
    <a href="<?=u('/admin/planning?edit=0')?>" class="btn btn-ghost">+ Nouveau créneau</a>
    <?php endif;?>
  </div>
</div>

<?php if(isset($_GET['edit'])): ?>
<div class="ac">
  <div class="ac-header"><h2><?=$editSlot?'Modifier le créneau':'Nouveau créneau'?></h2><a href="<?=u('/admin/planning')?>" class="btn btn-ghost btn-sm">← Retour</a></div>
  <div class="ac-body">
    <form method="post">
      <?=Auth::csrfField()?>
      <?php if($editSlot): ?><input type="hidden" name="slot_id" value="<?=$editSlot['id']?>"><?php endif; ?>
      <div class="form-row">
        <div class="fg span2"><label>Titre *</label><input type="text" name="title" value="<?=Helpers::e($editSlot['title']??'')?>" required></div>
        <div class="fg">
          <label>Type</label>
          <select name="type" class="be-select">
            <?php if(empty($slotTypes)): ?>
              <option value="open">Libre</option>
              <option value="training">Entraînement</option>
              <option value="event">Événement</option>
              <option value="maintenance">Fermé</option>
              <option value="competition">Compétition</option>
            <?php else: ?>
              <?php foreach($slotTypes as $st): ?>
              <option value="<?=Helpers::e($st['slug'])?>" <?=($editSlot['type']??'open')===$st['slug']?'selected':''?>>
                <?=Helpers::e($st['label'])?>
              </option>
              <?php endforeach; ?>
            <?php endif; ?>
          </select>
        </div>
        <div class="fg">
          <label>Coach</label>
          <select name="coach_id">
            <option value="">Aucun</option>
            <?php foreach($coaches as $c): ?>
              <option value="<?=$c['id']?>" <?=($editSlot['coach_id']??0)==(int)$c['id']?'selected':''?>><?=Helpers::e($c['firstname'].' '.$c['lastname'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fg"><label>Début *</label><input type="datetime-local" name="date_start" value="<?=$editSlot?date('Y-m-d\TH:i',strtotime($editSlot['date_start']??'now')):''?>" required></div>
        <div class="fg"><label>Fin *</label><input type="datetime-local" name="date_end" value="<?=$editSlot?date('Y-m-d\TH:i',strtotime($editSlot['date_end']??'now')):''?>" required></div>
        <div class="fg"><label>Places max (vide = illimité)</label><input type="number" name="max_participants" value="<?=Helpers::e($editSlot['max_participants']??'')?>" min="1"></div>
        <div class="fg"><label>Couleur</label><input type="color" name="color" value="<?=Helpers::e($editSlot['color']??'#3b82f6')?>" style="width:44px;height:38px;border-radius:6px;border:1px solid #e2e8f0"></div>
        <div class="fg span2"><label>Description</label><textarea name="description" rows="2"><?=Helpers::e($editSlot['description']??'')?></textarea></div>
        <div class="fg">
          <label>Récurrence</label>
          <select name="recurrence">
            <?php foreach(['none'=>'Aucune','weekly'=>'Hebdomadaire','daily'=>'Quotidienne','monthly'=>'Mensuelle'] as $k=>$l): ?>
              <option value="<?=$k?>" <?=($editSlot['recurrence']??'none')===$k?'selected':''?>><?=$l?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fg"><label>Fin récurrence</label><input type="date" name="recurrence_end" value="<?=Helpers::e($editSlot['recurrence_end']??'')?>"></div>
      </div>
      <div class="fg">
        <label style="display:flex;align-items:center;gap:.5rem;text-transform:none">
          <input type="checkbox" name="require_booking" value="1" id="rb-cb"
            <?=($editSlot['require_booking']??0)?'checked':''?>
            onchange="document.getElementById('booking-opts').style.display=this.checked?'block':'none'">
          Inscription requise
        </label>
      </div>
      <div id="booking-opts" style="display:<?=($editSlot['require_booking']??0)?'block':'none'?>;border:1px solid #e2e8f0;border-radius:8px;padding:1rem;margin-top:.5rem">
        <div class="form-row">
          <div class="fg">
            <label>Mode d'acceptation</label>
            <select name="booking_mode">
              <option value="auto"   <?=($editSlot['booking_mode']??'auto')==='auto'  ?'selected':''?>>Automatique (jusqu'au max)</option>
              <option value="manual" <?=($editSlot['booking_mode']??'')==='manual'    ?'selected':''?>>Manuel (coach valide chaque demande)</option>
            </select>
          </div>
          <div class="fg">
            <label>Formulaire</label>
            <select name="booking_form" onchange="document.getElementById('ext-url').style.display=this.value==='external'?'block':'none'">
              <option value="internal" <?=($editSlot['booking_form']??'internal')==='internal'?'selected':''?>>Formulaire interne</option>
              <option value="external" <?=($editSlot['booking_form']??'')==='external'         ?'selected':''?>>URL externe (Google Forms...)</option>
            </select>
          </div>
          <div class="fg span2" id="ext-url" style="display:<?=($editSlot['booking_form']??'')==='external'?'block':'none'?>">
            <label>URL externe</label>
            <input type="url" name="external_url" value="<?=Helpers::e($editSlot['external_url']??'')?>" placeholder="https://forms.google.com/...">
          </div>
        </div>

        <!-- Critères d'inscription -->
        <?php if(!empty($allCriteria)): ?>
        <?php $slotCriteriaIds = json_decode($editSlot['criteria_ids']??'[]',true)??[]; ?>
        <div style="margin-top:1rem;padding:1rem;background:#f8fafc;border-radius:10px;border:1px solid #e2e8f0">
          <div style="font-weight:700;font-size:.82rem;text-transform:uppercase;letter-spacing:.05em;color:#64748b;margin-bottom:.75rem">
            🏷 Critères demandés à l'inscription
          </div>
          <div style="display:flex;flex-wrap:wrap;gap:.5rem">
            <?php
            // criteria_required_ids : critères rendus obligatoires pour CE créneau spécifiquement
            $slotRequiredIds = json_decode($editSlot['criteria_required']??'[]',true)??[];
            foreach($allCriteria as $cr):
              $checked  = in_array($cr['id'], $slotCriteriaIds);
              $required = in_array($cr['id'], $slotRequiredIds);
            ?>
            <div style="display:inline-flex;flex-direction:column;align-items:flex-start;gap:.2rem;padding:.5rem .75rem;border-radius:10px;border:1.5px solid <?=$checked?'var(--color-primary)':'#e2e8f0'?>;background:<?=$checked?'#eff6ff':'#fff'?>;transition:all .15s;margin-bottom:.25rem">
              <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;font-size:.82rem;font-weight:600">
                <input type="checkbox" name="criteria_ids[]" value="<?=$cr['id']?>"
                  id="crit-<?=$cr['id']?>"
                  <?=$checked?'checked':''?>
                  style="accent-color:var(--color-primary);margin:0"
                  onchange="document.getElementById('critreq-<?=$cr['id']?>').style.display=this.checked?'flex':'none'">
                <?=Helpers::e($cr['name'])?>
                <?php if($cr['required']):?><span style="color:#94a3b8;font-size:.68rem" title="Obligatoire par défaut">*global</span><?php endif;?>
              </label>
              <label id="critreq-<?=$cr['id']?>" style="display:<?=$checked?'flex':'none'?>;align-items:center;gap:.3rem;font-size:.72rem;color:#64748b;cursor:pointer;padding-left:1.2rem">
                <input type="checkbox" name="criteria_required[]" value="<?=$cr['id']?>"
                  <?=$required?'checked':''?>
                  style="accent-color:#ef4444;width:12px;height:12px">
                <span style="color:#ef4444;font-weight:600">Obligatoire pour ce créneau</span>
              </label>
            </div>
            <?php endforeach; ?>
          </div>
          <div style="font-size:.72rem;color:#94a3b8;margin-top:.5rem">* = obligatoire à l'inscription</div>
        </div>
        <?php endif; ?>

      </div>
      <!-- Visibilité membres -->
      <div style="border:1.5px solid #e2e8f0;border-radius:10px;padding:1rem;margin-top:1rem;background:#f8fafc">
        <div style="font-weight:700;font-size:.82rem;text-transform:uppercase;letter-spacing:.05em;color:#64748b;margin-bottom:.75rem">🔒 Visibilité</div>
        <div style="display:flex;flex-direction:column;gap:.75rem">
          <label style="display:flex;align-items:center;gap:.5rem;font-size:.875rem;cursor:pointer">
            <input type="checkbox" name="members_only" value="1"
              <?=($editSlot['members_only']??0)?'checked':''?>>
            <span><strong>Visible uniquement aux membres connectés</strong><br>
            <span style="font-size:.78rem;color:#64748b">Les visiteurs non connectés ne verront pas ce créneau du tout</span></span>
          </label>
          <div>
            <label style="display:flex;align-items:center;gap:.5rem;font-size:.875rem;cursor:pointer;margin-bottom:.5rem">
              <input type="checkbox" name="members_message" value="1" id="cb-members-msg"
                <?=($editSlot['members_message']??0)?'checked':''?>
                onchange="document.getElementById('members-msg-text').style.display=this.checked?'block':'none'">
              <span><strong>Afficher un message aux non-membres</strong><br>
              <span style="font-size:.78rem;color:#64748b">Le créneau est visible mais sans bouton de réservation, avec un message personnalisé</span></span>
            </label>
            <div id="members-msg-text" style="display:<?=($editSlot['members_message']??0)?'block':'none'?>;padding-left:1.75rem">
              <label style="font-size:.78rem;font-weight:600;color:#64748b;display:block;margin-bottom:.3rem">Texte du message</label>
              <input type="text" name="members_msg_text" class="bi"
                value="<?=Helpers::e($editSlot['members_msg_text']??'Il faut être membre pour participer.')?>"
                placeholder="Il faut être membre pour participer.">
            </div>
          </div>
        </div>
      </div>

      <div style="display:flex;gap:.75rem;justify-content:flex-end;margin-top:1.25rem">
        <a href="<?=u('/admin/planning')?>" class="btn btn-ghost">Annuler</a>
        <button type="submit" name="save_slot" class="btn btn-primary">💾 Sauvegarder</button>
      </div>
    </form>
  </div>
</div>

<?php elseif($viewSlot): ?>
<?php
$slot     = Database::one("SELECT s.*,u.firstname AS cn,u.lastname AS cl FROM cc_planning_slots s LEFT JOIN cc_users u ON s.coach_id=u.id WHERE s.id=?",[$viewSlot]);
$bookings = Database::all("SELECT b.*,u.firstname,u.lastname,u.email FROM cc_planning_bookings b LEFT JOIN cc_users u ON b.user_id=u.id WHERE b.slot_id=? ORDER BY b.created_at",[$viewSlot]);
?>
<div class="page-head" style="margin-bottom:1rem">
  <h2 style="font-size:1.2rem"><?=Helpers::e($slot['title']??'')?> — <?=Helpers::dateTimeFormat($slot['date_start']??'')?></h2>
  <a href="<?=u('/admin/planning')?>" class="btn btn-ghost btn-sm">← Retour</a>
</div>
<div class="ac">
  <div class="ac-header"><h2>Réservations (<?=count($bookings)?>)</h2></div>
  <div class="at-wrap"><table class="at">
    <thead><tr><th>Personne</th><th>Email</th><th>Statut</th><th>Date</th><th>Action</th></tr></thead>
    <tbody>
      <?php foreach($bookings as $b): ?>
      <tr>
        <td><?=Helpers::e(($b['firstname']??$b['guest_name']??'').' '.($b['lastname']??''))?></td>
        <td style="font-size:.8rem"><?=Helpers::e($b['email']??$b['guest_email']??'')?></td>
        <td>
          <span class="badge badge-<?=match($b['status']){
            'confirmed'=>'success','waitlist'=>'warning',default=>'muted'
          }?>">
            <?=match($b['status']){'confirmed'=>'Confirmé','cancelled'=>'Annulé','waitlist'=>'Liste attente',default=>$b['status']}?>
          </span>
        </td>
        <td style="font-size:.78rem"><?=Helpers::dateFormat($b['created_at'])?></td>
        <td>
          <form method="post" style="display:inline-flex;gap:.35rem">
            <?=Auth::csrfField()?>
            <input type="hidden" name="booking_id" value="<?=$b['id']?>">
            <select name="booking_status" style="padding:.2rem .4rem;border:1px solid #e2e8f0;border-radius:6px;font-size:.78rem">
              <option value="confirmed" <?=$b['status']==='confirmed'?'selected':''?>>Confirmé</option>
              <option value="waitlist"  <?=$b['status']==='waitlist' ?'selected':''?>>Liste attente</option>
              <option value="cancelled" <?=$b['status']==='cancelled'?'selected':''?>>Annulé</option>
            </select>
            <button type="submit" name="update_booking" class="btn btn-ghost btn-sm">✓</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if(empty($bookings)): ?><tr><td colspan="5" style="text-align:center;padding:2rem;color:#94a3b8">Aucune réservation</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>

<?php elseif($tab === '' || $tab === 'list'): ?>
<div class="ac">
  <div class="at-wrap"><table class="at">
    <thead><tr><th>Titre</th><th>Type</th><th>Date</th><th>Coach</th><th>Places</th><th>Mode</th><th>Actions</th></tr></thead>
    <tbody>
      <?php
      $allSlots = Database::all("SELECT s.*, u.firstname AS cn, u.lastname AS cl,
        (SELECT COUNT(*) FROM cc_planning_bookings b WHERE b.slot_id=s.id AND b.status='confirmed') AS booked
        FROM cc_planning_slots s LEFT JOIN cc_users u ON s.coach_id=u.id ORDER BY s.date_start DESC LIMIT 60");
      foreach($allSlots as $s):
        $typeRow   = $typeMap[$s['type']] ?? null;
        $slotColor = $typeRow['color'] ?? '#3b82f6';
        $typeLabel = $typeRow['label'] ?? $s['type'];
      ?>
      <tr>
        <td><strong><?=Helpers::e($s['title'])?></strong></td>
        <td><span class="badge" style="background:<?=Helpers::e($slotColor)?>;color:#fff;font-size:.65rem"><?=Helpers::e($s['type'])?></span></td>
        <td style="font-size:.8rem"><?=Helpers::dateTimeFormat($s['date_start'])?></td>
        <td><?=$s['cn']?Helpers::e($s['cn'].' '.$s['cl']):'—'?></td>
        <td><?=$s['max_participants']?$s['booked'].'/'.$s['max_participants']:'∞'?></td>
        <td><span class="badge badge-muted" style="font-size:.65rem"><?=($s['booking_mode']??'auto')==='manual'?'Manuel':'Auto'?></span></td>
        <td style="display:flex;gap:.35rem">
          <?php if($s['max_participants']): ?>
            <a href="?view_slot=<?=$s['id']?>" class="btn btn-ghost btn-sm">👥 <?=$s['booked']?></a>
          <?php endif; ?>
          <a href="<?=u('/admin/planning?edit='.$s['id'])?>" class="btn btn-ghost btn-sm">✏️</a>
          <form method="post" onsubmit="return confirm('Supprimer ce créneau ?')">
            <?=Auth::csrfField()?>
            <input type="hidden" name="slot_id" value="<?=$s['id']?>">
            <button type="submit" name="delete_slot" class="btn btn-danger btn-sm">🗑️</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if(empty($allSlots)): ?>
        <tr><td colspan="7" style="text-align:center;padding:2rem;color:#94a3b8">Aucun créneau. <a href="<?=u('/admin/planning?edit=0')?>">Créer le premier →</a></td></tr>
      <?php endif; ?>
    </tbody>
  </table></div>
</div>

<?php elseif($tab === 'criteria'): ?>
<?php
$editCritId = (int)($_GET['edit_crit'] ?? 0);
$editCrit   = $editCritId ? Database::one("SELECT * FROM cc_planning_criteria WHERE id=?",[$editCritId]) : null;
$editOpts   = $editCrit ? (json_decode($editCrit['options']??'[]',true)??[]) : [];
$ftype      = $editCrit['field_type'] ?? 'text';
?>

<style>
/* Type selector */
.crit-type-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:.5rem; margin-top:.4rem; }
.crit-type-card { position:relative; }
.crit-type-card input[type=radio] { position:absolute;opacity:0;width:0;height:0; }
.crit-type-card label {
  display:flex; flex-direction:column; align-items:center; justify-content:center;
  gap:.2rem; padding:.6rem .4rem; border-radius:10px; border:2px solid #e2e8f0;
  cursor:pointer; text-align:center; transition:all .15s; background:#fff;
  font-size:.78rem; font-weight:600; color:#374151; line-height:1.3;
}
.crit-type-card label .icon { font-size:1.3rem; margin-bottom:.1rem; }
.crit-type-card label .sub  { font-size:.68rem; font-weight:400; color:#94a3b8; }
.crit-type-card input:checked + label {
  border-color:var(--color-primary); background:#eff6ff; color:var(--color-primary);
}
.crit-type-card input:checked + label .sub { color:#6366f1; }
/* Sections conditionnelles */
/* crit-section géré uniquement par JS */
</style>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;align-items:start">

  <!-- FORMULAIRE -->
  <div class="ac">
    <div class="ac-header">
      <h2><?=$editCrit?'✏️ Modifier le critère':'➕ Nouveau critère'?></h2>
      <?php if($editCrit):?><a href="<?=u('/admin/planning?tab=criteria')?>" class="btn btn-ghost btn-sm">← Annuler</a><?php endif;?>
    </div>
    <div class="ac-body">
      <form method="post">
        <?=Auth::csrfField()?>
        <input type="hidden" name="criteria_id" value="<?=$editCrit['id']??0?>">

        <!-- Nom -->
        <div class="fg" style="margin-bottom:1rem">
          <label>Nom du critère *</label>
          <input type="text" name="criteria_name" class="be-input" required
            value="<?=Helpers::e($editCrit['name']??'')?>" placeholder="Ex: Niveau, Âge, Catégorie…">
        </div>

        <!-- Type — grille 4 colonnes, style card CSS pur -->
        <div class="fg" style="margin-bottom:1rem">
          <label>Type de champ</label>
          <div class="crit-type-grid">
            <?php
            $types = [
              'text'   => ['📝','Texte libre'],
              'number' => ['🔢','Nombre'],
              'range'  => ['↔️','Tranche min/max'],
              'select' => ['📋','Liste déroulante'],
            ];
            foreach($types as $k=>[$ico,$lbl]):
              $sel = $ftype===$k;
            ?>
            <div class="crit-type-card">
              <input type="radio" name="field_type" value="<?=$k?>" id="ft-<?=$k?>"
                <?=$sel?'checked':''?> onchange="showCritSection(this.value)">
              <label for="ft-<?=$k?>">
                <span class="icon"><?=$ico?></span>
                <?=$lbl?>
              </label>
            </div>
            <?php endforeach;?>
          </div>
        </div>

        <!-- Section : RANGE -->
        <div id="cs-range" class="crit-section<?=$ftype==='range'?' active':''?>"
          style="background:#f8fafc;border-radius:10px;padding:.875rem;margin-bottom:.875rem">
          <label style="display:block;font-size:.82rem;font-weight:700;color:#64748b;margin-bottom:.5rem">Bornes de la tranche</label>
          <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
            <input type="number" name="range_min" class="be-input" style="max-width:80px"
              value="<?=$editCrit['range_min']??''?>" placeholder="Min">
            <span style="color:#64748b;font-size:.875rem">à</span>
            <input type="number" name="range_max" class="be-input" style="max-width:80px"
              value="<?=$editCrit['range_max']??''?>" placeholder="Max">
            <input type="text" name="range_unit" class="be-input" style="max-width:100px"
              value="<?=Helpers::e($editCrit['range_unit']??'')?>" placeholder="Ex: ans">
          </div>
          <div style="font-size:.72rem;color:#94a3b8;margin-top:.4rem">Le membre verra deux champs : "entre X et Y ans"</div>
        </div>

        <!-- Section : OPTIONS (select) -->
        <div id="cs-select" class="crit-section<?=$ftype==='select'?' active':''?>"
          style="background:#f8fafc;border-radius:10px;padding:.875rem;margin-bottom:.875rem">
          <label style="display:block;font-size:.82rem;font-weight:700;color:#64748b;margin-bottom:.5rem">
            Options du menu déroulant
          </label>
          <div id="crit-options">
            <?php foreach($editOpts as $o):?>
            <div class="crit-opt-row" style="display:flex;gap:.4rem;align-items:center;margin-bottom:.4rem">
              <input type="color" name="opt_color[]" value="<?=Helpers::e($o['color']??'#6366f1')?>"
                style="width:34px;height:34px;border-radius:6px;border:1.5px solid #e2e8f0;cursor:pointer;padding:2px;flex-shrink:0">
              <input type="text" name="opt_label[]" value="<?=Helpers::e($o['label']??'')?>"
                class="be-input" style="flex:1" placeholder="Ex: Benjamin, Cadet…">
              <button type="button" onclick="this.closest('.crit-opt-row').remove()"
                style="background:#fee2e2;border:none;border-radius:6px;width:30px;height:30px;cursor:pointer;color:#dc2626;flex-shrink:0;font-size:1rem">×</button>
            </div>
            <?php endforeach;?>
          </div>
          <button type="button" onclick="addCritOpt()"
            style="background:#fff;border:1.5px dashed #6366f1;border-radius:8px;padding:.4rem .875rem;cursor:pointer;font-size:.82rem;color:#6366f1;width:100%;margin-top:.25rem;font-family:inherit;font-weight:600">
            + Ajouter une option
          </button>
          <label style="display:flex;align-items:center;gap:.4rem;margin-top:.625rem;font-size:.82rem;cursor:pointer">
            <input type="checkbox" name="criteria_allow_other" value="1"
              <?=($editCrit['allow_other']??0)?'checked':''?> style="accent-color:var(--color-primary)">
            Autoriser "Autre" (champ texte libre en plus)
          </label>
        </div>

        <!-- Couleur globale -->
        <div style="display:flex;align-items:center;gap:.875rem;padding:.75rem;background:#f8fafc;border-radius:10px;margin-bottom:.875rem">
          <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;font-size:.875rem;font-weight:600;flex:1">
            <input type="checkbox" name="use_color" value="1"
              id="use-color-chk" <?=($editCrit['use_color']??0)?'checked':''?>
              onchange="document.getElementById('color-pick').style.opacity=this.checked?'1':'.3';document.getElementById('color-pick').disabled=!this.checked"
              style="accent-color:var(--color-primary)">
            Associer une couleur au critère
          </label>
          <input type="color" name="color" id="color-pick"
            value="<?=Helpers::e($editCrit['color']??'#6366f1')?>"
            <?=($editCrit['use_color']??0)?'':'disabled'?>
            style="width:38px;height:34px;border-radius:6px;border:1.5px solid #e2e8f0;cursor:pointer;padding:2px;opacity:<?=($editCrit['use_color']??0)?'1':'.3'?>">
        </div>

        <!-- Obligatoire -->
        <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;font-size:.875rem;margin-bottom:1rem">
          <input type="checkbox" name="criteria_required" value="1"
            <?=($editCrit['required']??1)?'checked':''?> style="accent-color:var(--color-primary)">
          <strong>Obligatoire par défaut</strong>
          <span style="color:#94a3b8;font-size:.78rem">(peut être ajusté par créneau)</span>
        </label>

        <button type="submit" name="save_criteria" class="btn btn-primary">
          <?=$editCrit?'💾 Modifier le critère':'➕ Créer le critère'?>
        </button>
      </form>
    </div>
  </div>

  <!-- LISTE -->
  <div class="ac">
    <div class="ac-header"><h2>Critères existants (<?=count($allCriteria)?>)</h2></div>
    <?php if(empty($allCriteria)):?>
    <div style="padding:2rem;text-align:center;color:#94a3b8">Aucun critère.</div>
    <?php else:?>
    <div style="padding:.5rem">
      <?php
      $typeIcons = ['text'=>'📝','number'=>'🔢','range'=>'↔️','select'=>'📋'];
      foreach($allCriteria as $cr):
        $opts2 = json_decode($cr['options']??'[]',true)??[];
      ?>
      <div style="padding:.875rem 1rem;border-radius:10px;border:1.5px solid #e2e8f0;margin-bottom:.5rem;background:#fff">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:.5rem">
          <div style="flex:1;min-width:0">
            <div style="display:flex;align-items:center;gap:.4rem;margin-bottom:.3rem;flex-wrap:wrap">
              <?php if($cr['use_color']??0):?>
              <span style="width:11px;height:11px;border-radius:50%;background:<?=Helpers::e($cr['color']??'#6366f1')?>;display:inline-block;flex-shrink:0"></span>
              <?php endif;?>
              <strong style="font-size:.9rem"><?=Helpers::e($cr['name'])?></strong>
              <span style="background:#f1f5f9;color:#64748b;padding:.1rem .4rem;border-radius:4px;font-size:.68rem;font-weight:700">
                <?=($typeIcons[$cr['field_type']??'text']??'📝').' '.(['text'=>'Texte','number'=>'Nombre','range'=>'Tranche','select'=>'Liste'][$cr['field_type']??'text']??'Texte')?>
              </span>
              <?php if($cr['required']):?><span style="color:#ef4444;font-size:.68rem;font-weight:700">*obligatoire</span><?php endif;?>
            </div>
            <?php if($cr['field_type']==='range'):?>
            <div style="font-size:.75rem;color:#64748b">entre <?=$cr['range_min']??'?'?> et <?=$cr['range_max']??'?'?> <?=Helpers::e($cr['range_unit']??'')?></div>
            <?php elseif(!empty($opts2)):?>
            <div style="display:flex;flex-wrap:wrap;gap:.2rem;margin-top:.25rem">
              <?php foreach($opts2 as $o2):?>
              <span style="background:<?=Helpers::e($o2['color']??'#6366f1')?>;color:#fff;padding:.1rem .45rem;border-radius:99px;font-size:.7rem;font-weight:700">
                <?=Helpers::e($o2['label'])?>
              </span>
              <?php endforeach;?>
            </div>
            <?php endif;?>
          </div>
          <div style="display:flex;gap:.35rem;flex-shrink:0">
            <a href="<?=u('/admin/planning?tab=criteria&edit_crit='.$cr['id'])?>" class="btn btn-ghost btn-sm">✏️</a>
            <form method="post" onsubmit="return confirm('Supprimer ce critère ?')">
              <?=Auth::csrfField()?>
              <input type="hidden" name="criteria_id" value="<?=$cr['id']?>">
              <button type="submit" name="delete_criteria" class="btn btn-danger btn-sm">🗑️</button>
            </form>
          </div>
        </div>
      </div>
      <?php endforeach;?>
    </div>
    <?php endif;?>
  </div>
</div>

<script>
// Initialiser les sections cachées dès que le script est parsé
(function initCritSections() {
  // Cacher TOUTES les sections conditionnelles via style inline
  var sections = ['cs-range', 'cs-select'];
  sections.forEach(function(id) {
    var el = document.getElementById(id);
    if (el) el.style.display = 'none';
  });
  // Afficher celle qui correspond au type actuellement sélectionné
  var checked = document.querySelector('[name="field_type"]:checked');
  var current = checked ? checked.value : 'text';
  var toShow = document.getElementById('cs-' + current);
  if (toShow) toShow.style.display = 'block';
})();

function showCritSection(val) {
  // Cacher toutes les sections
  ['cs-range', 'cs-select'].forEach(function(id) {
    var el = document.getElementById(id);
    if (el) el.style.display = 'none';
  });
  // Afficher la bonne
  var target = document.getElementById('cs-' + val);
  if (target) target.style.display = 'block';
}

function removeOptRow(btn) {
  var row = btn.parentNode;
  if (row) row.parentNode.removeChild(row);
}

function addCritOpt() {
  var c = document.getElementById('crit-options');
  if (!c) { alert('Erreur: conteneur options introuvable'); return; }
  var row = document.createElement('div');
  row.style.cssText = 'display:flex;gap:.4rem;align-items:center;margin-bottom:.4rem';
  var colorIn = document.createElement('input');
  colorIn.type = 'color'; colorIn.name = 'opt_color[]'; colorIn.value = '#6366f1';
  colorIn.style.cssText = 'width:34px;height:34px;border-radius:6px;border:1.5px solid #e2e8f0;cursor:pointer;padding:2px;flex-shrink:0';
  var textIn = document.createElement('input');
  textIn.type = 'text'; textIn.name = 'opt_label[]'; textIn.placeholder = 'Ex: Benjamin, Cadet…';
  textIn.className = 'be-input'; textIn.style.cssText = 'flex:1';
  var delBtn = document.createElement('button');
  delBtn.type = 'button'; delBtn.innerHTML = '×';
  delBtn.style.cssText = 'background:#fee2e2;border:none;border-radius:6px;width:30px;height:30px;cursor:pointer;color:#dc2626;flex-shrink:0;font-size:1rem';
  delBtn.onclick = function() { removeOptRow(this); };
  row.appendChild(colorIn); row.appendChild(textIn); row.appendChild(delBtn);
  c.appendChild(row);
  textIn.focus();
}
</script>

<?php elseif($tab === 'booking_settings'): ?>
<?php
$bookingMode    = Config::get('booking_mode',         'auto');
$waitlistOn     = (int)Config::get('booking_waitlist',     0);
$maxWaitlist    = (int)Config::get('booking_max_waitlist', 10);
?>
<div class="ac" style="max-width:680px">
  <div class="ac-header"><h2>⚙️ Paramètres de réservation</h2></div>
  <div class="ac-body">
    <form method="post">
      <?=Auth::csrfField()?>

      <!-- Mode de confirmation -->
      <div style="margin-bottom:2rem">
        <label style="display:block;font-weight:700;font-size:.95rem;margin-bottom:1rem">Mode de confirmation des inscriptions</label>

        <label style="display:flex;align-items:flex-start;gap:1rem;padding:1.1rem 1.25rem;border:2px solid <?=$bookingMode==='auto'?'var(--color-primary)':'#e2e8f0'?>;border-radius:12px;cursor:pointer;margin-bottom:.75rem;background:<?=$bookingMode==='auto'?'#eff6ff':'#fff'?>">
          <input type="radio" name="booking_mode" value="auto" <?=$bookingMode==='auto'?'checked':''?> style="margin-top:3px;accent-color:var(--color-primary);width:18px;height:18px;flex-shrink:0">
          <div>
            <div style="font-weight:700;font-size:.95rem;margin-bottom:.2rem">✅ Confirmation automatique</div>
            <div style="font-size:.83rem;color:#64748b;line-height:1.5">L'inscription est confirmée immédiatement. Un email de confirmation est envoyé automatiquement à l'utilisateur.</div>
          </div>
        </label>

        <label style="display:flex;align-items:flex-start;gap:1rem;padding:1.1rem 1.25rem;border:2px solid <?=$bookingMode==='manual'?'var(--color-primary)':'#e2e8f0'?>;border-radius:12px;cursor:pointer;background:<?=$bookingMode==='manual'?'#eff6ff':'#fff'?>">
          <input type="radio" name="booking_mode" value="manual" <?=$bookingMode==='manual'?'checked':''?> style="margin-top:3px;accent-color:var(--color-primary);width:18px;height:18px;flex-shrink:0">
          <div>
            <div style="font-weight:700;font-size:.95rem;margin-bottom:.2rem">⏳ Validation manuelle</div>
            <div style="font-size:.83rem;color:#64748b;line-height:1.5">L'inscription passe en statut "En attente". Un email d'attente est envoyé à l'utilisateur. Vous devez confirmer ou refuser depuis l'onglet Inscriptions. Un email de confirmation ou de refus est envoyé à la validation.</div>
          </div>
        </label>
      </div>

      <!-- Liste d'attente -->
      <div style="margin-bottom:2rem;padding:1.25rem;background:#f8fafc;border-radius:12px;border:1.5px solid #e2e8f0">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.75rem">
          <div>
            <div style="font-weight:700;font-size:.95rem">📋 Liste d'attente</div>
            <div style="font-size:.83rem;color:#64748b;margin-top:.2rem">Si toutes les places sont prises, proposer une liste d'attente</div>
          </div>
          <!-- Toggle CSS pur -->
          <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer">
            <span style="font-size:.82rem;color:#64748b"><?=$waitlistOn?'Activée':'Désactivée'?></span>
            <span style="position:relative;display:inline-block;width:44px;height:24px">
              <input type="checkbox" name="enable_waitlist" value="1" <?=$waitlistOn?'checked':''?>
                id="waitlist-toggle"
                onchange="document.getElementById('waitlist-opts').style.display=this.checked?'block':'none';this.previousElementSibling.previousElementSibling.textContent=this.checked?'Activée':'Désactivée'"
                style="opacity:0;position:absolute;inset:0;margin:0;cursor:pointer;z-index:2;width:100%;height:100%">
              <span style="position:absolute;inset:0;border-radius:99px;transition:background .2s;pointer-events:none"
                id="waitlist-track"
                class="wl-track <?=$waitlistOn?'wl-on':'wl-off'?>"></span>
              <span style="position:absolute;top:3px;width:18px;height:18px;background:#fff;border-radius:50%;transition:left .2s;box-shadow:0 1px 3px rgba(0,0,0,.2);pointer-events:none"
                id="waitlist-thumb"
                class="wl-thumb <?=$waitlistOn?'wl-on':'wl-off'?>"></span>
            </span>
          </label>
        </div>
        <style>
          .wl-track.wl-on  { background:var(--color-primary); }
          .wl-track.wl-off { background:#e2e8f0; }
          .wl-thumb.wl-on  { left:23px; }
          .wl-thumb.wl-off { left:3px; }
          #waitlist-toggle:checked ~ .wl-track { background:var(--color-primary); }
          #waitlist-toggle:checked ~ .wl-thumb { left:23px; }
          /* Approche native CSS */
          #waitlist-toggle:checked + span.wl-track { background:var(--color-primary); }
        </style>
        <script>
        (function() {
          var chk   = document.getElementById('waitlist-toggle');
          var track = document.getElementById('waitlist-track');
          var thumb = document.getElementById('waitlist-thumb');
          if (!chk) return;
          function update() {
            track.style.background = chk.checked ? 'var(--color-primary)' : '#e2e8f0';
            thumb.style.left       = chk.checked ? '23px' : '3px';
          }
          update();
          chk.addEventListener('change', update);
        })();
        </script>
        <div id="waitlist-opts" style="<?=$waitlistOn?'':'display:none'?>">
          <div class="fg" style="margin:0;max-width:280px">
            <label style="font-size:.82rem;font-weight:600">Nombre max de personnes en liste d'attente (0 = illimité)</label>
            <input type="number" name="max_waitlist" value="<?=$maxWaitlist?>" min="0" max="999"
              class="be-input" style="max-width:100px;margin-top:.35rem">
          </div>
        </div>
      </div>

      <!-- Résumé comportement -->
      <div style="padding:1rem 1.25rem;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;margin-bottom:1.5rem;font-size:.85rem;line-height:1.6;color:#166534">
        <strong>Comportement actuel :</strong><br>
        <?php if($bookingMode==='auto'): ?>
        • Inscription → statut <strong>Confirmé</strong> + email de confirmation envoyé<br>
        <?php else: ?>
        • Inscription → statut <strong>En attente</strong> + email "votre demande est en cours d'examen"<br>
        • Admin confirme → email de confirmation envoyé<br>
        • Admin annule → email de refus envoyé<br>
        <?php endif; ?>
        <?php if($waitlistOn): ?>
        • Si créneau complet → inscription en <strong>liste d'attente</strong>
          <?=$maxWaitlist>0?' (max '.$maxWaitlist.' personnes)':' (illimitée)'?><br>
        • Si place se libère → premier en liste promu automatiquement
        <?php else: ?>
        • Si créneau complet → inscription refusée
        <?php endif; ?>
      </div>

      <button type="submit" name="save_booking_settings" class="btn btn-primary">💾 Sauvegarder</button>
    </form>
  </div>
</div>

<?php elseif($tab === 'inscriptions'): ?>
<?php
// Charger tous les créneaux qui ont des inscriptions
$filterSlot = (int)($_GET['slot'] ?? 0);
$slotsWithBookings = Database::all(
    "SELECT s.*, COUNT(b.id) AS total, SUM(b.status='confirmed') AS confirmed
     FROM cc_planning_slots s
     JOIN cc_planning_bookings b ON b.slot_id = s.id
     WHERE s.published=1
     GROUP BY s.id
     ORDER BY s.date_start DESC"
);
$bookings = $filterSlot
    ? Database::all(
        "SELECT b.*, u.firstname, u.lastname, u.email AS user_email
         FROM cc_planning_bookings b
         LEFT JOIN cc_users u ON b.user_id = u.id
         WHERE b.slot_id = ?
         ORDER BY b.created_at ASC", [$filterSlot])
    : [];
$slotDetail = $filterSlot ? Database::one("SELECT * FROM cc_planning_slots WHERE id=?", [$filterSlot]) : null;
$slotVolunteers = $filterSlot ? Database::all(
    "SELECT u.firstname,u.lastname,u.email,sv.created_at FROM cc_benv_slot_volunteers sv JOIN cc_users u ON u.id=sv.user_id WHERE sv.slot_id=?",
    [$filterSlot]
) : [];
?>
<div style="display:grid;grid-template-columns:300px 1fr;gap:1.5rem;align-items:start">

  <!-- Colonne gauche : liste des créneaux -->
  <div class="ac">
    <div class="ac-header"><h2>Créneaux (<?=count($slotsWithBookings)?>)</h2></div>
    <?php if(empty($slotsWithBookings)): ?>
    <div style="padding:1.5rem;text-align:center;color:#94a3b8;font-size:.875rem">Aucune inscription pour l'instant.</div>
    <?php else: ?>
    <div style="padding:.5rem">
      <?php foreach($slotsWithBookings as $sw):
        $isActive = $filterSlot === (int)$sw['id'];
        $dt = new DateTime($sw['date_start']);
      ?>
      <a href="<?=u('/admin/planning?tab=inscriptions&slot='.$sw['id'])?>"
         style="display:block;padding:.65rem .875rem;border-radius:8px;text-decoration:none;margin-bottom:.25rem;background:<?=$isActive?'var(--color-primary)':'#f8fafc'?>;color:<?=$isActive?'#fff':'#1e293b'?>;transition:background .15s">
        <div style="font-weight:600;font-size:.875rem"><?=Helpers::e($sw['title'])?></div>
        <div style="font-size:.75rem;opacity:.75;margin-top:.1rem">
          <?=$dt->format('d/m/Y à H:i')?> &bull;
          <span style="font-weight:700"><?=$sw['confirmed']?>/<?=$sw['total']?> confirmées</span>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Colonne droite : inscriptions du créneau sélectionné -->
  <div>
    <?php if(!$filterSlot): ?>
    <div class="ac">
      <div style="padding:3rem;text-align:center;color:#94a3b8">
        <div style="font-size:2rem;margin-bottom:.5rem">👈</div>
        Sélectionnez un créneau pour voir ses inscriptions
      </div>
    </div>
    <?php else: ?>
    <?php
    $dtSlot = new DateTime($slotDetail['date_start']);
    $deSlot = new DateTime($slotDetail['date_end']);
    $takenSlot = count(array_filter($bookings, fn($b)=>$b['status']==='confirmed'));
    $maxSlot   = (int)$slotDetail['max_participants'];
    ?>
    <div class="ac" style="margin-bottom:1rem">
      <div class="ac-header" style="flex-wrap:wrap;gap:.5rem">
        <div>
          <h2 style="margin-bottom:.2rem"><?=Helpers::e($slotDetail['title'])?></h2>
          <div style="font-size:.8rem;color:#64748b">
            <?=$dtSlot->format('d/m/Y')?> • <?=$dtSlot->format('H:i')?> – <?=$deSlot->format('H:i')?>
            <?php if($maxSlot): ?>
            &bull; <strong><?=$takenSlot?>/<?=$maxSlot?> places</strong>
            (<?=max(0,$maxSlot-$takenSlot)?> restantes)
            <?php else: ?>
            &bull; <strong><?=$takenSlot?> inscrit<?=$takenSlot>1?'s':''?></strong> (places illimitées)
            <?php endif; ?>
          </div>
        </div>
        <a href="<?=u('/admin/planning?tab=inscriptions&slot='.$filterSlot.'&export=pdf')?>"
           class="btn btn-ghost btn-sm">📄 Exporter PDF</a>
      </div>
    </div>

    <?php if(empty($bookings)): ?>
    <div class="ac"><div style="padding:2rem;text-align:center;color:#94a3b8">Aucune inscription.</div></div>
    <?php else: ?>
    <div class="ac">
      <div class="at-wrap"><table class="at">
        <thead>
          <tr>
            <th>#</th>
            <th>Nom / Email</th>
            <th>Critères</th>
            <th>Date inscription</th>
            <th>Statut</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($bookings as $i=>$b):
          $name  = $b['firstname'] ? Helpers::e($b['firstname'].' '.$b['lastname']) : Helpers::e($b['guest_name']??'Visiteur');
          $email = $b['user_email'] ?? $b['guest_email'] ?? '—';
          $statusColors = ['confirmed'=>['#dcfce7','#16a34a','✅ Confirmé'],'cancelled'=>['#fee2e2','#dc2626','❌ Annulé'],'pending'=>['#fef3c7','#d97706','⏳ En attente']];
          [$bg,$fg,$lbl] = $statusColors[$b['status']] ?? ['#f1f5f9','#64748b',$b['status']];
        ?>
        <tr>
          <td style="color:#94a3b8;font-size:.8rem"><?=$i+1?></td>
          <td>
            <strong><?=$name?></strong>
            <?php if($b['user_id']): ?><span style="font-size:.7rem;background:#eff6ff;color:#3b82f6;padding:.1rem .35rem;border-radius:4px;margin-left:.3rem">Membre</span><?php endif; ?>
            <div style="font-size:.78rem;color:#64748b"><?=Helpers::e($email)?></div>
          </td>
          <td>
            <?php
            if(!class_exists('CriteriaRenderer'))require_once CC_ROOT.'/core/CriteriaRenderer.php';
            $critData = json_decode($b['form_data']??'{}',true)['_criteria']??[];
            foreach($critData as $cid=>$cv):
              if(empty($cv['value'])) continue;
              $crMatch = array_values(array_filter($allCriteria,fn($c)=>$c['id']==$cid));
              if($crMatch) {
                  echo CriteriaRenderer::badge($crMatch[0], $cv['value'], $cv['value2']??'');
              } else {
                  // Critère supprimé mais valeur existante : affichage simple
                  echo '<span style="display:inline-block;background:#e2e8f0;color:#374151;padding:.15rem .5rem;border-radius:99px;font-size:.72rem;font-weight:700;margin:.1rem">'
                      .Helpers::e($cv['value']).'</span>';
              }
            ?>
            <?php endforeach;?>
          </td>
          <td style="font-size:.82rem;color:#64748b"><?=(new DateTime($b['created_at']))->format('d/m/Y H:i')?></td>
          <td>
            <span style="background:<?=$bg?>;color:<?=$fg?>;padding:.2rem .6rem;border-radius:99px;font-size:.75rem;font-weight:700">
              <?=$lbl?>
            </span>
          </td>
          <td>
            <div style="display:flex;gap:.35rem;align-items:center;flex-wrap:wrap">
              <!-- Changer statut -->
              <form method="post" style="display:inline">
                <?=Auth::csrfField()?>
                <input type="hidden" name="booking_id" value="<?=$b['id']?>">
                <input type="hidden" name="slot_id" value="<?=$filterSlot?>">
                <select name="status" onchange="this.form.submit()" style="border:1.5px solid #e2e8f0;border-radius:6px;padding:.25rem .4rem;font-size:.75rem;font-family:inherit;cursor:pointer">
                  <option value="confirmed" <?=$b['status']==='confirmed'?'selected':''?>>✅ Confirmé</option>
                  <option value="pending"   <?=$b['status']==='pending'  ?'selected':''?>>⏳ En attente</option>
                  <option value="cancelled" <?=$b['status']==='cancelled'?'selected':''?>>❌ Annulé</option>
                </select>
                <input type="hidden" name="update_booking_status" value="1">
              </form>
              <!-- Supprimer -->
              <form method="post" onsubmit="return confirm('Supprimer cette inscription ?')">
                <?=Auth::csrfField()?>
                <input type="hidden" name="booking_id" value="<?=$b['id']?>">
                <input type="hidden" name="slot_id" value="<?=$filterSlot?>">
                <button type="submit" name="delete_booking" class="btn btn-danger btn-sm" style="padding:.25rem .5rem">🗑️</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>

</div>



<?php elseif($tab === 'export'): ?>
<?php $exportAccess = Config::get('planning_export_access','members'); ?>
<div class="ac">
  <div class="ac-header"><h2>📥 Export du planning</h2></div>
  <div class="ac-body">
    <p style="color:#64748b;margin-bottom:1.5rem;font-size:.9rem">
      Permettez aux visiteurs de télécharger le planning (PDF imprimable ou iCal pour agenda).
    </p>
    <form method="post">
      <?=Auth::csrfField()?>
      <div class="fg" style="max-width:420px;margin-bottom:1.5rem">
        <label style="font-weight:700;margin-bottom:.75rem;display:block">Qui peut télécharger le planning ?</label>
        <?php foreach(['all'=>['🌍','Tout le monde'],'members'=>['👥','Membres connectés uniquement'],'admin'=>['🔑','Admins et coachs uniquement']] as $v=>[$ico,$lbl]): ?>
        <label style="display:flex;align-items:center;gap:.875rem;padding:.875rem 1rem;margin-bottom:.5rem;border:2px solid <?=$exportAccess===$v?'var(--color-primary)':'#e2e8f0'?>;border-radius:10px;cursor:pointer;background:<?=$exportAccess===$v?'#eff6ff':'#fff'?>">
          <input type="radio" name="export_access" value="<?=$v?>" <?=$exportAccess===$v?'checked':''?> style="accent-color:var(--color-primary);width:18px;height:18px;flex-shrink:0">
          <span style="font-size:1.2rem"><?=$ico?></span>
          <span><?=$lbl?></span>
        </label>
        <?php endforeach; ?>
      </div>
      <button type="submit" name="save_export_settings" class="btn btn-primary">💾 Sauvegarder</button>
    </form>
    <div style="margin-top:2rem;padding:1.25rem;background:#f8fafc;border-radius:10px;border:1px solid #e2e8f0">
      <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#64748b;margin-bottom:.6rem">Liens d'export</div>
      <div style="font-size:.82rem;font-family:monospace;background:#fff;padding:.4rem .75rem;border-radius:6px;border:1px solid #e2e8f0;margin-bottom:.4rem"><?=u('/planning/export/pdf')?></div>
      <div style="font-size:.82rem;font-family:monospace;background:#fff;padding:.4rem .75rem;border-radius:6px;border:1px solid #e2e8f0"><?=u('/planning/export/ical')?></div>
    </div>
  </div>
</div>

<?php elseif($tab === 'types'): ?>
<?php
$editTypeId = (int)($_GET['edit_type'] ?? 0);
$editType   = $editTypeId ? Database::one("SELECT * FROM cc_planning_types WHERE id=?",[$editTypeId]) : null;
?>
<div class="ac" style="margin-bottom:1.5rem">
  <div class="ac-header">
    <h2><?=$editType ? '✏️ Modifier le type' : '➕ Nouveau type'?></h2>
    <?php if($editType): ?><a href="<?=u('/admin/planning?tab=types')?>" class="btn btn-ghost btn-sm">← Annuler</a><?php endif; ?>
  </div>
  <div class="ac-body">
    <form method="post">
      <?=Auth::csrfField()?>
      <input type="hidden" name="type_id" value="<?=$editType['id']??0?>">
      <div class="form-row" style="grid-template-columns:1fr 120px">
        <div class="fg">
          <label>Nom du type *</label>
          <input type="text" name="type_label" value="<?=Helpers::e($editType['label']??'')?>" required placeholder="Ex: Stage, Réunion…"
            <?=($editType['is_system']??0)?'title="Type système — nom fixe" style="opacity:.6"':''?>>
          <?php if($editType['is_system']??0): ?><small style="color:#94a3b8">Type système — seule la couleur est modifiable.</small><?php endif; ?>
        </div>
        <div class="fg">
          <label>Couleur</label>
          <input type="color" name="type_color" value="<?=Helpers::e($editType['color']??'#6366f1')?>"
            style="height:42px;width:100%;border-radius:8px;border:1.5px solid #e2e8f0;cursor:pointer;padding:3px">
        </div>
      </div>
      <button type="submit" name="save_slot_type" class="btn btn-primary"><?=$editType?'💾 Modifier':'➕ Créer le type'?></button>
    </form>
  </div>
</div>
<div class="ac">
  <div class="ac-header"><h2>Types de créneaux (<?=count($slotTypes)?>)</h2></div>
  <div class="at-wrap"><table class="at">
    <thead><tr><th>Type</th><th>Slug</th><th>Système</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach($slotTypes as $st): ?>
    <tr>
      <td><div style="display:flex;align-items:center;gap:.6rem"><div style="width:28px;height:28px;border-radius:6px;background:<?=Helpers::e($st['color'])?>"></div><strong><?=Helpers::e($st['label'])?></strong></div></td>
      <td style="font-family:monospace;font-size:.8rem;color:#64748b"><?=Helpers::e($st['slug'])?></td>
      <td><?=$st['is_system']?'<span style="color:#94a3b8;font-size:.8rem">Système</span>':'<span style="color:#22c55e;font-size:.8rem">Personnalisé</span>'?></td>
      <td style="display:flex;gap:.4rem">
        <a href="<?=u('/admin/planning?tab=types&edit_type='.$st['id'])?>" class="btn btn-ghost btn-sm">✏️</a>
        <?php if(!$st['is_system']): ?>
        <form method="post" onsubmit="return confirm('Supprimer ce type ?')">
          <?=Auth::csrfField()?>
          <input type="hidden" name="type_id" value="<?=$st['id']?>">
          <button type="submit" name="delete_slot_type" class="btn btn-danger btn-sm">🗑️</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

    <!-- Liste bénévoles pour ce créneau -->
    <?php if(!empty($slotVolunteers)): ?>
    <div class="ac" style="margin-top:1.25rem">
      <div class="ac-header">
        <h2>🤝 Bénévoles présents (<?=count($slotVolunteers)?>)</h2>
        <span style="font-size:.78rem;color:#64748b">Bénévoles inscrits via l'espace bénévoles</span>
      </div>
      <div class="at-wrap"><table class="at">
        <thead><tr><th>Nom</th><th>Email</th><th>Inscrit le</th></tr></thead>
        <tbody>
        <?php foreach($slotVolunteers as $sv): ?>
        <tr>
          <td><strong><?=Helpers::e($sv['firstname'].' '.$sv['lastname'])?></strong></td>
          <td style="font-size:.82rem"><?=Helpers::e($sv['email'])?></td>
          <td style="font-size:.78rem;color:#64748b"><?=(new DateTime($sv['created_at']))->format('d/m/Y H:i')?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
    <?php endif; ?>

<?php endif; ?>
<?php
$content = ob_get_clean();
include CC_ROOT . '/admin/layout.php';
