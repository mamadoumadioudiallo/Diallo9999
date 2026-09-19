<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : connexion.php
// Rôle    : Formulaire de connexion + traitement de l'authentification
// ============================================================

session_start();
require_once 'includes/db.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';
require_once 'includes/mailer.php';

// Si déjà connecté, redirection directe vers le bon dashboard
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['user_role'] === 'admin') {
        header('Location: admin/dashboard.php');
    } elseif ($_SESSION['user_role'] === 'conducteur') {
        header('Location: conducteur/dashboard.php');
    } else {
        header('Location: superviseur/dashboard.php');
    }
    exit();
}

$error = '';
$maxAttempts = 5;
$lockoutSeconds = 300; // 5 minutes

if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = 0;
}
if (!isset($_SESSION['login_lockout_until'])) {
    $_SESSION['login_lockout_until'] = 0;
}

$isLocked = $_SESSION['login_lockout_until'] > time();
$remainingLock = $isLocked ? ($_SESSION['login_lockout_until'] - time()) : 0;

$ipActuelle = $_SERVER['REMOTE_ADDR'] ?? '';
if (!$isLocked && ipEstBloquee($pdo, $ipActuelle)) {
    $isLocked = true;
    $error = 'Trop de tentatives de connexion depuis cette adresse. Réessayez plus tard.';
}

