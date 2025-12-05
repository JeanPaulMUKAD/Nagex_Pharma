<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../../config/database.php';

// Traitement du formulaire de connexion
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = new Database();
    
    // Récupération des données
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);
    
    $errors = [];
    
    // Validation des données
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "L'email n'est pas valide.";
    }
    
    if (empty($password)) {
        $errors[] = "Le mot de passe est obligatoire.";
    }
    
    // Si aucune erreur de validation, vérifier les identifiants
    if (empty($errors)) {
        try {
            // Vérifier si l'utilisateur existe
            $query = "SELECT id, nom, email, mot_de_passe, role, statut, telephone 
                      FROM utilisateurs 
                      WHERE email = :email AND statut = 'actif'";
            
            $stmt = $db->prepare($query);
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
                    $_SESSION['login_time'] = time();
                    
                    // Cookie "Se souvenir de moi"
                    if ($remember) {
                        setcookie('user_email', $email, time() + (30 * 24 * 60 * 60), '/'); // 30 jours
                    }
                    
                    // Mettre à jour la dernière connexion
                    try {
                        $updateQuery = "UPDATE utilisateurs SET derniere_connexion = NOW() WHERE id = :id";
                        $updateStmt = $db->prepare($updateQuery);
                        $updateStmt->bindParam(':id', $user['id']);
                        $updateStmt->execute();
                    } catch (PDOException $e) {
                        error_log("Erreur mise à jour dernière connexion: " . $e->getMessage());
                    }
                    
                    // Journaliser la connexion
                    try {
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
                        
                        // Insérer le log
                        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
                        $action = 'CONNEXION';
                        $details = 'Utilisateur connecté avec succès';
                        
                        $logQuery = "INSERT INTO user_logs (user_id, action, details, ip_address, user_agent) 
                                  VALUES (:user_id, :action, :details, :ip_address, :user_agent)";
                        
                        $logStmt = $db->prepare($logQuery);
                        $logStmt->bindParam(':user_id', $user['id']);
                        $logStmt->bindParam(':action', $action);
                        $logStmt->bindParam(':details', $details);
                        $logStmt->bindParam(':ip_address', $ip);
                        $logStmt->bindParam(':user_agent', $userAgent);
                        $logStmt->execute();
                    } catch (Exception $e) {
                        error_log("Erreur de journalisation: " . $e->getMessage());
                    }
                    
                    // Redirection basée sur le rôle
                    $role = $user['role'];
                    switch ($role) {
                        case 'admin':
                            header('Location: /modules/dashbord/index.php');
                            break;
                        case 'pharmacien':
                            header('Location: /modules/pharmacien/index.php');
                            break;
                        case 'gerant':
                            header('Location: /modules/gerant/index.php');
                            break;
                        case 'caissier':
                            header('Location: /modules/caissier/index.php');
                            break;
                        case 'fournisseur':
                            header('Location: /modules/fournisseur/index.php');
                            break;
                        case 'stockiste':
                            header('Location: /modules/stockiste/index.php');
                            break;
                        case 'client':
                            header('Location: /modules/client/index.php');
                            break;
                        default:
                            header('Location: /modules/dashboard/');
                    }
                    exit;
                    
                } else {
                    $errors[] = "Email ou mot de passe incorrect.";
                }
            } else {
                $errors[] = "Email ou mot de passe incorrect.";
            }
            
        } catch (PDOException $e) {
            error_log("Erreur de connexion: " . $e->getMessage());
            $errors[] = "Erreur technique lors de la connexion.";
        }
    }
}

