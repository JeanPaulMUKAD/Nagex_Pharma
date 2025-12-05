<?php
declare(strict_types=1);
session_start();

// Vérifier si l'utilisateur est connecté et est un client
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['user_role'] !== 'client') {
    header('Location: ../utilisateurs/login.php');
    exit;
}

require_once __DIR__ . '/../../config/database.php';

// Récupérer les informations de l'utilisateur connecté
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_nom'] ?? 'Client';
$user_email = $_SESSION['user_email'] ?? '';
$user_telephone = $_SESSION['user_telephone'] ?? '';
$user_adresse = $_SESSION['user_adresse'] ?? '';

// Initialisation des variables
$errors = [];
$success = '';
$produits = [];
$categories = [];
$fournisseurs = [];
$panier_actif = null;
$panier_items = [];
$commandes = [];
$favoris = [];

// Connexion à la base de données
try {
    $db = new Database();
} catch (Exception $e) {
    $errors[] = "Erreur de connexion à la base de données: " . $e->getMessage();
}

// RÉCUPÉRATION DES DONNÉES DE BASE
if (isset($db)) {
    try {
        // Récupérer les catégories
        $query = "SELECT id, nom FROM categories WHERE statut = 'actif' ORDER BY nom";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Récupérer les fournisseurs
        $query = "SELECT id, nom_societe FROM fournisseurs ORDER BY nom_societe";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $fournisseurs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Récupérer le panier actif du client
        $query = "SELECT id, statut FROM paniers WHERE client_id = :client_id AND statut = 'actif'";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':client_id', $user_id);
        $stmt->execute();
        $panier_actif = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($panier_actif) {
            // Récupérer les items du panier
            $query = "SELECT pi.id, pi.produit_id, pi.quantite, pi.prix_unitaire, 
                             p.nom, p.code_barre as reference, p.description,
                             COALESCE(SUM(l.quantite_actuelle), 0) as stock
                      FROM panier_items pi 
                      JOIN produits p ON pi.produit_id = p.id 
                      LEFT JOIN lots l ON p.id = l.produit_id AND l.statut = 'en_stock'
                      WHERE pi.panier_id = :panier_id
                      GROUP BY pi.id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':panier_id', $panier_actif['id']);
            $stmt->execute();
            $panier_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Calculer le total du panier
            $total_panier = 0;
            $nb_items = 0;
            foreach ($panier_items as $item) {
                $total_panier += $item['quantite'] * $item['prix_unitaire'];
                $nb_items += $item['quantite'];
            }
            $panier_actif['total_panier'] = $total_panier;
            $panier_actif['nb_items'] = $nb_items;
        }

        // Récupérer les commandes du client
        $query = "SELECT c.id, c.numero_commande, c.date_commande, c.montant_total, c.statut,
                         COUNT(cd.id) as nb_produits
                  FROM commandes c 
                  LEFT JOIN commande_details cd ON c.id = cd.commande_id
                  WHERE c.client_id = :client_id 
                  GROUP BY c.id 
                  ORDER BY c.date_commande DESC";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':client_id', $user_id);
        $stmt->execute();
        $commandes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Récupérer les favoris du client
        $query = "SELECT f.produit_id, p.nom, p.prix_fc 
                  FROM favoris f 
                  JOIN produits p ON f.produit_id = p.id 
                  WHERE f.client_id = :client_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':client_id', $user_id);
        $stmt->execute();
        $favoris = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        $errors[] = "Erreur lors de la récupération des données: " . $e->getMessage();
    }
}

// RECHERCHE DE PRODUITS (doit être fait avant le traitement POST pour éviter les redirections)
if (isset($db)) {
    try {
        $where_conditions = ["p.statut = 'actif'"];
        $params = [];

        // Filtre par terme de recherche
        if (!empty($_GET['search_term'])) {
            $where_conditions[] = "(p.nom LIKE :search_term OR p.description LIKE :search_term OR p.code_barre LIKE :search_term)";
            $params[':search_term'] = '%' . $_GET['search_term'] . '%';
        }

        // Filtre par catégorie
        if (!empty($_GET['categorie_id'])) {
            $where_conditions[] = "p.categorie_id = :categorie_id";
            $params[':categorie_id'] = $_GET['categorie_id'];
        }

        // Filtre par fournisseur
        if (!empty($_GET['fournisseur_id'])) {
            $where_conditions[] = "p.fournisseur_id = :fournisseur_id";
            $params[':fournisseur_id'] = $_GET['fournisseur_id'];
        }

        // Filtre par prix
        if (!empty($_GET['prix_min'])) {
            $where_conditions[] = "p.prix_fc >= :prix_min";
            $params[':prix_min'] = $_GET['prix_min'];
        }

        if (!empty($_GET['prix_max'])) {
            $where_conditions[] = "p.prix_fc <= :prix_max";
            $params[':prix_max'] = $_GET['prix_max'];
        }

        $where_clause = implode(' AND ', $where_conditions);

        $query = "SELECT p.id, p.nom, p.description, p.code_barre, p.prix_fc,
                         COALESCE(SUM(l.quantite_actuelle), 0) as stock,
                         c.nom as categorie_nom,
                         f.nom_societe as fournisseur_nom
                  FROM produits p
                  LEFT JOIN lots l ON p.id = l.produit_id AND l.statut = 'en_stock'
                  LEFT JOIN categories c ON p.categorie_id = c.id
                  LEFT JOIN fournisseurs f ON p.fournisseur_id = f.id
                  WHERE $where_clause
                  GROUP BY p.id, p.nom, p.description, p.code_barre, p.prix_fc, c.nom, f.nom_societe
                  HAVING stock > 0
                  ORDER BY p.nom";

        $stmt = $db->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $produits = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        $errors[] = "Erreur lors de la recherche des produits: " . $e->getMessage();
    }
}

