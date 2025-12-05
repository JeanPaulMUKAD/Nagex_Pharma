-- --------------------------------------------------------
-- Hôte:                         127.0.0.1
-- Version du serveur:           8.4.3 - MySQL Community Server - GPL
-- SE du serveur:                Win64
-- HeidiSQL Version:             12.1.0.6537
-- --------------------------------------------------------

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET NAMES utf8 */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;


-- Listage de la structure de la base pour nagex_pharma_db
CREATE DATABASE IF NOT EXISTS `nagex_pharma_db` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci */ /*!80016 DEFAULT ENCRYPTION='N' */;
USE `nagex_pharma_db`;

-- Listage de la structure de table nagex_pharma_db. alertes
CREATE TABLE IF NOT EXISTS `alertes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `type_alerte` enum('stock_bas','peremption','rupture_stock') NOT NULL,
  `produit_id` int NOT NULL,
  `lot_id` int DEFAULT NULL,
  `message` text NOT NULL,
  `niveau` enum('faible','moyen','critique') DEFAULT 'faible',
  `statut` enum('non_lu','lu','traite') DEFAULT 'non_lu',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `produit_id` (`produit_id`),
  KEY `lot_id` (`lot_id`),
  CONSTRAINT `alertes_ibfk_1` FOREIGN KEY (`produit_id`) REFERENCES `produits` (`id`),
  CONSTRAINT `alertes_ibfk_2` FOREIGN KEY (`lot_id`) REFERENCES `lots` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Listage des données de la table nagex_pharma_db.alertes : ~0 rows (environ)

-- Listage de la structure de table nagex_pharma_db. categories
CREATE TABLE IF NOT EXISTS `categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nom` varchar(100) NOT NULL,
  `description` text,
  `created_by` int NOT NULL,
  `statut` enum('actif','inactif') DEFAULT 'actif',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `categories_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `utilisateurs` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Listage des données de la table nagex_pharma_db.categories : ~0 rows (environ)
INSERT INTO `categories` (`id`, `nom`, `description`, `created_by`, `statut`, `created_at`) VALUES
	(1, 'Maux de tête', 'Produit pharmaceutique', 5, 'actif', '2025-11-27 01:18:45');

-- Listage de la structure de table nagex_pharma_db. commandes
CREATE TABLE IF NOT EXISTS `commandes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `numero_commande` varchar(50) NOT NULL,
  `client_id` int NOT NULL,
  `caissier_id` int DEFAULT NULL,
  `montant_total` decimal(10,2) NOT NULL,
  `statut` enum('en_attente','paye','annule','rembourse') DEFAULT 'en_attente',
  `mode_paiement` enum('especes','carte','mobile') DEFAULT 'especes',
  `date_commande` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `date_paiement` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `numero_commande` (`numero_commande`),
  KEY `client_id` (`client_id`),
  KEY `caissier_id` (`caissier_id`),
  CONSTRAINT `commandes_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `utilisateurs` (`id`),
  CONSTRAINT `commandes_ibfk_2` FOREIGN KEY (`caissier_id`) REFERENCES `utilisateurs` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Listage des données de la table nagex_pharma_db.commandes : ~0 rows (environ)

