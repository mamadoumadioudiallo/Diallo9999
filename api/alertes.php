<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : api/alertes.php
// Rôle    : Endpoint AJAX — prise en charge / résolution d'une alerte
//           depuis le tableau de bord (admin ou superviseur)
//           Trace qui a pris en charge l'alerte (id_traitant)
// ============================================================

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireSuperviseur(); // admin OU superviseur

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Méthode non autorisée.'], 405);
}

if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    jsonResponse(['success' => false, 'error' => 'Session expirée, veuillez recharger la page.'], 403);
}

$idAlerte = (int) ($_POST['id_alerte'] ?? 0);
$action = $_POST['action'] ?? '';

if (!$idAlerte || !in_array($action, ['take', 'resolve'], true)) {
    jsonResponse(['success' => false, 'error' => 'Requête invalide.'], 400);
}

$nouveauStatut = ($action === 'take') ? 'en_cours' : 'resolue';
$idTraitant = $_SESSION['user_id'];

// ====== VÉRIFICATION D'EXISTENCE + DROIT D'ACCÈS (avant modification) ======
if (isAdmin()) {
    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM alertes WHERE id_alerte = ?");
    $checkStmt->execute([$idAlerte]);
} else {
    $checkStmt = $pdo->prepare("
        SELECT COUNT(*) FROM alertes a
        JOIN vehicules v ON a.id_vehicule = v.id_vehicule
        WHERE a.id_alerte = ? AND v.id_superviseur = ?
    ");
    $checkStmt->execute([$idAlerte, $idTraitant]);
}

if ($checkStmt->fetchColumn() == 0) {
    jsonResponse(['success' => false, 'error' => 'Alerte introuvable ou accès refusé.'], 404);
}

// ====== MISE À JOUR (statut + traçabilité de qui a traité) ======
if (isAdmin()) {
    $stmt = $pdo->prepare("UPDATE alertes SET statut = ?, id_traitant = ? WHERE id_alerte = ?");
    $stmt->execute([$nouveauStatut, $idTraitant, $idAlerte]);
} else {
    $stmt = $pdo->prepare("
        UPDATE alertes a
        JOIN vehicules v ON a.id_vehicule = v.id_vehicule
        SET a.statut = ?, a.id_traitant = ?
        WHERE a.id_alerte = ? AND v.id_superviseur = ?
    ");
    $stmt->execute([$nouveauStatut, $idTraitant, $idAlerte, $idTraitant]);
}

jsonResponse(['success' => true, 'id_alerte' => $idAlerte, 'statut' => $nouveauStatut]);