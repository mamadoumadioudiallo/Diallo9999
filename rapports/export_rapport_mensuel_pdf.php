<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : rapports/export_rapport_mensuel_pdf.php
// Rôle    : Export PDF du rapport mensuel officiel (présentable
//           aux autorités, partenaires, régulateurs)
// ============================================================

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAdmin();
require_once '../lib/fpdf/fpdf.php';

function t($texte) {
    return iconv('UTF-8', 'ISO-8859-1//TRANSLIT', (string) $texte);
}

$mois = $_GET['mois'] ?? date('Y-m');
$dateDebut = $mois . '-01';
$dateFin = date('Y-m-t', strtotime($dateDebut));

// ====== MÊMES CALCULS QUE rapport_mensuel.php ======
$nbConducteursTotal = $pdo->query("SELECT COUNT(*) FROM conducteurs WHERE statut = 'actif'")->fetchColumn();
$nbConducteursGuineens = $pdo->query("SELECT COUNT(*) FROM conducteurs WHERE statut = 'actif' AND nationalite = 'Guinéenne'")->fetchColumn();
$nbFormesAcademy = $pdo->query("SELECT COUNT(*) FROM conducteurs WHERE statut = 'actif' AND formation_academy = 'certifie'")->fetchColumn();
$pctGuineens = $nbConducteursTotal > 0 ? round(($nbConducteursGuineens / $nbConducteursTotal) * 100) : 0;
$pctFormes = $nbConducteursTotal > 0 ? round(($nbFormesAcademy / $nbConducteursTotal) * 100) : 0;

$nbVehicules = $pdo->query("SELECT COUNT(*) FROM vehicules WHERE statut = 'actif'")->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM missions WHERE date_debut BETWEEN ? AND ?");
$stmt->execute([$dateDebut . ' 00:00:00', $dateFin . ' 23:59:59']);
$nbMissions = $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT SUM(km_max - km_min) FROM (
        SELECT id_vehicule, MAX(kilometrage) AS km_max, MIN(kilometrage) AS km_min
        FROM telemetrie WHERE horodatage BETWEEN ? AND ? GROUP BY id_vehicule
    ) t
");
$stmt->execute([$dateDebut . ' 00:00:00', $dateFin . ' 23:59:59']);
$kmTotal = (float) $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM alertes WHERE horodatage BETWEEN ? AND ?");
$stmt->execute([$dateDebut . ' 00:00:00', $dateFin . ' 23:59:59']);
$nbAlertesTotal = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM alertes WHERE horodatage BETWEEN ? AND ? AND statut = 'resolue'");
$stmt->execute([$dateDebut . ' 00:00:00', $dateFin . ' 23:59:59']);
$nbAlertesResolues = $stmt->fetchColumn();
$tauxResolution = $nbAlertesTotal > 0 ? round(($nbAlertesResolues / $nbAlertesTotal) * 100) : 100;

$coefConso = ['minier' => 0.35, 'routier' => 0.12];
$stmtVeh = $pdo->query("SELECT id_vehicule, type, capacite_carburant FROM vehicules WHERE statut = 'actif'");
$litresTotal = 0;
foreach ($stmtVeh->fetchAll() as $v) {
    $stmt = $pdo->prepare("SELECT MIN(kilometrage) AS km_min, MAX(kilometrage) AS km_max FROM telemetrie WHERE id_vehicule = ? AND horodatage BETWEEN ? AND ?");
    $stmt->execute([$v['id_vehicule'], $dateDebut . ' 00:00:00', $dateFin . ' 23:59:59']);
    $res = $stmt->fetch();
    $distance = ($res['km_min'] !== null) ? max(0, $res['km_max'] - $res['km_min']) : 0;
    $coef = $coefConso[$v['type']] ?? 0.15;
    $litresTotal += $distance * ($coef / 100) * $v['capacite_carburant'];
}
$co2EstimeKg = round($litresTotal * 2.68, 0);

// ====== GÉNÉRATION DU PDF ======
class RapportMensuelPDF extends FPDF {
    public $periodeTexte = '';

    function Header() {
        $this->SetFillColor(15, 110, 86);
        $this->Rect(0, 0, 210, 26, 'F');
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Arial', 'B', 16);
        $this->SetXY(10, 6);
        $this->Cell(0, 8, 'FleetIoT - Rapport mensuel officiel', 0, 1);
        $this->SetFont('Arial', '', 10);
        $this->SetXY(10, 15);
        $this->Cell(0, 6, t('Programme Simandou 2040 - ' . $this->periodeTexte), 0, 1);
        $this->SetTextColor(0, 0, 0);
        $this->SetY(34);
    }

    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(150, 150, 150);
        $this->Cell(0, 10, t('Document genere automatiquement le ' . date('d/m/Y a H:i') . ' - Page ' . $this->PageNo() . '/{nb}'), 0, 0, 'C');
    }

    function SectionTitre($titre) {
        $this->SetFont('Arial', 'B', 13);
        $this->SetTextColor(15, 110, 86);
        $this->Cell(0, 10, t($titre), 0, 1);
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Arial', '', 10);
        $this->Ln(2);
    }

    function LigneIndicateur($label, $valeur) {
        $this->SetFont('Arial', '', 10);
        $this->Cell(110, 8, t($label), 0, 0);
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(0, 8, t($valeur), 0, 1);
    }
}

$pdf = new RapportMensuelPDF();
$pdf->AliasNbPages();
$pdf->periodeTexte = ucfirst($mois);
$pdf->AddPage();

$pdf->SectionTitre('Contenu local (conformite Simandou 2040)');
$pdf->LigneIndicateur('Conducteurs de nationalite guineenne', $pctGuineens . '% (' . $nbConducteursGuineens . ' / ' . $nbConducteursTotal . ')');
$pdf->LigneIndicateur('Conducteurs certifies Simandou Academy', $pctFormes . '% (' . $nbFormesAcademy . ' conducteur(s))');
$pdf->Ln(8);

$pdf->SectionTitre('Activite de la flotte');
$pdf->LigneIndicateur('Vehicules actifs', $nbVehicules);
$pdf->LigneIndicateur('Missions realisees sur la periode', $nbMissions);
$pdf->LigneIndicateur('Distance totale parcourue', number_format($kmTotal, 0, ',', ' ') . ' km');
$pdf->Ln(8);

$pdf->SectionTitre('Securite et gestion des alertes');
$pdf->LigneIndicateur('Alertes declenchees sur la periode', $nbAlertesTotal);
$pdf->LigneIndicateur('Taux de resolution des alertes', $tauxResolution . '%');
$pdf->Ln(8);

$pdf->SectionTitre('Impact environnemental estime');
$pdf->LigneIndicateur('Carburant consomme (estimation)', number_format($litresTotal, 0, ',', ' ') . ' L');
$pdf->LigneIndicateur('Emissions de CO2 estimees', number_format($co2EstimeKg, 0, ',', ' ') . ' kg');
$pdf->Ln(4);
$pdf->SetFont('Arial', 'I', 8);
$pdf->SetTextColor(120, 120, 120);
$pdf->MultiCell(0, 5, t('Estimation basee sur un coefficient moyen de ~2,68 kg de CO2 par litre de diesel consomme. Donnees calculees a partir de la telemetrie des vehicules sur la periode selectionnee.'));

$nomFichier = 'fleetiot_rapport_mensuel_' . $mois . '.pdf';
$pdf->Output('D', $nomFichier);