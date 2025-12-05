<?php declare(strict_types=1); 
session_start();
require_once __DIR__ . '/../../config/database.php';

class Auth {
    private $db;
    
    public function __construct() {
        $this->db = new Database();
    }
    
    /**
     * Connexion utilisateur
     */
    public function login($email, $password) {
        try {
            // Vérifier si l'utilisateur existe
            $query = "SELECT id, nom, email, mot_de_passe, role, statut, telephone 
                      FROM utilisateurs 
                      WHERE email = :email AND statut = 'actif'";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':email', $email);
            $stmt->execute();
            
            if ($stmt->rowCount() == 1) {
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Vérifier le mot de passe
                if (password_verify($password, $user['mot_de_passe'])) {
                    // Créer la session
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_nom'] = $user['nom'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['user_role'] = $user['role'];
                    $_SESSION['user_telephone'] = $user['telephone'];
                    $_SESSION['logged_in'] = true;
                    
                    // Mettre à jour la dernière connexion
                    $this->updateLastLogin($user['id']);
                    
                    // Journaliser la connexion
                    $this->logAction($user['id'], 'CONNEXION', 'Utilisateur connecté avec succès');
                    
                    return [
                        'success' => true,
                        'message' => 'Connexion réussie!',
                        'role' => $user['role']
                    ];
                }
            }
            
            return [
                'success' => false,
                'message' => 'Email ou mot de passe incorrect!'
            ];
            
        } catch (PDOException $e) {
            error_log("Erreur de connexion: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Erreur technique lors de la connexion!'
            ];
        }
    }
    
    /**
     * Inscription utilisateur
     */
    public function register($data) {
        try {
            // Vérifier si l'email existe déjà
            if ($this->emailExists($data['email'])) {
                return [
                    'success' => false,
                    'message' => 'Cet email est déjà utilisé!'
                ];
            }
            
            // Valider les données
            $validation = $this->validateRegistration($data);
            if (!$validation['success']) {
                return $validation;
            }
            
            // Hasher le mot de passe
            $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
            
            // Insérer l'utilisateur
            $query = "INSERT INTO utilisateurs (nom, email, mot_de_passe, role, telephone) 
                      VALUES (:nom, :email, :mot_de_passe, :role, :telephone)";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':nom', $data['fullname']);
            $stmt->bindParam(':email', $data['email']);
            $stmt->bindParam(':mot_de_passe', $hashedPassword);
            $stmt->bindParam(':role', $data['role']);
            $stmt->bindParam(':telephone', $data['telephone']);
            
            if ($stmt->execute()) {
                $userId = $this->db->lastInsertId();
                
                // Journaliser l'inscription
                $this->logAction($userId, 'INSCRIPTION', 'Nouvel utilisateur inscrit');
                
                return [
                    'success' => true,
                    'message' => 'Compte créé avec succès! Vous pouvez maintenant vous connecter.'
                ];
            }
            
            return [
                'success' => false,
                'message' => 'Erreur lors de la création du compte!'
            ];
            
        } catch (PDOException $e) {
            error_log("Erreur d'inscription: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Erreur technique lors de la création du compte!'
            ];
        }
    }
    
