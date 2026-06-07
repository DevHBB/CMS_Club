<?php
/**
 * ClubCMS — Module Bénévoles
 */

// ── Détection AJAX/JSON/PDF avant tout output ─────────────────
$_isAjaxRequest = (
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
) || isset($_GET['poll']);

// Export PDF planning - doit être avant tout output HTML
$_pdfAction = ($segments[1] ?? '') === 'planning' && isset($_GET['pdf']);

if (!Auth::canAccessBenevole()) {
    if ($_isAjaxRequest) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Non autorisé']); exit;
    }
    Helpers::redirect(u('/login?return=/benevole'));
}

// ── Migrations tables bénévoles ───────────────────────────────
foreach ([
    "CREATE TABLE IF NOT EXISTS cc_benv_events (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(200) NOT NULL, description TEXT, location VARCHAR(200), date_start DATETIME NOT NULL, date_end DATETIME, max_volunteers INT DEFAULT 0, created_by INT NOT NULL, recurring VARCHAR(20) DEFAULT 'none', color VARCHAR(7) DEFAULT '#6366f1', created_at DATETIME DEFAULT CURRENT_TIMESTAMP)",
    "CREATE TABLE IF NOT EXISTS cc_benv_participations (id INT AUTO_INCREMENT PRIMARY KEY, event_id INT NOT NULL, user_id INT NOT NULL, status VARCHAR(20) DEFAULT 'confirmed', created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_evt_user (event_id,user_id))",
    "CREATE TABLE IF NOT EXISTS cc_benv_tasks (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(200) NOT NULL, description TEXT, status VARCHAR(20) DEFAULT 'todo', priority VARCHAR(10) DEFAULT 'normal', assigned_to INT DEFAULT NULL, due_date DATE DEFAULT NULL, created_by INT NOT NULL, recurring VARCHAR(20) DEFAULT 'none', color VARCHAR(7) DEFAULT '#6366f1', created_at DATETIME DEFAULT CURRENT_TIMESTAMP)",
    "CREATE TABLE IF NOT EXISTS cc_benv_task_volunteers (id INT AUTO_INCREMENT PRIMARY KEY, task_id INT NOT NULL, user_id INT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_tv (task_id,user_id))",
    "CREATE TABLE IF NOT EXISTS cc_benv_task_suggestions (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(200) NOT NULL, description TEXT, suggested_by INT NOT NULL, status VARCHAR(20) DEFAULT 'pending', reviewed_by INT DEFAULT NULL, review_note TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)",
    "CREATE TABLE IF NOT EXISTS cc_benv_channels (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL, slug VARCHAR(100) NOT NULL, open TINYINT(1) DEFAULT 1, created_by INT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY slug (slug))",
    "CREATE TABLE IF NOT EXISTS cc_benv_chat (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, message TEXT NOT NULL, channel VARCHAR(50) DEFAULT 'general', created_at DATETIME DEFAULT CURRENT_TIMESTAMP)",
    "CREATE TABLE IF NOT EXISTS cc_benv_chat_muted (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, muted_by INT NOT NULL, until DATETIME DEFAULT NULL, UNIQUE KEY uq_muted (user_id))",
    "CREATE TABLE IF NOT EXISTS cc_benv_alerts (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(200) NOT NULL, message TEXT, level VARCHAR(10) DEFAULT 'info', active TINYINT(1) DEFAULT 1, created_by INT NOT NULL, expires_at DATETIME DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)",
    "CREATE TABLE IF NOT EXISTS cc_benv_alerts_seen (id INT AUTO_INCREMENT PRIMARY KEY, alert_id INT NOT NULL, user_id INT NOT NULL, UNIQUE KEY uq_alert_user (alert_id,user_id))",
    "CREATE TABLE IF NOT EXISTS cc_benv_folders (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(200) NOT NULL, parent_id INT DEFAULT NULL, created_by INT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)",
    "CREATE TABLE IF NOT EXISTS cc_benv_docs (id INT AUTO_INCREMENT PRIMARY KEY, folder_id INT DEFAULT NULL, title VARCHAR(200) NOT NULL, type VARCHAR(10) DEFAULT 'note', content TEXT, filename VARCHAR(255) DEFAULT NULL, filesize INT DEFAULT NULL, created_by INT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)",
    "CREATE TABLE IF NOT EXISTS cc_benv_profiles (user_id INT PRIMARY KEY, skills TEXT, notes TEXT, blacklisted TINYINT(1) DEFAULT 0, blacklist_reason TEXT, can_add_tasks TINYINT(1) DEFAULT 0, can_upload TINYINT(1) DEFAULT 0, can_manage_planning TINYINT(1) DEFAULT 0, can_delete_notes TINYINT(1) DEFAULT 0, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)",
    "CREATE TABLE IF NOT EXISTS cc_benv_coach_access (coach_id INT PRIMARY KEY, can_access TINYINT(1) DEFAULT 0, see_blacklist TINYINT(1) DEFAULT 0)",
    "CREATE TABLE IF NOT EXISTS cc_benv_reminders_sent (id INT AUTO_INCREMENT PRIMARY KEY, event_id INT NOT NULL, user_id INT NOT NULL, sent_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_rem (event_id,user_id))",
    "ALTER TABLE cc_benv_tasks ADD COLUMN IF NOT EXISTS time_start TIME DEFAULT NULL",
    "ALTER TABLE cc_benv_tasks ADD COLUMN IF NOT EXISTS time_end TIME DEFAULT NULL",
    "ALTER TABLE cc_benv_tasks ADD COLUMN IF NOT EXISTS recurring_days VARCHAR(50) DEFAULT NULL",
    "ALTER TABLE cc_benv_tasks ADD COLUMN IF NOT EXISTS max_volunteers INT DEFAULT 1",
    "ALTER TABLE cc_benv_tasks ADD COLUMN IF NOT EXISTS volunteer_id INT DEFAULT NULL",
    "ALTER TABLE cc_benv_profiles ADD COLUMN IF NOT EXISTS can_add_tasks TINYINT(1) DEFAULT 0",
    "ALTER TABLE cc_benv_profiles ADD COLUMN IF NOT EXISTS can_upload TINYINT(1) DEFAULT 0",
    "ALTER TABLE cc_benv_profiles ADD COLUMN IF NOT EXISTS can_manage_planning TINYINT(1) DEFAULT 0",
    "ALTER TABLE cc_benv_profiles ADD COLUMN IF NOT EXISTS can_delete_notes TINYINT(1) DEFAULT 0",
    "ALTER TABLE cc_benv_docs ADD COLUMN IF NOT EXISTS visibility VARCHAR(20) DEFAULT 'all'",
    "ALTER TABLE cc_benv_docs ADD COLUMN IF NOT EXISTS can_download VARCHAR(20) DEFAULT 'all'",
    "ALTER TABLE cc_benv_docs ADD COLUMN IF NOT EXISTS mimetype VARCHAR(100) DEFAULT NULL",
    "ALTER TABLE cc_benv_docs ADD COLUMN IF NOT EXISTS allowed_users TEXT DEFAULT NULL",
    "CREATE TABLE IF NOT EXISTS cc_benv_slot_volunteers (id INT AUTO_INCREMENT PRIMARY KEY, slot_id INT NOT NULL, user_id INT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_sv (slot_id,user_id))",
] as $sql) { try { Database::run($sql); } catch(Exception $e) {} }

// Canaux par défaut
try {
    if ((int)Database::scalar("SELECT COUNT(*) FROM cc_benv_channels") === 0) {
        foreach ([['Général','general'],['Organisation','organisation'],['Logistique','logistique']] as [$n,$s]) {
            Database::run("INSERT IGNORE INTO cc_benv_channels (name,slug,open,created_by) VALUES (?,?,1,1)", [$n,$s]);
        }
    }
} catch(Exception $e) {}

