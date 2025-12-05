<?php
declare(strict_types=1);
session_start();

// Vérifier si l'utilisateur est connecté et est un admin
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../utilisateurs/login.php');
    exit;
}

require_once __DIR__ . '/../../config/database.php';

// Récupérer les informations de l'utilisateur connecté
$userName = $_SESSION['user_nom'] ?? 'Administrateur';
$userRole = $_SESSION['user_role'] ?? 'admin';
$userEmail = $_SESSION['user_email'] ?? '';

// Initialisation des variables
$errors = [];
$success = '';
$users = [];
$activityLogs = [];

// Connexion à la base de données
try {
    $db = new Database();
} catch (Exception $e) {
    $errors[] = "Erreur de connexion à la base de données: " . $e->getMessage();
    error_log("Erreur connexion DB: " . $e->getMessage());
}

// RÉCUPÉRATION DES UTILISATEURS
if (isset($db)) {
    try {
        // MODIFICATION : Ajout des nouvelles colonnes dans la requête
        $query = "SELECT id, nom, email, role, statut, telephone, adresse, date_creation, date_modification 
                  FROM utilisateurs 
                  ORDER BY date_creation ASC";

        $stmt = $db->prepare($query);
        $stmt->execute();
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        $errors[] = "Erreur lors de la récupération des utilisateurs: " . $e->getMessage();
        error_log("Erreur récupération utilisateurs: " . $e->getMessage());
    }
}

