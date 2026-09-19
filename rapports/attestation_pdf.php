<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../lib/fpdf/fpdf.php';

requireLogin();

function t($s) { return iconv('UTF-8', 'ISO-8859-1//TRANSLIT', (string)$s); }

$idConducteur = (int) ($_GET['id_conducteur'] ?? 0);
$idFormation  = (int) ($_GET['id_formation']  ?? 0);

// Vérifier accès
if (isConducteur()) {
    $moi = $_SESSION['conducteur_id'] ?? 0;
    if ($idConducteur !== $moi) die('Accès refusé.');
}

// Données
$stmt = $pdo->prepare("
    SELECT c.nom, c.prenom, c.nationalite,
           f.titre, f.categorie, f.duree_minutes,
           fi.score_quiz, fi.date_completion, fi.statut
    FROM formation_inscriptions fi
    JOIN conducteurs c ON fi.id_conducteur = c.id_conducteur
    JOIN formations f ON fi.id_formation = f.id_formation
    WHERE fi.id_conducteur = ? AND fi.id_formation = ? AND fi.statut = 'certifie'
");
$stmt->execute([$idConducteur, $idFormation]);
$data = $stmt->fetch();

if (!$data) die('Attestation non disponible — formation non certifiée.');

$typesLibelles = ['conduite_minier'=>'Conduite minier','ecoconduite'=>'Éco-conduite','maintenance'=>'Maintenance','securite'=>'Sécurité','reglementation'=>'Réglementation','environnement'=>'Environnement'];

// ====== PDF ======
$pdf = new FPDF('L', 'mm', 'A4'); // Paysage
$pdf->AddPage();
$pdf->SetMargins(20, 20, 20);

// Fond décoratif
$pdf->SetFillColor(240, 247, 244);
$pdf->Rect(0, 0, 297, 210, 'F');

// Bordure double
$pdf->SetDrawColor(15, 110, 86);
$pdf->SetLineWidth(3);
$pdf->Rect(10, 10, 277, 190);
$pdf->SetLineWidth(0.5);
$pdf->Rect(13, 13, 271, 184);

// Logo / titre organisation
$pdf->SetY(22);
$pdf->SetFont('Arial', 'B', 11);
$pdf->SetTextColor(15, 110, 86);
$pdf->Cell(0, 8, t('SIMANDOU 2040 — FLEETIOT'), 0, 1, 'C');
$pdf->SetFont('Arial', '', 9);
$pdf->SetTextColor(92, 107, 104);
$pdf->Cell(0, 6, t('Corridor Minier de Simandou — République de Guinée'), 0, 1, 'C');

// Ligne séparatrice
$pdf->SetDrawColor(15, 110, 86);
$pdf->SetLineWidth(0.8);
$pdf->Line(40, 40, 257, 40);
$pdf->Ln(4);

// Titre attestation
$pdf->SetFont('Arial', 'B', 28);
$pdf->SetTextColor(15, 110, 86);
$pdf->Cell(0, 14, t('ATTESTATION DE CERTIFICATION'), 0, 1, 'C');

$pdf->SetFont('Arial', 'I', 13);
$pdf->SetTextColor(92, 107, 104);
$pdf->Cell(0, 8, t('Simandou Academy — Formation Professionnelle'), 0, 1, 'C');
$pdf->Ln(6);

// Corps
$pdf->SetFont('Arial', '', 12);
$pdf->SetTextColor(30, 30, 30);
$pdf->Cell(0, 8, t('Nous certifions que'), 0, 1, 'C');
$pdf->Ln(2);

// Nom conducteur
$pdf->SetFont('Arial', 'B', 22);
$pdf->SetTextColor(15, 110, 86);
$pdf->Cell(0, 12, t(strtoupper($data['prenom']) . ' ' . strtoupper($data['nom'])), 0, 1, 'C');

$pdf->SetFont('Arial', '', 12);
$pdf->SetTextColor(30, 30, 30);
$pdf->Cell(0, 8, t('a suivi et validé avec succès la formation'), 0, 1, 'C');
$pdf->Ln(2);

// Titre formation
$pdf->SetFont('Arial', 'B', 16);
$pdf->SetTextColor(26, 26, 26);
$pdf->Cell(0, 10, t('"' . $data['titre'] . '"'), 0, 1, 'C');

$pdf->SetFont('Arial', '', 11);
$pdf->SetTextColor(92, 107, 104);
$pdf->Cell(0, 7, t('Catégorie : ' . ($typesLibelles[$data['categorie']] ?? $data['categorie'])), 0, 1, 'C');
$pdf->Ln(4);

// Infos score
$pdf->SetFillColor(255, 255, 255);
$pdf->SetDrawColor(15, 110, 86);
$pdf->SetLineWidth(0.5);
$xBox = 70; $wBox = 157;
$pdf->RoundedRect($xBox, $pdf->GetY(), $wBox, 22, 4, 'DF');
$pdf->SetY($pdf->GetY() + 3);
$pdf->SetFont('Arial', '', 10);
$pdf->SetTextColor(92, 107, 104);
$pdf->Cell(0, 6, t('Score obtenu : ' . $data['score_quiz'] . '%    |    Date de certification : ' . date('d/m/Y', strtotime($data['date_completion']))), 0, 1, 'C');
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 6, t($data['duree_minutes'] ? 'Durée de la formation : ' . $data['duree_minutes'] . ' minutes' : ''), 0, 1, 'C');
$pdf->Ln(6);