-- Listage de la structure de table nagex_pharma_db. commande_details
CREATE TABLE IF NOT EXISTS `commande_details` (
  `id` int NOT NULL AUTO_INCREMENT,
  `commande_id` int NOT NULL,
  `produit_id` int NOT NULL,
  `lot_id` int NOT NULL,
  `quantite` int NOT NULL,
  `prix_unitaire` decimal(10,2) NOT NULL,
  `sous_total` decimal(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `commande_id` (`commande_id`),
  KEY `produit_id` (`produit_id`),
  KEY `lot_id` (`lot_id`),
  CONSTRAINT `commande_details_ibfk_1` FOREIGN KEY (`commande_id`) REFERENCES `commandes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `commande_details_ibfk_2` FOREIGN KEY (`produit_id`) REFERENCES `produits` (`id`),
  CONSTRAINT `commande_details_ibfk_3` FOREIGN KEY (`lot_id`) REFERENCES `lots` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Listage des données de la table nagex_pharma_db.commande_details : ~0 rows (environ)

-- Listage de la structure de table nagex_pharma_db. favoris
CREATE TABLE IF NOT EXISTS `favoris` (
  `id` int NOT NULL AUTO_INCREMENT,
  `client_id` int NOT NULL,
  `produit_id` int NOT NULL,
  `date_ajout` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_favori` (`client_id`,`produit_id`),
  KEY `produit_id` (`produit_id`),
  CONSTRAINT `favoris_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `favoris_ibfk_2` FOREIGN KEY (`produit_id`) REFERENCES `produits` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Listage des données de la table nagex_pharma_db.favoris : ~0 rows (environ)

-- Listage de la structure de table nagex_pharma_db. fournisseurs
CREATE TABLE IF NOT EXISTS `fournisseurs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `nom_societe` varchar(200) NOT NULL,
  `contact_principal` varchar(100) DEFAULT NULL,
  `adresse_siege` text,
  `note_qualite` decimal(3,2) DEFAULT '0.00',
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `fournisseurs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Listage des données de la table nagex_pharma_db.fournisseurs : ~0 rows (environ)

-- Listage de la structure de table nagex_pharma_db. lots
CREATE TABLE IF NOT EXISTS `lots` (
  `id` int NOT NULL AUTO_INCREMENT,
  `produit_id` int NOT NULL,
  `numero_lot` varchar(100) NOT NULL,
  `quantite_initiale` int NOT NULL,
  `quantite_actuelle` int NOT NULL,
  `date_expiration` date NOT NULL,
  `prix_achat` decimal(10,2) NOT NULL,
  `date_reception` date NOT NULL,
  `statut` enum('en_stock','epuise','perime') DEFAULT 'en_stock',
  `created_by` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `produit_id` (`produit_id`),
  KEY `idx_date_expiration` (`date_expiration`),
  CONSTRAINT `lots_ibfk_1` FOREIGN KEY (`produit_id`) REFERENCES `produits` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Listage des données de la table nagex_pharma_db.lots : ~0 rows (environ)

-- Listage de la structure de table nagex_pharma_db. mouvements_stock
CREATE TABLE IF NOT EXISTS `mouvements_stock` (
  `id` int NOT NULL AUTO_INCREMENT,
  `produit_id` int NOT NULL,
  `lot_id` int NOT NULL,
  `type_mouvement` enum('entree','sortie','ajustement') NOT NULL,
  `quantite` int NOT NULL,
  `quantite_avant` int NOT NULL,
  `quantite_apres` int NOT NULL,
  `raison` varchar(200) DEFAULT NULL,
  `created_by` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `produit_id` (`produit_id`),
  KEY `lot_id` (`lot_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `mouvements_stock_ibfk_1` FOREIGN KEY (`produit_id`) REFERENCES `produits` (`id`),
  CONSTRAINT `mouvements_stock_ibfk_2` FOREIGN KEY (`lot_id`) REFERENCES `lots` (`id`),
  CONSTRAINT `mouvements_stock_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `utilisateurs` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Listage des données de la table nagex_pharma_db.mouvements_stock : ~0 rows (environ)

-- Listage de la structure de table nagex_pharma_db. paniers
CREATE TABLE IF NOT EXISTS `paniers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `client_id` int NOT NULL,
  `statut` enum('actif','abandonne','valide') DEFAULT 'actif',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `client_id` (`client_id`),
  CONSTRAINT `paniers_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `utilisateurs` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Listage des données de la table nagex_pharma_db.paniers : ~0 rows (environ)

-- Listage de la structure de table nagex_pharma_db. panier_items
CREATE TABLE IF NOT EXISTS `panier_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `panier_id` int NOT NULL,
  `produit_id` int NOT NULL,
  `quantite` int NOT NULL,
  `prix_unitaire` decimal(10,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `panier_id` (`panier_id`),
  KEY `produit_id` (`produit_id`),
  CONSTRAINT `panier_items_ibfk_1` FOREIGN KEY (`panier_id`) REFERENCES `paniers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `panier_items_ibfk_2` FOREIGN KEY (`produit_id`) REFERENCES `produits` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Listage des données de la table nagex_pharma_db.panier_items : ~0 rows (environ)

-- Listage de la structure de table nagex_pharma_db. prix_vente
CREATE TABLE IF NOT EXISTS `prix_vente` (
  `id` int NOT NULL AUTO_INCREMENT,
  `produit_id` int NOT NULL,
  `prix_fc` decimal(10,2) NOT NULL,
  `prix_usd` decimal(10,2) NOT NULL,
  `taux_conversion` decimal(8,4) DEFAULT '1.0000',
  `date_debut` date NOT NULL,
  `date_fin` date DEFAULT NULL,
  `created_by` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `produit_id` (`produit_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `prix_vente_ibfk_1` FOREIGN KEY (`produit_id`) REFERENCES `produits` (`id`) ON DELETE CASCADE,
  CONSTRAINT `prix_vente_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `utilisateurs` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Listage des données de la table nagex_pharma_db.prix_vente : ~0 rows (environ)

-- Listage de la structure de table nagex_pharma_db. produits
CREATE TABLE IF NOT EXISTS `produits` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nom` varchar(200) NOT NULL,
  `description` text,
  `code_barre` varchar(100) DEFAULT NULL,
  `categorie_id` int NOT NULL,
  `fournisseur_id` int NOT NULL,
  `necessite_ordonnance` tinyint(1) DEFAULT '0',
  `composition` text,
  `posologie` text,
  `contre_indications` text,
  `statut` enum('en_attente','actif','inactif') DEFAULT 'en_attente',
  `created_by` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code_barre` (`code_barre`),
  KEY `categorie_id` (`categorie_id`),
  KEY `fournisseur_id` (`fournisseur_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `produits_ibfk_1` FOREIGN KEY (`categorie_id`) REFERENCES `categories` (`id`),
  CONSTRAINT `produits_ibfk_2` FOREIGN KEY (`fournisseur_id`) REFERENCES `fournisseurs` (`id`),
  CONSTRAINT `produits_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `utilisateurs` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Listage des données de la table nagex_pharma_db.produits : ~0 rows (environ)

-- Listage de la structure de table nagex_pharma_db. statistiques_ventes
CREATE TABLE IF NOT EXISTS `statistiques_ventes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `date_periode` date NOT NULL,
  `type_periode` enum('jour','semaine','mois') NOT NULL,
  `total_ventes` decimal(12,2) NOT NULL,
  `nombre_transactions` int NOT NULL,
  `produit_plus_vendu` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `produit_plus_vendu` (`produit_plus_vendu`),
  CONSTRAINT `statistiques_ventes_ibfk_1` FOREIGN KEY (`produit_plus_vendu`) REFERENCES `produits` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Listage des données de la table nagex_pharma_db.statistiques_ventes : ~0 rows (environ)

-- Listage de la structure de table nagex_pharma_db. user_logs
CREATE TABLE IF NOT EXISTS `user_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `action` varchar(50) NOT NULL,
  `details` text,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Listage des données de la table nagex_pharma_db.user_logs : ~26 rows (environ)
INSERT INTO `user_logs` (`id`, `user_id`, `action`, `details`, `ip_address`, `user_agent`, `created_at`) VALUES
	(1, 1, 'CONNEXION', 'Utilisateur connecté avec succès', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-26 19:39:20'),
	(2, 1, 'MODIFICATION_UTILISATEUR', 'Utilisateur modifié: KASONGO LUMBALA Nathan (paularmimukad@gmail.com) - Rôle: admin', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-26 19:54:00'),
	(3, 5, 'CONNEXION', 'Utilisateur connecté avec succès', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-26 20:18:23'),
	(4, 5, 'DECONNEXION', 'Utilisateur déconnecté avec succès', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-26 20:19:27'),
	(5, 5, 'CONNEXION', 'Utilisateur connecté avec succès', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-26 20:19:38'),
	(6, 7, 'CONNEXION', 'Utilisateur connecté avec succès', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-26 20:34:55'),
	(7, 7, 'DECONNEXION', 'Utilisateur déconnecté avec succès', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-26 21:58:36'),
	(8, 8, 'CONNEXION', 'Utilisateur connecté avec succès', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-26 21:58:53'),
	(9, 7, 'CONNEXION', 'Utilisateur connecté avec succès', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-26 23:26:00'),
	(10, 5, 'CONNEXION', 'Utilisateur connecté avec succès', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-26 23:26:19'),
	(11, 8, 'CONNEXION', 'Utilisateur connecté avec succès', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-26 23:26:55'),
	(12, 1, 'CONNEXION', 'Utilisateur connecté avec succès', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-26 23:30:12'),
	(13, 1, 'AJOUT_UTILISATEUR', 'Nouvel utilisateur: Gnr (client)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-26 23:30:47'),
	(14, 5, 'CONNEXION', 'Utilisateur connecté avec succès', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-26 23:36:36'),
	(15, 10, 'CONNEXION', 'Utilisateur connecté avec succès', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-26 23:54:10'),
	(16, 10, 'CONNEXION', 'Utilisateur connecté avec succès', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-26 23:56:09'),
	(17, 10, 'CONNEXION', 'Utilisateur connecté avec succès', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-26 23:58:08'),
	(18, 8, 'CONNEXION', 'Utilisateur connecté avec succès', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-27 00:11:51'),
	(19, 10, 'CONNEXION', 'Utilisateur connecté avec succès', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-27 00:14:11'),
	(20, 10, 'CONNEXION', 'Utilisateur connecté avec succès', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-27 00:56:59'),
	(21, 10, 'CONNEXION', 'Utilisateur connecté avec succès', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-27 00:57:09'),
	(22, 10, 'CONNEXION', 'Utilisateur connecté avec succès', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-27 00:57:53'),
	(23, 10, 'CONNEXION', 'Utilisateur connecté avec succès', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-27 00:58:17'),
	(24, 10, 'CONNEXION', 'Utilisateur connecté avec succès', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-27 00:58:54'),
	(25, 10, 'CONNEXION', 'Utilisateur connecté avec succès', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-27 01:00:13'),
	(26, 5, 'CONNEXION', 'Utilisateur connecté avec succès', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-27 01:18:24');

-- Listage de la structure de table nagex_pharma_db. utilisateurs
CREATE TABLE IF NOT EXISTS `utilisateurs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nom` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `mot_de_passe` varchar(255) NOT NULL,
  `role` enum('admin','pharmacien','stockiste','gerant','fournisseur','caissier','client') NOT NULL,
  `telephone` varchar(20) DEFAULT NULL,
  `adresse` text,
  `date_creation` datetime DEFAULT CURRENT_TIMESTAMP,
  `date_modification` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `statut` enum('actif','inactif') DEFAULT 'actif',
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_role` (`role`),
  KEY `idx_statut` (`statut`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Listage des données de la table nagex_pharma_db.utilisateurs : ~12 rows (environ)
INSERT INTO `utilisateurs` (`id`, `nom`, `email`, `mot_de_passe`, `role`, `telephone`, `adresse`, `date_creation`, `date_modification`, `statut`) VALUES
	(1, 'KASONGO LUMBALA Nathan', 'paularmimukad@gmail.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', '+243977199714', '12, KALOMBO, GAMBELA II, L\'shi, RDC', '2025-11-19 18:57:18', '2025-11-26 19:54:00', 'actif'),
	(5, 'KASOMBW MUKAD Jean-Paul', 'mukadjeanpaul@gmail.com', '$2y$12$TpKUswxqaePsdJn12RXlRufP7DJbjFQpsETcp3qan65NRgxVoLvIm', 'pharmacien', '+243977199714', NULL, '2025-11-19 22:48:50', '2025-11-26 19:25:27', 'actif'),
	(6, 'KASA KIBINGA Israel', 'ize@gmail.com', '$2y$12$onbXonlyvVofnB3AacFosOHNkdY8Y3lufMNEsPq3wTYbCmxESuaUO', 'fournisseur', '+243977199714', NULL, '2025-11-19 23:33:34', '2025-11-26 19:25:27', 'actif'),
	(7, 'MULANGU Lumiere', 'lmr@gmail.com', '$2y$12$5Mn8I37dsYNH0FaqPZGC8uV53ExjxHZF5hHrB044soCD9zpjWOMwK', 'stockiste', '+243977199714', NULL, '2025-11-19 23:55:05', '2025-11-26 19:25:27', 'actif'),
	(8, 'neville orman', 'orman@gmail.com', '$2y$12$lV.HSn1KaBodr8lYieg9J.iRDkrpopIxnmpK/.l8mbV4vqey/kJJy', 'caissier', '+243977199714', NULL, '2025-11-20 00:11:01', '2025-11-26 19:25:27', 'actif'),
	(9, 'MUTAMBA Poly', 'poly@gmail.com', '$2y$12$DSEz.hnF3V4I/sbbGDcEEu2DQI4fafwEGz5GkFGrQApCatokJdmhK', 'gerant', '+243977199714', NULL, '2025-11-20 00:39:49', '2025-11-26 19:25:27', 'actif'),
	(10, 'Gnr', 'gnr@gmail.com', '$2y$12$MP8pNQfJKHT5TJVDPrVXFO754Xoka3C2dSt6PA1JFihjleTBNIqv6', 'client', '+243977199714', 'L\'shi', '2025-11-27 01:30:47', '2025-11-26 23:30:47', 'actif'),
	(11, 'CMDC', 'Inconnu', '$2y$12$ujg.bMY9RkhH/Z9jEjG8zuzwCITZVxFyudsSwrwvXFcaSFRwhhuC2', 'fournisseur', 'Inconnu', NULL, '2025-11-27 03:20:16', '2025-11-27 01:20:16', 'actif'),
	(13, 'CMDC', 'cmdc@fournisseur.pharma', '$2y$12$Eedtoi915kMZF6VsuugxuOX.ggzEJoJZyYXOQ2mvLQBdJsI0aVGu.', 'fournisseur', 'Inconnu', NULL, '2025-11-27 03:33:25', '2025-11-27 01:33:25', 'actif'),
	(14, 'CMDC', 'cmdc.1@fournisseur.pharma', '$2y$12$Bo5Gz9viDHr0vyadmhgZY.ZX0ZTkZRQBeeyeHXJUYQqSp.y1oQYt2', 'fournisseur', 'Inconnu', NULL, '2025-11-27 03:38:41', '2025-11-27 01:38:41', 'actif'),
	(15, 'CMDC', 'test.17642075812678@fournisseur.pharma', '$2y$12$KlBiGF0x6ScQWKF2ASouqumVtcCPGYwVgXc054ss7Nhp72/Ck23g2', 'fournisseur', 'Inconnu', NULL, '2025-11-27 03:39:41', '2025-11-27 01:39:41', 'actif'),
	(16, 'CMDC', 'test.17642076134346@fournisseur.pharma', '$2y$12$jOvELxxcnRkWI6LYrgGnr.tU40ihsTeHbKYSF5mH2TFGW8/cRHb6a', 'fournisseur', 'Inconnu', NULL, '2025-11-27 03:40:14', '2025-11-27 01:40:14', 'actif');

/*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE, 'system') */;
/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES, 1) */;
