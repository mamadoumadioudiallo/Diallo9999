<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : rapports/export_pdf.php
// Rôle    : Export PDF des rapports (consommation, kilométrage, alertes)
//           Utilise FPDF (bibliothèque légère, sans Composer)
// ============================================================

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireSuperviseur();

require_once '../lib/fpdf/fpdf.php';

$idSup = $_SESSION['user_id'];
$estAdmin = isAdmin();

$type = $_GET['type'] ?? 'conso';
$dateDebut = $_GET['date_debut'] ?? date('Y-m-d', strtotime('-7 days'));
$dateFin = $_GET['date_fin'] ?? date('Y-m-d');

// FPDF n'accepte que du Latin-1 : on convertit chaque texte avant affichage.
function t($texte) {
    return iconv('UTF-8', 'ISO-8859-1//TRANSLIT', (string) $texte);
}

// ====== CLASSE PDF PERSONNALISÉE (en-tête + pied de page FleetIoT) ======
class FleetIotPDF extends FPDF {
    public $titreRapport = '';
    public $periodeTexte = '';

    function Header() {
        $this->SetFillColor(15, 110, 86); // vert FleetIoT
        $this->Rect(0, 0, 210, 22, 'F');

        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Arial', 'B', 16);
        $this->SetXY(10, 6);
        $this->Cell(0, 8, 'FleetIoT', 0, 1);

        $this->SetFont('Arial', '', 10);
        $this->SetXY(10, 14);
        $this->Cell(0, 6, t($this->titreRapport . ' - ' . $this->periodeTexte), 0, 1);

        $this->SetTextColor(0, 0, 0);
        $this->SetY(28);
    }

    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(150, 150, 150);
        $this->Cell(0, 10, t('Genere le ' . date('d/m/Y a H:i') . ' - Page ' . $this->PageNo() . '/{nb}'), 0, 0, 'C');
    }

    function TableHeader($colonnes, $largeurs) {
        $this->SetFillColor(15, 110, 86);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Arial', 'B', 9);
        foreach ($colonnes as $i => $col) {
            $this->Cell($largeurs[$i], 8, t($col), 1, 0, 'C', true);
        }
        $this->Ln();
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Arial', '', 8);
    }

    function TableRow($valeurs, $largeurs, $fill = false) {
        $this->SetFillColor(245, 247, 246);
        foreach ($valeurs as $i => $val) {
            $this->Cell($largeurs[$i], 7, t($val), 1, 0, 'L', $fill);
        }
        $this->Ln();
    }
}

$periodeTexte = 'Du ' . date('d/m/Y', strtotime($dateDebut)) . ' au ' . date('d/m/Y', strtotime($dateFin));

$pdf = new FleetIotPDF();
$pdf->AliasNbPages();
$pdf->periodeTexte = $periodeTexte;

// ============================================================
// RAPPORT DE CONSOMMATION
// ============================================================
if ($type === 'conso') {

    $pdf->titreRapport = 'Rapport de consommation';
    $pdf->AddPage();

    $coefConso = ['minier' => 0.35, 'routier' => 0.12];

    $sqlVeh = "SELECT id_vehicule, immatriculation, marque, modele, type, capacite_carburant FROM vehicules";
    $paramsVeh = [];
    if (!$estAdmin) {
        $sqlVeh .= " WHERE id_superviseur = ?";
        $paramsVeh[] = $idSup;
    }
    $sqlVeh .= " ORDER BY immatriculation";
    $stmt = $pdo->prepare($sqlVeh);
    $stmt->execute($paramsVeh);
    $vehicules = $stmt->fetchAll();

    $largeurs = [30, 50, 25, 30, 30, 25];
    $pdf->TableHeader(['Immat.', 'Marque/Modele', 'Type', 'Distance', 'Carburant', 'Conso moy.'], $largeurs);

    $totalKm = 0;
    $totalLitres = 0;
    $fill = false;

    foreach ($vehicules as $v) {
        $stmt = $pdo->prepare("SELECT MIN(kilometrage) AS km_min, MAX(kilometrage) AS km_max FROM telemetrie WHERE id_vehicule = ? AND horodatage BETWEEN ? AND ?");
        $stmt->execute([$v['id_vehicule'], $dateDebut . ' 00:00:00', $dateFin . ' 23:59:59']);
        $res = $stmt->fetch();

        $distance = ($res['km_min'] !== null) ? max(0, $res['km_max'] - $res['km_min']) : 0;
        $coef = $coefConso[$v['type']] ?? 0.15;
        $litres = $distance * ($coef / 100) * $v['capacite_carburant'];
        $consoMoyenne = $distance > 0 ? ($litres / $distance) * 100 : 0;

        $pdf->TableRow([
            $v['immatriculation'], $v['marque'] . ' ' . $v['modele'], ucfirst($v['type']),
            round($distance, 1) . ' km', round($litres, 1) . ' L', round($consoMoyenne, 1) . ' L/100km',
        ], $largeurs, $fill);

        $totalKm += $distance;
        $totalLitres += $litres;
        $fill = !$fill;
    }

    $pdf->Ln(6);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 8, t('Total : ' . round($totalKm, 0) . ' km parcourus - ' . round($totalLitres, 1) . ' L estimes consommes'), 0, 1);