// TRAITEMENT DES FORMULAIRES
$should_redirect = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($db)) {
    
    // AJOUT AU PANIER
    if (isset($_POST['ajouter_panier'])) {
        $produit_id = $_POST['produit_id'] ?? '';
        $quantite = $_POST['quantite'] ?? 1;
        $prix_unitaire = $_POST['prix_unitaire'] ?? 0;

        // Validation
        if (empty($produit_id)) {
            $errors[] = "Produit non spécifié.";
        }
        if ($quantite < 1) {
            $errors[] = "La quantité doit être au moins de 1.";
        }

        // Vérifier le stock disponible
        if (empty($errors)) {
            try {
                $query = "SELECT COALESCE(SUM(quantite_actuelle), 0) as stock_total 
                          FROM lots 
                          WHERE produit_id = :produit_id AND statut = 'en_stock'";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':produit_id', $produit_id);
                $stmt->execute();
                $stock = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($stock['stock_total'] < $quantite) {
                    $errors[] = "Stock insuffisant pour ce produit.";
                }
            } catch (Exception $e) {
                $errors[] = "Erreur lors de la vérification du stock.";
            }
        }

        if (empty($errors)) {
            try {
                // Vérifier si le client a déjà un panier actif
                if (!$panier_actif) {
                    // Créer un nouveau panier
                    $query = "INSERT INTO paniers (client_id, statut) VALUES (:client_id, 'actif')";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':client_id', $user_id);
                    $stmt->execute();
                    $panier_id = $db->lastInsertId();
                } else {
                    $panier_id = $panier_actif['id'];
                }

                // Vérifier si le produit est déjà dans le panier
                $query = "SELECT id, quantite FROM panier_items 
                          WHERE panier_id = :panier_id AND produit_id = :produit_id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':panier_id', $panier_id);
                $stmt->bindParam(':produit_id', $produit_id);
                $stmt->execute();
                $existing_item = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($existing_item) {
                    // Mettre à jour la quantité
                    $new_quantite = $existing_item['quantite'] + $quantite;
                    $query = "UPDATE panier_items SET quantite = :quantite WHERE id = :id";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':quantite', $new_quantite);
                    $stmt->bindParam(':id', $existing_item['id']);
                    $stmt->execute();
                } else {
                    // Ajouter un nouvel item
                    $query = "INSERT INTO panier_items (panier_id, produit_id, quantite, prix_unitaire) 
                              VALUES (:panier_id, :produit_id, :quantite, :prix_unitaire)";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':panier_id', $panier_id);
                    $stmt->bindParam(':produit_id', $produit_id);
                    $stmt->bindParam(':quantite', $quantite);
                    $stmt->bindParam(':prix_unitaire', $prix_unitaire);
                    $stmt->execute();
                }

                $success = "Produit ajouté au panier avec succès!";
                $should_redirect = true;
                
            } catch (Exception $e) {
                $errors[] = "Erreur lors de l'ajout au panier: " . $e->getMessage();
            }
        }
    }

    // GESTION DES FAVORIS
    if (isset($_POST['ajouter_favori'])) {
        $produit_id = $_POST['produit_id'];
        
        try {
            $query = "INSERT IGNORE INTO favoris (client_id, produit_id) VALUES (:client_id, :produit_id)";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':client_id', $user_id);
            $stmt->bindParam(':produit_id', $produit_id);
            $stmt->execute();
            
            $success = "Produit ajouté aux favoris!";
            $should_redirect = true;
            
        } catch (Exception $e) {
            $errors[] = "Erreur lors de l'ajout aux favoris.";
        }
    }

    if (isset($_POST['supprimer_favori'])) {
        $produit_id = $_POST['produit_id'];
        
        try {
            $query = "DELETE FROM favoris WHERE client_id = :client_id AND produit_id = :produit_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':client_id', $user_id);
            $stmt->bindParam(':produit_id', $produit_id);
            $stmt->execute();
            
            $success = "Produit retiré des favoris!";
            $should_redirect = true;
            
        } catch (Exception $e) {
            $errors[] = "Erreur lors de la suppression des favoris.";
        }
    }

    // SUPPRIMER DU PANIER
    if (isset($_POST['supprimer_panier'])) {
        $item_id = $_POST['item_id'] ?? '';

        if (empty($item_id)) {
            $errors[] = "Item non spécifié.";
        }

        if (empty($errors)) {
            try {
                $query = "DELETE FROM panier_items WHERE id = :id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':id', $item_id);
                $stmt->execute();

                $success = "Produit retiré du panier.";
                $should_redirect = true;

            } catch (Exception $e) {
                $errors[] = "Erreur lors de la suppression du panier: " . $e->getMessage();
            }
        }
    }

    // METTRE À JOUR LA QUANTITÉ
    if (isset($_POST['update_quantity'])) {
        $item_id = $_POST['item_id'] ?? '';
        $quantite = $_POST['quantite'] ?? 1;

        if (empty($item_id)) {
            $errors[] = "Item non spécifié.";
        }
        if ($quantite < 1) {
            $errors[] = "La quantité doit être au moins de 1.";
        }

        if (empty($errors)) {
            try {
                // Vérifier le stock
                $query = "SELECT pi.produit_id, COALESCE(SUM(l.quantite_actuelle), 0) as stock
                          FROM panier_items pi
                          LEFT JOIN lots l ON pi.produit_id = l.produit_id AND l.statut = 'en_stock'
                          WHERE pi.id = :item_id
                          GROUP BY pi.id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':item_id', $item_id);
                $stmt->execute();
                $stock_info = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($stock_info['stock'] < $quantite) {
                    $errors[] = "Stock insuffisant pour cette quantité.";
                } else {
                    $query = "UPDATE panier_items SET quantite = :quantite WHERE id = :id";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':quantite', $quantite);
                    $stmt->bindParam(':id', $item_id);
                    $stmt->execute();

                    $success = "Quantité mise à jour.";
                    $should_redirect = true;
                }

            } catch (Exception $e) {
                $errors[] = "Erreur lors de la mise à jour de la quantité: " . $e->getMessage();
            }
        }
    }

    // MISE À JOUR DU PROFIL
    if (isset($_POST['update_profil'])) {
        $telephone = trim($_POST['telephone'] ?? '');
        $adresse = trim($_POST['adresse'] ?? '');

        try {
            $query = "UPDATE utilisateurs SET telephone = :telephone, adresse = :adresse WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':telephone', $telephone);
            $stmt->bindParam(':adresse', $adresse);
            $stmt->bindParam(':id', $user_id);
            $stmt->execute();

            $success = "Profil mis à jour avec succès!";
            $should_redirect = true;
            
            // Mettre à jour la session
            $_SESSION['user_telephone'] = $telephone;
            $_SESSION['user_adresse'] = $adresse;
            $user_telephone = $telephone;
            $user_adresse = $adresse;
            
        } catch (Exception $e) {
            $errors[] = "Erreur lors de la mise à jour du profil.";
        }
    }


