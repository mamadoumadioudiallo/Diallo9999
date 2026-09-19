<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : verification_2fa.php
// Rôle    : Vérification du code de double authentification (2FA)
//           envoyé par email, exigé pour les comptes admin
// ============================================================

session_start();
require_once 'includes/db.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';
require_once 'includes/mailer.php';

// Pas de session 2FA en attente : retour à la connexion
if (!isset($_SESSION['2fa_user_id']) || ($_SESSION['2fa_expire'] ?? 0) < time()) {
    session_unset();
    header('Location: connexion.php');
    exit();
}

$idUser = $_SESSION['2fa_user_id'];
$error = '';

// ====== RENVOI D'UN NOUVEAU CODE ======
if (isset($_GET['renvoyer'])) {
    if (($_SESSION['derniere_renvoi_2fa'] ?? 0) > time() - 30) {
        $error = 'Veuillez attendre 30 secondes avant de demander un nouveau code.';
    } else {
        $stmt = $pdo->prepare("SELECT email FROM utilisateurs WHERE id_user = ?");
        $stmt->execute([$idUser]);
        $emailUser = $stmt->fetchColumn();

        $code = strval(random_int(100000, 999999));
        $expire2fa = date('Y-m-d H:i:s', time() + 600);

        $stmt = $pdo->prepare("UPDATE utilisateurs SET code_2fa = ?, code_2fa_expire = ? WHERE id_user = ?");
        $stmt->execute([$code, $expire2fa, $idUser]);

        $corpsEmail = "
            <h3>Nouveau code de vérification FleetIoT</h3>
            <p style='font-size:28px;font-weight:bold;letter-spacing:4px;'>" . $code . "</p>
            <p>Ce code expire dans 10 minutes.</p>
        ";
        envoyerEmail($emailUser, '[FleetIoT] Nouveau code de vérification', $corpsEmail);

        $_SESSION['derniere_renvoi_2fa'] = time();
        $error = 'Un nouveau code a été envoyé.';
    }
}

// ====== VÉRIFICATION DU CODE ======
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Session expirée, veuillez réessayer.';
    } else {
        $codeSaisi = clean($_POST['code'] ?? '');

        $stmt = $pdo->prepare("SELECT * FROM utilisateurs WHERE id_user = ? AND code_2fa = ?");
        $stmt->execute([$idUser, $codeSaisi]);
        $candidat = $stmt->fetch();

        // Comparaison de l'expiration faite en PHP plutôt qu'en SQL pour éviter
        // tout décalage d'horloge entre le serveur web et le serveur MySQL
        $user = null;
        if ($candidat && $candidat['code_2fa_expire'] && strtotime($candidat['code_2fa_expire']) > time()) {
            $user = $candidat;
        }

        if ($user) {
            // Code valide : invalider le code (usage unique) et finaliser la connexion
            $stmt = $pdo->prepare("UPDATE utilisateurs SET code_2fa = NULL, code_2fa_expire = NULL WHERE id_user = ?");
            $stmt->execute([$idUser]);

            session_regenerate_id(true);

            $_SESSION['user_id'] = $user['id_user'];
            $_SESSION['user_nom'] = $user['nom'];
            $_SESSION['user_prenom'] = $user['prenom'];
            $_SESSION['user_role'] = $user['role'];

            unset($_SESSION['2fa_user_id'], $_SESSION['2fa_expire']);

            enregistrerAudit($pdo, 'connexion_reussie', $user['email'], $user['id_user']);

            header('Location: admin/dashboard.php');
            exit();
        } else {
            $error = 'Code incorrect ou expiré.';
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
    <title>Vérification — FleetIoT</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/responsive.css">
    <style>
        .auth-wrapper {
            min-height: 100vh; display: flex; align-items: center; justify-content: center;
            background: linear-gradient(135deg, var(--color-primary-dark) 0%, var(--color-primary) 60%, var(--color-primary-light) 100%);
            padding: 24px;
        }
        .auth-card { background: #fff; border-radius: var(--radius-lg); padding: 44px 40px; width: 100%; max-width: 420px; box-shadow: var(--shadow-md); text-align: center; }
        .auth-logo { display: flex; align-items: center; justify-content: center; gap: 10px; color: var(--color-primary); font-weight: 700; font-size: 19px; margin-bottom: 28px; }
        .auth-logo svg { width: 26px; height: 26px; }
        .auth-card h1 { font-size: 22px; margin-bottom: 6px; color: var(--color-text); }
        .auth-card p.subtitle { color: var(--color-text-secondary); font-size: 14px; margin-bottom: 26px; }
        .auth-alert { background: var(--color-danger-light); color: var(--color-danger-dark); padding: 12px 16px; border-radius: var(--radius-sm); font-size: 13px; margin-bottom: 18px; }
        .code-input {
            font-size: 28px; letter-spacing: 12px; text-align: center; font-weight: 700;
            padding: 14px; width: 100%; border: 2px solid var(--color-border); border-radius: var(--radius-md);
        }
        .btn-submit { width: 100%; background: var(--color-primary); color: #fff; padding: 13px; border-radius: var(--radius-md); font-weight: 600; font-size: 15px; transition: var(--transition); margin-top: 18px; }
        .btn-submit:hover { background: var(--color-primary-light); }
        .auth-links { margin-top: 22px; text-align: center; font-size: 13px; color: var(--color-text-secondary); }
        .auth-links a { color: var(--color-primary); font-weight: 600; }
    </style>
</head>
<body>
    <div class="auth-wrapper">
        <div class="auth-card">
            <div class="auth-logo">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M3 16V8a1 1 0 011-1h10l4 4v5a1 1 0 01-1 1H4a1 1 0 01-1-1z"/>
                    <circle cx="7" cy="17" r="1.5"/>
                    <circle cx="16" cy="17" r="1.5"/>
                </svg>
                FleetIoT
            </div>

            <h1>&#128231; Vérification en 2 étapes</h1>
            <p class="subtitle">Un code à 6 chiffres a été envoyé à votre adresse email. Saisissez-le ci-dessous.</p>

            <?php if ($error): ?>
                <div class="auth-alert"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="text" name="code" class="code-input" maxlength="6" pattern="[0-9]{6}" required autofocus placeholder="------">
                <button type="submit" class="btn-submit">Valider</button>
            </form>

            <div class="auth-links">
                <a href="?renvoyer=1">Renvoyer le code</a> &nbsp;|&nbsp;
                <a href="connexion.php">Annuler</a>
            </div>
        </div>
    </div>
</body>
</html>