// Ligne séparatrice bas
$pdf->Line(40, $pdf->GetY(), 257, $pdf->GetY());
$pdf->Ln(4);

// Pied de page attestation
$pdf->SetFont('Arial', 'I', 9);
$pdf->SetTextColor(150, 150, 150);
$pdf->Cell(0, 5, t('Ce document est delivre par FleetIoT dans le cadre du programme de formation Simandou 2040.'), 0, 1, 'C');
$pdf->Cell(0, 5, t('Numero de certification : CERT-' . str_pad($idConducteur, 4, '0', STR_PAD_LEFT) . '-' . str_pad($idFormation, 4, '0', STR_PAD_LEFT) . '-' . date('Ymd', strtotime($data['date_completion']))), 0, 1, 'C');

// Sceau simulé
$pdf->SetFillColor(15, 110, 86);
$pdf->SetDrawColor(15, 110, 86);
$pdf->SetTextColor(255, 255, 255);
$pdf->Ellipse = function() {};
$xSceau = 240; $ySceau = 165;
$r = 14;
// Cercle vert
$pdf->SetXY($xSceau - $r, $ySceau - $r);
$pdf->SetFillColor(15, 110, 86);
for ($i = 0; $i < 360; $i += 5) {
    // Approximer cercle avec rectangles fins — on utilise juste un texte stylisé
}
$pdf->SetFont('Arial', 'B', 7);
$pdf->SetXY($xSceau - 18, $ySceau - 6);
$pdf->SetTextColor(15, 110, 86);
$pdf->Cell(36, 5, t('✓ CERTIFIE'), 0, 0, 'C');
$pdf->SetXY($xSceau - 18, $ySceau);
$pdf->SetFont('Arial', '', 6);
$pdf->Cell(36, 4, t('SIMANDOU 2040'), 0, 0, 'C');

function RoundedRect($pdf, $x, $y, $w, $h, $r, $style='') {
    $k=$pdf->k; $hp=$pdf->h;
    if($style=='F') $op='f'; elseif($style=='FD'||$style=='DF') $op='B'; else $op='S';
    $MyArc = 4/3*(sqrt(2)-1);
    $pdf->_out(sprintf('%.2F %.2F m',($x+$r)*$k,($hp-$y)*$k));
    $xc=$x+$w-$r; $yc=$y+$r;
    $pdf->_out(sprintf('%.2F %.2F l',($x+$w-$r)*$k,($hp-$y)*$k));
    _Arc($pdf,$xc+$r*$MyArc,$yc-$r,$xc+$r,$yc-$r*$MyArc,$xc+$r,$yc);
    $xc=$x+$w-$r; $yc=$y+$h-$r;
    $pdf->_out(sprintf('%.2F %.2F l',($x+$w)*$k,($hp-($y+$h-$r))*$k));
    _Arc($pdf,$xc+$r,$yc+$r*$MyArc,$xc+$r*$MyArc,$yc+$r,$xc,$yc+$r);
    $xc=$x+$r; $yc=$y+$h-$r;
    $pdf->_out(sprintf('%.2F %.2F l',($x+$r)*$k,($hp-($y+$h))*$k));
    _Arc($pdf,$xc-$r*$MyArc,$yc+$r,$xc-$r,$yc+$r*$MyArc,$xc-$r,$yc);
    $xc=$x+$r; $yc=$y+$r;
    $pdf->_out(sprintf('%.2F %.2F l',($x)*$k,($hp-($y+$r))*$k));
    _Arc($pdf,$xc-$r,$yc-$r*$MyArc,$xc-$r*$MyArc,$yc-$r,$xc,$yc-$r);
    $pdf->_out($op);
}
function _Arc($pdf,$x1,$y1,$x2,$y2,$x3,$y3) {
    $h=$pdf->h;
    $pdf->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c',$x1*$pdf->k,($h-$y1)*$pdf->k,$x2*$pdf->k,($h-$y2)*$pdf->k,$x3*$pdf->k,($h-$y3)*$pdf->k));
}

$nomFichier = 'Attestation_' . $data['prenom'] . '_' . $data['nom'] . '_' . preg_replace('/[^a-z0-9]/i', '_', $data['titre']) . '.pdf';
$pdf->Output('D', $nomFichier);