// ====== TRAITEMENT DU FORMULAIRE ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isLocked) {

    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        $error = 'Session expirée, veuillez réessayer.';
    } else {

        $email = clean($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            $error = 'Veuillez renseigner votre email et votre mot de passe.';
        } else {

            $stmt = $pdo->prepare('SELECT * FROM utilisateurs WHERE email = ? AND statut = "actif"');
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (!$user) {
                $stmt = $pdo->prepare("
                    SELECT *, id_conducteur AS id_user, 'conducteur' AS role
                    FROM conducteurs
                    WHERE email = ? AND compte_actif = 'actif'
                ");
                $stmt->execute([$email]);
                $user = $stmt->fetch();
            }

            if ($user && $user['mot_de_passe'] && password_verify($password, $user['mot_de_passe'])) {

                if ($user['role'] === 'admin') {
                    $code = strval(random_int(100000, 999999));
                    $expire2fa = date('Y-m-d H:i:s', time() + 600);

                    $stmt2 = $pdo->prepare("UPDATE utilisateurs SET code_2fa = ?, code_2fa_expire = ? WHERE id_user = ?");
                    $stmt2->execute([$code, $expire2fa, $user['id_user']]);

                    $corpsEmail = templateCodeVerification($code, $user['email']);
                    envoyerEmail($user['email'], '[FleetIoT] Code de vérification', $corpsEmail);

                    session_regenerate_id(true);
                    $_SESSION['2fa_user_id'] = $user['id_user'];
                    $_SESSION['2fa_expire'] = time() + 600;
                    $_SESSION['login_attempts'] = 0;

                    enregistrerAudit($pdo, '2fa_code_envoye', $email, $user['id_user']);

                    header('Location: verification_2fa.php');
                    exit();
                }

                session_regenerate_id(true);

                $_SESSION['user_id']     = $user['id_user'];
                $_SESSION['user_nom']    = $user['nom'];
                $_SESSION['user_prenom'] = $user['prenom'];
                $_SESSION['user_role']   = $user['role'];
                $_SESSION['user_photo']  = $user['photo'] ?? null;

                $_SESSION['login_attempts'] = 0;

                enregistrerAudit($pdo, 'connexion_reussie', $email, $user['id_user']);

                if ($user['role'] === 'admin') {
                    header('Location: admin/dashboard.php');
                } elseif ($user['role'] === 'conducteur') {
                    header('Location: conducteur/dashboard.php');
                } else {
                    header('Location: superviseur/dashboard.php');
                }
                exit();

            } else {
                $_SESSION['login_attempts']++;
                $error = 'Email ou mot de passe incorrect.';
                enregistrerAudit($pdo, 'connexion_echouee', $email);
                enregistrerTentativeEchouee($pdo, $ipActuelle, $email);

                if ($_SESSION['login_attempts'] >= $maxAttempts) {
                    $_SESSION['login_lockout_until'] = time() + $lockoutSeconds;
                    $_SESSION['login_attempts'] = 0;
                    $isLocked = true;
                    $remainingLock = $lockoutSeconds;
                    $error = 'Trop de tentatives échouées. Réessayez dans 5 minutes.';
                }
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
    <title>Connexion — FleetIoT</title>
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
            max-width: 420px;
            box-shadow: var(--shadow-md);
        }
        .auth-logo {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--color-primary);
            font-weight: 700;
            font-size: 19px;
            margin-bottom: 28px;
        }
        .auth-logo svg { width: 26px; height: 26px; }
        .auth-card h1 {
            font-size: 22px;
            margin-bottom: 6px;
            color: var(--color-text);
        }
        .auth-card p.subtitle {
            color: var(--color-text-secondary);
            font-size: 14px;
            margin-bottom: 26px;
        }
        .auth-alert {
            background: var(--color-danger-light);
            color: var(--color-danger-dark);
            padding: 12px 16px;
            border-radius: var(--radius-sm);
            font-size: 13px;
            margin-bottom: 18px;
        }
        .auth-alert.locked {
            background: var(--color-warning-light);
            color: var(--color-warning-dark);
        }
        .btn-submit {
            width: 100%;
            background: var(--color-primary);
            color: #fff;
            padding: 13px;
            border-radius: var(--radius-md);
            font-weight: 600;
            font-size: 15px;
            transition: var(--transition);
            margin-top: 8px;
        }
        .btn-submit:hover { background: var(--color-primary-light); }
        .btn-submit:disabled { opacity: 0.5; cursor: not-allowed; }
        .auth-links {
            margin-top: 22px;
            text-align: center;
            font-size: 13px;
            color: var(--color-text-secondary);
        }
        .auth-links a { color: var(--color-primary); font-weight: 600; }
        .back-home {
            position: absolute;
            top: 24px;
            left: 24px;
            color: var(--color-text-light);
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .champ-mdp-wrapper { position: relative; }
        .champ-mdp { width: 100%; padding-right: 40px; }
        .btn-afficher-mdp {
            position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
            background: none; border: none; cursor: pointer; font-size: 16px; opacity: 0.5;
        }
        .btn-afficher-mdp:hover { opacity: 1; }
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

            <h1>Connexion</h1>
            <p class="subtitle">Accédez à votre tableau de bord de gestion de flotte.</p>

            <?php if ($isLocked): ?>
                <div class="auth-alert locked">
                    Compte temporairement bloqué. Réessayez dans <span id="countdown"><?= ceil($remainingLock / 60) ?> minute(s)</span>.
                </div>
            <?php elseif (isset($_GET['expiree'])): ?>
                <div class="auth-alert locked">
                    Votre session a expiré par inactivité. Veuillez vous reconnecter.
                </div>
            <?php elseif ($error): ?>
                <div class="auth-alert"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                <div class="form-group">
                    <label for="email">Adresse email</label>
                    <input type="email" id="email" name="email" required
                           <?= $isLocked ? 'disabled' : '' ?>
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                           placeholder="admin@fleetiot.com">
                </div>

                <div class="form-group">
                    <label for="password">Mot de passe</label>
                    <div class="champ-mdp-wrapper">
                        <input type="password" id="password" name="password" required class="champ-mdp"
                               <?= $isLocked ? 'disabled' : '' ?>
                               placeholder="••••••••">
                        <button type="button" class="btn-afficher-mdp" tabindex="-1">&#128065;</button>
                    </div>
                </div>

                <button type="submit" class="btn-submit" <?= $isLocked ? 'disabled' : '' ?>>
                    Se connecter
                </button>
            </form>

            <div class="auth-links">
                Pas encore de compte ? <a href="inscription.php">S'inscrire</a><br>
                <a href="mot_de_passe_oublie.php" style="font-size:12px;">Mot de passe oublié ?</a>
            </div>
        </div>
    </div>

    <script>
        document.querySelectorAll('.btn-afficher-mdp').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var champ = this.previousElementSibling;
                champ.type = champ.type === 'password' ? 'text' : 'password';
            });
        });
    </script>

</body>
</html>