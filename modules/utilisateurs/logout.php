<?php
declare(strict_types=1);
session_start();

// Récupérer les informations de l'utilisateur avant de détruire la session
$userName = $_SESSION['user_nom'] ?? 'Utilisateur';
$userRole = $_SESSION['user_role'] ?? 'Rôle';

// Traitement de la déconnexion
if ($_SERVER['REQUEST_METHOD'] === 'POST' || (isset($_GET['confirm']) && $_GET['confirm'] === 'true')) {
    // Journaliser la déconnexion (si possible)
    if (isset($_SESSION['user_id'])) {
        require_once __DIR__ . '/../../config/database.php';
        try {
            $db = new Database();
            
            // Créer la table de logs si elle n'existe pas
            $createTable = "CREATE TABLE IF NOT EXISTS user_logs (
                id INT PRIMARY KEY AUTO_INCREMENT,
                user_id INT NOT NULL,
                action VARCHAR(50) NOT NULL,
                details TEXT,
                ip_address VARCHAR(45),
                user_agent TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )";
            $db->exec($createTable);
            
            // Insérer le log de déconnexion
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
            
            $query = "INSERT INTO user_logs (user_id, action, details, ip_address, user_agent) 
                      VALUES (:user_id, :action, :details, :ip_address, :user_agent)";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':user_id', $_SESSION['user_id']);
            $stmt->bindValue(':action', 'DECONNEXION');
            $stmt->bindValue(':details', 'Utilisateur déconnecté avec succès');
            $stmt->bindParam(':ip_address', $ip);
            $stmt->bindParam(':user_agent', $userAgent);
            $stmt->execute();
        } catch (Exception $e) {
            error_log("Erreur de journalisation déconnexion: " . $e->getMessage());
        }
    }
    
    // Détruire la session
    session_unset();
    session_destroy();
    session_write_close();
    
    // Supprimer le cookie de session
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    // Rediriger vers la page de connexion
    header('Location: login.php?logout=success');
    exit;
}

// Si annulation, rediriger vers le dashboard
if (isset($_POST['cancel'])) {
    header('Location: ../dashbord/');
    exit;
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Déconnexion - NAGEX Pharma</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .pharma-green { background-color: #10B981; }
        .pharma-green:hover { background-color: #059669; }
    </style>
</head>
<body class="bg-gray-900 bg-opacity-50 min-h-screen flex items-center justify-center p-4">
    <div class="max-w-md w-full bg-white rounded-2xl shadow-2xl overflow-hidden">
        <div class="p-8">
            <!-- Icone d'avertissement -->
            <div class="flex justify-center mb-6">
                <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center">
                    <i class="fas fa-sign-out-alt text-red-600 text-2xl"></i>
                </div>
            </div>

            <!-- Titre et message -->
            <div class="text-center mb-6">
                <h2 class="text-2xl font-bold text-gray-900 mb-2">Déconnexion</h2>
                <p class="text-gray-600">
                    Êtes-vous sûr de vouloir vous déconnecter de votre session ?
                </p>
            </div>

            <!-- Informations de session -->
            <div class="bg-gray-50 rounded-lg p-4 mb-6">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 bg-green-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-user text-green-600"></i>
                    </div>
                    <div>
                        <p class="font-semibold text-gray-900"><?php echo htmlspecialchars($userName); ?></p>
                        <p class="text-sm text-gray-600"><?php echo htmlspecialchars($userRole); ?> • NAGEX Pharma</p>
                        <p class="text-xs text-gray-500 mt-1">Dernière connexion: <?php echo date('H:i'); ?></p>
                    </div>
                </div>
            </div>

            <!-- Boutons d'action -->
            <form method="POST" class="flex space-x-4">
                <button type="submit" name="cancel"
                        class="flex-1 py-3 px-4 border border-gray-300 text-gray-700 rounded-lg font-semibold hover:bg-gray-50 transition-colors duration-200 flex items-center justify-center">
                    <i class="fas fa-times mr-2"></i>
                    Annuler
                </button>
                <button type="submit" name="logout"
                        class="flex-1 bg-red-600 text-white py-3 px-4 rounded-lg font-semibold hover:bg-red-700 transition-colors duration-200 flex items-center justify-center">
                    <i class="fas fa-sign-out-alt mr-2"></i>
                    Se déconnecter
                </button>
            </form>

            <!-- Alternative avec lien GET -->
            <div class="mt-4 text-center">
                <p class="text-xs text-gray-500">ou</p>
                <a href="?confirm=true" 
                   class="text-xs text-red-600 hover:text-red-800 underline">
                    Se déconnecter immédiatement
                </a>
            </div>
        </div>

        <!-- Pied de page -->
        <div class="bg-gray-50 px-8 py-4 border-t border-gray-200">
            <p class="text-xs text-gray-500 text-center">
                <i class="fas fa-shield-alt mr-1"></i>
                Pour votre sécurité, veuillez toujours vous déconnecter après utilisation.
            </p>
        </div>
    </div>

    <script>
        // Animation de chargement
        document.querySelector('form').addEventListener('submit', function(e) {
            const buttons = this.querySelectorAll('button[type="submit"]');
            buttons.forEach(button => {
                if (button.name === 'logout') {
                    button.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Déconnexion...';
                }
                button.disabled = true;
            });
        });

        // Auto-redirection après 30 secondes d'inactivité (sécurité)
        let inactivityTimer;
        function resetInactivityTimer() {
            clearTimeout(inactivityTimer);
            inactivityTimer = setTimeout(() => {
                if (confirm('Session inactive. Souhaitez-vous vous déconnecter ?')) {
                    window.location.href = '?confirm=true';
                }
            }, 30000); // 30 secondes
        }

        // Réinitialiser le timer sur les événements utilisateur
        ['mousemove', 'keypress', 'click'].forEach(event => {
            document.addEventListener(event, resetInactivityTimer);
        });

        resetInactivityTimer();
    </script>
</body>
</html>