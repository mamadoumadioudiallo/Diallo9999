<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : scripts/backup_cli.php
// Rôle    : Sauvegarde automatique de la base de données.
//           Conçu pour être lancé par le Planificateur de tâches
//           Windows (ou une cron job en production), pas via le navigateur.
//
// Lancement : php scripts/backup_cli.php
// ============================================================

require dirname(__DIR__) . '/includes/db.php';
require dirname(__DIR__) . '/includes/backup.php';

$dossierBackup = dirname(__DIR__) . '/backups';

if (!is_dir($dossierBackup)) {
    mkdir($dossierBackup, 0755, true);
}

$nomFichier = sauvegarderSurDisque($pdo, DB_NAME, $dossierBackup, 14);

echo "[" . date('Y-m-d H:i:s') . "] Sauvegarde créée : $nomFichier\n";