// Traiter l'export PDF AVANT ob_start (évite la corruption par le buffer HTML)
if ($_pdfAction) {
    $month = preg_match('/^\d{4}-\d{2}$/', $_GET['month']??'') ? $_GET['month'] : date('Y-m');
    $mDt   = new DateTime($month.'-01');
    // Charger événements bénévoles + créneaux du planning site + tâches
    try {
        $pdfEvts = Database::all(
            "SELECT e.title, e.date_start, e.location, '' AS type_label,
             COUNT(CASE WHEN p.status='confirmed' THEN 1 END) AS nb_parts
             FROM cc_benv_events e
             LEFT JOIN cc_benv_participations p ON p.event_id=e.id
             WHERE DATE_FORMAT(e.date_start,'%Y-%m')=?
             GROUP BY e.id ORDER BY e.date_start",
            [$month]
        );
        // Créneaux du planning site
        $pdfSlots = Database::all(
            "SELECT s.title, s.date_start, '' AS location,
             COALESCE(pt.label,'') AS type_label,
             (SELECT COUNT(*) FROM cc_planning_bookings b WHERE b.slot_id=s.id AND b.status='confirmed') AS nb_parts
             FROM cc_planning_slots s
             LEFT JOIN cc_planning_types pt ON pt.slug=s.type
             WHERE DATE_FORMAT(s.date_start,'%Y-%m')=? AND s.published=1
             ORDER BY s.date_start",
            [$month]
        );
        // Fusionner et trier
        $pdfRows = array_merge($pdfEvts, $pdfSlots);
        usort($pdfRows, fn($a,$b)=>strtotime($a['date_start'])<=>strtotime($b['date_start']));
        // Tâches du mois
        $pdfTasks = Database::all(
            "SELECT t.title, t.due_date, t.status, t.priority,
             u.firstname AS v_firstname
             FROM cc_benv_tasks t LEFT JOIN cc_users u ON t.volunteer_id=u.id
             WHERE DATE_FORMAT(t.due_date,'%Y-%m')=? OR (t.due_date IS NULL AND t.status!='done')
             ORDER BY ISNULL(t.due_date), t.due_date",
            [$month]
        );
    } catch(Exception $e) { $pdfRows=[];$pdfTasks=[]; }

    require_once CC_ROOT.'/pdf/fpdf/fpdf.php';
    if (!class_exists('BenvPDF')) {
        class BenvPDF extends FPDF {
            public string $hdr = '';
            function Header(): void {
                $this->SetFillColor(99,102,241);
                $this->Rect(0,0,210,18,'F');
                $this->SetTextColor(255,255,255);
                $this->SetFont('Arial','B',12);
                $this->SetXY(8,4);
                $this->Cell(0,10,iconv('UTF-8','CP1252',$this->hdr),0,1,'L');
                $this->SetTextColor(0,0,0);
                $this->Ln(3);
            }
            function Footer(): void {
                $this->SetY(-12);
                $this->SetFont('Arial','I',7);
                $this->SetTextColor(148,163,184);
                $this->Cell(0,6,'Page '.$this->PageNo().' - Genere le '.date('d/m/Y'),0,0,'C');
            }
        }
    }

    // Traduire le mois en français
    $frMonths = ['January'=>'Janvier','February'=>'Février','March'=>'Mars','April'=>'Avril',
        'May'=>'Mai','June'=>'Juin','July'=>'Juillet','August'=>'Août',
        'September'=>'Septembre','October'=>'Octobre','November'=>'Novembre','December'=>'Décembre'];
    $monthLabel = strtr($mDt->format('F Y'), $frMonths);

    $pdf = new BenvPDF('P','mm','A4');
    $pdf->hdr = 'Planning benevoles - '.$monthLabel;
    $pdf->SetAutoPageBreak(true,16);
    $pdf->SetMargins(10,24,10);
    $pdf->AddPage();

    if (!empty($pdfRows)) {
        // Section événements + créneaux
        $pdf->SetFont('Arial','B',9);
        $pdf->SetFillColor(99,102,241); $pdf->SetTextColor(255,255,255);
        $pdf->Cell(0,7,iconv('UTF-8','CP1252','Evenements et creneaux'),0,1,'L',true);
        $pdf->Ln(2);

        $cols = [30,22,75,45,20];
        $headers = ['Date','Heure','Titre','Lieu','Benevoles'];
        $pdf->SetFont('Arial','B',8);
        $pdf->SetFillColor(241,245,249); $pdf->SetTextColor(71,85,105);
        foreach($headers as $ci=>$ch)
            $pdf->Cell($cols[$ci],7,iconv('UTF-8','CP1252',$ch),1,0,'C',true);
        $pdf->Ln();

        $pdf->SetFont('Arial','',8); $even=false;
        foreach($pdfRows as $row) {
            $dt = new DateTime($row['date_start']);
            $bg = $even ? [248,250,252] : [255,255,255];
            $pdf->SetFillColor($bg[0],$bg[1],$bg[2]); $pdf->SetTextColor(30,41,59);
            $pdf->Cell($cols[0],6,iconv('UTF-8','CP1252',$dt->format('d/m/Y')),0,0,'C',true);
            $pdf->Cell($cols[1],6,$dt->format('H:i'),0,0,'C',true);
            $pdf->Cell($cols[2],6,iconv('UTF-8','CP1252',mb_substr($row['title'],0,40)),0,0,'L',true);
            $pdf->Cell($cols[3],6,iconv('UTF-8','CP1252',mb_substr($row['location']??'',0,24)),0,0,'L',true);
            $pdf->Cell($cols[4],6,(string)($row['nb_parts']??0),0,1,'C',true);
            $even = !$even;
        }
        $pdf->Ln(4);
    } else {
        $pdf->SetFont('Arial','I',9); $pdf->SetTextColor(148,163,184);
        $pdf->Cell(0,8,iconv('UTF-8','CP1252','Aucun evenement ce mois-ci.'),0,1,'C');
        $pdf->Ln(2);
    }

    if (!empty($pdfTasks)) {
        $pdf->SetFont('Arial','B',9);
        $pdf->SetFillColor(99,102,241); $pdf->SetTextColor(255,255,255);
        $pdf->Cell(0,7,iconv('UTF-8','CP1252','Taches du mois'),0,1,'L',true);
        $pdf->Ln(2);

        $tCols = [30,90,40,32];
        $pdf->SetFont('Arial','B',8);
        $pdf->SetFillColor(241,245,249); $pdf->SetTextColor(71,85,105);
        foreach(['Echeance','Tache','Statut','Benevole'] as $ci=>$ch)
            $pdf->Cell($tCols[$ci],7,iconv('UTF-8','CP1252',$ch),1,0,'C',true);
        $pdf->Ln();

        $statusFr = ['todo'=>'A faire','inprogress'=>'En cours','done'=>'Termine'];
        $pdf->SetFont('Arial','',8); $even=false;
        foreach($pdfTasks as $tk) {
            $bg = $even ? [248,250,252] : [255,255,255];
            $pdf->SetFillColor($bg[0],$bg[1],$bg[2]); $pdf->SetTextColor(30,41,59);
            $pdf->Cell($tCols[0],6,$tk['due_date']?(new DateTime($tk['due_date']))->format('d/m/Y'):'-',0,0,'C',true);
            $pdf->Cell($tCols[1],6,iconv('UTF-8','CP1252',mb_substr($tk['title'],0,50)),0,0,'L',true);
            $pdf->Cell($tCols[2],6,iconv('UTF-8','CP1252',$statusFr[$tk['status']]??$tk['status']),0,0,'C',true);
            $pdf->Cell($tCols[3],6,iconv('UTF-8','CP1252',mb_substr($tk['v_firstname']??'-',0,18)),0,1,'C',true);
            $even = !$even;
        }
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="planning-benevoles-'.$month.'.pdf"');
    header('Cache-Control: no-cache');
    echo $pdf->Output('S');
    exit;
}


$action  = $segments[1] ?? 'dashboard';
$param   = $segments[2] ?? null;
$userId  = Auth::id();
$user    = Auth::user();
$isAdmin = Auth::isAdmin();
$isCoach = Auth::hasRole('coach');
$myRole  = Auth::role();

// Charger profil bénévole + droits
$myProfile = [];
try { $myProfile = Database::one("SELECT * FROM cc_benv_profiles WHERE user_id=?", [$userId]) ?: []; } catch(Exception $e) {}

$canAddTasks    = $isAdmin || $isCoach || ($myProfile['can_add_tasks']    ?? 0);
$canUpload      = $isAdmin || $isCoach || ($myProfile['can_upload']       ?? 0);
$canManagePlan  = $isAdmin || $isCoach || ($myProfile['can_manage_planning'] ?? 0);
$canDeleteNotes = $isAdmin             || ($myProfile['can_delete_notes'] ?? 0);

// Rappels J-1
try {
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $upcomingReminders = Database::all(
        "SELECT e.*,p.user_id FROM cc_benv_events e
         JOIN cc_benv_participations p ON p.event_id=e.id
         LEFT JOIN cc_benv_reminders_sent r ON r.event_id=e.id AND r.user_id=p.user_id
         WHERE p.user_id=? AND DATE(e.date_start)=? AND r.id IS NULL",
        [$userId,$tomorrow]
    );
    foreach ($upcomingReminders as $ev) {
        Database::run("INSERT IGNORE INTO cc_benv_reminders_sent (event_id,user_id) VALUES (?,?)", [$ev['id'],$userId]);
        Mailer::send($user['email'],$user['firstname'],"Rappel bénévole — ".$ev['title'],
            "<h2>⏰ Rappel demain !</h2><p>Vous participez à <strong>".htmlspecialchars($ev['title'])."</strong> le ".date('d/m/Y H:i',strtotime($ev['date_start'])).".</p>");
    }
} catch(Exception $e) {}

// ── Chat : AJAX poll ─────────────────────────────────────────
if ($action === 'chat' && isset($_GET['poll'])) {
    header('Content-Type: application/json');
    $since = (int)($_GET['since'] ?? 0);
    $ch    = Helpers::sanitize($_GET['channel'] ?? 'general');
    // Vérifier que le canal est ouvert
    try {
        $chan = Database::one("SELECT open FROM cc_benv_channels WHERE slug=?", [$ch]);
        if ($chan && !$chan['open'] && !$isAdmin) {
            echo json_encode(['messages'=>[],'closed'=>true]); exit;
        }
        $msgs = Database::all(
            "SELECT c.*,u.firstname,u.lastname FROM cc_benv_chat c
             LEFT JOIN cc_users u ON c.user_id=u.id
             WHERE c.channel=? AND c.id>? ORDER BY c.created_at ASC LIMIT 30",
            [$ch,$since]
        );
        echo json_encode(['messages'=>$msgs,'closed'=>false]);
    } catch(Exception $e) {
        echo json_encode(['messages'=>[],'error'=>$e->getMessage()]);
    }
    exit;
}

// ── Chat : envoi message (POST normal ou AJAX) ───────────────
if ($action === 'chat' && isset($_POST['send_chat']) && $_SERVER['REQUEST_METHOD']==='POST') {
    $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']);
    $msg = trim(Helpers::sanitize($_POST['message'] ?? ''));
    $ch  = Helpers::sanitize($_POST['channel'] ?? 'general');
    // Vérifier mute
    try {
        $muted = Database::one("SELECT until FROM cc_benv_chat_muted WHERE user_id=?", [$userId]);
        $isMutedNow = $muted && ($muted['until'] === null || strtotime($muted['until']) > time());
    } catch(Exception $e) { $isMutedNow = false; }
    if (!$isMutedNow && $msg) {
        try { Database::run("INSERT INTO cc_benv_chat (user_id,message,channel) VALUES (?,?,?)", [$userId,$msg,$ch]); } catch(Exception $e) {}
    }
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok'=>!$isMutedNow]);
        exit;
    }
    // POST normal : redirect pour éviter double-submit
    Helpers::redirect(u('/benevole/chat?channel='.urlencode($ch)));
}

// ── Bénévole sur un créneau site ─────────────────────────────
if (isset($_POST['join_slot_volunteer'])) {
    $sid = (int)$_POST['slot_id'];
    try { Database::run("INSERT IGNORE INTO cc_benv_slot_volunteers (slot_id,user_id) VALUES (?,?)", [$sid,$userId]); } catch(Exception $e) {}
    Helpers::redirect(u('/benevole/planning'));
}
if (isset($_POST['leave_slot_volunteer'])) {
    $sid = (int)$_POST['slot_id'];
    try { Database::run("DELETE FROM cc_benv_slot_volunteers WHERE slot_id=? AND user_id=?", [$sid,$userId]); } catch(Exception $e) {}
    Helpers::redirect(u('/benevole/planning'));
}

// ── Participation événement ───────────────────────────────────
if (isset($_POST['join_event'])) {
    $eid = (int)$_POST['event_id'];
    try {
        Database::run("INSERT INTO cc_benv_participations (event_id,user_id) VALUES (?,?) ON DUPLICATE KEY UPDATE status='confirmed'", [$eid,$userId]);
        $ev = Database::one("SELECT * FROM cc_benv_events WHERE id=?", [$eid]);
        if ($ev) {
            $admins = Database::all("SELECT email,firstname FROM cc_users WHERE role IN ('admin','superadmin')");
            foreach ($admins as $a) Mailer::send($a['email'],$a['firstname'],"Bénévole inscrit — ".$ev['title'],
                "<h2>🙋 Nouveau bénévole</h2><p><strong>".htmlspecialchars($user['firstname'].' '.$user['lastname'])."</strong> participe à <strong>".htmlspecialchars($ev['title'])."</strong>.</p>");
        }
    } catch(Exception $e) {}
    Helpers::redirect(u('/benevole/planning'));
}

if (isset($_POST['leave_event'])) {
    try { Database::run("UPDATE cc_benv_participations SET status='cancelled' WHERE event_id=? AND user_id=?", [(int)$_POST['event_id'],$userId]); } catch(Exception $e) {}
    Helpers::redirect(u('/benevole/planning'));
}

// ── Tâches ───────────────────────────────────────────────────
if (isset($_POST['update_task_status'])) {
    $tid = (int)$_POST['task_id'];
    $st  = in_array($_POST['status']??'',['todo','inprogress','done']) ? $_POST['status'] : 'todo';
    try { Database::run("UPDATE cc_benv_tasks SET status=? WHERE id=?", [$st,$tid]); } catch(Exception $e) {}
    if (Helpers::isAjax()) { ob_end_clean(); header('Content-Type: application/json'); echo json_encode(['ok'=>true]); exit; }
    Helpers::redirect(u('/benevole/taches'));
}

// ── Créer tâche (bénévoles autorisés) ─────────────────────────
if (isset($_POST['create_task']) && $canAddTasks) {
    $title    = Helpers::sanitize($_POST['title'] ?? '');
    $desc     = Helpers::sanitize($_POST['description'] ?? '');
    $due      = Helpers::sanitize($_POST['due_date'] ?? '') ?: null;
    $ts       = Helpers::sanitize($_POST['time_start'] ?? '') ?: null;
    $te       = Helpers::sanitize($_POST['time_end'] ?? '') ?: null;
    $rec      = in_array($_POST['recurring']??'',['none','daily','weekly','monthly']) ? $_POST['recurring'] : 'none';
    $recDays  = isset($_POST['recurring_days']) ? implode(',',array_filter(array_map('intval',$_POST['recurring_days']))) : null;
    $prio     = in_array($_POST['priority']??'',['low','normal','high']) ? $_POST['priority'] : 'normal';
    $color    = preg_match('/^#[0-9a-fA-F]{6}$/',$_POST['color']??'') ? $_POST['color'] : '#6366f1';
    $assignTo = (int)($_POST['assigned_to']??0) ?: null;
    $maxVol   = max(1, (int)($_POST['max_volunteers']??1));
    if ($title) {
        Database::run("INSERT INTO cc_benv_tasks (title,description,status,priority,due_date,time_start,time_end,recurring,recurring_days,assigned_to,color,created_by,max_volunteers) VALUES (?,?,'todo',?,?,?,?,?,?,?,?,?,?)",
            [$title,$desc,$prio,$due,$ts,$te,$rec,$recDays,$assignTo,$color,$userId,$maxVol]);
    }
    Helpers::redirect(u('/benevole/taches'));
}

// ── Alertes dismiss ───────────────────────────────────────────
if (isset($_POST['dismiss_alert'])) {
    $aid = (int)$_POST['alert_id'];
    try { Database::run("INSERT IGNORE INTO cc_benv_alerts_seen (alert_id,user_id) VALUES (?,?)", [$aid,$userId]); } catch(Exception $e) {}
    if (Helpers::isAjax()) { ob_end_clean(); header('Content-Type: application/json'); echo json_encode(['ok'=>true]); exit; }
    Helpers::redirect(u('/benevole'));
}

// ── Profil bénévole ───────────────────────────────────────────
if (isset($_POST['save_benv_profile'])) {
    $skills = Helpers::sanitize($_POST['skills'] ?? '');
    try { Database::run("INSERT INTO cc_benv_profiles (user_id,skills) VALUES (?,?) ON DUPLICATE KEY UPDATE skills=VALUES(skills)", [$userId,$skills]); } catch(Exception $e) {}
    Helpers::redirect(u('/benevole/annuaire'));
}

// ── Upload fichier ───────────────────────────────────────────
if (isset($_POST['upload_doc'])) {
    $fid      = (int)($_POST['folder_id'] ?? 0) ?: null;
    $vis      = in_array($_POST['visibility']??'',['all','coach','admin'])?$_POST['visibility']:'all';
    $dl       = in_array($_POST['can_download']??'',['all','coach','admin'])?$_POST['can_download']:'all';
    if (!empty($_FILES['doc_file']['name']) && $_FILES['doc_file']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['pdf','doc','docx','xls','xlsx','ppt','pptx','odt','ods','odp',
                    'jpg','jpeg','png','gif','webp','svg','mp4','zip','txt','csv'];
        $origName = basename($_FILES['doc_file']['name']);
        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $title    = Helpers::sanitize($_POST['doc_title'] ?? '') ?: pathinfo($origName, PATHINFO_FILENAME);
        if (in_array($ext, $allowed)) {
            $safeFile = uniqid('benv_').'.'.$ext;
            $destDir  = CC_ROOT.'/uploads/benevoles/';
            if (!is_dir($destDir)) mkdir($destDir, 0755, true);
            if (move_uploaded_file($_FILES['doc_file']['tmp_name'], $destDir.$safeFile)) {
                $mime = mime_content_type($destDir.$safeFile) ?: 'application/octet-stream';
                try {
                    Database::run(
                        "INSERT INTO cc_benv_docs (folder_id,title,type,filename,filesize,mimetype,visibility,can_download,allowed_users,created_by) VALUES (?,?,'file',?,?,?,?,?,?,?)",
                        [$fid,$title,$safeFile,(int)$_FILES['doc_file']['size'],$mime,$vis,$dl,$allowedUsers,$userId]
                    );
                } catch(Exception $e) {}
            }
        }
    }
    Helpers::redirect(u('/benevole/documents'.($fid?'?folder='.$fid:'')));
}

