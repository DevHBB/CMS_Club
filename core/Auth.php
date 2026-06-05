<?php
/**
 * ClubCMS — Système d'authentification
 * Gestion : sessions, rôles, reCAPTCHA v3
 */

class Auth {

    // Hiérarchie des rôles (du moins au plus privilégié)
    public const ROLES = ['member', 'benevole', 'coach', 'admin', 'superadmin'];
    public const ROLE_LABELS = [
        'member'     => 'Membre',
        'benevole'   => 'Bénévole',
        'coach'      => 'Coach',
        'admin'      => 'Administrateur',
        'superadmin' => 'Super Administrateur',
    ];

    // ── Session ──────────────────────────────────────────────────
    public static function startSession(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'secure'   => isset($_SERVER['HTTPS']),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    public static function check(): bool {
        self::startSession();
        return isset($_SESSION['user_id']);
    }

    public static function user(): ?array {
        if (!self::check()) return null;
        static $user = null;
        static $cachedId = null;
        // Réinitialiser si l'ID de session a changé (login/logout)
        $currentId = $_SESSION['user_id'] ?? null;
        if ($cachedId !== $currentId) { $user = null; $cachedId = $currentId; }
        if ($user === null) {
            try {
                $fetched = Database::one(
                    "SELECT * FROM cc_users WHERE id = ? AND status = 'active'",
                    [$currentId]
                );
            } catch(Exception $e) { return null; }
            if (!$fetched) { return null; }
            $user = $fetched;
            // Sync rôle BDD → session
            if (isset($user['role']) && $user['role'] !== ($_SESSION['user_role'] ?? '')) {
                $_SESSION['user_role'] = $user['role'];
            }
        }
        return $user;
    }

    public static function id(): ?int {
        return self::check() ? (int)$_SESSION['user_id'] : null;
    }

    public static function role(): ?string {
        return $_SESSION['user_role'] ?? null;
    }

    // ── Vérification des rôles ───────────────────────────────────
    /**
     * Vérifie si l'utilisateur a au moins le rôle requis
     */
    public static function hasRole(string $minRole): bool {
        $userRole = self::role();
        if (!$userRole) return false;
        $userIdx = array_search($userRole, self::ROLES);
        $minIdx  = array_search($minRole,  self::ROLES);
        // Si le rôle n'est pas reconnu (ex: ancien cache session), refuser
        if ($userIdx === false || $minIdx === false) return false;
        return $userIdx >= $minIdx;
    }

    public static function isMember(): bool     { return self::hasRole('member'); }
    public static function isCoach(): bool       { return self::hasRole('coach'); }
    public static function isAdmin(): bool       { return in_array(self::role() ?? '', ['admin','superadmin']); }
    public static function isSuperAdmin(): bool  { return self::hasRole('superadmin'); }

    public static function isBenevole(): bool {
        return in_array(self::role() ?? '', ['benevole','coach','admin','superadmin']);
    }

    public static function canAccessBenevole(): bool {
        if (!self::check()) return false;
        $role = self::role() ?? '';
        if (in_array($role, ['admin','superadmin','benevole'])) return true;
        if ($role === 'coach') {
            try {
                $a = Database::one("SELECT can_access FROM cc_benv_coach_access WHERE coach_id=?", [self::id()]);
                return $a && (int)$a['can_access'] === 1;
            } catch(Exception $e) { return false; }
        }
        return false;
    }

    /**
     * Redirige si pas connecté ou pas le bon rôle
     */
    public static function require(string $minRole = 'member', string $redirect = '/login'): void {
        if (!self::check()) {
            self::redirectWithReturn($redirect);
        }
        if (!self::hasRole($minRole)) {
            http_response_code(403);
            include CC_ROOT . '/templates/403.php';
            exit;
        }
    }

    private static function redirectWithReturn(string $url): void {
        $return = urlencode($_SERVER['REQUEST_URI'] ?? '/');
        header("Location: {$url}?return={$return}");
        exit;
    }

    // ── Connexion ────────────────────────────────────────────────
    public static function login(string $email, string $password): array {
        $user = Database::one(
            "SELECT * FROM cc_users WHERE email = ?",
            [strtolower(trim($email))]
        );

        if (!$user) {
            return ['success' => false, 'error' => 'Email ou mot de passe incorrect.'];
        }

        if (!password_verify($password, $user['password'])) {
            return ['success' => false, 'error' => 'Email ou mot de passe incorrect.'];
        }

        if ($user['status'] === 'pending') {
            return ['success' => false, 'error' => 'Votre compte est en attente de validation. Vérifiez votre email.'];
        }

        if (in_array($user['status'], ['suspended', 'banned'])) {
            return ['success' => false, 'error' => 'Votre compte a été suspendu. Contactez l\'administration.'];
        }

        // Régénère l'ID de session pour éviter la fixation de session
        session_regenerate_id(true);

        $_SESSION['user_id']   = $user['id'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['user_email']= $user['email'];

        // Mise à jour de la dernière connexion
        Database::run(
            "UPDATE cc_users SET last_login = NOW() WHERE id = ?",
            [$user['id']]
        );

        return ['success' => true, 'user' => $user];
    }

    // ── Déconnexion ──────────────────────────────────────────────
    public static function logout(): void {
        self::startSession();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    // ── Inscription ──────────────────────────────────────────────
    public static function register(array $data): array {
        $email = strtolower(trim($data['email'] ?? ''));
        $pass  = $data['password'] ?? '';
        $pass2 = $data['password2'] ?? '';

        // Validations
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Adresse email invalide.'];
        }
        if (strlen($pass) < 8) {
            return ['success' => false, 'error' => 'Le mot de passe doit contenir au moins 8 caractères.'];
        }
        if ($pass !== $pass2) {
            return ['success' => false, 'error' => 'Les mots de passe ne correspondent pas.'];
        }
        if (Database::scalar("SELECT COUNT(*) FROM cc_users WHERE email = ?", [$email])) {
            return ['success' => false, 'error' => 'Cette adresse email est déjà utilisée.'];
        }

        // reCAPTCHA v3
        if (Config::get('recaptcha_secret')) {
            $captchaResult = self::verifyRecaptcha($data['g-recaptcha-response'] ?? '');
            if (!$captchaResult['success'] || ($captchaResult['score'] ?? 0) < 0.5) {
                return ['success' => false, 'error' => 'Vérification anti-robot échouée. Réessayez.'];
            }
        }

        $hash  = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
        $token = bin2hex(random_bytes(32));

        $needsVerif = (bool) Config::get('email_verification', 1);
        $status     = $needsVerif ? 'pending' : 'active';

        $id = Database::insert(
            "INSERT INTO cc_users (email, password, firstname, lastname, phone, status, email_verified, email_token, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())",
            [
                $email, $hash,
                Helpers::sanitize($data['firstname'] ?? ''),
                Helpers::sanitize($data['lastname'] ?? ''),
                Helpers::sanitize($data['phone'] ?? ''),
                $status,
                $needsVerif ? 0 : 1,
                $token,
            ]
        );

        // Mail de bienvenue
        Mailer::sendWelcome((int)$id, $email, $data['firstname'] ?? '', $token, $needsVerif);

        return [
            'success'        => true,
            'id'             => $id,
            'needs_verif'    => $needsVerif,
        ];
    }

    // ── Vérification email ───────────────────────────────────────
    public static function verifyEmail(string $token): bool {
        $user = Database::one(
            "SELECT id FROM cc_users WHERE email_token = ? AND email_verified = 0",
            [$token]
        );
        if (!$user) return false;

        Database::run(
            "UPDATE cc_users SET email_verified = 1, status = 'active', email_token = NULL WHERE id = ?",
            [$user['id']]
        );
        return true;
    }

    // ── Mot de passe oublié ──────────────────────────────────────
    public static function requestPasswordReset(string $email): void {
        $user = Database::one("SELECT id, firstname FROM cc_users WHERE email = ?", [strtolower($email)]);
        if (!$user) return; // Ne pas révéler si l'email existe

        $token   = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

        Database::run(
            "UPDATE cc_users SET reset_token = ?, reset_expires = ? WHERE id = ?",
            [$token, $expires, $user['id']]
        );

        Mailer::sendPasswordReset($email, $user['firstname'], $token);
    }

    public static function resetPassword(string $token, string $newPassword): array {
        if (strlen($newPassword) < 8) {
            return ['success' => false, 'error' => 'Mot de passe trop court (8 caractères minimum).'];
        }

        $user = Database::one(
            "SELECT id FROM cc_users WHERE reset_token = ? AND reset_expires > NOW()",
            [$token]
        );
        if (!$user) {
            return ['success' => false, 'error' => 'Lien invalide ou expiré.'];
        }

        $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        Database::run(
            "UPDATE cc_users SET password = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?",
            [$hash, $user['id']]
        );

        return ['success' => true];
    }

    // ── reCAPTCHA v3 ─────────────────────────────────────────────
    public static function verifyRecaptcha(string $token): array {
        if (empty($token)) return ['success' => false, 'score' => 0];

        $secret  = Config::get('recaptcha_secret', '');
        $payload = http_build_query(['secret' => $secret, 'response' => $token]);
        $response = false;

        // Méthode 1 : cURL (préféré)
        if (function_exists('curl_init')) {
            $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $response = curl_exec($ch);
            curl_close($ch);
        }

        // Méthode 2 : file_get_contents (fallback si cURL absent)
        if ($response === false && ini_get('allow_url_fopen')) {
            $ctx = stream_context_create(['http' => [
                'method'  => 'POST',
                'header'  => 'Content-Type: application/x-www-form-urlencoded',
                'content' => $payload,
                'timeout' => 5,
            ]]);
            $response = @file_get_contents(
                'https://www.google.com/recaptcha/api/siteverify',
                false, $ctx
            );
        }

        // Si aucune méthode disponible : laisser passer (fail open)
        if ($response === false) return ['success' => true, 'score' => 1.0];

        return json_decode($response, true) ?? ['success' => false, 'score' => 0];
    }

    // ── CSRF ─────────────────────────────────────────────────────
    public static function csrfToken(): string {
        self::startSession();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function getCsrfToken(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function csrfField(): string {
        return '<input type="hidden" name="csrf_token" value="' . self::csrfToken() . '">';
    }

    public static function verifyCsrf(): bool {
        self::startSession();
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        return hash_equals($_SESSION['csrf_token'] ?? '', $token);
    }
}