// Remplir automatiquement l'email si cookie existe
$savedEmail = $_COOKIE['user_email'] ?? '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion - NAGEX Pharma</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!--Link of Monteserrat Font-->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sixtyfour&display=swap" rel="stylesheet"> 
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Tangerine">
    <link href="https://fonts.googleapis.com/css2?family=Arimo:ital,wght@0,400..700;1,400..700&family=Montserrat:ital,wght@0,100..900;1,100..900&family=Roboto+Mono:ital,wght@0,100..700;1,100..700&family=Stack+Sans+Text:wght@200..700&display=swap" rel="stylesheet">
    
    <style>
        .pharma-green { background-color: #10B981; }
        .pharma-green:hover { background-color: #059669; }
        .alert-error { background-color: #FEE2E2; border-color: #EF4444; color: #7F1D1D; }
        .alert-success { background-color: #D1FAE5; border-color: #10B981; color: #065F46; }
    </style>
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center p-4" style="font-family: 'Montserrat', sans-serif;">
    <div class="max-w-4xl w-full bg-white rounded-2xl shadow-2xl overflow-hidden flex flex-col md:flex-row">
        <!-- Section image avec overlay moderne -->
        <div class="hidden lg:flex w-1/2 relative overflow-hidden">
            <div class="absolute inset-0 bg-gradient-to-br from-[#059669]/90 to-[#059669]/80 z-10"></div>
            <img
                src="https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcRYlRWLhd8aNBNNW9VrqqnDG2AgjJvPYHyWdA&s"
                alt="Connexion Nagex Pharma"
                class="object-cover w-full h-full transform hover:scale-105 transition-transform duration-700"
            />
            <div class="absolute inset-0 z-20 flex flex-col items-center justify-center text-white text-center px-8">
                <div class="bg-white/20 backdrop-blur-sm p-4 rounded-2xl mb-6 transform hover:scale-110 transition-transform duration-300">
                    <i class="fas fa-graduation-cap text-white text-3xl"></i>
                </div>
                <h1 class="text-3xl md:text-4xl font-bold mb-4 bg-gradient-to-r from-white to-blue-100 bg-clip-text text-transparent">
                    Bienvenue sur NAGEX Pharma
                </h1>
                <p class="text-blue-100 text-lg leading-relaxed max-w-md">
                    Votre portail vers la gestion fluide de vos produits pharmaceutiques.
                </p>
            </div>
        </div>

        <!-- Section Formulaire -->
        <div class="md:w-1/2 p-8 md:p-12 flex items-center justify-center">
            <div class="w-full max-w-md">
                <div class="text-center mb-8">
                    <h1 class="text-3xl font-bold text-gray-900">Connexion</h1>
                    <p class="text-gray-600 mt-2">Accédez à votre espace professionnel</p>
                </div>

                <!-- Messages d'erreur -->
                <?php if (!empty($errors)): ?>
                    <div class="alert-error border rounded-lg p-4 mb-6">
                        <div class="flex items-center">
                            <i class="fas fa-exclamation-triangle mr-2"></i>
                            <span class="font-semibold">Erreur de connexion :</span>
                        </div>
                        <ul class="mt-2 list-disc list-inside">
                            <?php foreach ($errors as $error): ?>
                                <li class="text-sm"><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <!-- Message de succès après inscription -->
                <?php if (isset($_GET['registered']) && $_GET['registered'] === 'true'): ?>
                    <div class="alert-success border rounded-lg p-4 mb-6">
                        <div class="flex items-center">
                            <i class="fas fa-check-circle mr-2"></i>
                            <span class="font-semibold">Inscription réussie !</span>
                        </div>
                        <p class="mt-2 text-sm">Votre compte a été créé. Vous pouvez maintenant vous connecter.</p>
                    </div>
                <?php endif; ?>

                <!-- Message de déconnexion -->
                <?php if (isset($_GET['logout']) && $_GET['logout'] === 'true'): ?>
                    <div class="alert-success border rounded-lg p-4 mb-6">
                        <div class="flex items-center">
                            <i class="fas fa-check-circle mr-2"></i>
                            <span class="font-semibold">Déconnexion réussie !</span>
                        </div>
                        <p class="mt-2 text-sm">Vous avez été déconnecté avec succès.</p>
                    </div>
                <?php endif; ?>

                <form method="POST" class="space-y-6">
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-envelope mr-2"></i>Email professionnel
                        </label>
                        <input type="email" id="email" name="email" required
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200"
                               placeholder="votre@email.com"
                               value="<?php echo htmlspecialchars($savedEmail); ?>">
                    </div>

                    <div class="relative">
                        <label for="password" class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-lock mr-2"></i>Mot de passe
                        </label>
                        <input type="password" id="password" name="password" required
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200"
                               placeholder="Votre mot de passe">
                        <button type="button" onclick="togglePasswordVisibility()" 
                                class="absolute right-3 top-9 text-gray-400 hover:text-gray-600 transition-colors">
                            <i class="fas fa-eye" id="password-toggle-icon"></i>
                        </button>
                    </div>

                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <input type="checkbox" id="remember" name="remember"
                                   class="w-4 h-4 text-green-600 border-gray-300 rounded focus:ring-green-500"
                                   <?php echo $savedEmail ? 'checked' : ''; ?>>
                            <label for="remember" class="ml-2 text-sm text-gray-600">Se souvenir de moi</label>
                        </div>
                        <a href="reset.php" class="text-sm text-green-600 hover:text-green-800 transition-colors">
                            Mot de passe oublié ?
                        </a>
                    </div>

                    <button type="submit"
                            class="w-full pharma-green text-white py-3 px-4 rounded-lg font-semibold hover:bg-green-700 transition-colors duration-200 flex items-center justify-center">
                        <i class="fas fa-sign-in-alt mr-2"></i>
                        Se connecter
                    </button>
                </form>

               

            </div>
        </div>
    </div>

    <script>
        // Fonction pour basculer la visibilité du mot de passe
        function togglePasswordVisibility() {
            const passwordInput = document.getElementById('password');
            const icon = document.getElementById('password-toggle-icon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        // Auto-focus sur le champ email si vide
        document.addEventListener('DOMContentLoaded', function() {
            const emailInput = document.getElementById('email');
            if (!emailInput.value) {
                emailInput.focus();
            }
        });

        // Animation de chargement lors de la soumission
        document.querySelector('form').addEventListener('submit', function(e) {
            const submitButton = this.querySelector('button[type="submit"]');
            submitButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Connexion...';
            submitButton.disabled = true;
        });
    </script>
</body>
</html>