// RÉCUPÉRATION DES LOGS D'ACTIVITÉ
if (isset($db)) {
    try {
        // Vérifier si la table user_logs existe
        $checkTable = $db->prepare("SHOW TABLES LIKE 'user_logs'");
        $checkTable->execute();

        if ($checkTable->rowCount() > 0) {
            $query = "SELECT ul.*, u.nom as user_name 
                      FROM user_logs ul 
                      LEFT JOIN utilisateurs u ON ul.user_id = u.id 
                      ORDER BY ul.created_at ASC 
                      LIMIT 50";
            $stmt = $db->prepare($query);
            $stmt->execute();
            $activityLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        error_log("Erreur récupération logs: " . $e->getMessage());
    }
}

// TRAITEMENT DES FORMULAIRES
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($db)) {

    // AJOUT D'UTILISATEUR
    if (isset($_POST['add_user'])) {
        $fullname = trim($_POST['fullname'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $telephone = trim($_POST['telephone'] ?? '');
        $adresse = trim($_POST['adresse'] ?? ''); // NOUVELLE COLONNE
        $role = $_POST['role'] ?? '';
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        // Validation
        if (empty($fullname))
            $errors[] = "Le nom complet est obligatoire.";
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL))
            $errors[] = "L'email n'est pas valide.";
        if (empty($role))
            $errors[] = "Le rôle est obligatoire.";
        if (strlen($password) < 8)
            $errors[] = "Le mot de passe doit contenir au moins 8 caractères.";
        if ($password !== $confirm_password)
            $errors[] = "Les mots de passe ne correspondent pas.";

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

        // Créer l'utilisateur
        if (empty($errors)) {
            try {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

                // MODIFICATION : Ajout de la colonne adresse dans l'insertion
                $query = "INSERT INTO utilisateurs (nom, email, mot_de_passe, role, telephone, adresse, statut, date_creation) 
                          VALUES (:nom, :email, :mot_de_passe, :role, :telephone, :adresse, 'actif', NOW())";

                $stmt = $db->prepare($query);
                $stmt->bindParam(':nom', $fullname);
                $stmt->bindParam(':email', $email);
                $stmt->bindParam(':mot_de_passe', $hashedPassword);
                $stmt->bindParam(':role', $role);
                $stmt->bindParam(':telephone', $telephone);
                $stmt->bindParam(':adresse', $adresse); 

                if ($stmt->execute()) {
                    $success = "Utilisateur créé avec succès!";

                    // Journaliser l'action
                    try {
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

                        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

                        $query = "INSERT INTO user_logs (user_id, action, details, ip_address, user_agent) 
                                  VALUES (:user_id, :action, :details, :ip_address, :user_agent)";

                        $stmt = $db->prepare($query);
                        $stmt->bindParam(':user_id', $_SESSION['user_id']);
                        $stmt->bindValue(':action', 'AJOUT_UTILISATEUR');
                        $stmt->bindValue(':details', "Nouvel utilisateur: $fullname ($role)");
                        $stmt->bindParam(':ip_address', $ip);
                        $stmt->bindParam(':user_agent', $userAgent);
                        $stmt->execute();
                    } catch (Exception $e) {
                        error_log("Erreur journalisation: " . $e->getMessage());
                    }

                    // Recharger la liste des utilisateurs
                    // MODIFICATION : Ajout des nouvelles colonnes dans la requête
                    $query = "SELECT id, nom, email, role, statut, telephone, adresse, date_creation, date_modification 
                              FROM utilisateurs 
                              ORDER BY date_creation ASC";
                    $stmt = $db->prepare($query);
                    $stmt->execute();
                    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    $_POST = []; // Réinitialiser le formulaire
                }
            } catch (PDOException $e) {
                $errors[] = "Erreur lors de la création de l'utilisateur: " . $e->getMessage();
                error_log("Erreur création utilisateur: " . $e->getMessage());
            }
        }
    }

    // ACTIVATION/DÉSACTIVATION D'UTILISATEUR
    if (isset($_POST['toggle_status'])) {
        $userId = $_POST['user_id'];
        $newStatus = $_POST['new_status'];

        try {
            // Récupérer l'utilisateur avant modification pour le log
            $query = "SELECT nom, email FROM utilisateurs WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id', $userId);
            $stmt->execute();
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                // Mettre à jour le statut
                $query = "UPDATE utilisateurs SET statut = :statut WHERE id = :id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':statut', $newStatus);
                $stmt->bindParam(':id', $userId);

                if ($stmt->execute()) {
                    $action = $newStatus === 'actif' ? 'ACTIVATION' : 'DESACTIVATION';
                    $success = "Utilisateur " . ($newStatus === 'actif' ? 'activé' : 'désactivé') . " avec succès!";

                    // Journaliser l'action
                    try {
                        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

                        $query = "INSERT INTO user_logs (user_id, action, details, ip_address, user_agent) 
                                  VALUES (:user_id, :action, :details, :ip_address, :user_agent)";

                        $stmt = $db->prepare($query);
                        $stmt->bindParam(':user_id', $_SESSION['user_id']);
                        $stmt->bindValue(':action', $action);
                        $stmt->bindValue(':details', "Utilisateur: {$user['nom']} ({$user['email']}) - Statut: $newStatus");
                        $stmt->bindParam(':ip_address', $ip);
                        $stmt->bindParam(':user_agent', $userAgent);
                        $stmt->execute();
                    } catch (Exception $e) {
                        error_log("Erreur journalisation: " . $e->getMessage());
                    }

                    // Recharger la liste des utilisateurs
                    // MODIFICATION : Ajout des nouvelles colonnes dans la requête
                    $query = "SELECT id, nom, email, role, statut, telephone, adresse, date_creation, date_modification 
                              FROM utilisateurs 
                              ORDER BY date_creation ASC";
                    $stmt = $db->prepare($query);
                    $stmt->execute();
                    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
            } else {
                $errors[] = "Utilisateur non trouvé.";
            }
        } catch (PDOException $e) {
            $errors[] = "Erreur lors de la modification du statut: " . $e->getMessage();
        }
    }

    // SUPPRESSION D'UTILISATEUR
    if (isset($_POST['delete_user'])) {
        $userId = $_POST['user_id'];

        try {
            if ($userId == $_SESSION['user_id']) {
                $errors[] = "Vous ne pouvez pas supprimer votre propre compte.";
            } else {
                // Récupérer l'utilisateur avant suppression pour le log
                $query = "SELECT nom, email FROM utilisateurs WHERE id = :id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':id', $userId);
                $stmt->execute();
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user) {
                    // Supprimer l'utilisateur
                    $query = "DELETE FROM utilisateurs WHERE id = :id";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':id', $userId);

                    if ($stmt->execute()) {
                        $success = "Utilisateur supprimé avec succès!";

                        // Journaliser l'action
                        try {
                            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

                            $query = "INSERT INTO user_logs (user_id, action, details, ip_address, user_agent) 
                                      VALUES (:user_id, :action, :details, :ip_address, :user_agent)";

                            $stmt = $db->prepare($query);
                            $stmt->bindParam(':user_id', $_SESSION['user_id']);
                            $stmt->bindValue(':action', 'SUPPRESSION_UTILISATEUR');
                            $stmt->bindValue(':details', "Utilisateur supprimé: {$user['nom']} ({$user['email']})");
                            $stmt->bindParam(':ip_address', $ip);
                            $stmt->bindParam(':user_agent', $userAgent);
                            $stmt->execute();
                        } catch (Exception $e) {
                            error_log("Erreur journalisation: " . $e->getMessage());
                        }

                        // Recharger la liste des utilisateurs
                        // MODIFICATION : Ajout des nouvelles colonnes dans la requête
                        $query = "SELECT id, nom, email, role, statut, telephone, adresse, date_creation, date_modification 
                                  FROM utilisateurs 
                                  ORDER BY date_creation ASC";
                        $stmt = $db->prepare($query);
                        $stmt->execute();
                        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    }
                } else {
                    $errors[] = "Utilisateur non trouvé.";
                }
            }
        } catch (PDOException $e) {
            $errors[] = "Erreur lors de la suppression de l'utilisateur: " . $e->getMessage();
        }
    }

    // MODIFICATION D'UTILISATEUR
    if (isset($_POST['edit_user'])) {
        $userId = $_POST['user_id'];
        $nom = trim($_POST['edit_nom'] ?? '');
        $email = trim($_POST['edit_email'] ?? '');
        $telephone = trim($_POST['edit_telephone'] ?? '');
        $adresse = trim($_POST['edit_adresse'] ?? ''); 
        $role = $_POST['edit_role'] ?? '';

        // Validation
        if (empty($nom))
            $errors[] = "Le nom est obligatoire.";
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL))
            $errors[] = "L'email n'est pas valide.";
        if (empty($role))
            $errors[] = "Le rôle est obligatoire.";

        if (empty($errors)) {
            try {
                // Vérifier si l'email existe déjà pour un autre utilisateur
                $checkQuery = "SELECT id FROM utilisateurs WHERE email = :email AND id != :id";
                $checkStmt = $db->prepare($checkQuery);
                $checkStmt->bindParam(':email', $email);
                $checkStmt->bindParam(':id', $userId);
                $checkStmt->execute();

                if ($checkStmt->rowCount() > 0) {
                    $errors[] = "Cet email est déjà utilisé par un autre utilisateur.";
                } else {
                    // MODIFICATION : Ajout de la colonne adresse dans la mise à jour
                    $query = "UPDATE utilisateurs SET nom = :nom, email = :email, telephone = :telephone, adresse = :adresse, role = :role WHERE id = :id";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':nom', $nom);
                    $stmt->bindParam(':email', $email);
                    $stmt->bindParam(':telephone', $telephone);
                    $stmt->bindParam(':adresse', $adresse); // NOUVEAU BINDPARAM
                    $stmt->bindParam(':role', $role);
                    $stmt->bindParam(':id', $userId);

                    if ($stmt->execute()) {
                        $success = "Utilisateur modifié avec succès!";

                        // Journaliser l'action
                        try {
                            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

                            $query = "INSERT INTO user_logs (user_id, action, details, ip_address, user_agent) 
                                      VALUES (:user_id, :action, :details, :ip_address, :user_agent)";

                            $stmt = $db->prepare($query);
                            $stmt->bindParam(':user_id', $_SESSION['user_id']);
                            $stmt->bindValue(':action', 'MODIFICATION_UTILISATEUR');
                            $stmt->bindValue(':details', "Utilisateur modifié: $nom ($email) - Rôle: $role");
                            $stmt->bindParam(':ip_address', $ip);
                            $stmt->bindParam(':user_agent', $userAgent);
                            $stmt->execute();
                        } catch (Exception $e) {
                            error_log("Erreur journalisation: " . $e->getMessage());
                        }

                        // Recharger la liste des utilisateurs
                        // MODIFICATION : Ajout des nouvelles colonnes dans la requête
                        $query = "SELECT id, nom, email, role, statut, telephone, adresse, date_creation, date_modification 
                                  FROM utilisateurs 
                                  ORDER BY date_creation ASC";
                        $stmt = $db->prepare($query);
                        $stmt->execute();
                        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    }
                }
            } catch (PDOException $e) {
                $errors[] = "Erreur lors de la modification de l'utilisateur: " . $e->getMessage();
            }
        }
    }
}

