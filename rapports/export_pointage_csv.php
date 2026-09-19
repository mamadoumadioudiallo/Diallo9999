<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireSuperviseur();
$estAdmin = isAdmin();
$idSup    = $_SESSION['user_id'];

$dateDebut = $_GET['date_debut'] ?? date('Y-m-01');
$dateFin   = $_GET['date_fin']   ?? date('Y-m-d');
$filtreSup = $estAdmin ? ($_GET['sup'] ?? '') : $idSup;

$condWhere = $filtreSup ? "AND c.id_superviseur = ?" : "";
$condParams = $filtreSup ? [$filtreSup] : [];

// Détail par conducteur par jour
$stmt = $pdo->prepare("
    SELECT c.nom, c.prenom,
           u.prenom AS sup_prenom, u.nom AS sup_nom,
           DATE(p.horodatage) AS jour,
           MIN(CASE WHEN p.type_pointage='arrivee' THEN p.horodatage END) AS heure_arrivee,
           MAX(CASE WHEN p.type_pointage='depart' THEN p.horodatage END) AS heure_depart,
           COUNT(CASE WHEN p.type_pointage='pause' THEN 1 END) AS nb_pauses,
           GROUP_CONCAT(p.note ORDER BY p.horodatage SEPARATOR ' | ') AS notes
    FROM conducteurs c
    JOIN pointages p ON c.id_conducteur = p.id_conducteur
    LEFT JOIN utilisateurs u ON c.id_superviseur = u.id_user
    WHERE DATE(p.horodatage) BETWEEN ? AND ? AND c.statut = 'actif' $condWhere
    GROUP BY c.id_conducteur, DATE(p.horodatage)
    ORDER BY DATE(p.horodatage) DESC, c.nom ASC
");
$stmt->execute(array_merge([$dateDebut, $dateFin], $condParams));
$lignes = $stmt->fetchAll();

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="pointage_' . $dateDebut . '_' . $dateFin . '.csv"');
header('Pragma: no-cache');

$out = fopen('php://output', 'w');
fputs($out, "\xEF\xBB\xBF"); // BOM UTF-8 pour Excel

$headers = ['Date','Conducteur','Superviseur','Heure arrivée','Heure départ','Nb pauses','Durée (h)','Notes'];
fputcsv($out, $headers, ';');

foreach ($lignes as $l) {
    $duree = '';
    if ($l['heure_arrivee'] && $l['heure_depart']) {
        $sec = strtotime($l['heure_depart']) - strtotime($l['heure_arrivee']);
        $duree = round($sec / 3600, 2);
    }
    fputcsv($out, [
        date('d/m/Y', strtotime($l['jour'])),
        $l['prenom'] . ' ' . $l['nom'],
        ($l['sup_prenom'] ?? '') . ' ' . ($l['sup_nom'] ?? ''),
        $l['heure_arrivee'] ? date('H:i', strtotime($l['heure_arrivee'])) : '',
        $l['heure_depart']  ? date('H:i', strtotime($l['heure_depart']))  : '',
        $l['nb_pauses'],
        $duree,
        $l['notes'] ?? '',
    ], ';');
}

fclose($out);
