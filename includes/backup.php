<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : includes/backup.php
// Rôle    : Génération d'une sauvegarde SQL complète de la base,
//           en PHP pur (pas besoin de mysqldump en ligne de commande)
// ============================================================

function genererSauvegardeSql(PDO $pdo, $nomBase) {
    $sql = "-- ============================================================\n";
    $sql .= "-- FleetIoT — Sauvegarde de la base '" . $nomBase . "'\n";
    $sql .= "-- Générée le " . date('d/m/Y à H:i:s') . "\n";
    $sql .= "-- ============================================================\n\n";
    $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $table) {
        // Structure de la table
        $sql .= "-- ------------------------------------------------------------\n";
        $sql .= "-- Table : $table\n";
        $sql .= "-- ------------------------------------------------------------\n";
        $sql .= "DROP TABLE IF EXISTS `$table`;\n";

        $creation = $pdo->query("SHOW CREATE TABLE `$table`")->fetch();
        $sql .= $creation['Create Table'] . ";\n\n";

        // Données de la table
        $lignes = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($lignes)) {
            $colonnes = array_keys($lignes[0]);
            $colonnesStr = '`' . implode('`, `', $colonnes) . '`';

            foreach ($lignes as $ligne) {
                $valeurs = array_map(function ($val) use ($pdo) {
                    if ($val === null) return 'NULL';
                    return $pdo->quote($val);
                }, array_values($ligne));

                $sql .= "INSERT INTO `$table` ($colonnesStr) VALUES (" . implode(', ', $valeurs) . ");\n";
            }
            $sql .= "\n";
        }
    }

    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

    return $sql;
}

/**
 * Sauvegarde sur disque (utilisée par le script CLI planifié) avec
 * rotation : ne garde que les N sauvegardes les plus récentes.
 */
function sauvegarderSurDisque(PDO $pdo, $nomBase, $dossierBackup, $maxSauvegardesConservees = 14) {
    $contenu = genererSauvegardeSql($pdo, $nomBase);
    $nomFichier = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
    $chemin = rtrim($dossierBackup, '/') . '/' . $nomFichier;

    file_put_contents($chemin, $contenu);

    // Rotation : supprime les plus anciennes si on dépasse la limite
    $fichiers = glob(rtrim($dossierBackup, '/') . '/backup_*.sql');
    if (count($fichiers) > $maxSauvegardesConservees) {
        usort($fichiers, function ($a, $b) { return filemtime($a) <=> filemtime($b); });
        $aSupprimer = array_slice($fichiers, 0, count($fichiers) - $maxSauvegardesConservees);
        foreach ($aSupprimer as $f) {
            unlink($f);
        }
    }

    return $nomFichier;
}