// FONCTIONS UTILITAIRES POUR L'AFFICHAGE
function getLogIcon($action): string
{
    $icons = [
        'CONNEXION' => 'sign-in-alt',
        'DECONNEXION' => 'sign-out-alt',
        'AJOUT_UTILISATEUR' => 'user-plus',
        'MODIFICATION_UTILISATEUR' => 'edit',
        'ACTIVATION' => 'play',
        'DESACTIVATION' => 'pause',
        'SUPPRESSION_UTILISATEUR' => 'trash'
    ];
    return $icons[$action] ?? 'history';
}

function getRoleBadgeClass($role): string
{
    $classes = [
        'admin' => 'bg-red-100 text-red-800',
        'pharmacien' => 'bg-blue-100 text-blue-800',
        'caissier' => 'bg-yellow-100 text-yellow-800',
        'stockiste' => 'bg-purple-100 text-purple-800',
        'gerant' => 'bg-indigo-100 text-indigo-800',
        'fournisseur' => 'bg-teal-100 text-teal-800',
        'client' => 'bg-gray-100 text-gray-800' // NOUVEAU RÔLE
    ];
    return $classes[$role] ?? 'bg-gray-100 text-gray-800';
}

function formatDate($date): string
{
    if (!$date)
        return 'N/A';

    $timestamp = strtotime($date);
    $now = time();
    $diff = $now - $timestamp;

    if ($diff < 60)
        return 'À l\'instant';
    if ($diff < 3600)
        return 'Il y a ' . floor($diff / 60) . ' min';
    if ($diff < 86400)
        return 'Il y a ' . floor($diff / 3600) . ' h';
    if ($diff < 604800)
        return 'Il y a ' . floor($diff / 86400) . ' j';

    return date('d/m/Y à H:i', $timestamp);
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Admin - Pharma Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,100..900;1,100..900&display=swap"
        rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .sidebar {
            transition: all 0.3s ease;
        }

        .active {
            background-color: #10B981;
            color: white;
        }

        .pharma-green {
            background-color: #10B981;
        }

        .pharma-green:hover {
            background-color: #059669;
        }

        .alert-success {
            background-color: #D1FAE5;
            border-color: #10B981;
            color: #065F46;
        }

        .alert-error {
            background-color: #FEE2E2;
            border-color: #EF4444;
            color: #7F1D1D;
        }
    </style>
</head>

<body class="bg-gray-100" style="font-family: 'Montserrat', sans-serif;">
    <!-- Sidebar -->
    <div class="sidebar fixed inset-y-0 left-0 z-40 w-64 bg-white shadow-lg">
        <!-- Logo -->
        <div class="flex items-center justify-center p-6 border-b">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 bg-green-600 rounded-full flex items-center justify-center">
                    <i class="fas fa-capsules text-white text-lg"></i>
                </div>
                <div>
                    <h1 class="text-xl font-bold text-gray-800">NAGEX Pharma</h1>
                    <p class="text-xs text-green-600">Admin Dashboard</p>
                </div>
            </div>
        </div>

        <!-- Navigation -->
        <nav class="mt-6">
            <div class="px-4 space-y-2">
                <a href="#dashboard" onclick="showSection('dashboard')"
                    class="active flex items-center px-4 py-3 text-gray-700 rounded-lg transition-colors">
                    <i class="fas fa-tachometer-alt w-6"></i>
                    <span class="ml-3 font-medium">Tableau de bord</span>
                </a>
                <a href="#utilisateurs" onclick="showSection('utilisateurs')"
                    class="flex items-center px-4 py-3 text-gray-700 rounded-lg hover:bg-green-50 transition-colors">
                    <i class="fas fa-users w-6"></i>
                    <span class="ml-3 font-medium">Utilisateurs</span>
                </a>
                <a href="#historique" onclick="showSection('historique')"
                    class="flex items-center px-4 py-3 text-gray-700 rounded-lg hover:bg-green-50 transition-colors">
                    <i class="fas fa-history w-6"></i>
                    <span class="ml-3 font-medium">Historique</span>
                </a>
            </div>
        </nav>

        <!-- User Profile -->
        <div class="absolute bottom-0 left-0 right-0 p-4 border-t">
            <div class="flex items-center space-x-3">
                <img class="w-10 h-10 rounded-full border-2 border-green-200"
                    src="https://images.unsplash.com/photo-1472099645785-5658abf4ff4e?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=100&q=80"
                    alt="<?php echo htmlspecialchars($userName); ?>">
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-gray-900 truncate"><?php echo htmlspecialchars($userName); ?></p>
                    <p class="text-xs text-green-600 truncate"><?php echo htmlspecialchars($userRole); ?></p>
                </div>
                <a href="../utilisateurs/logout.php" class="text-gray-400 hover:text-red-500 transition-colors"
                    title="Déconnexion">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="ml-64">
        <!-- Header -->
        <header class="bg-white shadow-sm">
            <div class="flex items-center justify-between px-8 py-4">
                <div>
                    <h2 class="text-2xl font-bold text-gray-800" id="pageTitle">Tableau de bord Admin</h2>
                    <p class="text-gray-600">Gestion complète de la pharmacie</p>
                </div>
                <div class="flex items-center space-x-4">
                    <div class="text-sm text-gray-600">
                        <i class="fas fa-user-circle mr-2"></i>
                        Connecté en tant que <span
                            class="font-semibold"><?php echo htmlspecialchars($userRole); ?></span>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Content Area -->
        <main class="p-8">
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

            <?php if (!empty($success)): ?>
                <div class="alert-success border rounded-lg p-4 mb-6">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle mr-2"></i>
                        <span class="font-semibold">Succès !</span>
                    </div>
                    <p class="mt-2 text-sm"><?php echo htmlspecialchars($success); ?></p>
                </div>
            <?php endif; ?>

            <!-- Section Tableau de bord -->
            <div id="dashboard" class="section">
                <!-- Stats Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <div class="bg-white rounded-2xl shadow-sm p-6 border-l-4 border-green-500">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-600">Utilisateurs actifs</p>
                                <p class="text-2xl font-bold text-gray-900"><?php echo count($users); ?></p>
                            </div>
                            <div class="w-12 h-12 bg-green-100 rounded-full flex items-center justify-center">
                                <i class="fas fa-users text-green-600 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-2xl shadow-sm p-6 border-l-4 border-blue-500">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-600">Actions aujourd'hui</p>
                                <p class="text-2xl font-bold text-gray-900">
                                    <?php
                                    $todayLogs = array_filter($activityLogs, function ($log) {
                                        return date('Y-m-d', strtotime($log['created_at'] ?? '')) === date('Y-m-d');
                                    });
                                    echo count($todayLogs);
                                    ?>
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center">
                                <i class="fas fa-chart-line text-blue-600 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-2xl shadow-sm p-6 border-l-4 border-yellow-500">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-600">Admins</p>
                                <p class="text-2xl font-bold text-gray-900">
                                    <?php
                                    $adminCount = array_filter($users, function ($user) {
                                        return ($user['role'] ?? '') === 'admin';
                                    });
                                    echo count($adminCount);
                                    ?>
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-yellow-100 rounded-full flex items-center justify-center">
                                <i class="fas fa-user-shield text-yellow-600 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-2xl shadow-sm p-6 border-l-4 border-purple-500">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-600">Total des logs</p>
                                <p class="text-2xl font-bold text-gray-900"><?php echo count($activityLogs); ?></p>
                            </div>
                            <div class="w-12 h-12 bg-purple-100 rounded-full flex items-center justify-center">
                                <i class="fas fa-history text-purple-600 text-xl"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Charts and Activity -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                    <!-- Chart -->
                    <div class="bg-white rounded-2xl shadow-sm p-6">
                        <div class="flex items-center justify-between mb-6">
                            <h3 class="text-lg font-semibold text-gray-900">Activité des utilisateurs (7 jours)</h3>
                        </div>
                        <div class="h-80">
                            <canvas id="activityChart"></canvas>
                        </div>
                    </div>

                    <!-- Recent Activity -->
                    <div class="bg-white rounded-2xl shadow-sm p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-6">Activité récente</h3>
                        <div class="space-y-4 max-h-80 overflow-y-auto">
                            <?php if (!empty($activityLogs)): ?>
                                <?php foreach (array_slice($activityLogs, 0, 5) as $log): ?>
                                    <div class="flex items-start space-x-3">
                                        <div
                                            class="w-8 h-8 bg-green-100 rounded-full flex items-center justify-center flex-shrink-0">
                                            <i
                                                class="fas fa-<?php echo getLogIcon($log['action'] ?? ''); ?> text-green-600 text-sm"></i>
                                        </div>
                                        <div class="flex-1">
                                            <p class="text-sm font-medium text-gray-900">
                                                <?php echo htmlspecialchars($log['details'] ?? ''); ?>
                                            </p>
                                            <p class="text-xs text-gray-500">Par
                                                <?php echo htmlspecialchars($log['user_name'] ?? 'Système'); ?>
                                            </p>
                                            <p class="text-xs text-gray-400"><?php echo formatDate($log['created_at'] ?? ''); ?>
                                            </p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="text-gray-500 text-sm">Aucune activité récente</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section Gestion des Utilisateurs -->
            <div id="utilisateurs" class="section hidden">
                <div class="bg-white rounded-2xl shadow-sm">
                    <div class="px-6 py-4 border-b">
                        <div class="flex items-center justify-between">
                            <h3 class="text-xl font-semibold text-gray-900">Gestion des Utilisateurs</h3>
                        </div>
                    </div>

                    <div class="p-6">
                        <!-- Formulaire d'ajout d'utilisateur -->
                        <div class="mb-8">
                            <h4 class="text-lg font-semibold text-gray-900 mb-4">Ajouter un nouvel utilisateur</h4>
                            <form method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <input type="hidden" name="add_user" value="1">

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Nom complet</label>
                                    <input type="text" name="fullname" required
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent"
                                        value="<?php echo htmlspecialchars($_POST['fullname'] ?? ''); ?>">
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Email</label>
                                    <input type="email" name="email" required
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent"
                                        value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Téléphone</label>
                                    <input type="tel" name="telephone"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent"
                                        value="<?php echo htmlspecialchars($_POST['telephone'] ?? ''); ?>">
                                </div>
                                <!-- Ajouter ce champ après le champ téléphone -->
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Adresse</label>
                                    <textarea name="adresse"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent"
                                        rows="2"><?php echo htmlspecialchars($_POST['adresse'] ?? ''); ?></textarea>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Rôle</label>
                                    <select name="role" required
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent">
                                        <option value="">Sélectionnez un rôle</option>
                                        <option value="admin" <?php echo (isset($_POST['role']) && $_POST['role'] === 'admin') ? 'selected' : ''; ?>>Admin</option>
                                        <option value="pharmacien" <?php echo (isset($_POST['role']) && $_POST['role'] === 'pharmacien') ? 'selected' : ''; ?>>Pharmacien</option>
                                        <option value="caissier" <?php echo (isset($_POST['role']) && $_POST['role'] === 'caissier') ? 'selected' : ''; ?>>Caissier</option>
                                        <option value="stockiste" <?php echo (isset($_POST['role']) && $_POST['role'] === 'stockiste') ? 'selected' : ''; ?>>Stockiste</option>
                                        <option value="gerant" <?php echo (isset($_POST['role']) && $_POST['role'] === 'gerant') ? 'selected' : ''; ?>>Gerant</option>
                                        <option value="fournisseur" <?php echo (isset($_POST['role']) && $_POST['role'] === 'fournisseur') ? 'selected' : ''; ?>>Fournisseur</option>
                                        <option value="client" <?php echo (isset($_POST['role']) && $_POST['role'] === 'client') ? 'selected' : ''; ?>>Client</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Mot de passe</label>
                                    <input type="password" name="password" required
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent">
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Confirmer le mot de
                                        passe</label>
                                    <input type="password" name="confirm_password" required
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent">
                                </div>

                                <div class="md:col-span-2">
                                    <button type="submit"
                                        class="pharma-green text-white px-6 py-3 rounded-lg font-semibold hover:bg-green-700 transition-colors flex items-center justify-center">
                                        <i class="fas fa-user-plus mr-2"></i>
                                        Créer l'utilisateur
                                    </button>
                                </div>
                            </form>
                        </div>

                        <!-- Tableau des utilisateurs -->
                        <div class="overflow-x-auto">
                            <table class="w-full">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th
                                            class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Utilisateur</th>
                                        <th
                                            class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Adresse
                                        </th>
                                        <th
                                            class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Rôle</th>
                                        <th
                                            class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Statut</th>
                                        <th
                                            class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Date création</th>
                                       
                                        </th>
                                        <th
                                            class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php if (!empty($users)): ?>
                                        <?php foreach ($users as $user): ?>
                                            <tr class="hover:bg-gray-50">
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="flex items-center">
                                                        <div
                                                            class="w-10 h-10 bg-green-100 rounded-full flex items-center justify-center mr-3">
                                                            <i class="fas fa-user text-green-600"></i>
                                                        </div>
                                                        <div>
                                                            <div class="text-sm font-medium text-gray-900">
                                                                <?php echo htmlspecialchars($user['nom'] ?? 'Non défini'); ?>
                                                            </div>
                                                            <div class="text-sm text-gray-500">
                                                                <?php echo htmlspecialchars($user['email'] ?? 'Non défini'); ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo htmlspecialchars($user['adresse'] ?? 'Non définie'); ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <span
                                                        class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo getRoleBadgeClass($user['role'] ?? ''); ?>">
                                                        <?php echo htmlspecialchars($user['role'] ?? 'Non défini'); ?>
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <span
                                                        class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo ($user['statut'] ?? '') === 'actif' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                                        <?php echo htmlspecialchars($user['statut'] ?? 'inactif'); ?>
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo isset($user['date_creation']) ? formatDate($user['date_creation']) : 'Non définie'; ?>
                                                </td>
                                                
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                    <div class="flex space-x-2">
                                                        <!-- Bouton Modifier -->
                                                        <button
                                                            onclick="openEditModal(<?php echo htmlspecialchars(json_encode($user)); ?>)"
                                                            class="text-blue-600 hover:text-blue-900" title="Modifier">
                                                            <i class="fas fa-edit"></i>
                                                        </button>

                                                        <!-- Bouton Activer/Désactiver -->
                                                        <form method="POST" class="inline">
                                                            <input type="hidden" name="user_id"
                                                                value="<?php echo $user['id'] ?? ''; ?>">
                                                            <input type="hidden" name="new_status"
                                                                value="<?php echo ($user['statut'] ?? '') === 'actif' ? 'inactif' : 'actif'; ?>">
                                                            <button type="submit" name="toggle_status"
                                                                class="<?php echo ($user['statut'] ?? '') === 'actif' ? 'text-yellow-600 hover:text-yellow-900' : 'text-green-600 hover:text-green-900'; ?>"
                                                                title="<?php echo ($user['statut'] ?? '') === 'actif' ? 'Désactiver' : 'Activer'; ?>">
                                                                <i
                                                                    class="fas fa-<?php echo ($user['statut'] ?? '') === 'actif' ? 'pause' : 'play'; ?>"></i>
                                                            </button>
                                                        </form>

                                                        <!-- Bouton Supprimer -->
                                                        <?php if (($user['id'] ?? 0) != ($_SESSION['user_id'] ?? 0)): ?>
                                                            <form method="POST" class="inline"
                                                                onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer cet utilisateur ? Cette action est irréversible.')">
                                                                <input type="hidden" name="user_id"
                                                                    value="<?php echo $user['id'] ?? ''; ?>">
                                                                <button type="submit" name="delete_user"
                                                                    class="text-red-600 hover:text-red-900" title="Supprimer">
                                                                    <i class="fas fa-trash"></i>
                                                                </button>
                                                            </form>
                                                        <?php else: ?>
                                                            <span class="text-gray-400 cursor-not-allowed"
                                                                title="Vous ne pouvez pas supprimer votre propre compte">
                                                                <i class="fas fa-trash"></i>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" class="px-6 py-4 text-center text-gray-500">
                                                Aucun utilisateur trouvé dans la base de données.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section Historique des Actions -->
            <div id="historique" class="section hidden">
                <div class="bg-white rounded-2xl shadow-sm">
                    <div class="px-6 py-4 border-b">
                        <h3 class="text-xl font-semibold text-gray-900">Historique des Actions</h3>
                    </div>

                    <div class="p-6">
                        <!-- Liste des logs -->
                        <div class="space-y-4">
                            <?php if (!empty($activityLogs)): ?>
                                <?php foreach ($activityLogs as $log): ?>
                                    <div class="flex items-start space-x-4 p-4 border border-gray-200 rounded-lg">
                                        <div
                                            class="w-10 h-10 bg-green-100 rounded-full flex items-center justify-center flex-shrink-0">
                                            <i
                                                class="fas fa-<?php echo getLogIcon($log['action'] ?? ''); ?> text-green-600"></i>
                                        </div>
                                        <div class="flex-1">
                                            <div class="flex items-center justify-between">
                                                <h4 class="font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($log['action'] ?? ''); ?>
                                                </h4>
                                                <span
                                                    class="text-sm text-gray-500"><?php echo formatDate($log['created_at'] ?? ''); ?></span>
                                            </div>
                                            <p class="text-sm text-gray-600 mt-1">
                                                <?php echo htmlspecialchars($log['details'] ?? ''); ?>
                                            </p>
                                            <div class="flex items-center mt-2 text-xs text-gray-500">
                                                <span class="mr-4">Par:
                                                    <?php echo htmlspecialchars($log['user_name'] ?? 'Système'); ?></span>
                                                <span class="mr-4">IP:
                                                    <?php echo htmlspecialchars($log['ip_address'] ?? ''); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="text-gray-500 text-center py-8">Aucun historique d'actions disponible</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Modal de modification d'utilisateur -->
    <div id="editModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 class="text-lg font-medium text-gray-900 mb-4">Modifier l'utilisateur</h3>
                <form method="POST" id="editForm">
                    <input type="hidden" name="edit_user" value="1">
                    <input type="hidden" name="user_id" id="edit_user_id">

                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Nom</label>
                        <input type="text" name="edit_nom" id="edit_nom" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent">
                    </div>

                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Email</label>
                        <input type="email" name="edit_email" id="edit_email" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent">
                    </div>

                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Téléphone</label>
                        <input type="tel" name="edit_telephone" id="edit_telephone"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent">
                    </div>
                    <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Adresse</label>
                    <textarea name="edit_adresse" id="edit_adresse" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent" rows="3"></textarea>
                </div>

                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Rôle</label>
                        <select name="edit_role" id="edit_role" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent">
                            <option value="">Sélectionnez un rôle</option>
                            <option value="admin">Admin</option>
                            <option value="pharmacien">Pharmacien</option>
                            <option value="caissier">Caissier</option>
                            <option value="stockiste">Stockiste</option>
                            <option value="gerant">Gerant</option>
                            <option value="fournisseur">Fournisseur</option>
                            <option value="client">Client</option>
                        </select>
                    </div>

                    <div class="flex justify-end space-x-3 mt-6">
                        <button type="button" onclick="closeEditModal()"
                            class="px-4 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400 transition-colors">
                            Annuler
                        </button>
                        <button type="submit"
                            class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors">
                            Enregistrer
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Navigation entre sections
        function showSection(sectionName) {
            document.querySelectorAll('.section').forEach(section => {
                section.classList.add('hidden');
            });
            document.getElementById(sectionName).classList.remove('hidden');

            const titles = {
                'dashboard': 'Tableau de bord Admin',
                'utilisateurs': 'Gestion des Utilisateurs',
                'historique': 'Historique des Actions'
            };
            document.getElementById('pageTitle').textContent = titles[sectionName] || 'Tableau de bord Admin';

            document.querySelectorAll('nav a').forEach(link => {
                link.classList.remove('active');
                if (link.getAttribute('href') === '#' + sectionName) {
                    link.classList.add('active');
                }
            });
        }

        // Gestion du modal de modification
        function openEditModal(user) {
            document.getElementById('edit_user_id').value = user.id;
            document.getElementById('edit_nom').value = user.nom || '';
            document.getElementById('edit_email').value = user.email || '';
            document.getElementById('edit_telephone').value = user.telephone || '';
            document.getElementById('edit_role').value = user.role || '';
            document.getElementById('edit_adresse').value = user.adresse || '';
            document.getElementById('editModal').classList.remove('hidden');
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.add('hidden');
        }

        // Fermer le modal en cliquant à l'extérieur
        document.getElementById('editModal').addEventListener('click', function (e) {
            if (e.target.id === 'editModal') {
                closeEditModal();
            }
        });

        // Chart.js Initialization
        document.addEventListener('DOMContentLoaded', function () {
            const ctx = document.getElementById('activityChart');
            if (ctx) {
                new Chart(ctx.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'],
                        datasets: [{
                            label: 'Connexions',
                            data: [12, 19, 8, 15, 12, 5, 9],
                            backgroundColor: '#10B981',
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: { y: { beginAtZero: true } }
                    }
                });
            }

            showSection('dashboard');
        });
    </script>
</body>

</html>