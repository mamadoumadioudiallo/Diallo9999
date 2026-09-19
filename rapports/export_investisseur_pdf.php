<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : rapports/export_investisseur_pdf.php
// Rôle    : Génère un rapport PDF investisseur pour un véhicule
//           sur une période donnée + envoi optionnel par email
// ============================================================

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../lib/fpdf/fpdf.php';

requireAdmin();

function t($texte) {
    return iconv('UTF-8', 'ISO-8859-1//TRANSLIT', (string) $texte);
}

$idVehicule   = (int) ($_GET['id_vehicule']  ?? 0);
$dateDebut    = $_GET['date_debut']  ?? date('Y-m-01');
$dateFin      = $_GET['date_fin']    ?? date('Y-m-d');
$envoyerEmail = ($_GET['envoyer_email'] ?? '0') === '1';

// ====== DONNÉES VÉHICULE ======
$stmt = $pdo->prepare("
    SELECT v.*, u.nom AS sup_nom, u.prenom AS sup_prenom, u.email AS sup_email
    FROM vehicules v
    LEFT JOIN utilisateurs u ON v.id_superviseur = u.id_user
    WHERE v.id_vehicule = ? AND v.proprietaire_nom IS NOT NULL AND v.proprietaire_nom != ''
");
$stmt->execute([$idVehicule]);
$vehicule = $stmt->fetch();

if (!$vehicule) {
    die('Véhicule introuvable ou non associé à un investisseur.');
}

$debut = $dateDebut . ' 00:00:00';
$fin   = $dateFin   . ' 23:59:59';

// ====== KILOMÉTRAGE ======
$stmt = $pdo->prepare("
    SELECT MIN(kilometrage) AS km_min, MAX(kilometrage) AS km_max,
           COUNT(*) AS nb_releves
    FROM telemetrie WHERE id_vehicule = ? AND horodatage BETWEEN ? AND ?
");
$stmt->execute([$idVehicule, $debut, $fin]);
$statsKm = $stmt->fetch();
$kmPeriode = ($statsKm['km_min'] !== null) ? max(0, $statsKm['km_max'] - $statsKm['km_min']) : 0;

// ====== CONSOMMATION ======
$stmt = $pdo->prepare("
    SELECT AVG(carburant) AS conso_moy, MIN(carburant) AS conso_min
    FROM telemetrie WHERE id_vehicule = ? AND horodatage BETWEEN ? AND ?
");
$stmt->execute([$idVehicule, $debut, $fin]);
$statsConso = $stmt->fetch();

// ====== VITESSE / TEMPÉRATURE ======
$stmt = $pdo->prepare("
    SELECT AVG(vitesse) AS vitesse_moy, MAX(vitesse) AS vitesse_max,
           AVG(temperature_moteur) AS temp_moy, MAX(temperature_moteur) AS temp_max
    FROM telemetrie WHERE id_vehicule = ? AND horodatage BETWEEN ? AND ?
");
$stmt->execute([$idVehicule, $debut, $fin]);
$statsVitesse = $stmt->fetch();

// ====== MISSIONS ======
$stmt = $pdo->prepare("
    SELECT m.*, c.nom AS c_nom, c.prenom AS c_prenom
    FROM missions m JOIN conducteurs c ON m.id_conducteur = c.id_conducteur
    WHERE m.id_vehicule = ? AND m.date_debut BETWEEN ? AND ?
    ORDER BY m.date_debut DESC
");
$stmt->execute([$idVehicule, $debut, $fin]);
$missions = $stmt->fetchAll();

// ====== ALERTES ======
$stmt = $pdo->prepare("
    SELECT type_alerte, COUNT(*) AS nb, COUNT(CASE WHEN statut='resolue' THEN 1 END) AS nb_resolues
    FROM alertes WHERE id_vehicule = ? AND horodatage BETWEEN ? AND ?
    GROUP BY type_alerte ORDER BY nb DESC
");
$stmt->execute([$idVehicule, $debut, $fin]);
$alertesGroupees = $stmt->fetchAll();
$nbAlertesTotal = array_sum(array_column($alertesGroupees, 'nb'));

// ====== CHARGEMENT (minier) ======
$statsChargement = null;
if ($vehicule['type'] === 'minier') {
    $stmt = $pdo->prepare("
        SELECT AVG(CASE WHEN poids_charge_kg > 0 THEN poids_charge_kg END) AS charge_moy,
               MAX(poids_charge_kg) AS charge_max,
               SUM(poids_charge_kg) / 1000 AS tonnage_total,
               COUNT(CASE WHEN poids_charge_kg > 0 THEN 1 END) AS nb_chargements,
               COUNT(CASE WHEN poids_max_kg IS NOT NULL AND poids_charge_kg > poids_max_kg THEN 1 END) AS nb_surcharges
        FROM telemetrie t JOIN vehicules vv ON t.id_vehicule = vv.id_vehicule
        WHERE t.id_vehicule = ? AND t.horodatage BETWEEN ? AND ?
    ");
    $stmt->execute([$idVehicule, $debut, $fin]);
    $statsChargement = $stmt->fetch();
}

// ====== CONSTRUCTION PDF ======
class InvestisseurPDF extends FPDF {
    function Header() {
        // Bandeau vert
        $this->SetFillColor(15, 110, 86);
        $this->Rect(0, 0, 210, 18, 'F');
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Arial', 'B', 14);
        $this->SetY(4);
        $this->Cell(0, 10, t('FleetIoT — Rapport Investisseur'), 0, 1, 'C');
        $this->SetTextColor(0, 0, 0);
        $this->Ln(4);
    }

    function Footer() {
        $this->SetY(-12);
        $this->SetFont('Arial', 'I', 7);
        $this->SetTextColor(150, 150, 150);
        $this->Cell(0, 10, t('FleetIoT — Simandou 2040 — Confidentiel — Genere le ' . date('d/m/Y a H:i') . ' — Page ' . $this->PageNo() . '/{nb}'), 0, 0, 'C');
    }

    function sectionTitre($titre) {
        $this->SetFillColor(240, 247, 244);
        $this->SetTextColor(15, 110, 86);
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(0, 8, t($titre), 0, 1, 'L', true);
        $this->SetTextColor(0, 0, 0);
        $this->Ln(1);
    }

    function ligneDonnee($label, $valeur, $fill = false) {
        $this->SetFillColor(248, 250, 249);
        $this->SetFont('Arial', '', 9);
        $this->Cell(70, 7, t($label), 0, 0, 'L', $fill);
        $this->SetFont('Arial', 'B', 9);
        $this->Cell(0, 7, t($valeur), 0, 1, 'L', $fill);
    }

    function kpi($label, $valeur, $x, $y, $w = 42) {
        $this->SetXY($x, $y);
        $this->SetFillColor(245, 247, 246);
        $this->SetDrawColor(220, 228, 225);
        $this->RoundedRect($x, $y, $w, 18, 2, 'DF');
        $this->SetFont('Arial', '', 7);
        $this->SetTextColor(92, 107, 104);
        $this->SetXY($x + 2, $y + 2);
        $this->Cell($w - 4, 5, t($label), 0, 1, 'C');
        $this->SetFont('Arial', 'B', 11);
        $this->SetTextColor(15, 110, 86);
        $this->SetXY($x + 2, $y + 7);
        $this->Cell($w - 4, 8, t($valeur), 0, 1, 'C');
        $this->SetTextColor(0, 0, 0);
    }

    function RoundedRect($x, $y, $w, $h, $r, $style = '') {
        $k = $this->k;
        $hp = $this->h;
        if($style=='F') $op='f';
        elseif($style=='FD'||$style=='DF') $op='B';
        else $op='S';
        $MyArc = 4/3*(sqrt(2)-1);
        $this->_out(sprintf('%.2F %.2F m',($x+$r)*$k,($hp-$y)*$k));
        $xc=$x+$w-$r; $yc=$y+$r;
        $this->_out(sprintf('%.2F %.2F l',($x+$w-$r)*$k,($hp-$y)*$k));
        $this->_Arc($xc+$r*$MyArc,$yc-$r,$xc+$r,$yc-$r*$MyArc,$xc+$r,$yc);
        $xc=$x+$w-$r; $yc=$y+$h-$r;
        $this->_out(sprintf('%.2F %.2F l',($x+$w)*$k,($hp-($y+$h-$r))*$k));
        $this->_Arc($xc+$r,$yc+$r*$MyArc,$xc+$r*$MyArc,$yc+$r,$xc,$yc+$r);
        $xc=$x+$r; $yc=$y+$h-$r;
        $this->_out(sprintf('%.2F %.2F l',($x+$r)*$k,($hp-($y+$h))*$k));
        $this->_Arc($xc-$r*$MyArc,$yc+$r,$xc-$r,$yc+$r*$MyArc,$xc-$r,$yc);
        $xc=$x+$r; $yc=$y+$r;
        $this->_out(sprintf('%.2F %.2F l',($x)*$k,($hp-($y+$r))*$k));
        $this->_Arc($xc-$r,$yc-$r*$MyArc,$xc-$r*$MyArc,$yc-$r,$xc,$yc-$r);
        $this->_out($op);
    }

    function _Arc($x1,$y1,$x2,$y2,$x3,$y3) {
        $h=$this->h;
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c',
            $x1*$this->k,($h-$y1)*$this->k,$x2*$this->k,($h-$y2)*$this->k,$x3*$this->k,($h-$y3)*$this->k));
    }
}

$pdf = new InvestisseurPDF('P', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->AddPage();
$pdf->SetMargins(14, 24, 14);
$pdf->SetAutoPageBreak(true, 16);

// ====== PAGE 1 : EN-TÊTE RAPPORT ======

// Titre + période
$pdf->SetFont('Arial', 'B', 16);
$pdf->SetTextColor(15, 110, 86);
$pdf->Cell(0, 10, t('Rapport de performance vehicule'), 0, 1, 'C');
$pdf->SetFont('Arial', '', 10);
$pdf->SetTextColor(92, 107, 104);
$pdf->Cell(0, 6, t('Periode : ' . date('d/m/Y', strtotime($dateDebut)) . ' au ' . date('d/m/Y', strtotime($dateFin))), 0, 1, 'C');
$pdf->Ln(6);

// ====== SECTION VÉHICULE + INVESTISSEUR ======
$pdf->sectionTitre('  Informations vehicule');
$pdf->ligneDonnee('Immatriculation', $vehicule['immatriculation'], false);
$pdf->ligneDonnee('Marque / Modele', $vehicule['marque'] . ' ' . $vehicule['modele'] . ' (' . $vehicule['annee'] . ')', true);
$pdf->ligneDonnee('Type', ucfirst($vehicule['type']), false);
$pdf->ligneDonnee('Capacite carburant', $vehicule['capacite_carburant'] . ' L', true);
if ($vehicule['type'] === 'minier') {
    $pdf->ligneDonnee('Charge max autorisee', $vehicule['poids_max_kg'] ? number_format($vehicule['poids_max_kg'], 0, ',', ' ') . ' kg' : '—', false);
    $pdf->ligneDonnee('Poids tare (a vide)', $vehicule['poids_tare_kg'] ? number_format($vehicule['poids_tare_kg'], 0, ',', ' ') . ' kg' : '—', true);
}
$pdf->Ln(4);

$pdf->sectionTitre('  Investisseur / Proprietaire');
$pdf->ligneDonnee('Nom / Societe', $vehicule['proprietaire_nom'], false);
$pdf->ligneDonnee('Email', $vehicule['proprietaire_contact_email'] ?? '—', true);
$pdf->ligneDonnee('Telephone', $vehicule['proprietaire_contact_tel'] ?? '—', false);
$pdf->Ln(4);

$pdf->sectionTitre('  Superviseur responsable');
$pdf->ligneDonnee('Nom', ($vehicule['sup_prenom'] ?? '') . ' ' . ($vehicule['sup_nom'] ?? ''), false);
$pdf->ligneDonnee('Email', $vehicule['sup_email'] ?? '—', true);
$pdf->Ln(6);

// ====== KPI VISUELS ======
$pdf->sectionTitre('  Synthese de la periode');
$pdf->Ln(2);
$yKpi = $pdf->GetY();
$pdf->kpi('Km parcourus', number_format($kmPeriode, 0, ',', ' ') . ' km', 14, $yKpi);
$pdf->kpi('Vitesse moy.', round($statsVitesse['vitesse_moy'] ?? 0) . ' km/h', 59, $yKpi);
$pdf->kpi('Vitesse max', round($statsVitesse['vitesse_max'] ?? 0) . ' km/h', 104, $yKpi);
$pdf->kpi('Missions', count($missions), 149, $yKpi);
$pdf->SetY($yKpi + 22);
$yKpi2 = $pdf->GetY();
$pdf->kpi('Alertes totales', $nbAlertesTotal, 14, $yKpi2);
$pdf->kpi('Releves IoT', number_format($statsKm['nb_releves'] ?? 0, 0, ',', ' '), 59, $yKpi2);
$pdf->kpi('Temp. moy.', round($statsVitesse['temp_moy'] ?? 0) . ' C', 104, $yKpi2);
if ($vehicule['type'] === 'minier' && $statsChargement) {
    $pdf->kpi('Tonnage total', number_format($statsChargement['tonnage_total'] ?? 0, 1, ',', ' ') . ' t', 149, $yKpi2);
}
$pdf->SetY($yKpi2 + 22);
$pdf->Ln(4);

// ====== SECTION CHARGEMENT (minier) ======
if ($vehicule['type'] === 'minier' && $statsChargement) {
    $pdf->sectionTitre('  Chargement minier');
    $pdf->ligneDonnee('Tonnage total transporte', number_format($statsChargement['tonnage_total'] ?? 0, 1, ',', ' ') . ' t', false);
    $pdf->ligneDonnee('Charge moyenne', $statsChargement['charge_moy'] ? number_format($statsChargement['charge_moy'], 0, ',', ' ') . ' kg' : '—', true);
    $pdf->ligneDonnee('Charge maximale relevee', $statsChargement['charge_max'] ? number_format($statsChargement['charge_max'], 0, ',', ' ') . ' kg' : '—', false);
    $pdf->ligneDonnee('Nombre de chargements', $statsChargement['nb_chargements'] ?? 0, true);
    $surchargeTexte = ($statsChargement['nb_surcharges'] ?? 0) > 0
        ? $statsChargement['nb_surcharges'] . ' surcharge(s) detectee(s)'
        : 'Aucune surcharge';
    $pdf->ligneDonnee('Surcharges', $surchargeTexte, false);
    $pdf->Ln(4);
}

// ====== SECTION ALERTES ======
if (!empty($alertesGroupees)) {
    $pdf->sectionTitre('  Recapitulatif des alertes');
    $libellesAlertes = [
        'vitesse_excessive' => 'Vitesse excessive', 'temperature_critique' => 'Temperature critique',
        'carburant_bas' => 'Carburant bas', 'hors_zone' => 'Hors zone', 'surcharge' => 'Surcharge',
        'tpms_pression' => 'Pression pneu (TPMS)', 'tpms_temperature' => 'Temperature pneu (TPMS)',
        'moteur_anomalie' => 'Anomalie moteur',
    ];
    // En-tête tableau
    $pdf->SetFillColor(15, 110, 86);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(100, 7, t('Type d\'alerte'), 1, 0, 'C', true);
    $pdf->Cell(30, 7, t('Nb total'), 1, 0, 'C', true);
    $pdf->Cell(30, 7, t('Resolues'), 1, 0, 'C', true);
    $pdf->Cell(30, 7, t('Non resolues'), 1, 1, 'C', true);
    $pdf->SetTextColor(0, 0, 0);
    $fill = false;
    foreach ($alertesGroupees as $a) {
        $pdf->SetFillColor(248, 250, 249);
        $pdf->SetFont('Arial', '', 8);
        $pdf->Cell(100, 6, t($libellesAlertes[$a['type_alerte']] ?? $a['type_alerte']), 1, 0, 'L', $fill);
        $pdf->Cell(30, 6, t($a['nb']), 1, 0, 'C', $fill);
        $pdf->Cell(30, 6, t($a['nb_resolues']), 1, 0, 'C', $fill);
        $pdf->Cell(30, 6, t($a['nb'] - $a['nb_resolues']), 1, 1, 'C', $fill);
        $fill = !$fill;
    }
    $pdf->Ln(4);
}

// ====== SECTION MISSIONS ======
if (!empty($missions)) {
    $pdf->sectionTitre('  Historique des missions (' . count($missions) . ')');
    $pdf->SetFillColor(15, 110, 86);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(45, 7, t('Conducteur'), 1, 0, 'C', true);
    $pdf->Cell(40, 7, t('Depart'), 1, 0, 'C', true);
    $pdf->Cell(40, 7, t('Destination'), 1, 0, 'C', true);
    $pdf->Cell(25, 7, t('Date debut'), 1, 0, 'C', true);
    $pdf->Cell(22, 7, t('Statut'), 1, 1, 'C', true);
    $pdf->SetTextColor(0, 0, 0);
    $fill = false;
    foreach ($missions as $m) {
        $pdf->SetFillColor(248, 250, 249);
        $pdf->SetFont('Arial', '', 7);
        $pdf->Cell(45, 6, t(($m['c_prenom'] ?? '') . ' ' . ($m['c_nom'] ?? '')), 1, 0, 'L', $fill);
        $pdf->Cell(40, 6, t($m['lieu_depart'] ?? '—'), 1, 0, 'L', $fill);
        $pdf->Cell(40, 6, t($m['lieu_destination'] ?? '—'), 1, 0, 'L', $fill);
        $pdf->Cell(25, 6, t(date('d/m/Y', strtotime($m['date_debut']))), 1, 0, 'C', $fill);
        $pdf->Cell(22, 6, t(ucfirst($m['statut'])), 1, 1, 'C', $fill);
        $fill = !$fill;
    }
    $pdf->Ln(4);
}

// ====== PIED DE RAPPORT ======
$pdf->Ln(4);
$pdf->SetFont('Arial', 'I', 8);
$pdf->SetTextColor(150, 150, 150);
$pdf->Cell(0, 6, t('Ce document est confidentiel et destine uniquement a l\'investisseur et au superviseur concernes.'), 0, 1, 'C');
$pdf->Cell(0, 5, t('FleetIoT — Projet Simandou 2040 — ' . date('Y')), 0, 1, 'C');

// ====== GÉNÉRATION FICHIER PDF ======
$nomFichier = 'rapport_' . $vehicule['immatriculation'] . '_' . $dateDebut . '_' . $dateFin . '.pdf';
$nomFichier = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $nomFichier);

// ====== ENVOI EMAIL SI DEMANDÉ ======
if ($envoyerEmail && !empty($vehicule['sup_email'])) {

    // Capturer le PDF en chaîne binaire (évite le conflit de headers HTTP)
    $pdfString = $pdf->Output('S', $nomFichier);

    // Sauvegarder dans un fichier temporaire
    $tmpPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $nomFichier;
    $ecrit = file_put_contents($tmpPath, $pdfString);

    if (!$ecrit) {
        error_log('[FleetIoT] Impossible d\'écrire le PDF temporaire : ' . $tmpPath);
        header("Location: ../rapports/rapport_mensuel.php?mois=" . substr($dateDebut, 0, 7) . "&statut=email_erreur&immat=" . urlencode($vehicule['immatriculation']));
        exit();
    }

    require_once '../includes/mailer.php';

    $sujet = 'Rapport investisseur — ' . $vehicule['immatriculation'] . ' — ' . date('F Y', strtotime($dateDebut));
    $corps = '
    <div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;">
        <div style="background:#0F6E56;padding:20px;border-radius:8px 8px 0 0;">
            <h2 style="color:#fff;margin:0;">FleetIoT — Rapport Investisseur</h2>
        </div>
        <div style="background:#fff;padding:24px;border:1px solid #E9ECEC;border-radius:0 0 8px 8px;">
            <p>Bonjour ' . htmlspecialchars(($vehicule['sup_prenom'] ?? '') . ' ' . ($vehicule['sup_nom'] ?? '')) . ',</p>
            <p>Veuillez trouver ci-joint le rapport de performance du véhicule <strong>' . htmlspecialchars($vehicule['immatriculation']) . '</strong>
            pour la période du <strong>' . date('d/m/Y', strtotime($dateDebut)) . '</strong> au <strong>' . date('d/m/Y', strtotime($dateFin)) . '</strong>.</p>
            <p>Ce rapport est destiné à l\'investisseur : <strong>' . htmlspecialchars($vehicule['proprietaire_nom']) . '</strong></p>
            <hr style="border:none;border-top:1px solid #E9ECEC;margin:16px 0;">
            <p style="font-size:12px;color:#999;">Ce message a été généré automatiquement par FleetIoT — Simandou 2040.<br>Ne pas répondre à cet email.</p>
        </div>
    </div>';

    $envoye = envoyerEmail(
        $vehicule['sup_email'],
        $sujet,
        $corps,
        null,
        [['path' => $tmpPath, 'name' => $nomFichier]],
        ($vehicule['sup_prenom'] ?? '') . ' ' . ($vehicule['sup_nom'] ?? '')
    );

    // Supprimer le fichier temporaire
    @unlink($tmpPath);

    if ($envoye !== true) {
        $erreurDetail = is_string($envoye) ? $envoye : 'Erreur inconnue PHPMailer';
        error_log('[FleetIoT] Erreur envoi rapport investisseur : ' . $erreurDetail);
        $statut = 'email_erreur';
        $erreurEncode = urlencode($erreurDetail);
        header("Location: ../rapports/rapport_mensuel.php?mois=" . substr($dateDebut, 0, 7) . "&statut=$statut&immat=" . urlencode($vehicule['immatriculation']) . "&erreur=$erreurEncode");
        exit();
    }

    $statut = 'email_ok';
    header("Location: ../rapports/rapport_mensuel.php?mois=" . substr($dateDebut, 0, 7) . "&statut=$statut&immat=" . urlencode($vehicule['immatriculation']));
    exit();
}

// ====== TÉLÉCHARGEMENT DIRECT ======
$pdf->Output('D', $nomFichier);
