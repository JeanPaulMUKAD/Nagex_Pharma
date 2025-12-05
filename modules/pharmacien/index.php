<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../../config/database.php';

// Vérifier l'authentification et le rôle
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['user_role'] !== 'pharmacien') {
    header('Location: login.php');
    exit;
}

$db = new Database();
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_nom'];
$user_role = $_SESSION['user_role'];

// Initialiser toutes les variables
$produits_attente = $produits_actifs = $produits_inactifs = $categories_count = $fournisseurs_count = $alertes_stock = 0;
$produits_data = $categories_data = $fournisseurs_data = $produits_attente_data = $alertes_data = [];

// Traitement des formulaires
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // GESTION DES PRODUITS
        // Ajout produit
        if (isset($_POST['ajouter_produit'])) {
            $nom = trim($_POST['nom']);
            $description = trim($_POST['description']);
            $code_barre = trim($_POST['code_barre']);
            $categorie_id = (int) $_POST['categorie_id'];
            $fournisseur_id = (int) $_POST['fournisseur_id'];
            $necessite_ordonnance = isset($_POST['necessite_ordonnance']) ? 1 : 0;
            $composition = trim($_POST['composition']);
            $posologie = trim($_POST['posologie']);
            $contre_indications = trim($_POST['contre_indications']);

            $stmt = $db->prepare("INSERT INTO produits (nom, description, code_barre, categorie_id, fournisseur_id, necessite_ordonnance, composition, posologie, contre_indications, statut, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'en_attente', ?)");
            $stmt->execute([$nom, $description, $code_barre, $categorie_id, $fournisseur_id, $necessite_ordonnance, $composition, $posologie, $contre_indications, $user_id]);
            $_SESSION['success_message'] = "Produit ajouté avec succès (en attente de validation)";
        }

        // Modification produit
        if (isset($_POST['modifier_produit'])) {
            $produit_id = (int) $_POST['produit_id'];
            $nom = trim($_POST['nom']);
            $description = trim($_POST['description']);
            $code_barre = trim($_POST['code_barre']);
            $categorie_id = (int) $_POST['categorie_id'];
            $fournisseur_id = (int) $_POST['fournisseur_id'];
            $necessite_ordonnance = isset($_POST['necessite_ordonnance']) ? 1 : 0;
            $composition = trim($_POST['composition']);
            $posologie = trim($_POST['posologie']);
            $contre_indications = trim($_POST['contre_indications']);

            $stmt = $db->prepare("UPDATE produits SET nom = ?, description = ?, code_barre = ?, categorie_id = ?, fournisseur_id = ?, necessite_ordonnance = ?, composition = ?, posologie = ?, contre_indications = ? WHERE id = ?");
            $stmt->execute([$nom, $description, $code_barre, $categorie_id, $fournisseur_id, $necessite_ordonnance, $composition, $posologie, $contre_indications, $produit_id]);
            $_SESSION['success_message'] = "Produit modifié avec succès";
        }

        // Validation produit
        if (isset($_POST['valider_produit'])) {
            $produit_id = (int) $_POST['produit_id'];
            $stmt = $db->prepare("UPDATE produits SET statut = 'actif' WHERE id = ?");
            $stmt->execute([$produit_id]);
            $_SESSION['success_message'] = "Produit validé avec succès";
        }

        // Archivage produit
        if (isset($_POST['archiver_produit'])) {
            $produit_id = (int) $_POST['produit_id'];
            $stmt = $db->prepare("UPDATE produits SET statut = 'inactif' WHERE id = ?");
            $stmt->execute([$produit_id]);
            $_SESSION['success_message'] = "Produit archivé avec succès";
        }

        // GESTION DES CATÉGORIES
        // Ajout catégorie
        if (isset($_POST['ajouter_categorie'])) {
            $nom = trim($_POST['nom']);
            $description = trim($_POST['description']);

            $stmt = $db->prepare("INSERT INTO categories (nom, description, created_by, statut) VALUES (?, ?, ?, 'actif')");
            $stmt->execute([$nom, $description, $user_id]);
            $_SESSION['success_message'] = "Catégorie ajoutée avec succès";
        }

        // Modification catégorie
        if (isset($_POST['modifier_categorie'])) {
            $categorie_id = (int) $_POST['categorie_id'];
            $nom = trim($_POST['nom']);
            $description = trim($_POST['description']);
            $statut = $_POST['statut'];

            $stmt = $db->prepare("UPDATE categories SET nom = ?, description = ?, statut = ? WHERE id = ?");
            $stmt->execute([$nom, $description, $statut, $categorie_id]);
            $_SESSION['success_message'] = "Catégorie modifiée avec succès";
        }

        // GESTION DES FOURNISSEURS
        // Ajout fournisseur
        if (isset($_POST['ajouter_fournisseur'])) {
            $nom_societe = trim($_POST['nom_societe']);
            $contact_principal = trim($_POST['contact_principal']);
            $adresse_siege = trim($_POST['adresse_siege']);

            try {
                // Vérifier d'abord si un fournisseur avec ce nom existe déjà
                $stmt_check = $db->prepare("SELECT id FROM fournisseurs WHERE nom_societe = ?");
                $stmt_check->execute([$nom_societe]);

                if ($stmt_check->fetch()) {
                    $_SESSION['error_message'] = "Un fournisseur avec ce nom existe déjà";
                } else {
                    // Générer un email unique pour le fournisseur
                    $base_email = strtolower(preg_replace('/[^a-zA-Z0-9]/', '.', $nom_societe));
                    $email_fournisseur = $base_email . '@fournisseur.pharma';

                    // Vérifier si l'email existe déjà et générer un email unique si nécessaire
                    $counter = 1;
                    while (true) {
                        $stmt_check_email = $db->prepare("SELECT id FROM utilisateurs WHERE email = ?");
                        $stmt_check_email->execute([$email_fournisseur]);
                        if (!$stmt_check_email->fetch()) {
                            break; // Email unique trouvé
                        }
                        $email_fournisseur = $base_email . '.' . $counter . '@fournisseur.pharma';
                        $counter++;
                        if ($counter > 100) {
                            throw new Exception("Impossible de générer un email unique");
                        }
                    }

                    // Créer d'abord l'utilisateur fournisseur
                    $stmt_user = $db->prepare("INSERT INTO utilisateurs (nom, email, mot_de_passe, role, telephone, statut) VALUES (?, ?, ?, 'fournisseur', ?, 'actif')");
                    $password_hash = password_hash('temp_password123', PASSWORD_DEFAULT);
                    $stmt_user->execute([$nom_societe, $email_fournisseur, $password_hash, $contact_principal]);

                    $nouvel_user_id = $db->lastInsertId();

                    // Puis créer le fournisseur
                    $stmt_fournisseur = $db->prepare("INSERT INTO fournisseurs (user_id, nom_societe, contact_principal, adresse_siege) VALUES (?, ?, ?, ?)");
                    $stmt_fournisseur->execute([$nouvel_user_id, $nom_societe, $contact_principal, $adresse_siege]);

                    $_SESSION['success_message'] = "Fournisseur ajouté avec succès - Email: " . $email_fournisseur;
                }

            } catch (PDOException $e) {
                error_log("Erreur détaillée ajout fournisseur: " . $e->getMessage());
                if ($e->getCode() == '23000') {
                    $_SESSION['error_message'] = "Erreur : Email déjà utilisé ou contrainte d'unicité";
                } else {
                    $_SESSION['error_message'] = "Erreur lors de l'ajout: " . $e->getMessage();
                }
            } catch (Exception $e) {
                $_SESSION['error_message'] = $e->getMessage();
            }
        }

        // Modification fournisseur
        if (isset($_POST['modifier_fournisseur'])) {
            $fournisseur_id = (int) $_POST['fournisseur_id'];
            $nom_societe = trim($_POST['nom_societe']);
            $contact_principal = trim($_POST['contact_principal']);
            $adresse_siege = trim($_POST['adresse_siege']);
            $note_qualite = isset($_POST['note_qualite']) ? (float) $_POST['note_qualite'] : 0.00;

            try {
                $stmt = $db->prepare("UPDATE fournisseurs SET nom_societe = ?, contact_principal = ?, adresse_siege = ?, note_qualite = ? WHERE id = ?");
                $stmt->execute([$nom_societe, $contact_principal, $adresse_siege, $note_qualite, $fournisseur_id]);
                $_SESSION['success_message'] = "Fournisseur modifié avec succès";
            } catch (PDOException $e) {
                error_log("Erreur modification fournisseur: " . $e->getMessage());
                $_SESSION['error_message'] = "Erreur lors de la modification: " . $e->getMessage();
            }
        }

        // Redirection pour éviter le rechargement du formulaire
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;

    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Erreur lors de l'opération: " . $e->getMessage();
    }
}

