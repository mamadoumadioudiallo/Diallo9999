<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : api/vehicules_par_sup.php
// Rôle    : Retourne les véhicules d'un superviseur en JSON
//           (utilisé par les filtres dynamiques des pages admin)
// ============================================================

session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';

requireLogin();

header('Content-Type: application/json; charset=utf-8');

$idSup = (int) ($_GET['sup'] ?? 0);
$type  = $_GET['type'] ?? '';

if (!$idSup) {
    echo json_encode([]);
    exit();
}

$conditions = ["statut = 'actif'", "id_superviseur = ?"];
$params = [$idSup];
if ($type) { $conditions[] = "type = ?"; $params[] = $type; }

$stmt = $pdo->prepare("
    SELECT id_vehicule, immatriculation, type
    FROM vehicules
    WHERE " . implode(' AND ', $conditions) . "
    ORDER BY immatriculation
");
$stmt->execute($params);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