// ── Téléchargement fichier ────────────────────────────────────
if ($action==='documents' && isset($_GET['download'])) {
    $did  = (int)$_GET['download'];
    try { $doc = Database::one("SELECT * FROM cc_benv_docs WHERE id=?", [$did]); } catch(Exception $e) { $doc=null; }
    if ($doc && $doc['type']==='file' && $doc['filename']) {
        // Vérifier droits téléchargement
        $dlPerm = $doc['can_download'] ?? 'all';
        $allowedUids = json_decode($doc['allowed_users']??'[]',true)??[];
        $canDl  = ($dlPerm==='all') ||
                  ($dlPerm==='coach' && ($isAdmin||$isCoach)) ||
                  ($dlPerm==='admin' && $isAdmin) ||
                  ($dlPerm==='specific' && ($isAdmin||$isCoach||in_array($userId,$allowedUids)));
        if ($canDl) {
            $path = CC_ROOT.'/uploads/benevoles/'.$doc['filename'];
            if (file_exists($path)) {
                $ext  = strtolower(pathinfo($doc['filename'], PATHINFO_EXTENSION));
                $name = preg_replace('/[^a-zA-Z0-9._-]/', '_', $doc['title']).'.'.$ext;
                header('Content-Type: '.($doc['mimetype']??'application/octet-stream'));
                header('Content-Disposition: attachment; filename="'.$name.'"');
                header('Content-Length: '.filesize($path));
                readfile($path);
                exit;
            }
        }
    }
    Helpers::redirect(u('/benevole/documents'));
}

// ── Documents ─────────────────────────────────────────────────
if (isset($_POST['create_note'])) {
    $title   = Helpers::sanitize($_POST['doc_title'] ?? '');
    $content = Helpers::sanitize($_POST['doc_content'] ?? '');
    $fid     = (int)($_POST['folder_id'] ?? 0) ?: null;
    if ($title) try { Database::run("INSERT INTO cc_benv_docs (folder_id,title,type,content,created_by) VALUES (?,?,'note',?,?)", [$fid,$title,$content,$userId]); } catch(Exception $e) {}
    Helpers::redirect(u('/benevole/documents'.($fid?'?folder='.$fid:'')));
}

if (isset($_POST['update_note'])) {
    $did = (int)$_POST['doc_id'];
    $fid = (int)($_POST['folder_id'] ?? 0) ?: null;
    try { Database::run("UPDATE cc_benv_docs SET title=?,content=? WHERE id=?",
        [Helpers::sanitize($_POST['doc_title']??''), Helpers::sanitize($_POST['doc_content']??''), $did]); } catch(Exception $e) {}
    Helpers::redirect(u('/benevole/documents'.($fid?'?folder='.$fid:'')));
}

if (isset($_POST['delete_doc'])) {
    $did = (int)$_POST['doc_id'];
    $fid = (int)($_POST['folder_id'] ?? 0);
    if ($canDeleteNotes || $isAdmin || $isCoach) {
        try {
            $docToDel = Database::one("SELECT * FROM cc_benv_docs WHERE id=?", [$did]);
            if ($docToDel && $docToDel['type']==='file' && $docToDel['filename']) {
                $filePath = CC_ROOT.'/uploads/benevoles/'.$docToDel['filename'];
                if (file_exists($filePath)) @unlink($filePath);
            }
            Database::run("DELETE FROM cc_benv_docs WHERE id=?", [$did]);
        } catch(Exception $e) {}
    }
    Helpers::redirect(u('/benevole/documents'.($fid?'?folder='.$fid:'')));
}

if (isset($_POST['create_folder'])) {
    $folderWho  = Config::get('benv_folder_who','admin');
    $folderSpec = json_decode(Config::get('benv_folder_specific','[]'),true)??[];
    $canCreateFolder = $isAdmin || $isCoach
        || ($folderWho==='all')
        || ($folderWho==='coach' && ($isAdmin||$isCoach))
        || ($folderWho==='specific' && in_array($userId,$folderSpec));
    if (!$canCreateFolder) Helpers::redirect(u('/benevole/documents'));
    $name   = Helpers::sanitize($_POST['folder_name'] ?? '');
    $parent = (int)($_POST['parent_id'] ?? 0) ?: null;
    if ($name) try { Database::run("INSERT INTO cc_benv_folders (name,parent_id,created_by) VALUES (?,?,?)", [$name,$parent,$userId]); } catch(Exception $e) {}
    Helpers::redirect(u('/benevole/documents'));
}

// ── Données communes ──────────────────────────────────────────
$alerts = [];
try {
    $alerts = Database::all(
        "SELECT a.* FROM cc_benv_alerts a LEFT JOIN cc_benv_alerts_seen s ON s.alert_id=a.id AND s.user_id=?
         WHERE a.active=1 AND s.id IS NULL AND (a.expires_at IS NULL OR a.expires_at>NOW())
         ORDER BY a.level='urgent' DESC, a.created_at DESC",
        [$userId]
    );
} catch(Exception $e) {}

$channels = [];
try { $channels = Database::all("SELECT * FROM cc_benv_channels ORDER BY created_at"); } catch(Exception $e) {}
if (empty($channels)) $channels = [['id'=>0,'name'=>'Général','slug'=>'general','open'=>1]];

// Vérifier accès section vérification carte
$verifWho  = Config::get('benv_verif_carte_who', 'coach');
$verifSpec = json_decode(Config::get('benv_verif_carte_specific', '[]'), true) ?? [];
$canVerifCarte = $isAdmin || $isCoach
    || ($verifWho === 'all')
    || ($verifWho === 'specific' && in_array($userId, $verifSpec));

$pages = [
    'dashboard'    => ['🏠','Tableau de bord','/benevole'],
    'planning'     => ['📅','Planning',       '/benevole/planning'],
    'taches'       => ['✅','Tâches',         '/benevole/taches'],
    'chat'         => ['💬','Chat',           '/benevole/chat'],
    'documents'    => ['📁','Documents',      '/benevole/documents'],
    'annuaire'     => ['👥','Annuaire',        '/benevole/annuaire'],
];
if ($canVerifCarte) {
    $pages['verif'] = ['🔍','Vérif. carte', '/benevole/verif'];
}
$initials = strtoupper(substr($user['firstname']??'?',0,1).substr($user['lastname']??'',0,1));
$pageTitle = 'Espace Bénévoles';

// ── Vérification QR (AJAX depuis le portail bénévole) ─────────
if (isset($_GET['benv_verify_qr']) && $canVerifCarte) {
    $uid  = (int)($_GET['id']   ?? 0);
    $hash = trim($_GET['hash']  ?? '');
    header('Content-Type: application/json');
    if ($uid && $hash) {
        $member = Database::one("SELECT id,firstname,lastname,member_card_hash FROM cc_users WHERE id=?", [$uid]);
        if ($member && $member['member_card_hash'] === $hash) {
            echo json_encode(['valid'=>true,'name'=>$member['firstname'].' '.$member['lastname']]);
        } else {
            echo json_encode(['valid'=>false]);
        }
    } else {
        echo json_encode(['valid'=>false]);
    }
    exit;
}

// ── Vérification PDF ──────────────────────────────────────────
$cardVerifyResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_card_pdf']) && $canVerifCarte) {
    if (empty($_FILES['card_pdf']['tmp_name']) || $_FILES['card_pdf']['size'] === 0) {
        $cardVerifyResult = ['type'=>'warning','msg'=>'⚠️ Aucun fichier reçu.'];
    } else {
        $pdfContent = @file_get_contents($_FILES['card_pdf']['tmp_name']);
        if (!$pdfContent || substr($pdfContent,0,4) !== '%PDF') {
            $cardVerifyResult = ['type'=>'error','msg'=>"❌ Fichier invalide — ce n'est pas un PDF."];
        } else {
            $hashFromPdf = null; $memberIdFromPdf = null;
            if (preg_match('/CLUBCMS-HASH:([a-f0-9]{12})/i', $pdfContent, $mh)) $hashFromPdf = strtolower($mh[1]);
            if (preg_match('/clubcms-verify:[a-f0-9]+:member:([0-9]+)/i', $pdfContent, $mi)) $memberIdFromPdf = (int)$mi[1];
            if (!$hashFromPdf || !$memberIdFromPdf) {
                $cardVerifyResult = ['type'=>'error','msg'=>"❌ <strong>Document non authentique</strong> — Ce PDF n'a pas été généré par ce site."];
            } else {
                $member = Database::one("SELECT id,firstname,lastname,member_card_hash FROM cc_users WHERE id=?",[$memberIdFromPdf]);
                if (!$member) {
                    $cardVerifyResult = ['type'=>'error','msg'=>'❌ <strong>Membre introuvable</strong>.'];
                } elseif (!$member['member_card_hash']) {
                    $cardVerifyResult = ['type'=>'error','msg'=>'❌ <strong>Carte jamais générée</strong>.'];
                } elseif (strtolower($member['member_card_hash']) !== $hashFromPdf) {
                    $cardVerifyResult = ['type'=>'error','msg'=>"❌ <strong>Carte INVALIDE</strong> — La signature ne correspond pas. Document falsifié."];
                } else {
                    $cardVerifyResult = ['type'=>'success','msg'=>'✅ <strong>Carte AUTHENTIQUE</strong> — '.Helpers::e($member['firstname'].' '.$member['lastname']).' (membre #'.$memberIdFromPdf.').'];
                }
            }
        }
    }
}

