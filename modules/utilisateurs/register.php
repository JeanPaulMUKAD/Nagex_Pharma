<?php declare(strict_types=1); 
session_start();
require_once __DIR__ . '/../../config/database.php';

// Traitement du formulaire d'inscription
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = new Database();
    
    // Récupération des données
    $fullname = trim($_POST['fullname'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $telephone = trim($_POST['telephone'] ?? '');
    $role = $_POST['role'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    $errors = [];
    
    // Validation des données
    if (empty($fullname)) {
        $errors[] = "Le nom complet est obligatoire.";
    }
    
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "L'email n'est pas valide.";
    }
    
    if (empty($role)) {
        $errors[] = "Le rôle est obligatoire.";
    }
    
    if (strlen($password) < 8) {
        $errors[] = "Le mot de passe doit contenir au moins 8 caractères.";
    }
    
    if ($password !== $confirm_password) {
        $errors[] = "Les mots de passe ne correspondent pas.";
    }
    
    // Vérifier si l'email existe déjà
    if (empty($errors)) {
        try {
            $checkQuery = "SELECT id FROM utilisateurs WHERE email = :email";
            $checkStmt = $db->prepare($checkQuery);
            $checkStmt->bindParam(':email', $email);
            $checkStmt->execute();
            
            if ($checkStmt->rowCount() > 0) {
                $errors[] = "Cet email est déjà utilisé.";
            }
        } catch (PDOException $e) {
            $errors[] = "Erreur technique lors de la vérification de l'email.";
        }
    }
    
    // Si aucune erreur, créer l'utilisateur
    if (empty($errors)) {
        try {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            
            $query = "INSERT INTO utilisateurs (nom, email, mot_de_passe, role, telephone) 
                      VALUES (:nom, :email, :mot_de_passe, :role, :telephone)";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':nom', $fullname);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':mot_de_passe', $hashedPassword);
            $stmt->bindParam(':role', $role);
            $stmt->bindParam(':telephone', $telephone);
            
            if ($stmt->execute()) {
                // Envoyer les accès par email
                $emailSent = sendLoginCredentials($fullname, $email, $password, $role);
                
                if ($emailSent) {
                    $success = "Compte créé avec succès! Les accès de connexion ont été envoyés à votre email.";
                } else {
                    $success = "Compte créé avec succès! Cependant, l'envoi de l'email a échoué. Veuillez noter vos identifiants.";
                }
                
                // Redirection automatique après 3 secondes
                header("refresh:3;url=login.php");
            } else {
                $errors[] = "Erreur lors de la création du compte.";
            }
            
        } catch (PDOException $e) {
            $errors[] = "Erreur technique lors de la création du compte.";
        }
    }
}

/**
 * Fonction pour envoyer les accès de connexion par email (version native PHP)
 */
function sendLoginCredentials($fullname, $email, $password, $role) {
    $subject = 'Vos accès NAGEX Pharma - Compte créé avec succès';
    
    // Headers pour l'email HTML
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: NAGEX Pharma <no-nathankl@gmail.com>" . "\r\n";
    $headers .= "Reply-To: no-nathankl@gmail.com" . "\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();
    
    // Corps de l'email en HTML
    $message = "
    <!DOCTYPE html>
    <html lang='fr'>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 20px; }
            .container { max-width: 600px; margin: 0 auto; background: #f9f9f9; border-radius: 10px; overflow: hidden; }
            .header { background: linear-gradient(135deg, #10B981, #059669); color: white; padding: 30px; text-align: center; }
            .content { padding: 30px; background: white; }
            .credentials { background: #f0f9ff; padding: 20px; border-radius: 8px; border-left: 4px solid #10B981; margin: 20px 0; }
            .info-box { background: #e8f5e8; border: 1px solid #10B981; padding: 15px; border-radius: 5px; margin: 15px 0; }
            .footer { text-align: center; margin-top: 20px; font-size: 12px; color: #666; padding: 20px; }
            .warning { background: #fff3cd; border: 1px solid #ffc107; padding: 15px; border-radius: 5px; margin: 15px 0; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>NAGEX Pharma</h1>
                <p>Votre compte a été créé avec succès</p>
            </div>
            <div class='content'>
                <h2>Bonjour $fullname,</h2>
                <p>Votre compte administrateur NAGEX Pharma a été créé avec succès.</p>
                
                <div class='info-box'>
                    <h3>📋 Informations de votre compte :</h3>
                    <p><strong>Rôle :</strong> $role</p>
                    <p><strong>Email :</strong> $email</p>
                    <p><strong>Date de création :</strong> " . date('d/m/Y à H:i') . "</p>
                </div>
                
                <div class='credentials'>
                    <h3>🔐 Vos identifiants de connexion :</h3>
                    <p><strong>URL de connexion :</strong> http://localhost/NAGEX%20Pharma/modules/utilisateurs/login.php</p>
                    <p><strong>Email :</strong> $email</p>
                    <p><strong>Mot de passe :</strong> $password</p>
                </div>
                
                <div class='warning'>
                    <h3>⚠️ Important :</h3>
                    <p>Pour des raisons de sécurité, nous vous recommandons de :</p>
                    <ul>
                        <li>Changer votre mot de passe après votre première connexion</li>
                        <li>Ne jamais partager vos identifiants</li>
                        <li>Vous déconnecter après chaque utilisation</li>
                        <li>Conserver cet email en lieu sûr</li>
                    </ul>
                </div>
                
                <p>Si vous n'êtes pas à l'origine de cette création de compte, veuillez contacter immédiatement l'administrateur système.</p>
                
                <p>Cordialement,<br><strong>L'équipe NAGEX Pharma</strong></p>
            </div>
            <div class='footer'>
                <p>© 2024 NAGEX Pharma. Tous droits réservés.</p>
                <p>Cet email a été envoyé automatiquement, merci de ne pas y répondre.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    // Version texte alternative
    $text_message = "
    NAGEX Pharma - Compte créé avec succès
    
    Bonjour $fullname,
    
    Votre compte NAGEX Pharma a été créé avec succès.
    
    INFORMATIONS DE VOTRE COMPTE :
    Rôle : $role
    Email : $email
    Date de création : " . date('d/m/Y à H:i') . "
    
    VOS IDENTIFIANTS DE CONNEXION :
    URL de connexion : http://localhost/NAGEX%20Pharma/modules/utilisateurs/login.php
    Email : $email
    Mot de passe : $password
    
    IMPORTANT :
    - Changez votre mot de passe après votre première connexion
    - Ne partagez jamais vos identifiants
    - Déconnectez-vous après chaque utilisation
    - Conservez cet email en lieu sûr
    
    Si vous n'êtes pas à l'origine de cette création de compte, contactez immédiatement l'administrateur.
    
    Cordialement,
    L'équipe NAGEX Pharma
    
    © 2024 NAGEX Pharma. Tous droits réservés.
    ";
    
    // Envoyer l'email
    try {
        $mailSent = mail($email, $subject, $message, $headers);
        
        // Si l'envoi échoue, log l'erreur
        if (!$mailSent) {
            error_log("Échec de l'envoi d'email à: $email");
        }
        
        return $mailSent;
        
    } catch (Exception $e) {
        error_log("Erreur d'envoi d'email: " . $e->getMessage());
        return false;
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inscription - NAGEX Pharma</title>
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
        .alert-success { background-color: #D1FAE5; border-color: #10B981; color: #065F46; }
        .alert-error { background-color: #FEE2E2; border-color: #EF4444; color: #7F1D1D; }
        .alert-warning { background-color: #FEF3C7; border-color: #F59E0B; color: #92400E; }
    </style>
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center p-4" style="font-family: 'Montserrat', sans-serif;">
    <div class="max-w-7xl w-full bg-white rounded-2xl shadow-2xl overflow-hidden flex flex-col md:flex-row">
        <!-- Section image avec overlay moderne -->
        <div class="hidden lg:flex w-1/2 relative overflow-hidden">
            <div class="absolute inset-0 bg-gradient-to-br from-[#059669]/90 to-[#059669]/80 z-10"></div>
            <img
                src="https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcRYlRWLhd8aNBNNW9VrqqnDG2AgjJvPYHyWdA&s"
                alt="Inscription nagex pharma"
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
                    <h1 class="text-3xl font-bold text-gray-900">Inscription</h1>
                    <p class="text-gray-600 mt-2">Créez votre compte collaborateur</p>
                </div>

                <!-- Messages d'alerte -->
                <?php if (!empty($errors)): ?>
                    <div class="alert-error border rounded-lg p-4 mb-6">
                        <div class="flex items-center">
                            <i class="fas fa-exclamation-triangle mr-2"></i>
                            <span class="font-semibold">Erreurs :</span>
                        </div>
                        <ul class="mt-2 list-disc list-inside">
                            <?php foreach ($errors as $error): ?>
                                <li class="text-sm"><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if (isset($success)): ?>
                    <div class="alert-success border rounded-lg p-4 mb-6">
                        <div class="flex items-center">
                            <i class="fas fa-check-circle mr-2"></i>
                            <span class="font-semibold">Succès !</span>
                        </div>
                        <p class="mt-2 text-sm"><?php echo htmlspecialchars($success); ?></p>
                        <p class="mt-1 text-xs text-green-700">Redirection vers la page de connexion dans 3 secondes...</p>
                    </div>
                <?php endif; ?>

                <!-- Avertissement pour les emails -->
                <div class="alert-warning border rounded-lg p-4 mb-6">
                    <div class="flex items-center">
                        <i class="fas fa-info-circle mr-2"></i>
                        <span class="font-semibold">Information importante</span>
                    </div>
                    <p class="mt-2 text-sm">Vos identifiants de connexion seront envoyés à l'adresse email que vous renseignez.</p>
                </div>

                <form method="POST" class="space-y-4">
                    <div>
                        <label for="fullname" class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-user mr-2"></i>Nom complet
                        </label>
                        <input type="text" id="fullname" name="fullname" required
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200"
                               placeholder="Votre nom complet"
                               value="<?php echo isset($_POST['fullname']) ? htmlspecialchars($_POST['fullname']) : ''; ?>">
                    </div>

                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-envelope mr-2"></i>Email professionnel
                        </label>
                        <input type="email" id="email" name="email" required
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200"
                               placeholder="votre@email.com"
                               value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                    </div>

                    <div>
                        <label for="telephone" class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-phone mr-2"></i>Téléphone
                        </label>
                        <input type="tel" id="telephone" name="telephone"
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200"
                               placeholder="+243 XX XXX XX"
                               value="<?php echo isset($_POST['telephone']) ? htmlspecialchars($_POST['telephone']) : ''; ?>">
                    </div>

                    <div>
                        <label for="role" class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-briefcase mr-2"></i>Rôle
                        </label>
                        <select id="role" name="role" required
                                class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200">
                            <option value="">Sélectionnez un rôle</option>
                            <option value="Admin" <?php echo (isset($_POST['role']) && $_POST['role'] === 'Admin') ? 'selected' : ''; ?>>Admin</option>
                            <option value="Pharmacien" <?php echo (isset($_POST['role']) && $_POST['role'] === 'Pharmacien') ? 'selected' : ''; ?>>Pharmacien</option>
                            <option value="Caissier" <?php echo (isset($_POST['role']) && $_POST['role'] === 'Caissier') ? 'selected' : ''; ?>>Caissier</option>
                            <option value="Stockiste" <?php echo (isset($_POST['role']) && $_POST['role'] === 'Stockiste') ? 'selected' : ''; ?>>Stockiste</option>
                        </select>
                    </div>

                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-lock mr-2"></i>Mot de passe
                        </label>
                        <input type="password" id="password" name="password" required
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200"
                               placeholder="Minimum 8 caractères">
                    </div>

                    <div>
                        <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-lock mr-2"></i>Confirmer le mot de passe
                        </label>
                        <input type="password" id="confirm_password" name="confirm_password" required
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200"
                               placeholder="Retapez votre mot de passe">
                    </div>

                    <div class="flex items-center">
                        <input type="checkbox" id="terms" name="terms" required
                               class="w-4 h-4 text-green-600 border-gray-300 rounded focus:ring-green-500"
                               <?php echo (isset($_POST['terms'])) ? 'checked' : ''; ?>>
                        <label for="terms" class="ml-2 text-sm text-gray-600">
                            J'accepte les conditions d'utilisation et la politique de confidentialité
                        </label>
                    </div>

                    <button type="submit"
                            class="w-full pharma-green text-white py-3 px-4 rounded-lg font-semibold hover:bg-green-700 transition-colors duration-200 flex items-center justify-center">
                        <i class="fas fa-user-plus mr-2"></i>
                        Créer mon compte
                    </button>
                </form>

                <div class="mt-6 text-center">
                    <p class="text-gray-600">
                        Déjà inscrit ? 
                        <a href="login.php" class="text-green-600 font-semibold hover:text-green-800 transition-colors">
                            Se connecter
                        </a>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Script pour la validation des mots de passe -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const password = document.getElementById('password');
            const confirmPassword = document.getElementById('confirm_password');
            
            function validatePasswords() {
                if (password.value && confirmPassword.value) {
                    if (password.value !== confirmPassword.value) {
                        confirmPassword.classList.add('border-red-500');
                        confirmPassword.classList.remove('border-green-500');
                    } else {
                        confirmPassword.classList.remove('border-red-500');
                        confirmPassword.classList.add('border-green-500');
                    }
                }
            }
            
            password.addEventListener('input', validatePasswords);
            confirmPassword.addEventListener('input', validatePasswords);
        });
    </script>
</body>
</html>