// VALIDER LA COMMANDE
if (isset($_POST['valider_commande'])) {
    // Validation des informations personnelles
    $telephone = trim($_POST['telephone'] ?? '');
    $adresse = trim($_POST['adresse'] ?? '');
    
    // Vérifier que les informations obligatoires sont remplies
    if (empty($telephone)) {
        $errors[] = "Le numéro de téléphone est obligatoire pour valider la commande.";
    }
    
    if (empty($adresse)) {
        $errors[] = "L'adresse de livraison est obligatoire pour valider la commande.";
    }
    
    if (empty($telephone) || empty($adresse)) {
        // Stocker les données du formulaire pour les réafficher
        $_SESSION['commande_data'] = [
            'telephone' => $telephone,
            'adresse' => $adresse
        ];
    }

    if (!$panier_actif || empty($panier_items)) {
        $errors[] = "Votre panier est vide.";
    }

    if (empty($errors)) {
        try {
            // Mettre à jour les informations du client
            $query = "UPDATE utilisateurs SET telephone = :telephone, adresse = :adresse WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':telephone', $telephone);
            $stmt->bindParam(':adresse', $adresse);
            $stmt->bindParam(':id', $user_id);
            $stmt->execute();
            
            // Mettre à jour la session
            $_SESSION['user_telephone'] = $telephone;
            $_SESSION['user_adresse'] = $adresse;
            $user_telephone = $telephone;
            $user_adresse = $adresse;

            // Commencer une transaction
            $db->beginTransaction();

            // Générer un numéro de commande unique
            $numero_commande = 'CMD-' . date('Ymd-His') . '-' . $user_id;

            // Calculer le montant total
            $montant_total = $panier_actif['total_panier'];

            // Créer la commande
            $query = "INSERT INTO commandes (numero_commande, client_id, montant_total, statut, adresse_livraison, telephone) 
                      VALUES (:numero_commande, :client_id, :montant_total, 'en_attente', :adresse, :telephone)";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':numero_commande', $numero_commande);
            $stmt->bindParam(':client_id', $user_id);
            $stmt->bindParam(':montant_total', $montant_total);
            $stmt->bindParam(':adresse', $adresse);
            $stmt->bindParam(':telephone', $telephone);
            $stmt->execute();
            $commande_id = $db->lastInsertId();

            // Créer les détails de commande
            foreach ($panier_items as $item) {
                // Vérifier le stock disponible
                $query = "SELECT COALESCE(SUM(quantite_actuelle), 0) as stock_total 
                          FROM lots 
                          WHERE produit_id = :produit_id AND statut = 'en_stock'";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':produit_id', $item['produit_id']);
                $stmt->execute();
                $stock = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($stock['stock_total'] < $item['quantite']) {
                    throw new Exception("Stock insuffisant pour le produit: " . $item['nom']);
                }

                // Créer le détail de commande
                $sous_total = $item['quantite'] * $item['prix_unitaire'];
                $query = "INSERT INTO commande_details (commande_id, produit_id, quantite, prix_unitaire, sous_total) 
                          VALUES (:commande_id, :produit_id, :quantite, :prix_unitaire, :sous_total)";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':commande_id', $commande_id);
                $stmt->bindParam(':produit_id', $item['produit_id']);
                $stmt->bindParam(':quantite', $item['quantite']);
                $stmt->bindParam(':prix_unitaire', $item['prix_unitaire']);
                $stmt->bindParam(':sous_total', $sous_total);
                $stmt->execute();
                
                // Mettre à jour le stock (optionnel - selon votre logique métier)
                // $query = "UPDATE lots SET quantite_actuelle = quantite_actuelle - :quantite WHERE produit_id = :produit_id AND statut = 'en_stock'";
                // ...
            }

            // Vider le panier
            $query = "DELETE FROM panier_items WHERE panier_id = :panier_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':panier_id', $panier_actif['id']);
            $stmt->execute();

            $query = "UPDATE paniers SET statut = 'valide' WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id', $panier_actif['id']);
            $stmt->execute();

            // Valider la transaction
            $db->commit();

            $success = "Commande validée avec succès! Numéro: " . $numero_commande . ". Vous recevrez un email de confirmation.";
            $should_redirect = true;
            
            // Nettoyer les données de commande en session
            unset($_SESSION['commande_data']);

        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = "Erreur lors de la validation de la commande: " . $e->getMessage();
        }
    }
}



    // REDIRECTION APRÈS TRAITEMENT POST (seulement si nécessaire)
    if ($should_redirect) {
        // Stocker les messages dans la session
        if (!empty($success)) {
            $_SESSION['success_message'] = $success;
        }
        if (!empty($errors)) {
            $_SESSION['error_messages'] = $errors;
        }
        
        // Rediriger vers la même page sans les données POST
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
}

