<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../lib/fpdf/fpdf.php';

requireSuperviseur();
$estAdmin = isAdmin();
$idSup    = $_SESSION['user_id'];

function t($s) { return iconv('UTF-8', 'ISO-8859-1//TRANSLIT', (string)$s); }

$dateDebut = $_GET['date_debut'] ?? date('Y-m-01');
$dateFin   = $_GET['date_fin']   ?? date('Y-m-d');
$filtreSup = $estAdmin ? ($_GET['sup'] ?? '') : $idSup;

$condWhere = $filtreSup ? "AND c.id_superviseur = ?" : "";
$condParams = $filtreSup ? [$filtreSup] : [];

$stmt = $pdo->prepare("
    SELECT c.id_conducteur, c.nom, c.prenom,
           u.nom AS sup_nom, u.prenom AS sup_prenom,
           MIN(CASE WHEN p.type_pointage='arrivee' THEN p.horodatage END) AS heure_arrivee,
           MAX(CASE WHEN p.type_pointage='depart' THEN p.horodatage END) AS heure_depart,
           COUNT(CASE WHEN p.type_pointage='pause' THEN 1 END) AS nb_pauses
    FROM conducteurs c
    LEFT JOIN pointages p ON c.id_conducteur = p.id_conducteur AND DATE(p.horodatage) BETWEEN ? AND ?
    LEFT JOIN utilisateurs u ON c.id_superviseur = u.id_user
    WHERE c.statut = 'actif' $condWhere
    GROUP BY c.id_conducteur, c.nom, c.prenom, u.nom, u.prenom
    ORDER BY c.nom ASC
");
$stmt->execute(array_merge([$dateDebut, $dateFin], $condParams));
$conducteurs = $stmt->fetchAll();

// Stats globales
$nbPresents = 0; $nbAbsents = 0; $totalHeures = 0;
foreach ($conducteurs as $c) {
    if ($c['heure_arrivee']) {
        $nbPresents++;
        if ($c['heure_arrivee'] && $c['heure_depart']) {
            $totalHeures += (strtotime($c['heure_depart']) - strtotime($c['heure_arrivee'])) / 3600;
        }
    } else { $nbAbsents++; }
}

$pdf = new FPDF('L','mm','A4');
$pdf->AddPage();
$pdf->SetMargins(14, 14, 14);
$pdf->SetAutoPageBreak(true, 14);

// En-tête
$pdf->SetFillColor(15, 110, 86);
$pdf->Rect(0, 0, 297, 16, 'F');
$pdf->SetTextColor(255,255,255);
$pdf->SetFont('Arial','B',13);
$pdf->Cell(0, 16, t('FleetIoT — Simandou 2040 — Rapport de Pointage'), 0, 1, 'C');
$pdf->SetTextColor(0,0,0);
$pdf->Ln(3);

$pdf->SetFont('Arial','B',11);
$pdf->Cell(0, 8, t('Période : ' . date('d/m/Y', strtotime($dateDebut)) . ' au ' . date('d/m/Y', strtotime($dateFin))), 0, 1, 'C');
$pdf->Ln(2);

// KPI
$pdf->SetFillColor(240,247,244);
$pdf->SetFont('Arial','B',9);
$pdf->Cell(65, 10, t('Conducteurs actifs : ' . count($conducteurs)), 1, 0, 'C', true);
$pdf->Cell(65, 10, t('Ont pointé : ' . $nbPresents), 1, 0, 'C', true);
$pdf->Cell(65, 10, t('Absents : ' . $nbAbsents), 1, 0, 'C', true);
$pdf->Cell(68, 10, t('Total heures travaillées : ' . round($totalHeures, 1) . 'h'), 1, 1, 'C', true);
$pdf->Ln(4);

// Tableau
$pdf->SetFillColor(15,110,86);
$pdf->SetTextColor(255,255,255);
$pdf->SetFont('Arial','B',8);
$cols = $estAdmin
    ? [['Conducteur',55],['Superviseur',45],['Arrivée',25],['Départ',25],['Pauses',18],['Durée',22],['Statut',25],['Notes',60]]
    : [['Conducteur',65],['Arrivée',30],['Départ',30],['Pauses',22],['Durée',28],['Statut',30],['Notes',62]];
foreach ($cols as $col) { $pdf->Cell($col[1], 8, t($col[0]), 1, 0, 'C', true); }
$pdf->Ln();

$pdf->SetTextColor(0,0,0);
$fill = false;
foreach ($conducteurs as $c) {
    $duree = '—';
    if ($c['heure_arrivee'] && $c['heure_depart']) {
        $sec = strtotime($c['heure_depart']) - strtotime($c['heure_arrivee']);
        $duree = floor($sec/3600).'h'.str_pad(floor(($sec%3600)/60),2,'0',STR_PAD_LEFT);
    }
    $statut = $c['heure_arrivee'] ? ($c['heure_depart'] ? 'Parti' : 'Présent') : 'Absent';
    $pdf->SetFillColor($fill?248:255,$fill?250:255,$fill?249:255);
    $pdf->SetFont('Arial','',$c['heure_arrivee']?8:8);
    $pdf->SetTextColor($c['heure_arrivee']?0:150,$c['heure_arrivee']?0:150,$c['heure_arrivee']?0:150);

    if ($estAdmin) {
        $pdf->Cell(55, 7, t($c['prenom'].' '.$c['nom']), 1, 0, 'L', $fill);
        $pdf->Cell(45, 7, t(($c['sup_prenom']??'').' '.($c['sup_nom']??'')), 1, 0, 'L', $fill);
    } else {
        $pdf->Cell(65, 7, t($c['prenom'].' '.$c['nom']), 1, 0, 'L', $fill);
    }
    $pdf->Cell($estAdmin?25:30, 7, t($c['heure_arrivee']?date('H:i',strtotime($c['heure_arrivee'])):'—'), 1, 0, 'C', $fill);
    $pdf->Cell($estAdmin?25:30, 7, t($c['heure_depart']?date('H:i',strtotime($c['heure_depart'])):'—'), 1, 0, 'C', $fill);
    $pdf->Cell($estAdmin?18:22, 7, t($c['nb_pauses']?:'0'), 1, 0, 'C', $fill);
    $pdf->Cell($estAdmin?22:28, 7, t($duree), 1, 0, 'C', $fill);
    $pdf->Cell($estAdmin?25:30, 7, t($statut), 1, 0, 'C', $fill);
    $pdf->Cell($estAdmin?60:62, 7, '', 1, 1, 'L', $fill);
    $fill = !$fill;
}

$pdf->SetTextColor(150,150,150);
$pdf->SetFont('Arial','I',7);
$pdf->Ln(4);
$pdf->Cell(0, 5, t('Généré le '.date('d/m/Y à H:i').' — FleetIoT Simandou 2040'), 0, 1, 'C');

$pdf->Output('D', 'pointage_' . $dateDebut . '_' . $dateFin . '.pdf');