ob_start();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>🤝 Espace Bénévoles — <?=Helpers::e(Config::get('club_name','Club'))?></title>
<style>
:root{--bp:#6366f1;--bdk:#0f172a;--bbg:#f8fafc;--bsw:220px}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:var(--bbg);color:#1e293b;min-height:100vh}
.bl{display:flex;min-height:100vh}
/* Sidebar */
.bsb{width:var(--bsw);background:var(--bdk);color:#e2e8f0;position:fixed;top:0;left:0;bottom:0;display:flex;flex-direction:column;z-index:100;overflow-y:auto}
.bbrand{padding:1.1rem .875rem;border-bottom:1px solid rgba(255,255,255,.08);display:flex;align-items:center;gap:.5rem}
.bbrand-i{width:34px;height:34px;background:var(--bp);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0}
.bnav{padding:.5rem;flex:1}
.bns{font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:rgba(255,255,255,.3);padding:.625rem .5rem .2rem}
.bni{display:flex;align-items:center;gap:.625rem;padding:.5rem .75rem;border-radius:8px;color:rgba(255,255,255,.65);text-decoration:none;font-size:.83rem;transition:all .15s;margin-bottom:.1rem}
.bni:hover,.bni.active{background:rgba(255,255,255,.1);color:#fff}
.bni.active{background:var(--bp)}
.bback{padding:.75rem 1rem;border-top:1px solid rgba(255,255,255,.08)}
.bback a{color:rgba(255,255,255,.35);font-size:.75rem;text-decoration:none}
/* Main */
.bmain{margin-left:var(--bsw);flex:1;display:flex;flex-direction:column}
.btop{background:#fff;border-bottom:1px solid #e2e8f0;padding:.875rem 1.5rem;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50}
.bcontent{padding:1.5rem;flex:1}
/* Card */
.bcard{background:#fff;border-radius:14px;border:1px solid #e2e8f0;overflow:hidden;margin-bottom:1.25rem}
.bcard-h{padding:.875rem 1.25rem;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;gap:.75rem}
.bcard-h h2{font-size:.95rem;font-weight:700}
.bcard-b{padding:1.25rem}
/* Grids */
.bg2{display:grid;grid-template-columns:1fr 1fr;gap:1.25rem}
.bg3{display:grid;grid-template-columns:repeat(3,1fr);gap:1rem}
.bg4{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem}
/* Stats */
.bstat{background:#fff;border-radius:12px;border:1px solid #e2e8f0;padding:1.25rem;text-align:center}
.bstat-n{font-size:2rem;font-weight:800;color:var(--bp);line-height:1}
.bstat-l{font-size:.78rem;color:#64748b;margin-top:.35rem}
/* Alerts */
.balert{border-radius:10px;padding:.875rem 1.25rem;margin-bottom:.75rem;display:flex;align-items:flex-start;justify-content:space-between;gap:.75rem}
.balert.info{background:#eff6ff;border:1.5px solid #bfdbfe;color:#1e40af}
.balert.warning{background:#fef3c7;border:1.5px solid #fde68a;color:#92400e}
.balert.urgent{background:#fee2e2;border:1.5px solid #fecaca;color:#991b1b;animation:bpulse 1.5s ease-in-out infinite}
@keyframes bpulse{0%,100%{border-color:#fecaca}50%{border-color:#ef4444}}
/* Kanban */
.kc{background:#f8fafc;border-radius:12px;padding:.875rem;min-height:160px}
.kch{font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#64748b;margin-bottom:.75rem;display:flex;align-items:center;gap:.4rem}
.kcrd{background:#fff;border-radius:8px;padding:.75rem;margin-bottom:.5rem;border:1.5px solid #e2e8f0;transition:all .15s}
.kcrd:hover{border-color:var(--bp)}
.kcrd-t{font-size:.875rem;font-weight:600;margin-bottom:.3rem}
.kcrd-m{font-size:.72rem;color:#94a3b8;display:flex;gap:.5rem;flex-wrap:wrap}
/* Chat */
.chatbox{height:350px;overflow-y:auto;display:flex;flex-direction:column;gap:.5rem;padding:.75rem;background:#f8fafc;border-radius:10px;margin-bottom:.75rem}
.cmsg{display:flex;gap:.5rem;align-items:flex-end}
.cmsg.mine{flex-direction:row-reverse}
.cmsg>div{max-width:75%}
.cbub{padding:.5rem .875rem;border-radius:14px;font-size:.875rem;line-height:1.5;word-break:break-word;display:block;white-space:normal;width:fit-content;min-width:3rem;max-width:100%}
.cmsg.other .cbub{background:#fff;border:1px solid #e2e8f0;border-bottom-left-radius:4px}
.cmsg.mine .cbub{background:var(--bp);color:#fff;border-bottom-right-radius:4px}
.cmeta{font-size:.62rem;color:#94a3b8;margin-bottom:.1rem}
/* Events */
.bev{display:flex;gap:.875rem;padding:.875rem;border-radius:10px;border:1.5px solid #e2e8f0;background:#fff;margin-bottom:.625rem;align-items:flex-start}
.bev-d{min-width:50px;height:50px;border-radius:10px;background:var(--bp);color:#fff;display:flex;flex-direction:column;align-items:center;justify-content:center;flex-shrink:0}
.bev-d .dy{font-size:1.2rem;font-weight:800;line-height:1}
.bev-d .mo{font-size:.55rem;text-transform:uppercase;opacity:.85}
/* Docs */
.doci{display:flex;align-items:center;gap:.625rem;padding:.625rem .875rem;border-radius:8px;border:1.5px solid #e2e8f0;background:#fff;margin-bottom:.4rem}
/* Badges */
.bp-done{background:#dcfce7;color:#16a34a}
.bp-inprog{background:#fef3c7;color:#d97706}
.bp-todo{background:#f1f5f9;color:#64748b}
/* Avatar */
.bav{border-radius:50%;background:var(--bp);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;flex-shrink:0}
/* Inputs */
.bi{width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:.55rem .875rem;font-size:.875rem;font-family:inherit;outline:none;transition:border-color .15s}
.bi:focus{border-color:var(--bp)}
.bbt{display:inline-flex;align-items:center;gap:.4rem;padding:.5rem 1rem;border-radius:8px;font-size:.85rem;font-weight:600;cursor:pointer;border:none;transition:all .15s;text-decoration:none;font-family:inherit}
.bbt-p{background:var(--bp);color:#fff}.bbt-p:hover{background:#4f46e5;color:#fff}
.bbt-g{background:#f1f5f9;color:#475569;border:1.5px solid #e2e8f0}.bbt-g:hover{background:#e2e8f0}
.bbt-d{background:#fee2e2;color:#dc2626}.bbt-d:hover{background:#fecaca}
.bbt-s{background:#dcfce7;color:#16a34a}.bbt-s:hover{background:#bbf7d0}
/* Members */
.bmem{background:#fff;border-radius:12px;border:1.5px solid #e2e8f0;padding:1rem;text-align:center;transition:border-color .15s}
.bmem:hover{border-color:var(--bp)}
/* Tags */
.btag{display:inline-block;padding:.15rem .5rem;border-radius:99px;font-size:.7rem;font-weight:700}
@media(max-width:768px){.bsb{transform:translateX(-100%);transition:transform .25s}.bsb.open{transform:translateX(0)}.bmain{margin-left:0}.bg2,.bg3,.bg4{grid-template-columns:1fr}}
</style>

<?php
// Pour les requêtes AJAX, on ne doit pas arriver ici
if ($_isAjaxRequest) { header('Content-Type: application/json'); echo json_encode(['error'=>'handler non trouvé']); exit; }

$benevoles = [];
try { $benevoles = Database::all("SELECT u.*,p.skills,p.blacklisted FROM cc_users u LEFT JOIN cc_benv_profiles p ON p.user_id=u.id WHERE u.role='benevole' AND u.status='active' ORDER BY u.firstname"); } catch(Exception $e) {}
?>

<div class="bl">
<!-- SIDEBAR -->
<aside class="bsb" id="bsb">
  <div class="bbrand">
    <div class="bbrand-i">🤝</div>
    <div>
      <div style="font-weight:700;font-size:.875rem">Bénévoles</div>
      <div style="font-size:.68rem;opacity:.45"><?=Helpers::e(Config::get('club_name','Club'))?></div>
    </div>
  </div>
  <nav class="bnav">
    <div class="bns">Navigation</div>
    <?php foreach($pages as $k=>[$ico,$lbl,$url]): ?>
    <a href="<?=u($url)?>" class="bni <?=$action===$k||($k==='dashboard'&&$action==='index')?'active':''?>">
      <?=$ico?> <?=$lbl?>
    </a>
    <?php endforeach; ?>
    <?php if($isAdmin||$isCoach): ?>
    <div class="bns">Admin</div>
    <a href="<?=u('/admin/benevole')?>" class="bni">⚙️ Gérer les bénévoles</a>
    <?php endif; ?>
  </nav>
  <div class="bback"><a href="<?=u('/')?>">← Retour au site</a></div>
</aside>

<!-- MAIN -->
<main class="bmain">
  <div class="btop">
    <div style="display:flex;align-items:center;gap:.75rem">
      <button onclick="document.getElementById('bsb').classList.toggle('open')" style="background:none;border:none;cursor:pointer;font-size:1.3rem">☰</button>
      <strong style="font-size:1rem"><?php foreach($pages as $k=>[$ico,$lbl,$url]): if($action===$k||($k==='dashboard'&&$action==='index')): echo $ico.' '.$lbl; endif; endforeach; ?></strong>
    </div>
    <div style="display:flex;align-items:center;gap:.625rem;font-size:.82rem;color:#64748b">
      <div class="bav" style="width:32px;height:32px;font-size:.8rem"><?=$initials?></div>
      <?=Helpers::e($user['firstname']??'')?>
    </div>
  </div>

  <div class="bcontent">
    <!-- Alertes -->
    <?php foreach($alerts as $al): ?>
    <div class="balert <?=Helpers::e($al['level'])?>" id="al-<?=$al['id']?>">
      <div><strong><?=Helpers::e($al['title'])?></strong><?php if($al['message']): ?><div style="font-size:.82rem;margin-top:.2rem"><?=Helpers::e($al['message'])?></div><?php endif; ?></div>
      <button onclick="dismissAlert(<?=$al['id']?>)" style="background:none;border:none;cursor:pointer;opacity:.5;font-size:1.1rem">&times;</button>
    </div>
    <?php endforeach; ?>

<?php
// ══ DASHBOARD ═══════════════════════════════════════════════
if ($action==='dashboard'||$action==='index'):
  try {
    $stats = [
      (int)Database::scalar("SELECT COUNT(*) FROM cc_benv_events WHERE date_start>=NOW()"),
      (int)Database::scalar("SELECT COUNT(*) FROM cc_benv_tasks WHERE status!='done'"),
      (int)Database::scalar("SELECT COUNT(*) FROM cc_users WHERE role='benevole'"),
      (int)Database::scalar("SELECT COUNT(*) FROM cc_benv_docs"),
    ];
    $nextEvts = Database::all("SELECT e.*,COUNT(p.id) AS nb_parts,MAX(CASE WHEN p.user_id=? AND p.status='confirmed' THEN 1 ELSE 0 END) AS i_join FROM cc_benv_events e LEFT JOIN cc_benv_participations p ON p.event_id=e.id WHERE e.date_start>=NOW() GROUP BY e.id ORDER BY e.date_start LIMIT 3",[$userId]);
    $myTasks  = Database::all("SELECT * FROM cc_benv_tasks WHERE assigned_to=? AND status!='done' ORDER BY due_date LIMIT 5",[$userId]);
    $lastChat = Database::all("SELECT c.*,u.firstname,u.lastname FROM cc_benv_chat c LEFT JOIN cc_users u ON c.user_id=u.id ORDER BY c.created_at DESC LIMIT 4");
  } catch(Exception $e) { $stats=[0,0,0,0];$nextEvts=[];$myTasks=[];$lastChat=[]; }
?>
<div class="bg4" style="margin-bottom:1.25rem">
  <?php foreach([['📅',$stats[0],'Événements'],['✅',$stats[1],'Tâches actives'],['👥',$stats[2],'Bénévoles'],['📁',$stats[3],'Documents']] as [$i,$n,$l]): ?>
  <div class="bstat"><div style="font-size:1.4rem"><?=$i?></div><div class="bstat-n"><?=$n?></div><div class="bstat-l"><?=$l?></div></div>
  <?php endforeach; ?>
</div>
<div class="bg2">
  <div class="bcard">
    <div class="bcard-h"><h2>📅 Prochains événements</h2><a href="<?=u('/benevole/planning')?>" class="bbt bbt-g" style="font-size:.75rem;padding:.3rem .6rem">Tout →</a></div>
    <div class="bcard-b">
      <?php if(empty($nextEvts)): ?><p style="color:#94a3b8;text-align:center;padding:.75rem">Aucun événement</p>
      <?php else: foreach($nextEvts as $ev): $dt=new DateTime($ev['date_start']); ?>
      <div style="display:flex;gap:.75rem;align-items:center;margin-bottom:.75rem;padding-bottom:.75rem;border-bottom:1px solid #f1f5f9">
        <div class="bav" style="width:44px;height:44px;border-radius:10px;flex-direction:column;font-weight:800;background:<?=Helpers::e($ev['color'])?>">
          <span style="font-size:1.1rem;line-height:1"><?=$dt->format('d')?></span>
          <span style="font-size:.55rem;text-transform:uppercase"><?=$dt->format('M')?></span>
        </div>
        <div style="flex:1"><div style="font-weight:600;font-size:.875rem"><?=Helpers::e($ev['title'])?></div><div style="font-size:.72rem;color:#64748b"><?=$dt->format('H:i')?></div></div>
        <?php if($ev['i_join']): ?><span class="btag" style="background:#dcfce7;color:#16a34a">✓</span><?php endif; ?>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
  <div class="bcard">
    <div class="bcard-h"><h2>💬 Derniers messages</h2><a href="<?=u('/benevole/chat')?>" class="bbt bbt-g" style="font-size:.75rem;padding:.3rem .6rem">Chat →</a></div>
    <div class="bcard-b">
      <?php if(empty($lastChat)): ?><p style="color:#94a3b8;text-align:center;padding:.75rem">Aucun message</p>
      <?php else: foreach(array_reverse($lastChat) as $cm): ?>
      <div style="display:flex;gap:.5rem;margin-bottom:.625rem">
        <div class="bav" style="width:26px;height:26px;font-size:.65rem"><?=strtoupper(substr($cm['firstname'],0,1).substr($cm['lastname'],0,1))?></div>
        <div><div style="font-size:.7rem;color:#94a3b8"><?=Helpers::e($cm['firstname'])?></div><div style="font-size:.875rem"><?=Helpers::e(Helpers::excerpt($cm['message'],60))?></div></div>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>

<?php
// ══ PLANNING ════════════════════════════════════════════════
elseif($action==='planning'):
  $month  = preg_match('/^\d{4}-\d{2}$/',$_GET['month']??'') ? $_GET['month'] : date('Y-m');
  $mDt    = new DateTime($month.'-01');
  $prevM  = (clone $mDt)->modify('-1 month')->format('Y-m');
  $nextM  = (clone $mDt)->modify('+1 month')->format('Y-m');
  $planTasks = [];
  try {
    // Événements bénévoles du mois
    $benvEvts = Database::all(
        "SELECT e.id, e.title, e.date_start, e.date_end, e.location, e.color,
         e.max_volunteers, 'benv' AS source,
         COUNT(CASE WHEN p.status='confirmed' THEN 1 END) AS nb_parts,
         MAX(CASE WHEN p.user_id=? AND p.status='confirmed' THEN 1 ELSE 0 END) AS i_join
         FROM cc_benv_events e LEFT JOIN cc_benv_participations p ON p.event_id=e.id
         WHERE DATE_FORMAT(e.date_start,'%Y-%m')=? GROUP BY e.id ORDER BY e.date_start",
        [$userId,$month]
    );
    // Créneaux du planning site
    $siteSlots = Database::all(
        "SELECT s.id, s.title, s.date_start, s.date_end, '' AS location, s.color,
         s.max_participants AS max_volunteers, 'site' AS source,
         (SELECT COUNT(*) FROM cc_planning_bookings b WHERE b.slot_id=s.id AND b.status='confirmed') AS nb_parts,
         0 AS i_join,
         (SELECT COUNT(*) FROM cc_benv_slot_volunteers sv WHERE sv.slot_id=s.id) AS nb_volunteers,
         MAX(CASE WHEN sv2.user_id=? THEN 1 ELSE 0 END) AS i_volunteer
         FROM cc_planning_slots s
         LEFT JOIN cc_benv_slot_volunteers sv2 ON sv2.slot_id=s.id AND sv2.user_id=?
         WHERE DATE_FORMAT(s.date_start,'%Y-%m')=? AND s.published=1
         GROUP BY s.id ORDER BY s.date_start",
        [$userId, $userId, $month]
    );
    $events = array_merge($benvEvts, $siteSlots);
    usort($events, fn($a,$b)=>strtotime($a['date_start'])<=>strtotime($b['date_start']));

    // Si mois courant et aucun event, montrer les prochains
    if (empty($events) && $month === date('Y-m')) {
        $benvEvts = Database::all(
            "SELECT e.id,e.title,e.date_start,e.date_end,e.location,e.color,
             e.max_volunteers,'benv' AS source,
             COUNT(CASE WHEN p.status='confirmed' THEN 1 END) AS nb_parts,
             MAX(CASE WHEN p.user_id=? AND p.status='confirmed' THEN 1 ELSE 0 END) AS i_join
             FROM cc_benv_events e LEFT JOIN cc_benv_participations p ON p.event_id=e.id
             WHERE e.date_start>=NOW() GROUP BY e.id ORDER BY e.date_start LIMIT 5",
            [$userId]
        );
        $siteSlots = Database::all(
            "SELECT s.id,s.title,s.date_start,s.date_end,'' AS location,s.color,
             s.max_participants AS max_volunteers,'site' AS source,
             (SELECT COUNT(*) FROM cc_planning_bookings b WHERE b.slot_id=s.id AND b.status='confirmed') AS nb_parts,
             0 AS i_join,
             (SELECT COUNT(*) FROM cc_benv_slot_volunteers sv WHERE sv.slot_id=s.id) AS nb_volunteers,
             MAX(CASE WHEN sv2.user_id=? THEN 1 ELSE 0 END) AS i_volunteer
             FROM cc_planning_slots s
             LEFT JOIN cc_benv_slot_volunteers sv2 ON sv2.slot_id=s.id AND sv2.user_id=?
             WHERE s.date_start>=NOW() AND s.published=1
             GROUP BY s.id ORDER BY s.date_start LIMIT 5",
            [$userId, $userId]
        );
        $events = array_merge($benvEvts, $siteSlots);
        usort($events, fn($a,$b)=>strtotime($a['date_start'])<=>strtotime($b['date_start']));
        if (!empty($events)) $month = 'all';
    }
    // TOUTES les tâches non terminées (avec ou sans date) pour ce mois
    // Si 'all' : les 20 prochaines non terminées
    // Sinon : tâches du mois OU sans date
    if ($month === 'all') {
        $planTasks = Database::all(
            "SELECT t.*,u.firstname AS v_firstname, u.lastname AS v_lastname
             FROM cc_benv_tasks t
             LEFT JOIN cc_users u ON t.volunteer_id=u.id
             WHERE t.status!='done'
             ORDER BY ISNULL(t.due_date), t.due_date ASC, t.created_at ASC
             LIMIT 20"
        );
    } else {
        $planTasks = Database::all(
            "SELECT t.*,u.firstname AS v_firstname, u.lastname AS v_lastname
             FROM cc_benv_tasks t
             LEFT JOIN cc_users u ON t.volunteer_id=u.id
             WHERE t.status!='done'
               AND (DATE_FORMAT(t.due_date,'%Y-%m')=? OR t.due_date IS NULL)
             ORDER BY ISNULL(t.due_date), t.due_date ASC, t.created_at ASC",
            [$month]
        );
    }
  } catch(Exception $e) { $events=[]; $planTasks=[]; }

?>
<div class="bcard">
  <div class="bcard-h">
    <div style="display:flex;align-items:center;gap:.75rem">
      <a href="<?=u('/benevole/planning?month='.$prevM)?>" class="bbt bbt-g" style="padding:.3rem .5rem">‹</a>
      <h2>📅 <?=$month==='all'?'Prochains événements':ucfirst($mDt->format('F Y'))?></h2>
      <a href="<?=u('/benevole/planning?month='.$nextM)?>" class="bbt bbt-g" style="padding:.3rem .5rem">›</a>
    </div>
    <a href="<?=u('/benevole/planning?month='.$month.'&pdf=1')?>" class="bbt bbt-g" style="font-size:.78rem">📄 PDF</a>
  </div>
  <div class="bcard-b">
    <?php if(empty($events)): ?><p style="color:#94a3b8;text-align:center;padding:2rem">Aucun événement ce mois</p>
    <?php else: foreach($events as $ev): $dt=new DateTime($ev['date_start']); $de=$ev['date_end']?new DateTime($ev['date_end']):null; ?>
    <div class="bev">
      <div class="bev-d" style="background:<?=Helpers::e($ev['color'])?>"><div class="dy"><?=$dt->format('d')?></div><div class="mo"><?=$dt->format('M')?></div></div>
      <div style="flex:1">
        <div style="font-weight:700;font-size:.9rem">
          <?=Helpers::e($ev['title'])?>
          <?php if(($ev['source']??'benv')==='site'): ?>
          <span style="font-size:.65rem;background:#e0f2fe;color:#0369a1;padding:.1rem .4rem;border-radius:4px;margin-left:.35rem">Créneau site</span>
          <?php endif; ?>
        </div>
        <div style="font-size:.75rem;color:#64748b">🕐 <?=$dt->format('H:i')?><?=$de?' – '.$de->format('H:i'):''?><?php if($ev['location']): ?> · 📍 <?=Helpers::e($ev['location'])?><?php endif; ?>
        · 👥 <?=$ev['nb_parts']?><?=$ev['max_volunteers']?' / '.$ev['max_volunteers']:''?>
        </div>
      </div>
      <div style="display:flex;gap:.35rem;align-items:center">
        <?php if(($ev['source']??'benv')==='site'): ?>
          <?php $iVol=(int)($ev['i_volunteer']??0); $nbVol=(int)($ev['nb_volunteers']??0); ?>
          <div style="display:flex;flex-direction:column;gap:.35rem;align-items:flex-end">
            <a href="<?=u('/planning')?>" class="bbt bbt-s" style="font-size:.72rem;padding:.25rem .5rem">📅 S'inscrire (site)</a>
            <?php if($iVol): ?>
            <form method="post" style="margin:0"><?=Auth::csrfField()?>
              <input type="hidden" name="slot_id" value="<?=$ev['id']?>">
              <span style="font-size:.7rem;color:#16a34a;font-weight:600">🙋 Bénévole inscrit (<?=$nbVol?>)</span>
              <button type="submit" name="leave_slot_volunteer" class="bbt bbt-g" style="font-size:.68rem;padding:.2rem .4rem">↩ Se retirer</button>
            </form>
            <?php else: ?>
            <form method="post" style="margin:0"><?=Auth::csrfField()?>
              <input type="hidden" name="slot_id" value="<?=$ev['id']?>">
              <button type="submit" name="join_slot_volunteer" class="bbt bbt-g" style="font-size:.7rem;padding:.25rem .5rem">🤝 Je serai bénévole<?php if($nbVol>0): ?> (<?=$nbVol?>)<?php endif; ?></button>
            </form>
            <?php endif; ?>
          </div>
        <?php elseif($ev['i_join']): ?>
        <span class="btag" style="background:#dcfce7;color:#16a34a">✓ Inscrit</span>
        <form method="post" style="margin:0"><?=Auth::csrfField()?><input type="hidden" name="event_id" value="<?=$ev['id']?>"><button type="submit" name="leave_event" class="bbt bbt-d" style="font-size:.72rem;padding:.25rem .5rem">Annuler</button></form>
        <?php else: $full=$ev['max_volunteers']>0&&$ev['nb_parts']>=$ev['max_volunteers']; ?>
        <form method="post" style="margin:0"><?=Auth::csrfField()?><input type="hidden" name="event_id" value="<?=$ev['id']?>"><button type="submit" name="join_event" class="bbt bbt-s" style="font-size:.72rem;padding:.25rem .5rem" <?=$full?'disabled':''?>><?=$full?'🔴 Complet':'🙋 Je participe'?></button></form>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<?php if(!empty($planTasks)): ?>
<div class="bcard">
  <div class="bcard-h"><h2>✅ Tâches planifiées ce mois</h2></div>
  <div class="bcard-b">
    <?php foreach($planTasks as $tk): ?>
    <div style="display:flex;gap:.875rem;align-items:center;padding:.625rem;border-radius:8px;border:1.5px solid #e2e8f0;background:#fff;margin-bottom:.5rem">
      <div style="width:8px;height:36px;border-radius:4px;background:<?=Helpers::e($tk['color']??'#6366f1')?>;flex-shrink:0"></div>
      <div style="flex:1">
        <div style="font-weight:600;font-size:.875rem"><?=Helpers::e($tk['title'])?></div>
        <div style="font-size:.75rem;color:#64748b">
          <?php if($tk['due_date']): ?>📅 <?=(new DateTime($tk['due_date']))->format('d/m/Y')?><?php if(!empty($tk['time_start'])): ?> <?=substr($tk['time_start'],0,5)?><?php endif; ?>
          <?php else: ?><span style="color:#94a3b8">Sans échéance</span><?php endif; ?>
          <?php if($tk['v_firstname']): ?> · 🙋 <?=Helpers::e($tk['v_firstname'].' '.($tk['v_lastname']??''))?><?php endif; ?>
        </div>
      </div>
      <span style="padding:.2rem .6rem;border-radius:99px;font-size:.7rem;font-weight:700;
        background:<?=['todo'=>'#f1f5f9','inprogress'=>'#fef3c7','done'=>'#dcfce7'][$tk['status']]??'#f1f5f9'?>;
        color:<?=['todo'=>'#64748b','inprogress'=>'#d97706','done'=>'#16a34a'][$tk['status']]??'#64748b'?>">
        <?=['todo'=>'À faire','inprogress'=>'En cours','done'=>'Terminé'][$tk['status']]?>
      </span>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php
// ══ TÂCHES ══════════════════════════════════════════════════
elseif($action==='taches'):
  try { $tasks=Database::all("SELECT t.*,u.firstname,u.lastname,v.firstname AS v_firstname,v.lastname AS v_lastname FROM cc_benv_tasks t LEFT JOIN cc_users u ON t.assigned_to=u.id LEFT JOIN cc_users v ON t.volunteer_id=v.id ORDER BY t.priority='high' DESC,t.due_date,t.created_at"); } catch(Exception $e) { $tasks=[]; }
  $cols=['todo'=>['À faire','#64748b','📋'],'inprogress'=>['En cours','#f59e0b','⚡'],'done'=>['Terminé','#22c55e','✅']];
  $dayNames=['1'=>'Lun','2'=>'Mar','3'=>'Mer','4'=>'Jeu','5'=>'Ven','6'=>'Sam','0'=>'Dim'];
?>
<?php if($canAddTasks): ?>
<div class="bcard" style="margin-bottom:1.25rem">
  <div class="bcard-h"><h2>➕ Nouvelle tâche</h2></div>
  <div class="bcard-b">
    <form method="post">
      <?=Auth::csrfField()?>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-bottom:.75rem">
        <div><label style="font-size:.78rem;font-weight:600;color:#64748b;display:block;margin-bottom:.25rem">Titre *</label>
          <input type="text" name="title" class="bi" required placeholder="Titre de la tâche"></div>
        <div><label style="font-size:.78rem;font-weight:600;color:#64748b;display:block;margin-bottom:.25rem">Priorité</label>
          <select name="priority" class="bi"><option value="normal">🟡 Normale</option><option value="high">🔴 Haute</option><option value="low">🟢 Basse</option></select></div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.75rem;margin-bottom:.75rem">
        <div><label style="font-size:.78rem;font-weight:600;color:#64748b;display:block;margin-bottom:.25rem">Date échéance</label>
          <input type="date" name="due_date" class="bi"></div>
        <div><label style="font-size:.78rem;font-weight:600;color:#64748b;display:block;margin-bottom:.25rem">Heure début</label>
          <input type="time" name="time_start" class="bi"></div>
        <div><label style="font-size:.78rem;font-weight:600;color:#64748b;display:block;margin-bottom:.25rem">Heure fin</label>
          <input type="time" name="time_end" class="bi"></div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.75rem;margin-bottom:.75rem">
        <div><label style="font-size:.78rem;font-weight:600;color:#64748b;display:block;margin-bottom:.25rem">Assigner à</label>
          <select name="assigned_to" class="bi">
            <option value="">— Personne —</option>
            <?php foreach($benevoles as $bv): ?><option value="<?=$bv['id']?>"><?=Helpers::e($bv['firstname'].' '.$bv['lastname'])?></option><?php endforeach; ?>
          </select></div>
        <div><label style="font-size:.78rem;font-weight:600;color:#64748b;display:block;margin-bottom:.25rem">Nb bénévoles nécessaires</label>
          <input type="number" name="max_volunteers" class="bi" value="1" min="1" max="50"></div>
        <div><label style="font-size:.78rem;font-weight:600;color:#64748b;display:block;margin-bottom:.25rem">Récurrence</label>
          <select name="recurring" class="bi" onchange="document.getElementById('rec-days').style.display=this.value==='weekly'?'block':'none'">
            <option value="none">Aucune</option>
            <option value="daily">Quotidien</option>
            <option value="weekly">Hebdomadaire</option>
            <option value="monthly">Mensuel</option>
          </select></div>
        <div><label style="font-size:.78rem;font-weight:600;color:#64748b;display:block;margin-bottom:.25rem">Couleur</label>
          <input type="color" name="color" value="#6366f1" style="height:40px;width:100%;border-radius:8px;border:1.5px solid #e2e8f0;cursor:pointer;padding:2px"></div>
      </div>
      <!-- Jours de la semaine (récurrence hebdo) -->
      <div id="rec-days" style="display:none;margin-bottom:.75rem;padding:.75rem;background:#f8fafc;border-radius:8px">
        <div style="font-size:.78rem;font-weight:600;color:#64748b;margin-bottom:.4rem">Jours de la semaine</div>
        <div style="display:flex;gap:.4rem;flex-wrap:wrap">
          <?php foreach(['1'=>'Lun','2'=>'Mar','3'=>'Mer','4'=>'Jeu','5'=>'Ven','6'=>'Sam','0'=>'Dim'] as $v=>$l): ?>
          <label style="display:inline-flex;align-items:center;gap:.25rem;padding:.3rem .6rem;border-radius:99px;border:1.5px solid #e2e8f0;cursor:pointer;font-size:.78rem;font-weight:600">
            <input type="checkbox" name="recurring_days[]" value="<?=$v?>" style="accent-color:var(--bp)"> <?=$l?>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
      <div><label style="font-size:.78rem;font-weight:600;color:#64748b;display:block;margin-bottom:.25rem">Description</label>
        <textarea name="description" class="bi" rows="2" placeholder="Description optionnelle" style="resize:vertical"></textarea></div>
      <div style="margin-top:.75rem"><button type="submit" name="create_task" class="bbt bbt-p">➕ Créer la tâche</button></div>
    </form>
  </div>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem">
<?php foreach($cols as $st=>[$lbl,$col,$ico]): $cT=array_filter($tasks,fn($t)=>$t['status']===$st); ?>
<div class="kc">
  <div class="kch"><?=$ico?> <span style="color:<?=$col?>"><?=$lbl?></span>
    <span style="margin-left:auto;background:<?=$col?>;color:#fff;border-radius:99px;width:18px;height:18px;display:flex;align-items:center;justify-content:center;font-size:.65rem"><?=count($cT)?></span>
  </div>
  <?php
  foreach($cT as $tk):
    $recStr='';
    if($tk['recurring']!=='none') $recStr=['daily'=>'📅 Quotidien','weekly'=>'📅 Hebdo','monthly'=>'📅 Mensuel'][$tk['recurring']]??'';
    $maxVol = max(1,(int)($tk['max_volunteers']??1));
    try { $taskVols = Database::all("SELECT u.id,u.firstname FROM cc_benv_task_volunteers tv JOIN cc_users u ON u.id=tv.user_id WHERE tv.task_id=?",[$tk['id']]); } catch(Exception $e) { $taskVols=[]; }
    $iMine = !empty(array_filter($taskVols, fn($v)=>$v['id']==$userId));
    $volCount = count($taskVols);
    $taskFull = $volCount >= $maxVol;
  ?>
  <div class="kcrd" style="border-left:3px solid <?=Helpers::e($tk['color'])?>">
    <div class="kcrd-t"><?=Helpers::e($tk['title'])?></div>
    <div class="kcrd-m">
      <?php if($tk['priority']==='high'): ?><span style="color:#dc2626">⚠ Urgent</span><?php endif; ?>
      <?php if($tk['firstname']): ?><span>👤 Assigné: <?=Helpers::e($tk['firstname'])?></span><?php endif; ?>
      <?php foreach($taskVols as $vv): ?><span style="color:#16a34a">🙋 <?=Helpers::e($vv['firstname'])?></span><?php endforeach; ?>
      <?php if($maxVol>1): ?><span style="color:#64748b"><?=$volCount?>/<?=$maxVol?> bénévoles</span><?php endif; ?>
      <?php if($tk['due_date']): ?><span>📅 <?=(new DateTime($tk['due_date']))->format('d/m')?><?php if($tk['time_start']): ?> <?=substr($tk['time_start'],0,5)?><?php endif; ?></span><?php endif; ?>
      <?php if($recStr): ?><span><?=$recStr?></span><?php endif; ?>
    </div>
    <!-- Bouton Je m'en charge / Je me décharge -->
    <div style="margin-top:.5rem;display:flex;gap:.25rem;flex-wrap:wrap">
      <?php if(!$iMine && !$taskFull && $st!=='done'): ?>
      <form method="post" style="margin:0"><?=Auth::csrfField()?>
        <input type="hidden" name="task_id" value="<?=$tk['id']?>">
        <button type="submit" name="take_task" class="bbt bbt-s" style="font-size:.7rem;padding:.25rem .5rem">🙋 Je m'en charge</button>
      </form>
      <?php elseif($taskFull && !$iMine): ?>
      <span style="font-size:.7rem;color:#94a3b8;padding:.25rem 0">🔴 Complet (<?=$volCount?>/<?=$maxVol?>)</span>
      <?php endif; ?>
      <?php if($iMine): ?>
      <span style="font-size:.7rem;color:#16a34a;font-weight:600;padding:.25rem 0">✓ Je m'en charge</span>
      <form method="post" style="margin:0"><?=Auth::csrfField()?>
        <input type="hidden" name="task_id" value="<?=$tk['id']?>">
        <button type="submit" name="drop_task" class="bbt bbt-g" style="font-size:.68rem;padding:.2rem .4rem">↩ Se retirer</button>
      </form>
      <?php endif; ?>
      <?php if($tk['assigned_to']==$userId||$isAdmin||$isCoach): ?>
      <?php foreach($cols as $s=>[$l]): if($s===$st) continue; ?>
      <form method="post" style="margin:0"><?=Auth::csrfField()?>
        <input type="hidden" name="task_id" value="<?=$tk['id']?>">
        <input type="hidden" name="status" value="<?=$s?>">
        <button type="submit" name="update_task_status" class="bbt bbt-g" style="font-size:.68rem;padding:.2rem .4rem">→ <?=$l?></button>
      </form>
      <?php endforeach; ?>
      <?php if($isAdmin||$isCoach): ?>
      <form method="post" style="margin:0" onsubmit="return confirm('Supprimer cette tâche ?')"><?=Auth::csrfField()?>
        <input type="hidden" name="task_id" value="<?=$tk['id']?>">
        <button type="submit" name="delete_task" class="bbt bbt-d" style="font-size:.68rem;padding:.2rem .4rem">🗑</button>
      </form>
      <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endforeach; ?>
</div>

<?php if(!$isAdmin && !$isCoach): ?>
<!-- Suggérer une tâche -->
<div class="bcard" style="margin-top:1.25rem">
  <div class="bcard-h"><h2>💡 Suggérer une tâche</h2></div>
  <div class="bcard-b">
    <form method="post">
      <?=Auth::csrfField()?>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-bottom:.75rem">
        <div><label style="font-size:.78rem;font-weight:600;color:#64748b;display:block;margin-bottom:.25rem">Titre *</label>
          <input type="text" name="title" class="bi" required placeholder="Idée de tâche…"></div>
        <div><label style="font-size:.78rem;font-weight:600;color:#64748b;display:block;margin-bottom:.25rem">Description</label>
          <input type="text" name="description" class="bi" placeholder="Détails optionnels…"></div>
      </div>
      <button type="submit" name="suggest_task" class="bbt bbt-p">💡 Proposer cette tâche</button>
    </form>
  </div>
</div>
<?php endif; ?>

<?php
// Suggestions en attente (admin/coach les voient pour valider)
if ($isAdmin || $isCoach):
  try { $suggestions = Database::all("SELECT s.*,u.firstname,u.lastname FROM cc_benv_task_suggestions s LEFT JOIN cc_users u ON s.suggested_by=u.id WHERE s.status='pending' ORDER BY s.created_at DESC"); } catch(Exception $e) { $suggestions=[]; }
  if (!empty($suggestions)):
?>
<div class="bcard" style="margin-top:1.25rem">
  <div class="bcard-h"><h2>📬 Suggestions en attente (<?=count($suggestions)?>)</h2></div>
  <div class="bcard-b">
    <?php foreach($suggestions as $sg): ?>
    <div style="background:#f8fafc;border-radius:10px;padding:.875rem;margin-bottom:.75rem;border:1.5px solid #e2e8f0">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:.75rem">
        <div style="flex:1">
          <div style="font-weight:700;font-size:.9rem"><?=Helpers::e($sg['title'])?></div>
          <?php if($sg['description']): ?><div style="font-size:.8rem;color:#64748b;margin-top:.2rem"><?=Helpers::e($sg['description'])?></div><?php endif; ?>
          <div style="font-size:.72rem;color:#94a3b8;margin-top:.35rem">Proposé par <?=Helpers::e($sg['firstname'].' '.$sg['lastname'])?> · <?=(new DateTime($sg['created_at']))->format('d/m/Y')?></div>
        </div>
        <div style="display:flex;gap:.5rem;flex-shrink:0;flex-direction:column">
          <form method="post" style="display:flex;flex-direction:column;gap:.3rem">
            <?=Auth::csrfField()?>
            <input type="hidden" name="suggestion_id" value="<?=$sg['id']?>">
            <input type="hidden" name="status" value="approved">
            <button type="submit" name="review_suggestion" class="bbt bbt-s" style="font-size:.75rem;padding:.3rem .6rem">✅ Approuver</button>
          </form>
          <form method="post" style="display:flex;flex-direction:column;gap:.3rem">
            <?=Auth::csrfField()?>
            <input type="hidden" name="suggestion_id" value="<?=$sg['id']?>">
            <input type="hidden" name="status" value="rejected">
            <input type="text" name="review_note" class="bi" placeholder="Raison du refus…" style="font-size:.75rem">
            <button type="submit" name="review_suggestion" class="bbt bbt-d" style="font-size:.75rem;padding:.3rem .6rem">❌ Refuser</button>
          </form>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; endif; ?>

<?php
// ══ CHAT ════════════════════════════════════════════════════
elseif($action==='chat'):
  $channel = Helpers::sanitize($_GET['channel'] ?? ($channels[0]['slug'] ?? 'general'));
  $msgs = [];
  try { $msgs = Database::all(
      "SELECT c.*,u.firstname,u.lastname FROM cc_benv_chat c LEFT JOIN cc_users u ON c.user_id=u.id WHERE c.channel=? ORDER BY c.created_at ASC LIMIT 80",
      [$channel]
  ); } catch(Exception $e) {}
  $lastId = !empty($msgs) ? (int)end($msgs)['id'] : 0;
  $curChan = null;
  foreach($channels as $ch) { if($ch['slug']===$channel) { $curChan=$ch; break; } }
  $isMuted = false;
  try { $mu = Database::one("SELECT until FROM cc_benv_chat_muted WHERE user_id=?",[$userId]);
        $isMuted = $mu && ($mu['until']===null || strtotime($mu['until'])>time()); } catch(Exception $e) {}
?>
<div style="display:grid;grid-template-columns:180px 1fr;gap:1.25rem">
  <div class="bcard" style="height:fit-content">
    <div class="bcard-h"><h2>Canaux</h2><?php if($isAdmin): ?><a href="<?=u('/admin/benevole?tab=channels')?>" class="bbt bbt-g" style="font-size:.7rem;padding:.2rem .4rem">⚙️</a><?php endif; ?></div>
    <div class="bcard-b" style="padding:.5rem">
      <?php foreach($channels as $ch): ?>
      <a href="<?=u('/benevole/chat?channel='.urlencode($ch['slug']))?>"
        style="display:block;padding:.5rem .75rem;border-radius:8px;text-decoration:none;font-size:.85rem;font-weight:600;margin-bottom:.2rem;background:<?=$channel===$ch['slug']?'var(--bp)':'transparent'?>;color:<?=$channel===$ch['slug']?'#fff':'#475569'?>">
        <?=$ch['open']?'💬':'🔒'?> <?=Helpers::e($ch['name'])?>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="bcard">
    <div class="bcard-h"><h2><?=$curChan?Helpers::e($curChan['name']):'Chat'?></h2>
      <?php if($isAdmin&&$curChan): ?>
      <form method="post" action="<?=u('/admin/benevole')?>"><?=Auth::csrfField()?>
        <input type="hidden" name="channel_id" value="<?=$curChan['id']?>">
        <input type="hidden" name="channel_open" value="<?=$curChan['open']?0:1?>">
        <button type="submit" name="toggle_channel" class="bbt bbt-g" style="font-size:.75rem;padding:.3rem .6rem"><?=$curChan['open']?'🔒 Fermer':'🔓 Ouvrir'?></button>
      </form>
      <?php endif; ?>
    </div>
    <div class="bcard-b" style="padding:.75rem">
      <div class="chatbox" id="chatbox">
        <?php foreach($msgs as $cm): $mine=$cm['user_id']==$userId; ?>
        <div class="cmsg <?=$mine?'mine':'other'?>">
          <?php if(!$mine): ?><div class="bav" style="width:26px;height:26px;font-size:.65rem;align-self:flex-end"><?=strtoupper(substr($cm['firstname'],0,1).substr($cm['lastname'],0,1))?></div><?php endif; ?>
          <div><?php if(!$mine): ?><div class="cmeta"><?=Helpers::e($cm['firstname'])?></div><?php endif; ?>
          <div class="cbub"><?=nl2br(Helpers::e($cm['message']))?></div>
          <div class="cmeta" style="text-align:<?=$mine?'right':'left'?>"><?=(new DateTime($cm['created_at']))->format('H:i')?></div></div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php if($isMuted): ?>
      <p style="text-align:center;color:#dc2626;font-size:.875rem;padding:.75rem;background:#fee2e2;border-radius:8px">🔇 Vous êtes muté.</p>
      <?php elseif($curChan&&!$curChan['open']&&!$isAdmin): ?>
      <p style="text-align:center;color:#64748b;font-size:.875rem;padding:.75rem;background:#f1f5f9;border-radius:8px">🔒 Canal fermé.</p>
      <?php else: ?>
      <form id="chat-form" method="post" action="<?=u('/benevole/chat')?>" style="display:flex;gap:.5rem;margin-top:.5rem">
        <?=Auth::csrfField()?>
        <input type="hidden" name="channel" value="<?=Helpers::e($channel)?>">
        <input type="hidden" name="send_chat" value="1">
        <input type="text" id="chat-msg" name="message" class="bi" placeholder="Votre message…" autocomplete="off" style="flex:1" maxlength="500">
        <button type="submit" id="chat-btn" class="bbt bbt-p">Envoyer</button>
      </form>
      <div id="chat-err" style="font-size:.72rem;color:#dc2626;margin-top:.25rem"></div>
      <?php endif; ?>
    </div>
  </div>

</div>

<script>
var chatLastId=<?=$lastId?>;
var chatMyId=<?=(int)$userId?>;
var chatChan='<?=str_replace("'","\'",Helpers::e($channel))?>';
var chatUrl='<?=str_replace("'","\'",u('/benevole/chat'))?>';
var chatTok='<?=str_replace("'","\'",Auth::getCsrfToken())?>';
var chatBox=document.getElementById('chatbox');
if(chatBox)chatBox.scrollTop=chatBox.scrollHeight;
function chatAddMsg(id,uid,fn,ln,msg,ts){
    if(document.getElementById('cm'+id))return;
    var mine=(uid==chatMyId);
    var ini=((fn||'?')[0]+((ln||'')[0]||'')).toUpperCase();
    var hm=ts?ts.substr(11,5):new Date().toTimeString().substr(0,5);
    var txt=String(msg).replace(/[&<>]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;'}[c];}).replace(/\n/g,'<br>');
    var d=document.createElement('div');
    d.id='cm'+id;d.className='cmsg '+(mine?'mine':'other');
    d.innerHTML=(mine?'':'<div class="bav" style="width:26px;height:26px;font-size:.65rem;align-self:flex-end">'+ini+'</div>')
        +'<div>'+(mine?'':'<div class="cmeta">'+fn+'</div>')
        +'<div class="cbub">'+txt+'</div>'
        +'<div class="cmeta" style="text-align:'+(mine?'right':'left')+'">'+hm+'</div></div>';
    if(chatBox){chatBox.appendChild(d);chatBox.scrollTop=chatBox.scrollHeight;}
    chatLastId=parseInt(id)||chatLastId;
}
function chatPoll(){
    var x=new XMLHttpRequest();
    x.open('GET',chatUrl+'&poll=1&since='+chatLastId+'&channel='+encodeURIComponent(chatChan),true);
    x.setRequestHeader('X-Requested-With','XMLHttpRequest');
    x.onload=function(){
        if(x.status!==200)return;
        try{var r=JSON.parse(x.responseText);if(r&&r.messages)r.messages.forEach(function(m){chatAddMsg(m.id,m.user_id,m.firstname||'?',m.lastname||'',m.message,m.created_at);});}catch(e){}
    };x.send();
}
setInterval(chatPoll,3000);
var chatForm=document.getElementById('chat-form');
if(chatForm){
    chatForm.addEventListener('submit',function(e){
        e.preventDefault();
        var inp=document.getElementById('chat-msg');
        if(!inp||!inp.value.trim())return;
        var msg=inp.value.trim();inp.value='';inp.focus();
        var x=new XMLHttpRequest();
        x.open('POST',chatUrl,true);
        x.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
        x.setRequestHeader('X-Requested-With','XMLHttpRequest');
        x.onload=function(){setTimeout(chatPoll,100);};
        x.onerror=function(){chatForm.submit();};
        x.send('send_chat=1&csrf_token='+encodeURIComponent(chatTok)+'&channel='+encodeURIComponent(chatChan)+'&message='+encodeURIComponent(msg));
    });
}
</script>

<?php
// ══ DOCUMENTS ═══════════════════════════════════════════════
elseif($action==='documents'):
  $folderId = (int)($_GET['folder']??0) ?: null;
  try {
    $folder     = $folderId ? Database::one("SELECT * FROM cc_benv_folders WHERE id=?",[$folderId]) : null;
    $subFolders = Database::all("SELECT * FROM cc_benv_folders WHERE parent_id".($folderId?" = $folderId":" IS NULL")." ORDER BY name");
    $allDocs    = Database::all("SELECT d.*,u.firstname,u.lastname FROM cc_benv_docs d LEFT JOIN cc_users u ON d.created_by=u.id WHERE d.folder_id".($folderId?" = $folderId":" IS NULL")." ORDER BY d.type DESC, d.created_at DESC");
  } catch(Exception $e) { $subFolders=[];$allDocs=[]; }
  // Filtrer selon visibilité
  $docs = array_filter($allDocs, function($d) use ($isAdmin,$isCoach,$userId) {
      $vis = $d['visibility'] ?? 'all';
      if ($vis==='admin') return $isAdmin;
      if ($vis==='coach') return $isAdmin||$isCoach;
      if ($vis==='specific') {
          if ($isAdmin||$isCoach) return true;
          $allowed = json_decode($d['allowed_users']??'[]',true)??[];
          return in_array($userId, $allowed);
      }
      return true;
  });
  $editDoc = isset($_GET['edit']) ? Database::one("SELECT * FROM cc_benv_docs WHERE id=?",[(int)$_GET['edit']]) : null;

  // Icônes selon extension
  $docIcons = ['pdf'=>'📄','doc'=>'📝','docx'=>'📝','xls'=>'📊','xlsx'=>'📊',
    'ppt'=>'📋','pptx'=>'📋','jpg'=>'🖼','jpeg'=>'🖼','png'=>'🖼','gif'=>'🖼',
    'webp'=>'🖼','svg'=>'🖼','zip'=>'🗜','txt'=>'📃','csv'=>'📊','mp4'=>'🎬'];
  function docIcon($filename, $icons) {
      $ext = strtolower(pathinfo($filename??'',PATHINFO_EXTENSION));
      return $icons[$ext] ?? '📎';
  }
  function isImage($filename) {
      return in_array(strtolower(pathinfo($filename??'',PATHINFO_EXTENSION)),['jpg','jpeg','png','gif','webp','svg']);
  }
?>

<div class="bg2" style="align-items:start;gap:1.25rem">

  <!-- Colonne gauche : dossiers + upload -->
  <div>
    <!-- Dossiers -->
    <div class="bcard" style="margin-bottom:1rem">
      <div class="bcard-h">
        <h2>📁 <?=$folder?Helpers::e($folder['name']):'Dossiers'?></h2>
        <?php if($folder): ?><a href="<?=u('/benevole/documents')?>" class="bbt bbt-g" style="font-size:.75rem;padding:.3rem .6rem">↑ Racine</a><?php endif; ?>
      </div>
      <div class="bcard-b">
        <?php foreach($subFolders as $sf): ?>
        <a href="<?=u('/benevole/documents?folder='.$sf['id'])?>" style="display:flex;align-items:center;gap:.625rem;padding:.625rem .875rem;border-radius:8px;border:1.5px solid #e2e8f0;background:#fff;text-decoration:none;color:#1e293b;margin-bottom:.4rem;transition:border-color .15s" onmouseover="this.style.borderColor='var(--bp)'" onmouseout="this.style.borderColor='#e2e8f0'">
          <span style="font-size:1.1rem">📂</span><span style="font-weight:600;flex:1"><?=Helpers::e($sf['name'])?></span>
          <?php if($isAdmin): ?><form method="post" onsubmit="return confirm('Supprimer ce dossier et son contenu ?')"><?=Auth::csrfField()?><input type="hidden" name="folder_id" value="<?=$sf['id']?>"><button type="submit" name="delete_folder" style="background:none;border:none;cursor:pointer;color:#dc2626;opacity:.4" onmouseover="this.style.opacity=1" onmouseout="this.style.opacity=.4">🗑</button></form><?php endif; ?>
        </a>
        <?php endforeach; ?>
        <?php if(empty($subFolders)&&!$folder): ?><p style="color:#94a3b8;font-size:.82rem;text-align:center;padding:.5rem">Aucun sous-dossier</p><?php endif; ?>
        <?php
        $folderWhoP  = Config::get('benv_folder_who','admin');
        $folderSpecP = json_decode(Config::get('benv_folder_specific','[]'),true)??[];
        $canCreateF  = $isAdmin || ($folderWhoP==='all')
            || ($folderWhoP==='coach' && ($isAdmin||$isCoach))
            || ($folderWhoP==='specific' && in_array($userId,$folderSpecP));
        ?>
        <?php if($canCreateF): ?>
        <form method="post" style="display:flex;gap:.4rem;margin-top:.75rem"><?=Auth::csrfField()?>
          <input type="hidden" name="parent_id" value="<?=$folderId??''?>">
          <input type="text" name="folder_name" class="bi" placeholder="Nouveau dossier…" style="flex:1">
          <button type="submit" name="create_folder" class="bbt bbt-p" style="white-space:nowrap">+ Créer</button>
        </form>
        <?php endif; ?>
      </div>
    </div>

    <!-- Upload fichier -->
    <?php
    $uploadWho = Config::get('benv_upload_who','coach');
    $uploadSpec = json_decode(Config::get('benv_upload_specific','[]'),true)??[];
    $canUploadDoc = $isAdmin || $isCoach
        || ($uploadWho==='all')
        || ($uploadWho==='specific' && in_array($userId,$uploadSpec));
    ?>
    <?php if($canUploadDoc): ?>
    <div class="bcard" style="margin-bottom:1rem">
      <div class="bcard-h"><h2>📤 Uploader un fichier</h2></div>
      <div class="bcard-b">
        <form method="post" enctype="multipart/form-data">
          <?=Auth::csrfField()?>
          <input type="hidden" name="folder_id" value="<?=$folderId??''?>">
          <div style="margin-bottom:.75rem">
            <label style="font-size:.78rem;font-weight:600;color:#64748b;display:block;margin-bottom:.3rem">Fichier *</label>
            <input type="file" name="doc_file" class="bi" required accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.gif,.webp,.svg,.zip,.txt,.csv,.mp4" style="padding:.4rem">
            <div style="font-size:.72rem;color:#94a3b8;margin-top:.25rem">PDF, Word, Excel, PowerPoint, images, ZIP…</div>
          </div>
          <div style="margin-bottom:.75rem">
            <label style="font-size:.78rem;font-weight:600;color:#64748b;display:block;margin-bottom:.3rem">Titre (optionnel)</label>
            <input type="text" name="doc_title" class="bi" placeholder="Nom du fichier si vide">
          </div>
          <?php if($isAdmin||$isCoach): ?>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;margin-bottom:.5rem">
            <div>
              <label style="font-size:.78rem;font-weight:600;color:#64748b;display:block;margin-bottom:.3rem">👁 Visible par</label>
              <select name="visibility" class="bi" id="sel-vis" onchange="toggleSpecific()">
                <option value="all">Tous les bénévoles</option>
                <option value="coach">Coachs + Admins</option>
                <option value="admin">Admins uniquement</option>
                <option value="specific">Bénévoles spécifiques…</option>
              </select>
            </div>
            <div>
              <label style="font-size:.78rem;font-weight:600;color:#64748b;display:block;margin-bottom:.3rem">⬇️ Téléchargeable par</label>
              <select name="can_download" class="bi">
                <option value="all">Tous les bénévoles</option>
                <option value="coach">Coachs + Admins</option>
                <option value="admin">Admins uniquement</option>
                <option value="specific">Bénévoles spécifiques…</option>
              </select>
            </div>
          </div>
          <!-- Sélection bénévoles spécifiques -->
          <div id="specific-users" style="display:none;background:#f8fafc;border-radius:8px;padding:.75rem;margin-bottom:.75rem;border:1.5px solid #e2e8f0">
            <label style="font-size:.78rem;font-weight:600;color:#64748b;display:block;margin-bottom:.5rem">🙋 Choisir les bénévoles autorisés</label>
            <div style="display:flex;flex-wrap:wrap;gap:.35rem">
              <?php foreach($benevoles as $bv): ?>
              <label style="display:inline-flex;align-items:center;gap:.35rem;padding:.3rem .625rem;border-radius:6px;border:1.5px solid #e2e8f0;cursor:pointer;font-size:.8rem;background:#fff">
                <input type="checkbox" name="allowed_users[]" value="<?=$bv['id']?>" style="accent-color:var(--bp)">
                <?=Helpers::e($bv['firstname'].' '.$bv['lastname'])?>
              </label>
              <?php endforeach; ?>
            </div>
            <p style="font-size:.72rem;color:#94a3b8;margin-top:.5rem">Les admins et coachs ont toujours accès.</p>
          </div>
          <script>
          function toggleSpecific(){
            var v=document.getElementById('sel-vis').value;
            document.getElementById('specific-users').style.display=(v==='specific')?'block':'none';
          }
          </script>
          <?php endif; ?>
          <button type="submit" name="upload_doc" class="bbt bbt-p">📤 Uploader</button>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <!-- Nouvelle note texte -->
    <div class="bcard">
      <div class="bcard-h"><h2><?=$editDoc?'✏️ Modifier la note':'📝 Nouvelle note'?></h2></div>
      <div class="bcard-b">
        <form method="post"><?=Auth::csrfField()?>
          <?php if($editDoc): ?><input type="hidden" name="doc_id" value="<?=$editDoc['id']?>"><?php endif; ?>
          <input type="hidden" name="folder_id" value="<?=$folderId??''?>">
          <input type="text" name="doc_title" class="bi" value="<?=Helpers::e($editDoc['title']??'')?>" placeholder="Titre de la note" required style="margin-bottom:.5rem">
          <textarea name="doc_content" class="bi" rows="4" placeholder="Contenu de la note…" style="margin-bottom:.75rem;resize:vertical"><?=Helpers::e($editDoc['content']??'')?></textarea>
          <button type="submit" name="<?=$editDoc?'update_note':'create_note'?>" class="bbt bbt-p"><?=$editDoc?'💾 Enregistrer':'+ Créer la note'?></button>
          <?php if($editDoc): ?><a href="<?=u('/benevole/documents'.($folderId?'?folder='.$folderId:''))?>" class="bbt bbt-g" style="margin-left:.4rem">Annuler</a><?php endif; ?>
        </form>
      </div>
    </div>
  </div>

  <!-- Colonne droite : liste des documents -->
  <div class="bcard">
    <div class="bcard-h"><h2>📄 Documents & Notes (<?=count($docs)?>)</h2></div>
    <div class="bcard-b">
      <?php if(empty($docs)): ?>
      <p style="color:#94a3b8;text-align:center;padding:2rem">Aucun document dans ce dossier</p>
      <?php else: foreach($docs as $doc):
        $isFile = $doc['type']==='file';
        $ext    = $isFile ? strtolower(pathinfo($doc['filename']??'',PATHINFO_EXTENSION)) : '';
        $icon   = $isFile ? docIcon($doc['filename'], $docIcons) : '📝';
        $isImg  = $isFile && isImage($doc['filename']??'');
        $isPdf  = ($ext==='pdf');
        $visLabels = ['all'=>'Tous','coach'=>'Coachs+','admin'=>'Admin'];
        $dlLabels  = ['all'=>'Tous','coach'=>'Coachs+','admin'=>'Admin'];
        $vis = $doc['visibility'] ?? 'all';
        $dl  = $doc['can_download'] ?? 'all';
        $canDl = ($dl==='all') ||
                  ($dl==='coach' && ($isAdmin||$isCoach)) ||
                  ($dl==='admin' && $isAdmin) ||
                  ($dl==='specific' && ($isAdmin||$isCoach||in_array($userId, json_decode($doc['allowed_users']??'[]',true)??[])));
      ?>
      <div style="border:1.5px solid #e2e8f0;border-radius:10px;overflow:hidden;margin-bottom:.875rem">
        <!-- Prévisualisation -->
        <?php if($isImg): ?>
        <div style="background:#f8fafc;padding:.5rem;text-align:center;border-bottom:1px solid #f1f5f9">
          <img src="<?=asset('/uploads/benevoles/'.$doc['filename'])?>" alt="<?=Helpers::e($doc['title'])?>"
            style="max-height:180px;max-width:100%;border-radius:6px;object-fit:contain">
        </div>
        <?php elseif($isPdf): ?>
        <div style="background:#f8fafc;padding:.5rem;border-bottom:1px solid #f1f5f9">
          <iframe src="<?=asset('/uploads/benevoles/'.$doc['filename'])?>" style="width:100%;height:200px;border:none;border-radius:6px"></iframe>
        </div>
        <?php endif; ?>
        <!-- Infos + actions -->
        <div style="padding:.75rem;display:flex;align-items:flex-start;gap:.75rem">
          <span style="font-size:1.5rem;flex-shrink:0"><?=$icon?></span>
          <div style="flex:1;min-width:0">
            <div style="font-weight:600;font-size:.875rem"><?=Helpers::e($doc['title'])?></div>
            <div style="font-size:.72rem;color:#94a3b8;margin-top:.15rem">
              <?=Helpers::e($doc['firstname'].' '.$doc['lastname'])?> · <?=(new DateTime($doc['updated_at']))->format('d/m/Y')?>
              <?php if($isFile&&$doc['filesize']): ?>
              · <?=round($doc['filesize']/1024,1)?> Ko
              <?php endif; ?>
            </div>
            <?php if(!$isFile&&$doc['content']): ?>
            <div style="font-size:.8rem;color:#64748b;margin-top:.35rem;line-height:1.5"><?=Helpers::e(Helpers::excerpt($doc['content'],120))?></div>
            <?php endif; ?>
            <!-- Badges visibilité -->
            <?php if($isAdmin||$isCoach): ?>
            <?php
            $visLabels2 = ['all'=>'Tous','coach'=>'Coachs+','admin'=>'Admin','specific'=>'Spécifiques'];
            $dlLabels2  = ['all'=>'Tous','coach'=>'Coachs+','admin'=>'Admin','specific'=>'Spécifiques'];
            $allowedList = [];
            if (($vis==='specific'||$dl==='specific') && $doc['allowed_users']) {
                $aids = json_decode($doc['allowed_users'],true)??[];
                foreach($benevoles as $bv) if(in_array($bv['id'],$aids)) $allowedList[]=$bv['firstname'];
            }
            ?>
            <div style="display:flex;gap:.35rem;margin-top:.4rem;flex-wrap:wrap">
              <span style="font-size:.65rem;padding:.1rem .4rem;border-radius:4px;background:#eff6ff;color:#2563eb">👁 <?=$visLabels2[$vis]??$vis?></span>
              <span style="font-size:.65rem;padding:.1rem .4rem;border-radius:4px;background:#f0fdf4;color:#16a34a">⬇️ <?=$dlLabels2[$dl]??$dl?></span>
              <?php if(!empty($allowedList)): ?>
              <span style="font-size:.65rem;padding:.1rem .4rem;border-radius:4px;background:#fef3c7;color:#92400e">🙋 <?=Helpers::e(implode(', ',$allowedList))?></span>
              <?php endif; ?>
            </div>
            <?php endif; ?>
          </div>
          <!-- Actions -->
          <div style="display:flex;gap:.3rem;flex-shrink:0;flex-wrap:wrap;justify-content:flex-end">
            <?php if($isFile&&$canDl): ?>
            <a href="<?=u('/benevole/documents?download='.$doc['id'].($folderId?'&folder='.$folderId:''))?>" class="bbt bbt-p" style="font-size:.72rem;padding:.25rem .5rem">⬇️</a>
            <?php endif; ?>
            <?php if(!$isFile): ?>
            <a href="<?=u('/benevole/documents?'.($folderId?'folder='.$folderId.'&':'').'edit='.$doc['id'])?>" class="bbt bbt-g" style="font-size:.72rem;padding:.25rem .4rem">✏️</a>
            <?php endif; ?>
            <?php if($canDeleteNotes||$isAdmin||$isCoach): ?>
            <form method="post" onsubmit="return confirm('Supprimer ce document ?')"><?=Auth::csrfField()?>
              <input type="hidden" name="doc_id" value="<?=$doc['id']?>">
              <input type="hidden" name="folder_id" value="<?=$folderId??''?>">
              <button type="submit" name="delete_doc" class="bbt bbt-d" style="font-size:.72rem;padding:.25rem .4rem">🗑</button>
            </form>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

</div>

<?php
// ══ ANNUAIRE ════════════════════════════════════════════════
elseif($action==='annuaire'):
  $myProf=Database::one("SELECT * FROM cc_benv_profiles WHERE user_id=?",[$userId])??[];
?>
<div class="bcard" style="margin-bottom:1.25rem">
  <div class="bcard-h"><h2>👤 Mon profil</h2></div>
  <div class="bcard-b">
    <form method="post" style="display:flex;gap:.75rem;align-items:flex-end"><?=Auth::csrfField()?>
      <div style="flex:1"><label style="font-size:.78rem;font-weight:600;color:#64748b;display:block;margin-bottom:.3rem">Mes compétences</label>
        <input type="text" name="skills" class="bi" value="<?=Helpers::e($myProf['skills']??'')?>" placeholder="Ex: Logistique, Photographie…"></div>
      <button type="submit" name="save_benv_profile" class="bbt bbt-p">💾 Enregistrer</button>
    </form>
  </div>
</div>
<div class="bcard">
  <div class="bcard-h"><h2>👥 Bénévoles (<?=count($benevoles)?>)</h2></div>
  <div class="bcard-b">
    <div class="bg3">
      <?php foreach($benevoles as $b): $ini=strtoupper(substr($b['firstname'],0,1).substr($b['lastname'],0,1)); ?>
      <div class="bmem" style="<?=($isAdmin&&$b['blacklisted'])?'border-color:#fecaca;background:#fff5f5':''?>">
        <div class="bav" style="width:42px;height:42px;font-size:.9rem;margin:0 auto .5rem"><?=$ini?></div>
        <div style="font-weight:700;font-size:.875rem"><?=Helpers::e($b['firstname'].' '.$b['lastname'])?></div>
        <?php if($b['email']): ?><div style="font-size:.72rem;color:#94a3b8"><?=Helpers::e($b['email'])?></div><?php endif; ?>
        <?php if($b['skills']): ?><div style="font-size:.75rem;color:#64748b;margin-top:.3rem"><?=Helpers::e($b['skills'])?></div><?php endif; ?>
        <?php if($isAdmin&&$b['blacklisted']): ?><span class="btag" style="background:#fee2e2;color:#dc2626;margin-top:.4rem;display:inline-block">⚠ Attention</span><?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; // ferme le bloc elseif des actions ?>
<?php if($action==='verif' && $canVerifCarte):
$benvVerifUrl = u('/benevole/verif');
?>
<div class="bcard" style="max-width:560px;margin:0 auto">
  <div class="bcard-h"><h2>🔍 Vérification de carte membre</h2></div>
  <div class="bcard-b">

    <!-- Onglets internes -->
    <?php $vtab = $_GET['vtab'] ?? 'qr'; ?>
    <div style="display:flex;gap:.35rem;margin-bottom:1.25rem">
      <a href="<?=u('/benevole/verif?vtab=qr')?>"  class="bbt <?=$vtab==='qr'?'bbt-p':'bbt-g'?>">📷 Scanner QR</a>
      <a href="<?=u('/benevole/verif?vtab=pdf')?>" class="bbt <?=$vtab==='pdf'?'bbt-p':'bbt-g'?>">📄 Vérifier PDF</a>
    </div>

    <?php if($vtab==='qr'): ?>
    <!-- ── QR Code ── -->
    <p style="font-size:.875rem;color:#64748b;margin-bottom:1rem">Scannez le QR code de la carte membre pour vérifier son authenticité.</p>
    <div id="benv-qr-result" style="display:none;border-radius:10px;padding:.875rem;margin-bottom:1rem;font-size:.9rem;font-weight:600;text-align:center"></div>
    <div style="display:flex;gap:.5rem;margin-bottom:1rem;flex-wrap:wrap">
      <button type="button" id="benv-qr-start" onclick="benvQrStart()" class="bbt bbt-p" style="flex:1;justify-content:center">📷 Démarrer</button>
      <button type="button" id="benv-qr-stop"  onclick="benvQrStop()"  class="bbt bbt-g" style="flex:1;justify-content:center;display:none">⏹ Arrêter</button>
    </div>
    <div id="benv-qr-reader" style="width:100%;border-radius:10px;overflow:hidden;background:#000"></div>
    <p style="font-size:.75rem;color:#94a3b8;margin-top:.75rem;text-align:center">Pointez la caméra vers le QR code. Résultat instantané.</p>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js" crossorigin="anonymous"></script>
    <script>
    var _benvQr = null;
    var _benvVerifUrl = "<?=u('/benevole/verif')?>";
    function benvQrStart(){
      document.getElementById("benv-qr-start").style.display="none";
      document.getElementById("benv-qr-stop").style.display="inline-flex";
      document.getElementById("benv-qr-result").style.display="none";
      _benvQr = new Html5Qrcode("benv-qr-reader");
      Html5Qrcode.getCameras().then(function(cams){
        if(!cams||!cams.length){benvQrResult(false,"Aucune camera detectee.");benvQrStop();return;}
        var camId=cams[cams.length-1].id;
        _benvQr.start(camId,{fps:10,qrbox:{width:220,height:220}},function(text){
          var m=text.match(/verifier-carte\?id=(\d+)&hash=([a-f0-9]+)/);
          if(m){
            fetch(_benvVerifUrl+"&benv_verify_qr=1&id="+m[1]+"&hash="+m[2])
              .then(function(r){return r.json();})
              .then(function(data){
                if(data.valid){benvQrResult(true,"Membre reconnu : "+data.name);}
                else{benvQrResult(false,"QR code invalide ou membre inconnu.");}
              }).catch(function(){benvQrResult(false,"Erreur de verification.");});
            benvQrStop();
          } else {
            benvQrResult(false,"QR non reconnu.");benvQrStop();
          }
        },function(){}).catch(function(e){benvQrResult(false,"Camera inaccessible : "+e);});
      });
    }
    function benvQrStop(){
      if(_benvQr){_benvQr.stop().catch(function(){});_benvQr=null;}
      document.getElementById("benv-qr-start").style.display="inline-flex";
      document.getElementById("benv-qr-stop").style.display="none";
    }
    function benvQrResult(ok,msg){
      var el=document.getElementById("benv-qr-result");
      el.style.display="block";
      el.style.background=ok?"#f0fdf4":"#fff5f5";
      el.style.border="1.5px solid "+(ok?"#bbf7d0":"#fecaca");
      el.style.color=ok?"#166534":"#991b1b";
      el.innerHTML=(ok?"✅ ":"❌ ")+msg;
    }
    </script>

    <?php else: ?>
    <!-- ── Vérification PDF ── -->
    <p style="font-size:.875rem;color:#64748b;margin-bottom:1rem">Uploadez le PDF de la carte membre pour vérifier son authenticité.</p>
    <?php if($cardVerifyResult): ?>
    <div class="bcard" style="background:<?=$cardVerifyResult['type']==='success'?'#f0fdf4':($cardVerifyResult['type']==='warning'?'#fffbeb':'#fff5f5')?>;border-color:<?=$cardVerifyResult['type']==='success'?'#bbf7d0':($cardVerifyResult['type']==='warning'?'#fde68a':'#fecaca')?>;margin-bottom:1rem">
      <div class="bcard-b" style="color:<?=$cardVerifyResult['type']==='success'?'#166534':($cardVerifyResult['type']==='warning'?'#92400e':'#991b1b')?>;font-weight:600"><?=$cardVerifyResult['msg']?></div>
    </div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data">
      <?=Auth::csrfField()?>
      <div style="margin-bottom:.875rem">
        <label style="font-size:.78rem;font-weight:600;color:#64748b;display:block;margin-bottom:.35rem">Fichier PDF de la carte membre</label>
        <input type="file" name="card_pdf" accept="application/pdf" required class="bi" style="padding:.4rem">
      </div>
      <button type="submit" name="verify_card_pdf" value="1" class="bbt bbt-p">🔍 Vérifier l'authenticité</button>
    </form>
    <?php endif; ?>

  </div>
</div>

<?php endif; // fin bloc verif ?>

  </div><!-- bcontent -->
</main>
</div>

<script>
function dismissAlert(id){
  var el=document.getElementById('al-'+id);
  fetch('<?=u('/benevole')?>', {method:'POST',body:new URLSearchParams({dismiss_alert:1,alert_id:id,_csrf_token:'<?=Auth::getCsrfToken()?>'}),headers:{'X-Requested-With':'XMLHttpRequest'}});
  if(el){el.style.opacity='0';el.style.transition='opacity .3s';setTimeout(function(){el.remove()},300);}
}
</script>

<?php
$content = ob_get_clean();
include CC_ROOT . '/templates/layout.php';