// ============================================================
// RAPPORT KILOMÉTRIQUE
// ============================================================
} elseif ($type === 'km') {

    $pdf->titreRapport = 'Rapport kilometrique';
    $pdf->AddPage();

    $sqlVeh = "SELECT id_vehicule, immatriculation, marque, modele, type FROM vehicules";
    $paramsVeh = [];
    if (!$estAdmin) {
        $sqlVeh .= " WHERE id_superviseur = ?";
        $paramsVeh[] = $idSup;
    }
    $sqlVeh .= " ORDER BY immatriculation";
    $stmt = $pdo->prepare($sqlVeh);
    $stmt->execute($paramsVeh);
    $vehicules = $stmt->fetchAll();

    $largeurs = [35, 60, 30, 35, 30];
    $pdf->TableHeader(['Immat.', 'Marque/Modele', 'Type', 'Distance', 'Releves'], $largeurs);

    $totalKm = 0;
    $fill = false;

    foreach ($vehicules as $v) {
        $stmt = $pdo->prepare("SELECT MIN(kilometrage) AS km_min, MAX(kilometrage) AS km_max, COUNT(*) AS nb FROM telemetrie WHERE id_vehicule = ? AND horodatage BETWEEN ? AND ?");
        $stmt->execute([$v['id_vehicule'], $dateDebut . ' 00:00:00', $dateFin . ' 23:59:59']);
        $res = $stmt->fetch();

        $distance = ($res['km_min'] !== null) ? max(0, $res['km_max'] - $res['km_min']) : 0;

        $pdf->TableRow([
            $v['immatriculation'], $v['marque'] . ' ' . $v['modele'], ucfirst($v['type']),
            round($distance, 1) . ' km', (int) $res['nb'],
        ], $largeurs, $fill);

        $totalKm += $distance;
        $fill = !$fill;
    }

    $pdf->Ln(6);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 8, t('Total : ' . round($totalKm, 0) . ' km parcourus sur la periode'), 0, 1);

// ============================================================
// RAPPORT DES ALERTES
// ============================================================
} elseif ($type === 'alertes') {

    $pdf->titreRapport = 'Rapport des alertes';
    $pdf->AddPage();

    $filtreType = $_GET['type_alerte'] ?? '';

    $sql = "
        SELECT a.type_alerte, a.valeur_declenchante, a.statut, a.horodatage,
               v.immatriculation, u.nom AS traitant_nom, u.prenom AS traitant_prenom
        FROM alertes a
        JOIN vehicules v ON a.id_vehicule = v.id_vehicule
        LEFT JOIN utilisateurs u ON a.id_traitant = u.id_user
        WHERE a.horodatage BETWEEN ? AND ?
    ";
    $params = [$dateDebut . ' 00:00:00', $dateFin . ' 23:59:59'];

    if (!$estAdmin) {
        $sql .= " AND v.id_superviseur = ?";
        $params[] = $idSup;
    }
    if ($filtreType) {
        $sql .= " AND a.type_alerte = ?";
        $params[] = $filtreType;
    }
    $sql .= " ORDER BY a.horodatage DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $alertes = $stmt->fetchAll();

    $largeurs = [25, 35, 30, 30, 25, 45];
    $pdf->TableHeader(['Immat.', 'Type', 'Valeur', 'Date', 'Statut', 'Traite par'], $largeurs);

    $statutLabel = ['non_traitee' => 'Non traitee', 'en_cours' => 'En cours', 'resolue' => 'Resolue'];
    $fill = false;

    foreach ($alertes as $a) {
        $traitant = $a['traitant_nom'] ? $a['traitant_prenom'] . ' ' . $a['traitant_nom'] : '-';

        $pdf->TableRow([
            $a['immatriculation'],
            str_replace('_', ' ', ucfirst($a['type_alerte'])),
            $a['valeur_declenchante'],
            date('d/m/Y H:i', strtotime($a['horodatage'])),
            $statutLabel[$a['statut']] ?? $a['statut'],
            $traitant,
        ], $largeurs, $fill);

        $fill = !$fill;
    }

    $pdf->Ln(6);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 8, t('Total : ' . count($alertes) . ' alerte(s) sur la periode'), 0, 1);

} else {
    $pdf->titreRapport = 'Rapport inconnu';
    $pdf->AddPage();
    $pdf->Cell(0, 10, 'Type de rapport non reconnu.', 0, 1);
}

$nomFichier = 'fleetiot_rapport_' . $type . '_' . date('Y-m-d') . '.pdf';
$pdf->Output('D', $nomFichier);