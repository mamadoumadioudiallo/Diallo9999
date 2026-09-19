<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : mot_de_passe_oublie.php
// Rôle    : Demande de réinitialisation de mot de passe
//           (génère un token à durée limitée)
// ============================================================

session_start();
require_once 'includes/db.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';

if (isset($_SESSION['user_id'])) {
    header('Location: ' . ($_SESSION['user_role'] === 'admin' ? 'admin/dashboard.php' : 'superviseur/dashboard.php'));
    exit();
}

$error = '';
$resetLink = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        $error = 'Session expirée, veuillez réessayer.';
    } else {
        $email = clean($_POST['email'] ?? '');

        // Toujours afficher le même message, qu'un compte existe ou pas,
        // pour ne pas révéler quels emails sont enregistrés (énumération de comptes).
        $messageGenerique = true;

        if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $stmt = $pdo->prepare("SELECT id_user FROM utilisateurs WHERE email = ? AND statut = 'actif'");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user) {
                $token = bin2hex(random_bytes(32));
                $expire = date('Y-m-d H:i:s', time() + 3600); // valide 1 heure

                $update = $pdo->prepare("UPDATE utilisateurs SET reset_token = ?, reset_token_expire = ? WHERE id_user = ?");
                $update->execute([$token, $expire, $user['id_user']]);

                // ====== EN PRODUCTION ======
                // Ici, on enverrait un email avec PHPMailer/SMTP contenant ce lien.
                // Faute de serveur mail configuré en local, on affiche le lien directement.
                $resetLink = 'reinitialiser_mot_de_passe.php?token=' . $token;
            }
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
    <title>Mot de passe oublié — FleetIoT</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/responsive.css">
    <style>
        .auth-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--color-primary-dark) 0%, var(--color-primary) 60%, var(--color-primary-light) 100%);
            padding: 24px;
        }
        .auth-card {
            background: #fff;
            border-radius: var(--radius-lg);
            padding: 44px 40px;
            width: 100%;
            max-width: 440px;
            box-shadow: var(--shadow-md);
        }
        .auth-logo {
            display: flex; align-items: center; gap: 10px;
            color: var(--color-primary); font-weight: 700; font-size: 19px;
            margin-bottom: 28px;
        }
        .auth-logo svg { width: 26px; height: 26px; }
        .auth-card h1 { font-size: 22px; margin-bottom: 6px; color: var(--color-text); }
        .auth-card p.subtitle { color: var(--color-text-secondary); font-size: 14px; margin-bottom: 26px; }
        .auth-alert {
            background: var(--color-danger-light); color: var(--color-danger-dark);
            padding: 12px 16px; border-radius: var(--radius-sm); font-size: 13px; margin-bottom: 18px;
        }
        .auth-alert.success { background: #E1F5EE; color: #085041; }
        .auth-alert.info { background: var(--color-warning-light); color: var(--color-warning-dark); }
        .btn-submit {
            width: 100%; background: var(--color-primary); color: #fff; padding: 13px;
            border-radius: var(--radius-md); font-weight: 600; font-size: 15px;
            transition: var(--transition); margin-top: 8px;
        }
        .btn-submit:hover { background: var(--color-primary-light); }
        .auth-links { margin-top: 22px; text-align: center; font-size: 13px; color: var(--color-text-secondary); }
        .auth-links a { color: var(--color-primary); font-weight: 600; }
        .back-home {
            position: absolute; top: 24px; left: 24px; color: var(--color-text-light);
            font-size: 13px; display: flex; align-items: center; gap: 6px;
        }
        .reset-link-box {
            background: #F5F7F6; border: 1px dashed var(--color-border);
            border-radius: var(--radius-sm); padding: 12px; font-size: 12px;
            word-break: break-all; margin-bottom: 18px;
        }
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

            <h1>Mot de passe oublié</h1>
            <p class="subtitle">Entrez votre email pour recevoir un lien de réinitialisation.</p>

            <?php if ($error): ?>
                <div class="auth-alert"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error): ?>
                <div class="auth-alert success">
                    Si cet email correspond à un compte actif, un lien de réinitialisation a été généré.
                </div>

                <?php if ($resetLink): ?>
                    <div class="auth-alert info">
                        <strong>Mode démonstration (pas de serveur mail configuré) :</strong>
                        en production, ce lien serait envoyé par email. Pour l'instant, cliquez directement ici :
                    </div>
                    <div class="reset-link-box">
                        <a href="<?= htmlspecialchars($resetLink) ?>"><?= htmlspecialchars($resetLink) ?></a>
                    </div>
                <?php endif; ?>

                <div class="auth-links">
                    <a href="connexion.php">Retour à la connexion</a>
                </div>
            <?php else: ?>

                <form method="POST" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                    <div class="form-group">
                        <label for="email">Adresse email</label>
                        <input type="email" id="email" name="email" required placeholder="vous@fleetiot.gn">
                    </div>

                    <button type="submit" class="btn-submit">Envoyer le lien de réinitialisation</button>
                </form>

                <div class="auth-links">
                    <a href="connexion.php">Retour à la connexion</a>
                </div>
            <?php endif; ?>
        </div>
    </div>

</body>
</html>