    /**
     * Réinitialisation du mot de passe
     */
    public function requestPasswordReset($email) {
        try {
            // Vérifier si l'utilisateur existe
            $query = "SELECT id, nom FROM utilisateurs WHERE email = :email AND statut = 'actif'";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':email', $email);
            $stmt->execute();
            
            if ($stmt->rowCount() == 1) {
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Générer un token de réinitialisation
                $token = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
                
                // Stocker le token (vous devrez créer cette table)
                $this->storeResetToken($user['id'], $token, $expires);
                
                // Envoyer l'email (à implémenter)
                $this->sendResetEmail($email, $token, $user['nom']);
                
                // Journaliser la demande
                $this->logAction($user['id'], 'RESET_PASSWORD', 'Demande de réinitialisation de mot de passe');
                
                return [
                    'success' => true,
                    'message' => 'Un lien de réinitialisation a été envoyé à votre email!'
                ];
            }
            
            return [
                'success' => false,
                'message' => 'Aucun compte actif trouvé avec cet email!'
            ];
            
        } catch (PDOException $e) {
            error_log("Erreur reset password: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Erreur technique lors de la réinitialisation!'
            ];
        }
    }
    
    /**
     * Déconnexion
     */
    public function logout() {
        if (isset($_SESSION['user_id'])) {
            $userId = $_SESSION['user_id'];
            
            // Journaliser la déconnexion
            $this->logAction($userId, 'DECONNEXION', 'Utilisateur déconnecté');
        }
        
        // Détruire la session
        session_unset();
        session_destroy();
        
        return [
            'success' => true,
            'message' => 'Déconnexion réussie!'
        ];
    }
    
    /**
     * Vérifier si l'utilisateur est connecté
     */
    public function isLoggedIn() {
        return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
    }
    
    /**
     * Vérifier les permissions
     */
    public function hasRole($allowedRoles) {
        if (!isset($_SESSION['user_role'])) {
            return false;
        }
        
        if (is_array($allowedRoles)) {
            return in_array($_SESSION['user_role'], $allowedRoles);
        }
        
        return $_SESSION['user_role'] === $allowedRoles;
    }
    
    /**
     * Méthodes privées auxiliaires
     */
    private function emailExists($email) {
        $query = "SELECT id FROM utilisateurs WHERE email = :email";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':email', $email);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }
    
    private function validateRegistration($data) {
        // Vérifier le mot de passe
        if (strlen($data['password']) < 8) {
            return [
                'success' => false,
                'message' => 'Le mot de passe doit contenir au moins 8 caractères!'
            ];
        }
        
        if ($data['password'] !== $data['confirm_password']) {
            return [
                'success' => false,
                'message' => 'Les mots de passe ne correspondent pas!'
            ];
        }
        
        // Vérifier l'email
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'message' => 'Format d\'email invalide!'
            ];
        }
        
        // Vérifier le rôle
        $allowedRoles = ['Pharmacien', 'Caissier', 'Stockiste'];
        if (!in_array($data['role'], $allowedRoles)) {
            return [
                'success' => false,
                'message' => 'Rôle invalide!'
            ];
        }
        
        return ['success' => true];
    }
    
    private function updateLastLogin($userId) {
        $query = "UPDATE utilisateurs SET derniere_connexion = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':id', $userId);
        $stmt->execute();
    }
    
    private function storeResetToken($userId, $token, $expires) {
        // Créer la table si elle n'existe pas
        $createTable = "CREATE TABLE IF NOT EXISTS password_resets (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            token VARCHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            used TINYINT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES utilisateurs(id) ON DELETE CASCADE
        )";
        
        $this->db->exec($createTable);
        
        // Insérer le token
        $query = "INSERT INTO password_resets (user_id, token, expires_at) 
                  VALUES (:user_id, :token, :expires_at)";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':token', $token);
        $stmt->bindParam(':expires_at', $expires);
        $stmt->execute();
    }
    
    private function sendResetEmail($email, $token, $name) {
        // À implémenter avec votre service d'email
        // Pour l'instant, on log juste l'information
        $resetLink = "http://localhost/pharma-management/reset_confirm.php?token=$token";
        error_log("Reset link for $email: $resetLink");
        
        // Exemple d'implémentation email (à adapter)
        /*
        $to = $email;
        $subject = "Réinitialisation de mot de passe - NAGEX Pharma";
        $message = "
            Bonjour $name,
            
            Vous avez demandé la réinitialisation de votre mot de passe.
            Cliquez sur le lien suivant pour créer un nouveau mot de passe:
            $resetLink
            
            Ce lien expirera dans 1 heure.
            
            Cordialement,
            L'équipe NAGEX Pharma
        ";
        
        $headers = "From: no-reply@nagexpharma.com\r\n";
        mail($to, $subject, $message, $headers);
        */
    }
    
    private function logAction($userId, $action, $details) {
        // Créer la table de logs si elle n'existe pas
        $createTable = "CREATE TABLE IF NOT EXISTS user_logs (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            action VARCHAR(50) NOT NULL,
            details TEXT,
            ip_address VARCHAR(45),
            user_agent TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES utilisateurs(id) ON DELETE CASCADE
        )";
        
        $this->db->exec($createTable);
        
        // Insérer le log
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        
        $query = "INSERT INTO user_logs (user_id, action, details, ip_address, user_agent) 
                  VALUES (:user_id, :action, :details, :ip_address, :user_agent)";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':action', $action);
        $stmt->bindParam(':details', $details);
        $stmt->bindParam(':ip_address', $ip);
        $stmt->bindParam(':user_agent', $userAgent);
        $stmt->execute();
    }
}

// Traitement des requêtes
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $auth = new Auth();
    $action = $_POST['action'] ?? '';
    
    header('Content-Type: application/json');
    
    switch ($action) {
        case 'login':
            $email = trim($_POST['email']);
            $password = $_POST['password'];
            
            $result = $auth->login($email, $password);
            
            if ($result['success']) {
                // Redirection basée sur le rôle
                switch ($result['role']) {
                    case 'Admin':
                        $redirect = '../modules/dashboard/index.php';
                        break;
                    case 'Pharmacien':
                        $redirect = '../modules/medicaments/';
                        break;
                    case 'Caissier':
                        $redirect = '../modules/ventes/';
                        break;
                    case 'Stockiste':
                        $redirect = '../modules/stock/';
                        break;
                    default:
                        $redirect = '../modules/dashboard/';
                }
                $result['redirect'] = $redirect;
            }
            
            echo json_encode($result);
            break;
            
        case 'register':
            $data = [
                'fullname' => trim($_POST['fullname']),
                'email' => trim($_POST['email']),
                'telephone' => trim($_POST['telephone']),
                'role' => $_POST['role'],
                'password' => $_POST['password'],
                'confirm_password' => $_POST['confirm_password']
            ];
            
            $result = $auth->register($data);
            echo json_encode($result);
            break;
            
        case 'reset_password':
            $email = trim($_POST['email']);
            $result = $auth->requestPasswordReset($email);
            echo json_encode($result);
            break;
            
        case 'logout':
            $result = $auth->logout();
            echo json_encode($result);
            break;
            
        default:
            echo json_encode([
                'success' => false,
                'message' => 'Action non reconnue!'
            ]);
    }
    exit;
}
?>