// Récupérer les données avec gestion d'erreurs
try {
    // PRODUITS - Tous les produits
    $stmt_produits = $db->prepare("
        SELECT p.*, c.nom as categorie_nom, f.nom_societe as fournisseur_nom,
               u.nom as createur_nom
        FROM produits p
        JOIN categories c ON p.categorie_id = c.id
        JOIN fournisseurs f ON p.fournisseur_id = f.id
        JOIN utilisateurs u ON p.created_by = u.id
        ORDER BY p.statut, p.nom
    ");
    $stmt_produits->execute();
    $produits_data = $stmt_produits->fetchAll(PDO::FETCH_ASSOC);

    // Produits en attente
    $stmt_produits_attente = $db->prepare("
        SELECT p.*, c.nom as categorie_nom, f.nom_societe as fournisseur_nom
        FROM produits p
        JOIN categories c ON p.categorie_id = c.id
        JOIN fournisseurs f ON p.fournisseur_id = f.id
        WHERE p.statut = 'en_attente'
        ORDER BY p.created_at DESC
    ");
    $stmt_produits_attente->execute();
    $produits_attente_data = $stmt_produits_attente->fetchAll(PDO::FETCH_ASSOC);
    $produits_attente = count($produits_attente_data);

    // Produits actifs
    $stmt_produits_actifs = $db->prepare("SELECT COUNT(*) FROM produits WHERE statut = 'actif'");
    $stmt_produits_actifs->execute();
    $produits_actifs = $stmt_produits_actifs->fetchColumn();

    // Produits inactifs
    $stmt_produits_inactifs = $db->prepare("SELECT COUNT(*) FROM produits WHERE statut = 'inactif'");
    $stmt_produits_inactifs->execute();
    $produits_inactifs = $stmt_produits_inactifs->fetchColumn();

    // CATÉGORIES
    $stmt_categories = $db->prepare("
        SELECT c.*, u.nom as createur_nom,
               COUNT(p.id) as nb_produits
        FROM categories c
        JOIN utilisateurs u ON c.created_by = u.id
        LEFT JOIN produits p ON c.id = p.categorie_id
        GROUP BY c.id
        ORDER BY c.nom
    ");
    $stmt_categories->execute();
    $categories_data = $stmt_categories->fetchAll(PDO::FETCH_ASSOC);
    $categories_count = count($categories_data);

    // FOURNISSEURS
    $stmt_fournisseurs = $db->prepare("
        SELECT f.*, u.nom as contact_nom, u.email, u.telephone,
               COUNT(p.id) as nb_produits,
               AVG(p.prix_achat) as prix_moyen_achat
        FROM fournisseurs f
        JOIN utilisateurs u ON f.user_id = u.id
        LEFT JOIN produits p ON f.id = p.fournisseur_id
        GROUP BY f.id
        ORDER BY f.nom_societe
    ");
    $stmt_fournisseurs->execute();
    $fournisseurs_data = $stmt_fournisseurs->fetchAll(PDO::FETCH_ASSOC);
    $fournisseurs_count = count($fournisseurs_data);

    // ALERTES STOCK
    $stmt_alertes = $db->prepare("
        SELECT p.*, c.nom as categorie_nom,
               COALESCE(SUM(l.quantite_actuelle), 0) as stock_actuel,
               p.seuil_alerte
        FROM produits p
        JOIN categories c ON p.categorie_id = c.id
        LEFT JOIN lots l ON p.id = l.produit_id AND l.statut = 'en_stock'
        WHERE p.statut = 'actif'
        GROUP BY p.id
        HAVING stock_actuel <= p.seuil_alerte
        ORDER BY stock_actuel ASC
    ");
    $stmt_alertes->execute();
    $alertes_data = $stmt_alertes->fetchAll(PDO::FETCH_ASSOC);
    $alertes_stock = count($alertes_data);

} catch (PDOException $e) {
    error_log("Erreur base de données: " . $e->getMessage());
    $_SESSION['error_message'] = "Erreur de connexion à la base de données";
}

// Fonction pour formater les dates
function formatDate($date)
{
    if (empty($date))
        return 'Non définie';
    return date('d/m/Y H:i', strtotime($date));
}

// Fonction pour le badge de statut
function getStatutBadge($statut)
{
    $classes = [
        'en_attente' => 'bg-yellow-100 text-yellow-800',
        'actif' => 'bg-green-100 text-green-800',
        'inactif' => 'bg-red-100 text-red-800'
    ];
    return $classes[$statut] ?? 'bg-gray-100 text-gray-800';
}

// Fonction pour le badge de statut catégorie
function getStatutCategorieBadge($statut)
{
    return $statut === 'actif' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800';
}
?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Pharmacien - NAGEX Pharma</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            width: 16rem;
            background-color: white;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            z-index: 40;
            overflow-y: auto;
        }

        .main-content {
            margin-left: 16rem;
            min-height: 100vh;
        }

        .section {
            display: none;
        }

        .section.active {
            display: block;
        }

        .nav-link.active {
            background-color: #10B981;
            color: white;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 50;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }

        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 20px;
            border-radius: 8px;
            width: 90%;
            max-width: 600px;
            max-height: 80vh;
            overflow-y: auto;
        }
    </style>
</head>

<body class="bg-gray-100" style="font-family: 'Montserrat', sans-serif;">

<!-- Messages d'alerte -->
<?php if (isset($_SESSION['error_message'])): ?>
    <div class="fixed top-4 right-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded z-50">
        <div class="flex items-center">
            <i class="fas fa-exclamation-circle mr-2"></i>
            <?php echo $_SESSION['error_message']; ?>
        </div>
        <?php unset($_SESSION['error_message']); ?>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['success_message'])): ?>
    <div class="fixed top-4 right-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded z-50">
        <div class="flex items-center">
            <i class="fas fa-check-circle mr-2"></i>
            <?php echo $_SESSION['success_message']; ?>
        </div>
        <?php unset($_SESSION['success_message']); ?>
    </div>
<?php endif; ?>


    <!-- Sidebar -->
    <div class="sidebar">
        <!-- Logo -->
        <div class="flex items-center justify-center p-6 border-b">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 bg-green-600 rounded-full flex items-center justify-center">
                    <i class="fas fa-user-md text-white text-lg"></i>
                </div>
                <div>
                    <h1 class="text-xl font-bold text-gray-800">NAGEX Pharma</h1>
                    <p class="text-xs text-green-600">Dashboard Pharmacien</p>
                </div>
            </div>
        </div>

        <!-- Navigation -->
        <nav class="mt-6">
            <div class="px-4 space-y-2">
                <a href="#dashboard"
                    class="nav-link active flex items-center px-4 py-3 text-gray-700 rounded-lg transition-colors">
                    <i class="fas fa-tachometer-alt w-6"></i>
                    <span class="ml-3 font-medium">Tableau de bord</span>
                </a>

                <!-- Gestion des Produits -->
                <div class="mt-4">
                    <p class="px-4 text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Gestion Produits
                    </p>
                    <a href="#produits"
                        class="nav-link flex items-center justify-between px-4 py-3 text-gray-700 rounded-lg hover:bg-green-50 transition-colors">
                        <div class="flex items-center">
                            <i class="fas fa-pills w-6 text-blue-500"></i>
                            <span class="ml-3 font-medium">Tous les produits</span>
                        </div>
                    </a>

                    <a href="#produits-attente"
                        class="nav-link flex items-center justify-between px-4 py-3 text-gray-700 rounded-lg hover:bg-green-50 transition-colors">
                        <div class="flex items-center">
                            <i class="fas fa-clock w-6 text-orange-500"></i>
                            <span class="ml-3 font-medium">En attente</span>
                        </div>
                        <span
                            class="bg-orange-500 text-white text-xs px-2 py-1 rounded-full"><?php echo $produits_attente; ?></span>
                    </a>

                    <a href="#ajouter-produit"
                        class="nav-link flex items-center px-4 py-3 text-gray-700 rounded-lg hover:bg-green-50 transition-colors">
                        <i class="fas fa-plus-circle w-6 text-green-500"></i>
                        <span class="ml-3 font-medium">Ajouter produit</span>
                    </a>
                </div>

                <!-- Gestion des Catégories -->
                <div class="mt-4">
                    <p class="px-4 text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Catégories</p>
                    <a href="#categories"
                        class="nav-link flex items-center justify-between px-4 py-3 text-gray-700 rounded-lg hover:bg-green-50 transition-colors">
                        <div class="flex items-center">
                            <i class="fas fa-tags w-6 text-purple-500"></i>
                            <span class="ml-3 font-medium">Gestion catégories</span>
                        </div>
                        <span
                            class="bg-purple-500 text-white text-xs px-2 py-1 rounded-full"><?php echo $categories_count; ?></span>
                    </a>
                </div>

                <!-- Gestion des Fournisseurs -->
                <div class="mt-4">
                    <p class="px-4 text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Fournisseurs</p>
                    <a href="#fournisseurs"
                        class="nav-link flex items-center justify-between px-4 py-3 text-gray-700 rounded-lg hover:bg-green-50 transition-colors">
                        <div class="flex items-center">
                            <i class="fas fa-truck w-6 text-indigo-500"></i>
                            <span class="ml-3 font-medium">Gestion fournisseurs</span>
                        </div>
                        <span
                            class="bg-indigo-500 text-white text-xs px-2 py-1 rounded-full"><?php echo $fournisseurs_count; ?></span>
                    </a>
                </div>

                <!-- Supervision -->
                <div class="mt-4">
                    <p class="px-4 text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Supervision</p>
                    <a href="#alertes-stock"
                        class="nav-link flex items-center justify-between px-4 py-3 text-gray-700 rounded-lg hover:bg-green-50 transition-colors">
                        <div class="flex items-center">
                            <i class="fas fa-exclamation-triangle w-6 text-red-500"></i>
                            <span class="ml-3 font-medium">Alertes Stock</span>
                        </div>
                        <span
                            class="bg-red-500 text-white text-xs px-2 py-1 rounded-full"><?php echo $alertes_stock; ?></span>
                    </a>
                </div>
            </div>
        </nav>

        <!-- User Profile -->
        <div class="absolute bottom-0 left-0 right-0 p-4 border-t">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 bg-green-100 rounded-full flex items-center justify-center">
                    <i class="fas fa-user-md text-green-600"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-gray-900 truncate"><?php echo htmlspecialchars($user_name); ?>
                    </p>
                    <p class="text-xs text-green-600 truncate"><?php echo htmlspecialchars($user_role); ?></p>
                </div>
                <a href="../utilisateurs/logout.php" class="text-gray-400 hover:text-red-500 transition-colors"
                    title="Déconnexion">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <header class="bg-white shadow-sm">
            <div class="flex items-center justify-between px-8 py-4">
                <div>
                    <h2 class="text-2xl font-bold text-gray-800" id="pageTitle">Tableau de bord Pharmacien</h2>
                    <p class="text-gray-600">Gestion des produits, catégories et fournisseurs</p>
                </div>
                <div class="flex items-center space-x-4">
                    <div class="text-sm text-gray-600">
                        <i class="fas fa-user-circle mr-2"></i>
                        Connecté en tant que <span
                            class="font-semibold"><?php echo htmlspecialchars($user_role); ?></span>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Content Area -->
        <main class="p-8">
            <!-- Section Tableau de bord -->
            <div id="dashboard" class="section active">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <!-- Cartes statistiques -->
                    <div class="bg-white rounded-2xl shadow-sm p-6 border-l-4 border-orange-500">
                        <div class="flex items-center">
                            <div class="p-3 bg-orange-100 rounded-lg">
                                <i class="fas fa-clock text-orange-600 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-sm font-medium text-gray-500">Produits en attente</h3>
                                <p class="text-2xl font-bold text-gray-900"><?php echo $produits_attente; ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-2xl shadow-sm p-6 border-l-4 border-green-500">
                        <div class="flex items-center">
                            <div class="p-3 bg-green-100 rounded-lg">
                                <i class="fas fa-check-circle text-green-600 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-sm font-medium text-gray-500">Produits actifs</h3>
                                <p class="text-2xl font-bold text-gray-900"><?php echo $produits_actifs; ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-2xl shadow-sm p-6 border-l-4 border-purple-500">
                        <div class="flex items-center">
                            <div class="p-3 bg-purple-100 rounded-lg">
                                <i class="fas fa-tags text-purple-600 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-sm font-medium text-gray-500">Catégories</h3>
                                <p class="text-2xl font-bold text-gray-900"><?php echo $categories_count; ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-2xl shadow-sm p-6 border-l-4 border-indigo-500">
                        <div class="flex items-center">
                            <div class="p-3 bg-indigo-100 rounded-lg">
                                <i class="fas fa-truck text-indigo-600 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-sm font-medium text-gray-500">Fournisseurs</h3>
                                <p class="text-2xl font-bold text-gray-900"><?php echo $fournisseurs_count; ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Alertes importantes -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Alertes stock -->
                    <div class="bg-white rounded-2xl shadow-sm">
                        <div class="px-6 py-4 border-b">
                            <h3 class="text-lg font-semibold text-gray-900">Alertes Stock Critique</h3>
                        </div>
                        <div class="p-6">
                            <?php if ($alertes_stock > 0): ?>
                                <div class="space-y-3">
                                    <?php foreach (array_slice($alertes_data, 0, 5) as $alerte): ?>
                                        <div class="flex items-center justify-between p-3 bg-red-50 rounded-lg">
                                            <div>
                                                <p class="font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($alerte['nom']); ?>
                                                </p>
                                                <p class="text-sm text-gray-600">Stock: <?php echo $alerte['stock_actuel']; ?> /
                                                    Seuil: <?php echo $alerte['seuil_alerte']; ?></p>
                                            </div>
                                            <span class="bg-red-100 text-red-800 text-xs px-2 py-1 rounded-full">
                                                Critique
                                            </span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-center text-gray-500 py-4">
                                    <i class="fas fa-check-circle text-green-500 text-2xl mb-2"></i>
                                    <p>Aucune alerte stock critique</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Produits récemment ajoutés -->
                    <div class="bg-white rounded-2xl shadow-sm">
                        <div class="px-6 py-4 border-b">
                            <h3 class="text-lg font-semibold text-gray-900">Produits Récemment Ajoutés</h3>
                        </div>
                        <div class="p-6">
                            <?php if (!empty($produits_attente_data)): ?>
                                <div class="space-y-3">
                                    <?php foreach (array_slice($produits_attente_data, 0, 5) as $produit): ?>
                                        <div class="flex items-center justify-between p-3 bg-orange-50 rounded-lg">
                                            <div>
                                                <p class="font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($produit['nom']); ?>
                                                </p>
                                                <p class="text-sm text-gray-600">
                                                    <?php echo htmlspecialchars($produit['categorie_nom']); ?>
                                                </p>
                                            </div>
                                            <span class="bg-orange-100 text-orange-800 text-xs px-2 py-1 rounded-full">
                                                En attente
                                            </span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-center text-gray-500 py-4">
                                    <i class="fas fa-check-circle text-green-500 text-2xl mb-2"></i>
                                    <p>Aucun produit récent en attente</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section Tous les produits -->
            <div id="produits" class="section">
                <div class="bg-white rounded-2xl shadow-sm">
                    <div class="px-6 py-4 border-b">
                        <div class="flex items-center justify-between">
                            <h3 class="text-xl font-semibold text-gray-900">Gestion des Produits</h3>
                            <span class="text-sm text-gray-600"><?php echo count($produits_data); ?> produit(s)</span>
                        </div>
                    </div>
                    <div class="p-6">
                        <?php if (!empty($produits_data)): ?>
                            <div class="overflow-x-auto">
                                <table class="w-full">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Produit</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Catégorie</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Fournisseur</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Code
                                                Barre</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Ordonnance</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Statut</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php foreach ($produits_data as $produit): ?>
                                            <tr class="hover:bg-gray-50">
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div>
                                                        <div class="text-sm font-medium text-gray-900">
                                                            <?php echo htmlspecialchars($produit['nom']); ?>
                                                        </div>
                                                        <div class="text-sm text-gray-500">
                                                            <?php echo htmlspecialchars($produit['description']); ?>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo htmlspecialchars($produit['categorie_nom']); ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo htmlspecialchars($produit['fournisseur_nom']); ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo htmlspecialchars($produit['code_barre']); ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo $produit['necessite_ordonnance'] ? '<i class="fas fa-check text-green-600"></i>' : '<i class="fas fa-times text-red-600"></i>'; ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <span
                                                        class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo getStatutBadge($produit['statut']); ?>">
                                                        <?php echo $produit['statut']; ?>
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                    <div class="flex space-x-2">
                                                        <button
                                                            onclick="modifierProduit(<?php echo htmlspecialchars(json_encode($produit)); ?>)"
                                                            class="text-blue-600 hover:text-blue-900">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <?php if ($produit['statut'] === 'en_attente'): ?>
                                                            <form method="POST" class="inline">
                                                                <input type="hidden" name="produit_id"
                                                                    value="<?php echo $produit['id']; ?>">
                                                                <button type="submit" name="valider_produit"
                                                                    class="text-green-600 hover:text-green-900">
                                                                    <i class="fas fa-check"></i>
                                                                </button>
                                                            </form>
                                                        <?php endif; ?>
                                                        <?php if ($produit['statut'] === 'actif'): ?>
                                                            <form method="POST" class="inline">
                                                                <input type="hidden" name="produit_id"
                                                                    value="<?php echo $produit['id']; ?>">
                                                                <button type="submit" name="archiver_produit"
                                                                    class="text-red-600 hover:text-red-900">
                                                                    <i class="fas fa-archive"></i>
                                                                </button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-8 text-gray-500">
                                <i class="fas fa-pills text-4xl text-gray-300 mb-2"></i>
                                <p>Aucun produit trouvé</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- À AJOUTER APRÈS LA SECTION "produits" -->
            <!-- Section Produits en Attente -->
            <div id="produits-attente" class="section">
                <div class="bg-white rounded-2xl shadow-sm">
                    <div class="px-6 py-4 border-b">
                        <div class="flex items-center justify-between">
                            <h3 class="text-xl font-semibold text-gray-900">Produits en Attente de Validation</h3>
                            <span class="text-sm text-gray-600"><?php echo $produits_attente; ?> produit(s) à
                                valider</span>
                        </div>
                    </div>
                    <div class="p-6">
                        <?php if (!empty($produits_attente_data)): ?>
                            <div class="overflow-x-auto">
                                <table class="w-full">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Produit</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Catégorie</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Fournisseur</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Code
                                                Barre</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date
                                                création</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php foreach ($produits_attente_data as $produit): ?>
                                            <tr class="hover:bg-gray-50">
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div>
                                                        <div class="text-sm font-medium text-gray-900">
                                                            <?php echo htmlspecialchars($produit['nom']); ?>
                                                        </div>
                                                        <div class="text-sm text-gray-500">
                                                            <?php echo htmlspecialchars($produit['description']); ?>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo htmlspecialchars($produit['categorie_nom']); ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo htmlspecialchars($produit['fournisseur_nom']); ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo htmlspecialchars($produit['code_barre']); ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo formatDate($produit['created_at']); ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                    <div class="flex space-x-2">
                                                        <button
                                                            onclick="modifierProduit(<?php echo htmlspecialchars(json_encode($produit)); ?>)"
                                                            class="text-blue-600 hover:text-blue-900" title="Modifier">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <form method="POST" class="inline">
                                                            <input type="hidden" name="produit_id"
                                                                value="<?php echo $produit['id']; ?>">
                                                            <button type="submit" name="valider_produit"
                                                                class="text-green-600 hover:text-green-900"
                                                                onclick="return confirmerAction('Valider ce produit ?')"
                                                                title="Valider">
                                                                <i class="fas fa-check"></i>
                                                            </button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-8 text-gray-500">
                                <i class="fas fa-clock text-4xl text-gray-300 mb-2"></i>
                                <p>Aucun produit en attente de validation</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- À AJOUTER APRÈS LA SECTION "produits-attente" -->
            <!-- Section Gestion des Catégories -->
            <div id="categories" class="section">
                <div class="bg-white rounded-2xl shadow-sm">
                    <div class="px-6 py-4 border-b">
                        <div class="flex items-center justify-between">
                            <h3 class="text-xl font-semibold text-gray-900">Gestion des Catégories</h3>
                            <div class="flex space-x-2">
                                <span class="text-sm text-gray-600"><?php echo $categories_count; ?> catégorie(s)</span>
                                <button onclick="ouvrirModalAjoutCategorie()"
                                    class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors text-sm">
                                    <i class="fas fa-plus mr-1"></i> Nouvelle catégorie
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="p-6">
                        <?php if (!empty($categories_data)): ?>
                            <div class="overflow-x-auto">
                                <table class="w-full">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Nom
                                            </th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Description</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Nb
                                                Produits</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Créée par</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Statut</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php foreach ($categories_data as $categorie): ?>
                                            <tr class="hover:bg-gray-50">
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($categorie['nom']); ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo htmlspecialchars($categorie['description']); ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo $categorie['nb_produits']; ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo htmlspecialchars($categorie['createur_nom']); ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <span
                                                        class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo getStatutCategorieBadge($categorie['statut']); ?>">
                                                        <?php echo $categorie['statut']; ?>
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                    <div class="flex space-x-2">
                                                        <button
                                                            onclick="modifierCategorie(<?php echo htmlspecialchars(json_encode($categorie)); ?>)"
                                                            class="text-blue-600 hover:text-blue-900" title="Modifier">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-8 text-gray-500">
                                <i class="fas fa-tags text-4xl text-gray-300 mb-2"></i>
                                <p>Aucune catégorie trouvée</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- À AJOUTER APRÈS LA SECTION "categories" -->
            <!-- Section Gestion des Fournisseurs -->
            <div id="fournisseurs" class="section">
                <div class="bg-white rounded-2xl shadow-sm">
                    <div class="px-6 py-4 border-b">
                        <div class="flex items-center justify-between">
                            <h3 class="text-xl font-semibold text-gray-900">Gestion des Fournisseurs</h3>
                            <div class="flex space-x-2">
                                <span class="text-sm text-gray-600"><?php echo $fournisseurs_count; ?>
                                    fournisseur(s)</span>
                                <button onclick="ouvrirModalAjoutFournisseur()"
                                    class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors text-sm">
                                    <i class="fas fa-plus mr-1"></i> Nouveau fournisseur
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="p-6">
                        <?php if (!empty($fournisseurs_data)): ?>
                            <div class="overflow-x-auto">
                                <table class="w-full">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Société</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Contact</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Téléphone</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Nb
                                                Produits</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Note
                                                Qualité</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php foreach ($fournisseurs_data as $fournisseur): ?>
                                            <tr class="hover:bg-gray-50">
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="text-sm font-medium text-gray-900">
                                                        <?php echo htmlspecialchars($fournisseur['nom_societe']); ?>
                                                    </div>
                                                    <div class="text-sm text-gray-500">
                                                        <?php echo htmlspecialchars($fournisseur['adresse_siege']); ?>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo htmlspecialchars($fournisseur['contact_nom']); ?><br>
                                                    <span
                                                        class="text-xs"><?php echo htmlspecialchars($fournisseur['email']); ?></span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo htmlspecialchars($fournisseur['telephone']); ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo $fournisseur['nb_produits']; ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <div class="flex items-center">
                                                        <span class="text-yellow-500 mr-1">
                                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                                <i
                                                                    class="fas fa-star<?php echo $i <= $fournisseur['note_qualite'] ? '' : '-o'; ?> text-sm"></i>
                                                            <?php endfor; ?>
                                                        </span>
                                                        <span
                                                            class="text-xs">(<?php echo number_format($fournisseur['note_qualite'], 1); ?>)</span>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                    <div class="flex space-x-2">
                                                        <button
                                                            onclick="modifierFournisseur(<?php echo htmlspecialchars(json_encode($fournisseur)); ?>)"
                                                            class="text-blue-600 hover:text-blue-900" title="Modifier">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-8 text-gray-500">
                                <i class="fas fa-truck text-4xl text-gray-300 mb-2"></i>
                                <p>Aucun fournisseur trouvé</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <!-- À AJOUTER APRÈS LA SECTION "fournisseurs" -->
            <!-- Section Alertes Stock -->
            <div id="alertes-stock" class="section">
                <div class="bg-white rounded-2xl shadow-sm">
                    <div class="px-6 py-4 border-b">
                        <div class="flex items-center justify-between">
                            <h3 class="text-xl font-semibold text-gray-900">Alertes Stock Critique</h3>
                            <span class="text-sm text-gray-600"><?php echo $alertes_stock; ?> alerte(s)</span>
                        </div>
                    </div>
                    <div class="p-6">
                        <?php if (!empty($alertes_data)): ?>
                            <div class="overflow-x-auto">
                                <table class="w-full">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Produit</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Catégorie</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Stock Actuel</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Seuil Alerte</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Déficit</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Statut</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php foreach ($alertes_data as $alerte): ?>
                                            <?php
                                            $deficit = $alerte['seuil_alerte'] - $alerte['stock_actuel'];
                                            $niveau_alerte = $deficit > 10 ? 'Critique' : ($deficit > 5 ? 'Élevé' : 'Modéré');
                                            $couleur_alerte = $deficit > 10 ? 'red' : ($deficit > 5 ? 'orange' : 'yellow');
                                            ?>
                                            <tr class="hover:bg-gray-50">
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="text-sm font-medium text-gray-900">
                                                        <?php echo htmlspecialchars($alerte['nom']); ?>
                                                    </div>
                                                    <div class="text-sm text-gray-500">
                                                        <?php echo htmlspecialchars($alerte['description']); ?>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo htmlspecialchars($alerte['categorie_nom']); ?>
                                                </td>
                                                <td
                                                    class="px-6 py-4 whitespace-nowrap text-sm font-medium text-<?php echo $couleur_alerte; ?>-600">
                                                    <?php echo $alerte['stock_actuel']; ?> unités
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo $alerte['seuil_alerte']; ?> unités
                                                </td>
                                                <td
                                                    class="px-6 py-4 whitespace-nowrap text-sm font-medium text-<?php echo $couleur_alerte; ?>-600">
                                                    -<?php echo $deficit; ?> unités
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <span
                                                        class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-<?php echo $couleur_alerte; ?>-100 text-<?php echo $couleur_alerte; ?>-800">
                                                        <?php echo $niveau_alerte; ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-8 text-gray-500">
                                <i class="fas fa-check-circle text-green-500 text-4xl mb-2"></i>
                                <p class="text-lg font-medium text-green-600">Aucune alerte stock</p>
                                <p class="text-sm">Tous les produits ont un stock suffisant</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>



        </main>
    </div>

    <div id="modalProduit" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold" id="modalProduitTitre">Ajouter un produit</h3>
                <button onclick="closeModal('modalProduit')" class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" id="formProduit">
                <input type="hidden" name="produit_id" id="produit_id">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Nom du produit</label>
                        <input type="text" name="nom" id="nom" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-md">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                        <textarea name="description" id="description" rows="3"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md"></textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Code barre</label>
                        <input type="text" name="code_barre" id="code_barre"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Catégorie</label>
                        <select name="categorie_id" id="categorie_id" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-md">
                            <option value="">Sélectionnez une catégorie</option>
                            <?php foreach ($categories_data as $categorie): ?>
                                <option value="<?php echo $categorie['id']; ?>">
                                    <?php echo htmlspecialchars($categorie['nom']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Fournisseur</label>
                        <select name="fournisseur_id" id="fournisseur_id" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-md">
                            <option value="">Sélectionnez un fournisseur</option>
                            <?php foreach ($fournisseurs_data as $fournisseur): ?>
                                <option value="<?php echo $fournisseur['id']; ?>">
                                    <?php echo htmlspecialchars($fournisseur['nom_societe']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="md:col-span-2">
                        <label class="flex items-center">
                            <input type="checkbox" name="necessite_ordonnance" id="necessite_ordonnance"
                                class="rounded border-gray-300 text-green-600 focus:ring-green-500">
                            <span class="ml-2 text-sm text-gray-700">Nécessite une ordonnance</span>
                        </label>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Composition</label>
                        <textarea name="composition" id="composition" rows="2"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md"></textarea>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Posologie</label>
                        <textarea name="posologie" id="posologie" rows="2"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md"></textarea>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Contre-indications</label>
                        <textarea name="contre_indications" id="contre_indications" rows="2"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md"></textarea>
                    </div>
                </div>
                <div class="flex justify-end space-x-3 mt-6">
                    <button type="button" onclick="closeModal('modalProduit')"
                        class="px-4 py-2 text-gray-600 hover:text-gray-800">
                        Annuler
                    </button>
                    <button type="submit" name="ajouter_produit" id="submitProduit"
                        class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">
                        Ajouter le produit
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- À AJOUTER AVANT LA FERMETURE DU BODY, APRÈS LE MODAL PRODUIT -->
    <!-- Modal Ajout/Modification Catégorie -->
    <div id="modalCategorie" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold" id="modalCategorieTitre">Ajouter une catégorie</h3>
                <button onclick="closeModal('modalCategorie')" class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" id="formCategorie">
                <input type="hidden" name="categorie_id" id="categorie_id">
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Nom de la catégorie</label>
                        <input type="text" name="nom" id="categorie_nom" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                        <textarea name="description" id="categorie_description" rows="3"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500"></textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Statut</label>
                        <select name="statut" id="categorie_statut"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500">
                            <option value="actif">Actif</option>
                            <option value="inactif">Inactif</option>
                        </select>
                    </div>
                </div>
                <div class="flex justify-end space-x-3 mt-6">
                    <button type="button" onclick="closeModal('modalCategorie')"
                        class="px-4 py-2 text-gray-600 hover:text-gray-800">
                        Annuler
                    </button>
                    <button type="submit" name="ajouter_categorie" id="submitCategorie"
                        class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">
                        Ajouter la catégorie
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Ajout/Modification Fournisseur -->
    <div id="modalFournisseur" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold" id="modalFournisseurTitre">Ajouter un fournisseur</h3>
                <button onclick="closeModal('modalFournisseur')" class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" id="formFournisseur">
                <input type="hidden" name="fournisseur_id" id="fournisseur_id">
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Nom de la société</label>
                        <input type="text" name="nom_societe" id="nom_societe" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Contact principal</label>
                        <input type="text" name="contact_principal" id="contact_principal" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Adresse du siège</label>
                        <textarea name="adresse_siege" id="adresse_siege" rows="3"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500"></textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Note de qualité (0-5)</label>
                        <input type="number" name="note_qualite" id="note_qualite" min="0" max="5" step="0.1"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500">
                    </div>
                </div>
                <div class="flex justify-end space-x-3 mt-6">
                    <button type="button" onclick="closeModal('modalFournisseur')"
                        class="px-4 py-2 text-gray-600 hover:text-gray-800">
                        Annuler
                    </button>
                    <button type="submit" name="ajouter_fournisseur" id="submitFournisseur"
                        class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">
                        Ajouter le fournisseur
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Navigation entre sections
        document.addEventListener('DOMContentLoaded', function () {
            // Activer la section dashboard par défaut
            showSection('dashboard');

            // Gérer les clics sur les liens de navigation
            document.querySelectorAll('.nav-link').forEach(link => {
                link.addEventListener('click', function (e) {
                    e.preventDefault();
                    const targetId = this.getAttribute('href').substring(1);
                    showSection(targetId);
                });
            });
        });

        function showSection(sectionName) {
            // Cacher toutes les sections
            document.querySelectorAll('.section').forEach(section => {
                section.classList.remove('active');
            });

            // Afficher la section demandée
            const targetSection = document.getElementById(sectionName);
            if (targetSection) {
                targetSection.classList.add('active');
            }

            // Mettre à jour le titre de la page
            const titles = {
                'dashboard': 'Tableau de bord Pharmacien',
                'produits': 'Gestion des Produits',
                'produits-attente': 'Produits en Attente de Validation',
                'ajouter-produit': 'Ajouter un Produit',
                'categories': 'Gestion des Catégories',
                'fournisseurs': 'Gestion des Fournisseurs',
                'alertes-stock': 'Alertes Stock'
            };
            document.getElementById('pageTitle').textContent = titles[sectionName] || 'Tableau de bord Pharmacien';

            // Mettre à jour la navigation active
            document.querySelectorAll('.nav-link').forEach(link => {
                link.classList.remove('active');
                if (link.getAttribute('href') === '#' + sectionName) {
                    link.classList.add('active');
                }
            });

            // Gérer les sections spéciales
            if (sectionName === 'ajouter-produit') {
                ouvrirModalAjoutProduit();
            }
        }

        // Fonctions pour la gestion des produits
        function ouvrirModalAjoutProduit() {
            document.getElementById('modalProduitTitre').textContent = 'Ajouter un produit';
            document.getElementById('submitProduit').name = 'ajouter_produit';
            document.getElementById('submitProduit').textContent = 'Ajouter le produit';
            document.getElementById('formProduit').reset();
            document.getElementById('produit_id').value = '';
            openModal('modalProduit');
        }

        function modifierProduit(produit) {
            document.getElementById('modalProduitTitre').textContent = 'Modifier le produit';
            document.getElementById('submitProduit').name = 'modifier_produit';
            document.getElementById('submitProduit').textContent = 'Modifier le produit';

            // Remplir le formulaire avec les données du produit
            document.getElementById('produit_id').value = produit.id;
            document.getElementById('nom').value = produit.nom || '';
            document.getElementById('description').value = produit.description || '';
            document.getElementById('code_barre').value = produit.code_barre || '';
            document.getElementById('categorie_id').value = produit.categorie_id || '';
            document.getElementById('fournisseur_id').value = produit.fournisseur_id || '';
            document.getElementById('necessite_ordonnance').checked = produit.necessite_ordonnance == 1;
            document.getElementById('composition').value = produit.composition || '';
            document.getElementById('posologie').value = produit.posologie || '';
            document.getElementById('contre_indications').value = produit.contre_indications || '';

            openModal('modalProduit');
        }

        // Fonctions pour la gestion des catégories
        function ouvrirModalAjoutCategorie() {
            document.getElementById('modalCategorieTitre').textContent = 'Ajouter une catégorie';
            document.getElementById('submitCategorie').name = 'ajouter_categorie';
            document.getElementById('submitCategorie').textContent = 'Ajouter la catégorie';
            document.getElementById('formCategorie').reset();
            document.getElementById('categorie_id').value = '';
            openModal('modalCategorie');
        }

        function modifierCategorie(categorie) {
            document.getElementById('modalCategorieTitre').textContent = 'Modifier la catégorie';
            document.getElementById('submitCategorie').name = 'modifier_categorie';
            document.getElementById('submitCategorie').textContent = 'Modifier la catégorie';

            document.getElementById('categorie_id').value = categorie.id;
            document.getElementById('categorie_nom').value = categorie.nom || '';
            document.getElementById('categorie_description').value = categorie.description || '';
            document.getElementById('categorie_statut').value = categorie.statut || 'actif';

            openModal('modalCategorie');
        }

        // Fonctions pour la gestion des fournisseurs
        function ouvrirModalAjoutFournisseur() {
            document.getElementById('modalFournisseurTitre').textContent = 'Ajouter un fournisseur';
            document.getElementById('submitFournisseur').name = 'ajouter_fournisseur';
            document.getElementById('submitFournisseur').textContent = 'Ajouter le fournisseur';
            document.getElementById('formFournisseur').reset();
            document.getElementById('fournisseur_id').value = '';
            openModal('modalFournisseur');
        }

        function modifierFournisseur(fournisseur) {
            document.getElementById('modalFournisseurTitre').textContent = 'Modifier le fournisseur';
            document.getElementById('submitFournisseur').name = 'modifier_fournisseur';
            document.getElementById('submitFournisseur').textContent = 'Modifier le fournisseur';

            document.getElementById('fournisseur_id').value = fournisseur.id;
            document.getElementById('nom_societe').value = fournisseur.nom_societe || '';
            document.getElementById('contact_principal').value = fournisseur.contact_principal || '';
            document.getElementById('adresse_siege').value = fournisseur.adresse_siege || '';
            document.getElementById('note_qualite').value = fournisseur.note_qualite || '0';

            openModal('modalFournisseur');
        }

        // Gestion des modals
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // Fermer les modals en cliquant à l'extérieur
        window.onclick = function (event) {
            document.querySelectorAll('.modal').forEach(modal => {
                if (event.target === modal) {
                    modal.style.display = 'none';
                }
            });
        }

        // Auto-hide des messages de succès/erreur
        setTimeout(() => {
            const messages = document.querySelectorAll('.fixed');
            messages.forEach(msg => {
                if (msg.style.display !== 'none') {
                    msg.style.display = 'none';
                }
            });
        }, 5000);

        // Confirmation pour les actions critiques
        function confirmerAction(message) {
            return confirm(message);
        }
    </script>

</body>

</html>