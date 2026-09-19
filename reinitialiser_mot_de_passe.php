<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : reinitialiser_mot_de_passe.php
// Rôle    : Définition d'un nouveau mot de passe via un token valide
// ============================================================

session_start();
require_once 'includes/db.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';

if (isset($_SESSION['user_id'])) {
    header('Location: ' . ($_SESSION['user_role'] === 'admin' ? 'admin/dashboard.php' : 'superviseur/dashboard.php'));
    exit();
}

$token = $_GET['token'] ?? ($_POST['token'] ?? '');
$error = '';
$success = false;

// ====== VÉRIFICATION DU TOKEN ======
$user = null;
if ($token) {
    $stmt = $pdo->prepare("
        SELECT id_user, reset_token_expire FROM utilisateurs
        WHERE reset_token = ? AND statut = 'actif'
    ");
    $stmt->execute([$token]);
    $candidat = $stmt->fetch();

    // Comparaison faite en PHP plutôt qu'en SQL pour éviter tout
    // décalage d'horloge entre le serveur web et le serveur MySQL
    if ($candidat && strtotime($candidat['reset_token_expire']) > time()) {
        $user = $candidat;
    }
}
// ====== TRAITEMENT DU NOUVEAU MOT DE PASSE ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user) {

    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        $error = 'Session expirée, veuillez réessayer.';
    } else {
        $password = $_POST['password'] ?? '';
        $passwordConfirm = $_POST['password_confirm'] ?? '';

        if (strlen($password) < 6) {
            $error = 'Le mot de passe doit contenir au moins 6 caractères.';
        } elseif ($password !== $passwordConfirm) {
            $error = 'Les mots de passe ne correspondent pas.';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);

            // Le token est invalidé immédiatement après usage (usage unique)
            $update = $pdo->prepare("
                UPDATE utilisateurs
                SET mot_de_passe = ?, reset_token = NULL, reset_token_expire = NULL
                WHERE id_user = ?
            ");
            $update->execute([$hash, $user['id_user']]);
            $success = true;
        }
    }
}

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Réinitialiser le mot de passe — FleetIoT</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/responsive.css">
    <style>
        .auth-wrapper {
            min-height: 100vh; display: flex; align-items: center; justify-content: center;
            background: linear-gradient(135deg, var(--color-primary-dark) 0%, var(--color-primary) 60%, var(--color-primary-light) 100%);
            padding: 24px;
        }
        .auth-card { background: #fff; border-radius: var(--radius-lg); padding: 44px 40px; width: 100%; max-width: 440px; box-shadow: var(--shadow-md); }
        .auth-logo { display: flex; align-items: center; gap: 10px; color: var(--color-primary); font-weight: 700; font-size: 19px; margin-bottom: 28px; }
        .auth-logo svg { width: 26px; height: 26px; }
        .auth-card h1 { font-size: 22px; margin-bottom: 6px; color: var(--color-text); }
        .auth-card p.subtitle { color: var(--color-text-secondary); font-size: 14px; margin-bottom: 26px; }
        .auth-alert { background: var(--color-danger-light); color: var(--color-danger-dark); padding: 12px 16px; border-radius: var(--radius-sm); font-size: 13px; margin-bottom: 18px; }
        .auth-alert.success { background: #E1F5EE; color: #085041; }
        .btn-submit { width: 100%; background: var(--color-primary); color: #fff; padding: 13px; border-radius: var(--radius-md); font-weight: 600; font-size: 15px; transition: var(--transition); margin-top: 8px; }
        .btn-submit:hover { background: var(--color-primary-light); }
        .auth-links { margin-top: 22px; text-align: center; font-size: 13px; color: var(--color-text-secondary); }
        .auth-links a { color: var(--color-primary); font-weight: 600; }
        .back-home { position: absolute; top: 24px; left: 24px; color: var(--color-text-light); font-size: 13px; display: flex; align-items: center; gap: 6px; }
    </style>
</head>
<body>

    <div class="auth-wrapper">
        <a href="index.php" class="back-home">&#8592; Retour à l'accueil</a>

        <div class="auth-card">
            <div class="auth-logo">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M3 16V8a1 1 0 011-1h10l4 4v5a1 1 0 01-1 1H4a1 1 0 01-1-1z"/>
                    <circle cx="7" cy="17" r="1.5"/>
                    <circle cx="16" cy="17" r="1.5"/>
                </svg>
                FleetIoT
            </div>

            <?php if ($success): ?>
                <h1>Mot de passe modifié</h1>
                <div class="auth-alert success">
                    Votre mot de passe a été mis à jour avec succès. Vous pouvez maintenant vous connecter.
                </div>
                <div class="auth-links">
                    <a href="connexion.php">Se connecter</a>
                </div>

            <?php elseif (!$user): ?>
                <h1>Lien invalide</h1>
                <div class="auth-alert"><?= htmlspecialchars($error) ?></div>
                <div class="auth-links">
                    <a href="mot_de_passe_oublie.php">Demander un nouveau lien</a>
                </div>

            <?php else: ?>
                <h1>Nouveau mot de passe</h1>
                <p class="subtitle">Choisissez un nouveau mot de passe pour votre compte.</p>

                <?php if ($error): ?>
                    <div class="auth-alert"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="POST" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

                    <div class="form-group">
                        <label for="password">Nouveau mot de passe</label>
                        <input type="password" id="password" name="password" required minlength="6" placeholder="6 caractères minimum">
                    </div>

                    <div class="form-group">
                        <label for="password_confirm">Confirmer le mot de passe</label>
                        <input type="password" id="password_confirm" name="password_confirm" required minlength="6" placeholder="••••••••">
                    </div>

                    <button type="submit" class="btn-submit">Réinitialiser le mot de passe</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

</body>
</html>