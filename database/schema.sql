-- Daymond Agent Commercial IA — Schema SQL
-- À importer via phpMyAdmin Infomaniak
-- Base : 4n2xy_daymond_agent

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Migrations
CREATE TABLE IF NOT EXISTS `migrations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `migration` VARCHAR(255) NOT NULL,
  `batch` INT NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Users (coordinateurs et admin)
CREATE TABLE IF NOT EXISTS `users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `role` ENUM('admin','coordinator') NOT NULL DEFAULT 'coordinator',
  `whatsapp_phone` VARCHAR(255) NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `remember_token` VARCHAR(100) NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Agents WhatsApp (un par numéro)
CREATE TABLE IF NOT EXISTS `whatsapp_agents` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `phone_number` VARCHAR(255) NOT NULL,
  `phone_number_id` VARCHAR(255) NOT NULL,
  `access_token` TEXT NOT NULL,
  `waba_id` VARCHAR(255) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `persona` JSON NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `whatsapp_agents_phone_number_unique` (`phone_number`),
  UNIQUE KEY `whatsapp_agents_phone_number_id_unique` (`phone_number_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catalogue produits
CREATE TABLE IF NOT EXISTS `products` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `brand` VARCHAR(255) NULL,
  `description` TEXT NOT NULL,
  `price` DECIMAL(12,2) NOT NULL,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'FCFA',
  `specs` JSON NULL,
  `image_url` VARCHAR(255) NULL,
  `is_available` TINYINT(1) NOT NULL DEFAULT 1,
  `stock` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Conversations WhatsApp
CREATE TABLE IF NOT EXISTS `conversations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `whatsapp_agent_id` BIGINT UNSIGNED NOT NULL,
  `customer_phone` VARCHAR(255) NOT NULL,
  `customer_name` VARCHAR(255) NULL,
  `status` ENUM('active','pending_confirmation','confirmed','transferred','abandoned','completed') NOT NULL DEFAULT 'active',
  `stage` ENUM('greeting','product_selection','customer_info','order_summary','confirmation','done') NOT NULL DEFAULT 'greeting',
  `last_message_at` TIMESTAMP NULL,
  `window_expires_at` TIMESTAMP NULL,
  `collected_data` JSON NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `conversations_customer_phone_agent_index` (`customer_phone`, `whatsapp_agent_id`),
  KEY `conversations_status_index` (`status`),
  CONSTRAINT `conversations_whatsapp_agent_id_foreign`
    FOREIGN KEY (`whatsapp_agent_id`) REFERENCES `whatsapp_agents` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Messages
CREATE TABLE IF NOT EXISTS `messages` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `conversation_id` BIGINT UNSIGNED NOT NULL,
  `direction` ENUM('inbound','outbound') NOT NULL,
  `type` ENUM('text','image','document','template','interactive') NOT NULL DEFAULT 'text',
  `content` TEXT NOT NULL,
  `whatsapp_message_id` VARCHAR(255) NULL,
  `status` ENUM('sent','delivered','read','failed') NOT NULL DEFAULT 'sent',
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `messages_whatsapp_message_id_unique` (`whatsapp_message_id`),
  KEY `messages_conversation_id_index` (`conversation_id`),
  CONSTRAINT `messages_conversation_id_foreign`
    FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Commandes
CREATE TABLE IF NOT EXISTS `orders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `reference` VARCHAR(255) NOT NULL,
  `conversation_id` BIGINT UNSIGNED NOT NULL,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `customer_name` VARCHAR(255) NOT NULL,
  `customer_phone` VARCHAR(255) NOT NULL,
  `customer_email` VARCHAR(255) NULL,
  `delivery_address` TEXT NOT NULL,
  `delivery_city` VARCHAR(255) NOT NULL,
  `total_amount` DECIMAL(12,2) NOT NULL,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'FCFA',
  `status` ENUM('pending','confirmed','processing','shipped','delivered','cancelled') NOT NULL DEFAULT 'pending',
  `assigned_coordinator_id` BIGINT UNSIGNED NULL,
  `coordinator_notes` TEXT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `orders_reference_unique` (`reference`),
  KEY `orders_status_index` (`status`),
  KEY `orders_customer_phone_index` (`customer_phone`),
  CONSTRAINT `orders_conversation_id_foreign`
    FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`),
  CONSTRAINT `orders_product_id_foreign`
    FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `orders_assigned_coordinator_id_foreign`
    FOREIGN KEY (`assigned_coordinator_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Relances planifiées
CREATE TABLE IF NOT EXISTS `followups` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `conversation_id` BIGINT UNSIGNED NOT NULL,
  `scheduled_at` TIMESTAMP NOT NULL,
  `template_name` VARCHAR(255) NOT NULL,
  `template_params` JSON NULL,
  `status` ENUM('pending','sent','failed','cancelled') NOT NULL DEFAULT 'pending',
  `sent_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `followups_status_scheduled_index` (`status`, `scheduled_at`),
  CONSTRAINT `followups_conversation_id_foreign`
    FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Queue jobs (driver: database)
CREATE TABLE IF NOT EXISTS `jobs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `queue` VARCHAR(255) NOT NULL,
  `payload` LONGTEXT NOT NULL,
  `attempts` TINYINT UNSIGNED NOT NULL,
  `reserved_at` INT UNSIGNED NULL,
  `available_at` INT UNSIGNED NOT NULL,
  `created_at` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `failed_jobs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` VARCHAR(255) NOT NULL,
  `connection` TEXT NOT NULL,
  `queue` TEXT NOT NULL,
  `payload` LONGTEXT NOT NULL,
  `exception` LONGTEXT NOT NULL,
  `failed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ================================================
-- DONNÉES INITIALES (admin + coordinateurs + produits)
-- ================================================

-- Admin (mot de passe: Daymond@2024!)
INSERT IGNORE INTO `users` (`name`, `email`, `password`, `role`, `is_active`, `created_at`, `updated_at`) VALUES
('Admin Daymond', 'admin@daymondboutique.com', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 1, NOW(), NOW());

-- Coordinateurs (mot de passe: Coord1@2024! / Coord2@2024!)
INSERT IGNORE INTO `users` (`name`, `email`, `password`, `role`, `is_active`, `created_at`, `updated_at`) VALUES
('Coordinateur 1', 'coord1@daymondboutique.com', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'coordinator', 1, NOW(), NOW()),
('Coordinateur 2', 'coord2@daymondboutique.com', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'coordinator', 1, NOW(), NOW());

-- Produits initiaux
INSERT IGNORE INTO `products` (`name`, `brand`, `description`, `price`, `currency`, `specs`, `is_available`, `stock`, `created_at`, `updated_at`) VALUES
('HP Pavilion 15', 'HP', 'Ordinateur portable idéal pour le bureau et les études.', 350000, 'FCFA', '{"RAM":"8 Go","Stockage":"512 Go SSD","Processeur":"Intel Core i5","Écran":"15.6\\""}', 1, 10, NOW(), NOW()),
('Lenovo IdeaPad 3', 'Lenovo', 'Ordinateur portable polyvalent, parfait pour un usage quotidien.', 280000, 'FCFA', '{"RAM":"8 Go","Stockage":"256 Go SSD","Processeur":"AMD Ryzen 5","Écran":"15.6\\""}', 1, 8, NOW(), NOW()),
('Dell Inspiron 15', 'Dell', 'Performances optimales pour les professionnels et entrepreneurs.', 420000, 'FCFA', '{"RAM":"16 Go","Stockage":"512 Go SSD","Processeur":"Intel Core i7","Écran":"15.6\\""}', 1, 5, NOW(), NOW()),
('Acer Aspire 5', 'Acer', 'Bon rapport qualité-prix, robuste et fiable.', 260000, 'FCFA', '{"RAM":"8 Go","Stockage":"256 Go SSD","Processeur":"Intel Core i3","Écran":"15.6\\""}', 1, 12, NOW(), NOW()),
('MacBook Air M2', 'Apple', 'Le meilleur ultrabook du marché, ultra-léger et puissant.', 950000, 'FCFA', '{"RAM":"8 Go","Stockage":"256 Go SSD","Processeur":"Apple M2","Écran":"13.6\\""}', 1, 3, NOW(), NOW());