// Récupérer les messages de session après redirection
if (isset($_SESSION['success_message'])) {
    $success = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

if (isset($_SESSION['error_messages'])) {
    $errors = $_SESSION['error_messages'];
    unset($_SESSION['error_messages']);
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Client - NAGEX Pharma</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet">
    <style>
        .sidebar { transition: all 0.3s ease; }
        .nav-active { background-color: #10B981; color: white; }
        .pharma-green { background-color: #10B981; }
        .pharma-green:hover { background-color: #059669; }
        .alert-success { background-color: #D1FAE5; border-color: #10B981; color: #065F46; }
        .alert-error { background-color: #FEE2E2; border-color: #EF4444; color: #7F1D1D; }
        body { font-family: 'Montserrat', sans-serif; }
        .section { display: none; }
        .section.active { display: block; }
        .product-card { transition: transform 0.2s; }
        .product-card:hover { transform: translateY(-2px); }
    </style>
</head>
<body class="bg-gray-100">
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
                    <p class="text-xs text-green-600">Client Dashboard</p>
                </div>
            </div>
        </div>

        <!-- Navigation -->
        <nav class="mt-6">
            <div class="px-4 space-y-2">
                <a href="#catalogue" onclick="showSection('catalogue')" class="nav-active flex items-center px-4 py-3 text-gray-700 rounded-lg transition-colors">
                    <i class="fas fa-shopping-bag w-6"></i>
                    <span class="ml-3 font-medium">Catalogue Produits</span>
                </a>
                <a href="#panier" onclick="showSection('panier')" class="flex items-center px-4 py-3 text-gray-700 rounded-lg hover:bg-green-50 transition-colors">
                    <i class="fas fa-shopping-cart w-6"></i>
                    <span class="ml-3 font-medium">Mon Panier</span>
                    <?php if ($panier_actif && $panier_actif['nb_items'] > 0): ?>
                        <span class="ml-auto bg-red-500 text-white text-xs rounded-full w-6 h-6 flex items-center justify-center">
                            <?php echo $panier_actif['nb_items']; ?>
                        </span>
                    <?php endif; ?>
                </a>
                <a href="#commandes" onclick="showSection('commandes')" class="flex items-center px-4 py-3 text-gray-700 rounded-lg hover:bg-green-50 transition-colors">
                    <i class="fas fa-clipboard-list w-6"></i>
                    <span class="ml-3 font-medium">Mes Commandes</span>
                </a>
                <a href="#favoris" onclick="showSection('favoris')" class="flex items-center px-4 py-3 text-gray-700 rounded-lg hover:bg-green-50 transition-colors">
                    <i class="fas fa-heart w-6"></i>
                    <span class="ml-3 font-medium">Mes Favoris</span>
                </a>
                <a href="#profil" onclick="showSection('profil')" class="flex items-center px-4 py-3 text-gray-700 rounded-lg hover:bg-green-50 transition-colors">
                    <i class="fas fa-user w-6"></i>
                    <span class="ml-3 font-medium">Mon Profil</span>
                </a>
            </div>
        </nav>

        <!-- User Profile -->
        <div class="absolute bottom-0 left-0 right-0 p-4 border-t">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 bg-green-100 rounded-full flex items-center justify-center">
                    <i class="fas fa-user text-green-600"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-gray-900 truncate"><?php echo htmlspecialchars($user_name); ?></p>
                    <p class="text-xs text-green-600 truncate">Client</p>
                </div>
                <a href="/logout.php" class="text-gray-400 hover:text-red-500 transition-colors" title="Déconnexion">
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
                    <h2 class="text-2xl font-bold text-gray-800" id="pageTitle">Catalogue Produits</h2>
                    <p class="text-gray-600">Bienvenue dans votre espace client</p>
                </div>
                <div class="flex items-center space-x-4">
                    <div class="text-sm text-gray-600">
                        <i class="fas fa-user-circle mr-2"></i>
                        Connecté en tant que <span class="font-semibold">Client</span>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Content Area -->
        <main class="p-8">
            <!-- Messages d'alerte -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert-success border rounded-lg p-4 mb-6">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle mr-2"></i>
                        <span class="font-semibold">Succès !</span>
                    </div>
                    <p class="mt-2 text-sm"><?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?></p>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_messages'])): ?>
                <div class="alert-error border rounded-lg p-4 mb-6">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        <span class="font-semibold">Erreur :</span>
                    </div>
                    <div class="mt-2 text-sm">
                        <?php 
                        foreach ($_SESSION['error_messages'] as $error) {
                            echo '<p>' . $error . '</p>';
                        }
                        unset($_SESSION['error_messages']);
                        ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Section Catalogue -->
            <div id="catalogue" class="section active">
                <!-- Stats Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <div class="bg-white rounded-2xl shadow-sm p-6 border-l-4 border-green-500">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-600">Produits disponibles</p>
                                <p class="text-2xl font-bold text-gray-900"><?php echo count($produits); ?></p>
                            </div>
                            <div class="w-12 h-12 bg-green-100 rounded-full flex items-center justify-center">
                                <i class="fas fa-capsules text-green-600 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-2xl shadow-sm p-6 border-l-4 border-blue-500">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-600">Articles dans le panier</p>
                                <p class="text-2xl font-bold text-gray-900"><?php echo $panier_actif['nb_items'] ?? 0; ?></p>
                            </div>
                            <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center">
                                <i class="fas fa-shopping-cart text-blue-600 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-2xl shadow-sm p-6 border-l-4 border-yellow-500">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-600">Commandes passées</p>
                                <p class="text-2xl font-bold text-gray-900"><?php echo count($commandes); ?></p>
                            </div>
                            <div class="w-12 h-12 bg-yellow-100 rounded-full flex items-center justify-center">
                                <i class="fas fa-clipboard-list text-yellow-600 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-2xl shadow-sm p-6 border-l-4 border-purple-500">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-600">Total dépensé</p>
                                <p class="text-2xl font-bold text-gray-900">
                                    <?php 
                                    $total_achats = array_sum(array_column($commandes, 'montant_total'));
                                    echo number_format($total_achats, 0, ',', ' '); 
                                    ?> FC
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-purple-100 rounded-full flex items-center justify-center">
                                <i class="fas fa-coins text-purple-600 text-xl"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recherche et Filtres -->
                <div class="bg-white rounded-2xl shadow-sm p-6 mb-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Rechercher des produits</h3>
                    <form method="GET" id="searchForm" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Nom du produit</label>
                            <input type="text" name="search_term" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent" 
                                   placeholder="Rechercher..." value="<?php echo $_GET['search_term'] ?? ''; ?>">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Catégorie</label>
                            <select name="categorie_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent">
                                <option value="">Toutes catégories</option>
                                <?php foreach ($categories as $categorie): ?>
                                    <option value="<?php echo $categorie['id']; ?>" 
                                        <?php echo (isset($_GET['categorie_id']) && $_GET['categorie_id'] == $categorie['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($categorie['nom']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Fournisseur</label>
                            <select name="fournisseur_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent">
                                <option value="">Tous fournisseurs</option>
                                <?php foreach ($fournisseurs as $fournisseur): ?>
                                    <option value="<?php echo $fournisseur['id']; ?>"
                                        <?php echo (isset($_GET['fournisseur_id']) && $_GET['fournisseur_id'] == $fournisseur['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($fournisseur['nom_societe']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Prix min</label>
                            <input type="number" name="prix_min" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent" 
                                   placeholder="Prix min" value="<?php echo $_GET['prix_min'] ?? ''; ?>">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Prix max</label>
                            <input type="number" name="prix_max" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent" 
                                   placeholder="Prix max" value="<?php echo $_GET['prix_max'] ?? ''; ?>">
                        </div>

                        <div class="md:col-span-2 lg:col-span-5 flex space-x-4">
                            <button type="submit" class="pharma-green text-white px-6 py-2 rounded-lg font-semibold hover:bg-green-700 transition-colors flex items-center">
                                <i class="fas fa-search mr-2"></i>Rechercher
                            </button>
                            <a href="client.php" class="px-6 py-2 border border-gray-300 text-gray-700 rounded-lg font-semibold hover:bg-gray-50 transition-colors flex items-center">
                                <i class="fas fa-times mr-2"></i>Effacer
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Liste des produits -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                    <?php foreach ($produits as $produit): ?>
                        <div class="bg-white rounded-2xl shadow-sm overflow-hidden hover:shadow-md transition-shadow product-card">
                            <img src="/images/produits/<?php echo $produit['id']; ?>.jpg" 
                                 class="w-full h-48 object-cover"
                                 alt="<?php echo htmlspecialchars($produit['nom']); ?>"
                                 onerror="this.src='https://via.placeholder.com/300x200?text=Produit'">
                            
                            <div class="p-4">
                                <h4 class="font-semibold text-gray-900 mb-2"><?php echo htmlspecialchars($produit['nom']); ?></h4>
                                <p class="text-sm text-gray-600 mb-1"><?php echo htmlspecialchars($produit['categorie_nom']); ?></p>
                                <p class="text-sm text-gray-600 mb-3 line-clamp-2">
                                    <?php echo htmlspecialchars(substr($produit['description'], 0, 100)); ?>...
                                </p>
                                
                                <div class="flex items-center justify-between mb-3">
                                    <span class="text-lg font-bold text-green-600">
                                        <?php echo number_format($produit['prix_fc'], 0, ',', ' '); ?> FC
                                    </span>
                                    <span class="bg-green-100 text-green-800 text-xs px-2 py-1 rounded-full">
                                        Stock: <?php echo $produit['stock']; ?>
                                    </span>
                                </div>

                                <div class="flex space-x-2">
                                    <form method="POST" class="flex-1">
                                        <input type="hidden" name="produit_id" value="<?php echo $produit['id']; ?>">
                                        <input type="hidden" name="prix_unitaire" value="<?php echo $produit['prix_fc']; ?>">
                                        <div class="flex space-x-2">
                                            <input type="number" name="quantite" value="1" min="1" 
                                                   max="<?php echo $produit['stock']; ?>" 
                                                   class="flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent">
                                            <button type="submit" name="ajouter_panier" 
                                                    class="pharma-green text-white px-4 py-2 rounded-lg hover:bg-green-700 transition-colors">
                                                <i class="fas fa-cart-plus"></i>
                                            </button>
                                        </div>
                                    </form>
                                    
                                    <form method="POST">
                                        <input type="hidden" name="produit_id" value="<?php echo $produit['id']; ?>">
                                        <button type="submit" name="ajouter_favori" 
                                                class="px-4 py-2 border border-red-300 text-red-600 rounded-lg hover:bg-red-50 transition-colors">
                                            <i class="fas fa-heart"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    
                    <?php if (empty($produits)): ?>
                        <div class="col-span-full text-center py-12">
                            <i class="fas fa-search fa-3x text-gray-400 mb-4"></i>
                            <h3 class="text-xl font-semibold text-gray-600 mb-2">Aucun produit trouvé</h3>
                            <p class="text-gray-500">Essayez de modifier vos critères de recherche</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Section Panier -->
            <div id="panier" class="section">
                <div class="bg-white rounded-2xl shadow-sm">
                    <div class="px-6 py-4 border-b">
                        <div class="flex items-center justify-between">
                            <h3 class="text-xl font-semibold text-gray-900">Mon Panier</h3>
                            <?php if ($panier_actif && $panier_actif['nb_items'] > 0): ?>
                                <button type="button" onclick="openValidationModal()" class="pharma-green text-white px-6 py-2 rounded-lg font-semibold hover:bg-green-700 transition-colors flex items-center">
                                    <i class="fas fa-check mr-2"></i>Valider la Commande
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="p-6">
                        <?php if ($panier_actif && !empty($panier_items)): ?>
                            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                                <div class="lg:col-span-2 space-y-4">
                                    <?php foreach ($panier_items as $item): ?>
                                        <div class="bg-gray-50 rounded-lg p-4">
                                            <div class="flex items-center space-x-4">
                                                <img src="/images/produits/<?php echo $item['produit_id']; ?>.jpg" 
                                                     class="w-16 h-16 object-cover rounded-lg"
                                                     alt="<?php echo htmlspecialchars($item['nom']); ?>"
                                                     onerror="this.src='https://via.placeholder.com/80x80?text=Produit'">
                                                
                                                <div class="flex-1">
                                                    <h4 class="font-semibold text-gray-900"><?php echo htmlspecialchars($item['nom']); ?></h4>
                                                    <p class="text-sm text-gray-600">Ref: <?php echo $item['reference']; ?></p>
                                                    <p class="text-lg font-bold text-green-600">
                                                        <?php echo number_format($item['prix_unitaire'], 0, ',', ' '); ?> FC
                                                    </p>
                                                </div>
                                                
                                                <div class="flex items-center space-x-2">
                                                    <form method="POST" class="update-quantity-form flex items-center space-x-2">
                                                        <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                                                        <input type="number" name="quantite" 
                                                               value="<?php echo $item['quantite']; ?>" 
                                                               min="1" max="<?php echo $item['stock']; ?>"
                                                               class="w-20 px-2 py-1 border border-gray-300 rounded focus:ring-2 focus:ring-green-500 focus:border-transparent">
                                                        <button type="submit" name="update_quantity" class="text-blue-600 hover:text-blue-800">
                                                            <i class="fas fa-sync-alt"></i>
                                                        </button>
                                                    </form>
                                                    
                                                    <form method="POST" class="inline">
                                                        <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                                                        <button type="submit" name="supprimer_panier" 
                                                                class="text-red-600 hover:text-red-800">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                
                                <div class="lg:col-span-1">
                                    <div class="bg-white border border-gray-200 rounded-lg p-6">
                                        <h4 class="text-lg font-semibold text-gray-900 mb-4">Récapitulatif</h4>
                                        <div class="space-y-2 mb-4">
                                            <div class="flex justify-between">
                                                <span class="text-gray-600">Sous-total:</span>
                                                <span class="font-semibold"><?php echo number_format($panier_actif['total_panier'], 0, ',', ' '); ?> FC</span>
                                            </div>
                                            <div class="flex justify-between">
                                                <span class="text-gray-600">Frais de service:</span>
                                                <span class="font-semibold">0 FC</span>
                                            </div>
                                            <hr>
                                            <div class="flex justify-between text-lg">
                                                <strong>Total:</strong>
                                                <strong class="text-green-600"><?php echo number_format($panier_actif['total_panier'], 0, ',', ' '); ?> FC</strong>
                                            </div>
                                        </div>
                                        <button type="button" onclick="openValidationModal()" 
                                                class="w-full pharma-green text-white py-3 rounded-lg font-semibold hover:bg-green-700 transition-colors flex items-center justify-center">
                                            <i class="fas fa-check mr-2"></i>Valider la Commande
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-12">
                                <i class="fas fa-shopping-cart fa-4x text-gray-400 mb-4"></i>
                                <h3 class="text-xl font-semibold text-gray-600 mb-2">Votre panier est vide</h3>
                                <p class="text-gray-500 mb-4">Ajoutez des produits depuis le catalogue pour commencer vos achats</p>
                                <button onclick="showSection('catalogue')" class="pharma-green text-white px-6 py-2 rounded-lg font-semibold hover:bg-green-700 transition-colors">
                                    <i class="fas fa-shopping-bag mr-2"></i>Voir le Catalogue
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Section Commandes -->
            <div id="commandes" class="section">
                <div class="bg-white rounded-2xl shadow-sm">
                    <div class="px-6 py-4 border-b">
                        <h3 class="text-xl font-semibold text-gray-900">Mes Commandes</h3>
                    </div>

                    <div class="p-6">
                        <?php if (!empty($commandes)): ?>
                            <div class="overflow-x-auto">
                                <table class="w-full">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Référence</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Produits</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Montant</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Statut</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php foreach ($commandes as $commande): ?>
                                            <tr class="hover:bg-gray-50">
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($commande['numero_commande']); ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo date('d/m/Y H:i', strtotime($commande['date_commande'])); ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo $commande['nb_produits']; ?> produit(s)
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900">
                                                    <?php echo number_format($commande['montant_total'], 0, ',', ' '); ?> FC
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <?php 
                                                    $badge_class = [
                                                        'en_attente' => 'bg-yellow-100 text-yellow-800',
                                                        'confirme' => 'bg-blue-100 text-blue-800',
                                                        'en_preparation' => 'bg-purple-100 text-purple-800',
                                                        'expedie' => 'bg-indigo-100 text-indigo-800',
                                                        'livre' => 'bg-green-100 text-green-800',
                                                        'annule' => 'bg-red-100 text-red-800'
                                                    ][$commande['statut']] ?? 'bg-gray-100 text-gray-800';
                                                    ?>
                                                    <span class="px-2 py-1 text-xs font-semibold rounded-full <?php echo $badge_class; ?>">
                                                        <?php echo ucfirst(str_replace('_', ' ', $commande['statut'])); ?>
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                    <button onclick="viewOrderDetails(<?php echo $commande['id']; ?>)" 
                                                            class="text-blue-600 hover:text-blue-900 mr-3">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <button onclick="imprimerCommande(<?php echo $commande['id']; ?>)" 
                                                            class="text-green-600 hover:text-green-900">
                                                        <i class="fas fa-print"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-12">
                                <i class="fas fa-clipboard-list fa-4x text-gray-400 mb-4"></i>
                                <h3 class="text-xl font-semibold text-gray-600 mb-2">Aucune commande</h3>
                                <p class="text-gray-500 mb-4">Vous n'avez pas encore passé de commande</p>
                                <button onclick="showSection('catalogue')" class="pharma-green text-white px-6 py-2 rounded-lg font-semibold hover:bg-green-700 transition-colors">
                                    <i class="fas fa-shopping-bag mr-2"></i>Commencer mes Achats
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Section Favoris -->
            <div id="favoris" class="section">
                <div class="bg-white rounded-2xl shadow-sm">
                    <div class="px-6 py-4 border-b">
                        <h3 class="text-xl font-semibold text-gray-900">Mes Produits Favoris</h3>
                    </div>

                    <div class="p-6">
                        <?php if (!empty($favoris)): ?>
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                                <?php foreach ($favoris as $favori): ?>
                                    <div class="bg-white border border-gray-200 rounded-lg p-4">
                                        <div class="flex items-center justify-between mb-3">
                                            <h4 class="font-semibold text-gray-900"><?php echo htmlspecialchars($favori['nom']); ?></h4>
                                            <form method="POST">
                                                <input type="hidden" name="produit_id" value="<?php echo $favori['produit_id']; ?>">
                                                <button type="submit" name="supprimer_favori" 
                                                        class="text-red-600 hover:text-red-800">
                                                    <i class="fas fa-heart-broken"></i>
                                                </button>
                                            </form>
                                        </div>
                                        <p class="text-lg font-bold text-green-600 mb-3">
                                            <?php echo number_format($favori['prix_fc'], 0, ',', ' '); ?> FC
                                        </p>
                                        <form method="POST">
                                            <input type="hidden" name="produit_id" value="<?php echo $favori['produit_id']; ?>">
                                            <input type="hidden" name="prix_unitaire" value="<?php echo $favori['prix_fc']; ?>">
                                            <div class="flex space-x-2">
                                                <input type="number" name="quantite" value="1" min="1" 
                                                       class="flex-1 px-3 py-2 border border-gray-300 rounded focus:ring-2 focus:ring-green-500 focus:border-transparent">
                                                <button type="submit" name="ajouter_panier" 
                                                        class="pharma-green text-white px-4 py-2 rounded hover:bg-green-700 transition-colors">
                                                    <i class="fas fa-cart-plus"></i>
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-12">
                                <i class="fas fa-heart fa-4x text-gray-400 mb-4"></i>
                                <h3 class="text-xl font-semibold text-gray-600 mb-2">Aucun favori</h3>
                                <p class="text-gray-500 mb-4">Ajoutez des produits à vos favoris depuis le catalogue</p>
                                <button onclick="showSection('catalogue')" class="pharma-green text-white px-6 py-2 rounded-lg font-semibold hover:bg-green-700 transition-colors">
                                    <i class="fas fa-shopping-bag mr-2"></i>Voir le Catalogue
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Section Profil -->
            <div id="profil" class="section">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Informations Personnelles -->
                    <div class="bg-white rounded-2xl shadow-sm">
                        <div class="px-6 py-4 border-b">
                            <h3 class="text-xl font-semibold text-gray-900">Informations Personnelles</h3>
                        </div>
                        <div class="p-6">
                            <form method="POST" class="space-y-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Nom complet</label>
                                    <input type="text" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent" 
                                           value="<?php echo htmlspecialchars($user_name); ?>" readonly>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Email</label>
                                    <input type="email" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent" 
                                           value="<?php echo htmlspecialchars($user_email); ?>" readonly>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Téléphone</label>
                                    <input type="tel" name="telephone" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent" 
                                           value="<?php echo htmlspecialchars($user_telephone); ?>" placeholder="Votre numéro de téléphone">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Adresse</label>
                                    <textarea name="adresse" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent" 
                                              rows="3" placeholder="Votre adresse de livraison"><?php echo htmlspecialchars($user_adresse); ?></textarea>
                                </div>
                                <button type="submit" name="update_profil" class="pharma-green text-white px-6 py-2 rounded-lg font-semibold hover:bg-green-700 transition-colors flex items-center">
                                    <i class="fas fa-save mr-2"></i>Enregistrer
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Statistiques -->
                    <div class="bg-white rounded-2xl shadow-sm">
                        <div class="px-6 py-4 border-b">
                            <h3 class="text-xl font-semibold text-gray-900">Statistiques d'Achats</h3>
                        </div>
                        <div class="p-6">
                            <div class="grid grid-cols-2 gap-4">
                                <div class="text-center p-4 bg-green-50 rounded-lg">
                                    <div class="text-3xl font-bold text-green-600"><?php echo count($commandes); ?></div>
                                    <div class="text-sm text-gray-600 mt-1">Commandes</div>
                                </div>
                                <div class="text-center p-4 bg-blue-50 rounded-lg">
                                    <div class="text-3xl font-bold text-blue-600">
                                        <?php echo number_format($total_achats, 0, ',', ' '); ?>
                                    </div>
                                    <div class="text-sm text-gray-600 mt-1">FC dépensés</div>
                                </div>
                                <div class="text-center p-4 bg-yellow-50 rounded-lg">
                                    <div class="text-3xl font-bold text-yellow-600"><?php echo count($favoris); ?></div>
                                    <div class="text-sm text-gray-600 mt-1">Favoris</div>
                                </div>
                                <div class="text-center p-4 bg-purple-50 rounded-lg">
                                    <div class="text-3xl font-bold text-purple-600"><?php echo $panier_actif['nb_items'] ?? 0; ?></div>
                                    <div class="text-sm text-gray-600 mt-1">Dans le panier</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Modal Validation Commande -->
    <!-- Modal Validation Commande -->
<div id="validationModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-2xl shadow-lg rounded-md bg-white">
        <div class="mt-3">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-medium text-gray-900">Finaliser votre commande</h3>
                <button type="button" onclick="closeValidationModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form method="POST" id="commandeForm">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                    <!-- Récapitulatif de la commande -->
                    <div>
                        <h4 class="font-semibold text-gray-900 mb-3">Récapitulatif de la commande</h4>
                        <div class="bg-gray-50 rounded-lg p-4">
                            <?php if ($panier_actif && !empty($panier_items)): ?>
                                <div class="space-y-3 max-h-60 overflow-y-auto">
                                    <?php foreach ($panier_items as $item): ?>
                                        <div class="flex justify-between items-center border-b pb-2">
                                            <div class="flex-1">
                                                <p class="font-medium text-sm"><?php echo htmlspecialchars($item['nom']); ?></p>
                                                <p class="text-xs text-gray-600">Quantité: <?php echo $item['quantite']; ?></p>
                                            </div>
                                            <p class="font-semibold text-green-600">
                                                <?php echo number_format($item['quantite'] * $item['prix_unitaire'], 0, ',', ' '); ?> FC
                                            </p>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="mt-4 pt-4 border-t">
                                    <div class="flex justify-between text-lg font-bold">
                                        <span>Total:</span>
                                        <span class="text-green-600"><?php echo number_format($panier_actif['total_panier'], 0, ',', ' '); ?> FC</span>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Informations personnelles -->
                    <div>
                        <h4 class="font-semibold text-gray-900 mb-3">Informations de livraison</h4>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Nom complet *</label>
                                <input type="text" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent" 
                                       value="<?php echo htmlspecialchars($user_name); ?>" readonly>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Email *</label>
                                <input type="email" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent" 
                                       value="<?php echo htmlspecialchars($user_email); ?>" readonly>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Téléphone *</label>
                                <input type="tel" name="telephone" required
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent" 
                                       value="<?php echo htmlspecialchars($user_telephone); ?>" 
                                       placeholder="Votre numéro de téléphone"
                                       oninvalid="this.setCustomValidity('Veuillez saisir votre numéro de téléphone')"
                                       oninput="this.setCustomValidity('')">
                                <p class="text-xs text-gray-500 mt-1">Pour vous contacter concernant votre commande</p>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Adresse de livraison *</label>
                                <textarea name="adresse" required rows="3"
                                          class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent" 
                                          placeholder="Votre adresse complète de livraison"
                                          oninvalid="this.setCustomValidity('Veuillez saisir votre adresse de livraison')"
                                          oninput="this.setCustomValidity('')"><?php echo htmlspecialchars($user_adresse); ?></textarea>
                                <p class="text-xs text-gray-500 mt-1">Où souhaitez-vous recevoir votre commande ?</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Méthode de paiement -->
                <div class="mb-6">
                    <h4 class="font-semibold text-gray-900 mb-3">Méthode de paiement</h4>
                    <div class="bg-gray-50 rounded-lg p-4">
                        <div class="flex items-center space-x-3">
                            <input type="radio" id="paiement_livraison" name="methode_paiement" value="livraison" checked 
                                   class="text-green-600 focus:ring-green-500">
                            <label for="paiement_livraison" class="flex items-center">
                                <i class="fas fa-money-bill-wave text-green-600 mr-2"></i>
                                <span>Paiement à la livraison</span>
                            </label>
                        </div>
                        <p class="text-xs text-gray-600 mt-2 ml-6">Vous payez lorsque vous recevez votre commande</p>
                    </div>
                </div>

                <!-- Conditions générales -->
                <div class="mb-6">
                    <div class="flex items-start space-x-3">
                        <input type="checkbox" id="conditions" name="conditions" required
                               class="mt-1 text-green-600 focus:ring-green-500"
                               oninvalid="this.setCustomValidity('Vous devez accepter les conditions générales')"
                               oninput="this.setCustomValidity('')">
                        <label for="conditions" class="text-sm text-gray-700">
                            J'accepte les <a href="#" class="text-green-600 hover:text-green-700">conditions générales de vente</a> 
                            et la <a href="#" class="text-green-600 hover:text-green-700">politique de confidentialité</a>. 
                            Je comprends que ma commande sera traitée dans les 24-48 heures.
                        </label>
                    </div>
                </div>

                <!-- Boutons d'action -->
                <div class="flex justify-end space-x-3 pt-4 border-t">
                    <button type="button" onclick="closeValidationModal()" 
                            class="px-6 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400 transition-colors font-medium">
                        Annuler
                    </button>
                    <button type="submit" name="valider_commande" 
                            class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors font-medium flex items-center">
                        <i class="fas fa-check mr-2"></i>Confirmer la commande
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // Navigation entre sections
    function showSection(sectionName) {
        // Cacher toutes les sections
        document.querySelectorAll('.section').forEach(section => {
            section.classList.remove('active');
        });

        // Afficher la section demandée
        document.getElementById(sectionName).classList.add('active');

        // Mettre à jour le titre de la page
        const titles = {
            'catalogue': 'Catalogue Produits',
            'panier': 'Mon Panier',
            'commandes': 'Mes Commandes',
            'favoris': 'Mes Favoris',
            'profil': 'Mon Profil'
        };
        document.getElementById('pageTitle').textContent = titles[sectionName] || 'Dashboard Client';

        // Mettre à jour la navigation active
        document.querySelectorAll('nav a').forEach(link => {
            link.classList.remove('nav-active');
            if (link.getAttribute('href') === '#' + sectionName) {
                link.classList.add('nav-active');
            }
        });

        // Sauvegarder la section active dans le localStorage
        localStorage.setItem('activeSection', sectionName);
    }

    // Gestion du modal de validation
    function openValidationModal() {
        document.getElementById('validationModal').classList.remove('hidden');
    }

    // Validation du formulaire de commande
function validateCommandeForm() {
    const form = document.getElementById('commandeForm');
    const telephone = form.querySelector('input[name="telephone"]');
    const adresse = form.querySelector('textarea[name="adresse"]');
    const conditions = form.querySelector('input[name="conditions"]');
    
    let isValid = true;
    
    // Validation téléphone
    if (!telephone.value.trim()) {
        showFieldError(telephone, 'Le numéro de téléphone est obligatoire');
        isValid = false;
    } else {
        clearFieldError(telephone);
    }
    
    // Validation adresse
    if (!adresse.value.trim()) {
        showFieldError(adresse, 'L\'adresse de livraison est obligatoire');
        isValid = false;
    } else if (adresse.value.trim().length < 10) {
        showFieldError(adresse, 'L\'adresse doit être plus détaillée');
        isValid = false;
    } else {
        clearFieldError(adresse);
    }
    
    // Validation conditions
    if (!conditions.checked) {
        showFieldError(conditions, 'Vous devez accepter les conditions générales');
        isValid = false;
    } else {
        clearFieldError(conditions);
    }
    
    return isValid;
}

// Afficher une erreur de champ
function showFieldError(field, message) {
    field.classList.add('border-red-500');
    field.classList.remove('border-gray-300');
    
    // Supprimer l'erreur existante
    const existingError = field.parentNode.querySelector('.field-error');
    if (existingError) {
        existingError.remove();
    }
    
    // Ajouter le message d'erreur
    const errorDiv = document.createElement('div');
    errorDiv.className = 'field-error text-red-500 text-xs mt-1';
    errorDiv.textContent = message;
    field.parentNode.appendChild(errorDiv);
}

// Effacer l'erreur de champ
function clearFieldError(field) {
    field.classList.remove('border-red-500');
    field.classList.add('border-gray-300');
    
    const existingError = field.parentNode.querySelector('.field-error');
    if (existingError) {
        existingError.remove();
    }
}

// Ouvrir le modal de validation avec vérification
function openValidationModal() {
    if (!<?php echo $panier_actif && !empty($panier_items) ? 'true' : 'false'; ?>) {
        alert('Votre panier est vide. Ajoutez des produits avant de valider votre commande.');
        return;
    }
    
    document.getElementById('validationModal').classList.remove('hidden');
    document.body.classList.add('overflow-hidden');
}

// Fermer le modal
function closeValidationModal() {
    document.getElementById('validationModal').classList.add('hidden');
    document.body.classList.remove('overflow-hidden');
    
    // Réinitialiser les erreurs
    document.querySelectorAll('.field-error').forEach(error => error.remove());
    document.querySelectorAll('.border-red-500').forEach(field => {
        field.classList.remove('border-red-500');
        field.classList.add('border-gray-300');
    });
}

// Écouter la soumission du formulaire
document.addEventListener('DOMContentLoaded', function() {
    const commandeForm = document.getElementById('commandeForm');
    if (commandeForm) {
        commandeForm.addEventListener('submit', function(e) {
            if (!validateCommandeForm()) {
                e.preventDefault();
                // Faire défiler vers la première erreur
                const firstError = this.querySelector('.border-red-500');
                if (firstError) {
                    firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }
        });
    }
    
    // Fermer le modal en cliquant à l'extérieur
    document.getElementById('validationModal').addEventListener('click', function(e) {
        if (e.target.id === 'validationModal') {
            closeValidationModal();
        }
    });
    
    // Fermer le modal avec la touche Échap
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeValidationModal();
        }
    });
});

    function closeValidationModal() {
        document.getElementById('validationModal').classList.add('hidden');
    }

    // Fermer le modal en cliquant à l'extérieur
    document.getElementById('validationModal').addEventListener('click', function (e) {
        if (e.target.id === 'validationModal') {
            closeValidationModal();
        }
    });

    // Fonction pour voir les détails d'une commande
    function viewOrderDetails(orderId) {
        // Ouvrir un modal ou une nouvelle page avec les détails de la commande
        alert('Détails de la commande #' + orderId + ' - Fonctionnalité à implémenter');
        // window.open('details_commande.php?id=' + orderId, '_blank');
    }

    // Fonction pour imprimer une commande
    function imprimerCommande(commandeId) {
        // Ouvrir la page d'impression dans une nouvelle fenêtre
        window.open('client.php?imprimer_commande=' + commandeId, '_blank');
    }

    // Mise à jour automatique des quantités
    function setupQuantityUpdates() {
        document.querySelectorAll('.update-quantity-form input[type="number"]').forEach(input => {
            input.addEventListener('change', function () {
                // Soumettre le formulaire automatiquement
                const form = this.closest('form');
                if (form) {
                    form.submit();
                }
            });
        });
    }

    // Recherche en temps réel
    function setupRealTimeSearch() {
        const searchForm = document.getElementById('searchForm');
        if (!searchForm) return;

        let searchTimeout;

        document.querySelectorAll('#searchForm input, #searchForm select').forEach(element => {
            element.addEventListener('input', function () {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    searchForm.submit();
                }, 800);
            });
        });
    }

    // Confirmation pour les actions critiques
    function setupConfirmations() {
        // Confirmation pour suppression du panier
        document.querySelectorAll('form[method="POST"]').forEach(form => {
            const deleteButton = form.querySelector('button[name="supprimer_panier"]');
            if (deleteButton) {
                deleteButton.addEventListener('click', function (e) {
                    if (!confirm('Êtes-vous sûr de vouloir retirer ce produit du panier ?')) {
                        e.preventDefault();
                    }
                });
            }

            // Confirmation pour suppression des favoris
            const removeFavoriteButton = form.querySelector('button[name="supprimer_favori"]');
            if (removeFavoriteButton) {
                removeFavoriteButton.addEventListener('click', function (e) {
                    if (!confirm('Êtes-vous sûr de vouloir retirer ce produit des favoris ?')) {
                        e.preventDefault();
                    }
                });
            }
        });
    }

    // Animation des cartes produits
    function setupProductAnimations() {
        const productCards = document.querySelectorAll('.product-card');
        productCards.forEach(card => {
            card.addEventListener('mouseenter', function () {
                this.style.transform = 'translateY(-5px)';
                this.style.boxShadow = '0 10px 25px rgba(0,0,0,0.1)';
            });
            card.addEventListener('mouseleave', function () {
                this.style.transform = 'translateY(0)';
                this.style.boxShadow = '';
            });
        });
    }

    // Gestion des messages d'alerte automatique
    function setupAutoDismissAlerts() {
        const alerts = document.querySelectorAll('.alert-success, .alert-error');
        alerts.forEach(alert => {
            setTimeout(() => {
                alert.style.opacity = '0';
                alert.style.transition = 'opacity 0.5s';
                setTimeout(() => {
                    alert.remove();
                }, 500);
            }, 5000);
        });
    }

    // Validation des formulaires
    function setupFormValidation() {
        const profileForm = document.querySelector('form[name="update_profil"]');
        if (profileForm) {
            profileForm.addEventListener('submit', function (e) {
                const telephone = this.querySelector('input[name="telephone"]');
                const adresse = this.querySelector('textarea[name="adresse"]');

                if (telephone && telephone.value && !/^[\+]?[0-9\s\-\(\)]{8,}$/.test(telephone.value)) {
                    alert('Veuillez entrer un numéro de téléphone valide');
                    e.preventDefault();
                    return;
                }

                if (adresse && adresse.value && adresse.value.length < 5) {
                    alert('L\'adresse doit contenir au moins 5 caractères');
                    e.preventDefault();
                    return;
                }
            });
        }
    }

    // Initialisation au chargement de la page
    document.addEventListener('DOMContentLoaded', function () {
        // Restaurer la section active depuis le localStorage
        const savedSection = localStorage.getItem('activeSection') || 'catalogue';
        showSection(savedSection);

        // Configurer toutes les fonctionnalités
        setupQuantityUpdates();
        setupRealTimeSearch();
        setupConfirmations();
        setupProductAnimations();
        setupAutoDismissAlerts();
        setupFormValidation();

        // Gestion des images manquantes
        document.querySelectorAll('img').forEach(img => {
            img.addEventListener('error', function () {
                this.src = 'https://via.placeholder.com/300x200?text=Produit+Non+Disponible';
            });
        });

        // Amélioration de l'UX pour les inputs de quantité
        document.querySelectorAll('input[type="number"]').forEach(input => {
            input.addEventListener('focus', function () {
                this.select();
            });
        });
    });

    // Fonction utilitaire pour formater les prix
    function formatPrice(price) {
        return new Intl.NumberFormat('fr-FR', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 0
        }).format(price) + ' FC';
    }

    // Fonction pour calculer le total en temps réel (pour futures améliorations)
    function calculateCartTotal() {
        let total = 0;
        document.querySelectorAll('.cart-item').forEach(item => {
            const quantity = parseInt(item.querySelector('.quantity-input').value) || 0;
            const price = parseFloat(item.querySelector('.price').dataset.price) || 0;
            total += quantity * price;
        });
        document.getElementById('cart-total').textContent = formatPrice(total);
    }

    // Export des fonctions pour une utilisation globale
    window.showSection = showSection;
    window.openValidationModal = openValidationModal;
    window.closeValidationModal = closeValidationModal;
    window.viewOrderDetails = viewOrderDetails;
    window.imprimerCommande = imprimerCommande;
</script>
</body>

</html>