-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: habit_tracker
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `cache`
--

DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache`
--

LOCK TABLES `cache` WRITE;
/*!40000 ALTER TABLE `cache` DISABLE KEYS */;
/*!40000 ALTER TABLE `cache` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache_locks`
--

DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache_locks`
--

LOCK TABLES `cache_locks` WRITE;
/*!40000 ALTER TABLE `cache_locks` DISABLE KEYS */;
/*!40000 ALTER TABLE `cache_locks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `failed_jobs`
--

DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `failed_jobs`
--

LOCK TABLES `failed_jobs` WRITE;
/*!40000 ALTER TABLE `failed_jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `failed_jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `habit_logs`
--

DROP TABLE IF EXISTS `habit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `habit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `habit_id` bigint(20) unsigned NOT NULL,
  `checked_at` datetime DEFAULT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `date` date NOT NULL,
  `time_slot` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `status` varchar(20) NOT NULL DEFAULT 'none',
  `rating` tinyint(3) unsigned DEFAULT NULL,
  `note` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `habit_time_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `habit_logs_user_habit_time_date_unique` (`user_id`,`habit_time_id`,`date`),
  KEY `habit_logs_habit_time_id_foreign` (`habit_time_id`),
  KEY `idx_habit_logs_habit_id` (`habit_id`),
  KEY `habit_logs_user_id_index` (`user_id`),
  CONSTRAINT `habit_logs_habit_id_foreign` FOREIGN KEY (`habit_id`) REFERENCES `habits` (`id`) ON DELETE CASCADE,
  CONSTRAINT `habit_logs_habit_time_id_foreign` FOREIGN KEY (`habit_time_id`) REFERENCES `habit_times` (`id`) ON DELETE CASCADE,
  CONSTRAINT `habit_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `habit_logs`
--

LOCK TABLES `habit_logs` WRITE;
/*!40000 ALTER TABLE `habit_logs` DISABLE KEYS */;
INSERT INTO `habit_logs` VALUES (1,1,'2025-12-26 13:59:52',1,'2025-12-26',1,'none',NULL,NULL,'2025-12-26 02:50:00','2025-12-26 04:59:52',1),(2,3,'2025-12-26 14:00:04',1,'2025-12-26',0,'none',0,NULL,'2025-12-26 02:56:40','2025-12-26 05:00:04',4),(3,2,'2025-12-26 14:22:16',1,'2025-12-26',1,'none',NULL,NULL,'2025-12-26 03:47:57','2025-12-26 05:22:16',2),(4,2,'2025-12-26 16:06:35',1,'2025-12-26',4,'done',NULL,NULL,'2025-12-26 05:00:19','2025-12-26 07:06:35',3),(5,2,'2025-12-27 10:10:20',1,'2025-12-27',1,'done',NULL,NULL,'2025-12-27 01:10:20','2025-12-27 01:10:20',2),(6,1,'2026-01-05 13:52:26',1,'2026-01-05',1,'done',NULL,NULL,'2026-01-05 01:48:21','2026-01-05 04:52:26',1),(7,3,'2026-01-05 15:11:07',1,'2026-01-05',0,'done',4,NULL,'2026-01-05 01:48:23','2026-01-05 06:11:07',4),(8,4,'2026-01-05 11:15:21',1,'2026-01-05',3,'none',NULL,NULL,'2026-01-05 02:06:32','2026-01-05 02:15:21',5),(9,2,'2026-01-05 15:03:05',1,'2026-01-05',1,'none',NULL,NULL,'2026-01-05 04:08:48','2026-01-05 06:03:05',2);
/*!40000 ALTER TABLE `habit_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `habit_times`
--

DROP TABLE IF EXISTS `habit_times`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `habit_times` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `habit_id` bigint(20) unsigned NOT NULL,
  `time_slot` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `notify_time` time DEFAULT NULL,
  `remind_offset` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `habit_times_habit_id_foreign` (`habit_id`),
  CONSTRAINT `habit_times_habit_id_foreign` FOREIGN KEY (`habit_id`) REFERENCES `habits` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `habit_times`
--

LOCK TABLES `habit_times` WRITE;
/*!40000 ALTER TABLE `habit_times` DISABLE KEYS */;
INSERT INTO `habit_times` VALUES (1,1,1,NULL,0,'2025-12-26 02:49:28','2025-12-26 02:49:28'),(2,2,1,NULL,0,'2025-12-26 02:50:43','2025-12-26 02:50:43'),(3,2,4,NULL,0,'2025-12-26 02:50:43','2025-12-26 02:50:43'),(4,3,0,NULL,0,'2025-12-26 02:56:29','2025-12-26 02:56:29'),(5,4,3,NULL,0,'2025-12-26 04:04:56','2025-12-26 04:04:56');
/*!40000 ALTER TABLE `habit_times` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `habits`
--

DROP TABLE IF EXISTS `habits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `habits` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `frequency_type` varchar(255) NOT NULL,
  `days_of_week` longtext DEFAULT NULL CHECK (json_valid(`days_of_week`)),
  `target_times` longtext DEFAULT NULL CHECK (json_valid(`target_times`)),
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `archived` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `time_slot` varchar(255) NOT NULL DEFAULT 'anytime',
  `category` varchar(255) DEFAULT NULL,
  `color_tag` varchar(255) DEFAULT NULL,
  `evaluation_type` varchar(255) NOT NULL DEFAULT 'simple',
  PRIMARY KEY (`id`),
  KEY `habits_user_id_foreign` (`user_id`),
  CONSTRAINT `habits_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `habits`
--

LOCK TABLES `habits` WRITE;
/*!40000 ALTER TABLE `habits` DISABLE KEYS */;
INSERT INTO `habits` VALUES (1,1,'朝食',NULL,'daily','[]',NULL,NULL,NULL,0,'2025-12-26 02:49:28','2025-12-26 02:49:28','0',NULL,NULL,'simple'),(2,1,'歯磨き',NULL,'daily','[]',NULL,NULL,NULL,0,'2025-12-26 02:50:43','2025-12-26 02:50:43','0',NULL,NULL,'simple'),(3,1,'読書',NULL,'daily','[]',NULL,NULL,NULL,0,'2025-12-26 02:56:29','2025-12-26 02:56:29','0',NULL,NULL,'self'),(4,1,'ストレッチ',NULL,'daily','[]',NULL,NULL,NULL,0,'2025-12-26 04:04:56','2025-12-26 04:04:56','0',NULL,NULL,'simple');
/*!40000 ALTER TABLE `habits` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `job_batches`
--

DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext DEFAULT NULL,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `job_batches`
--

LOCK TABLES `job_batches` WRITE;
/*!40000 ALTER TABLE `job_batches` DISABLE KEYS */;
/*!40000 ALTER TABLE `job_batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `jobs`
--

DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` tinyint(3) unsigned NOT NULL,
  `reserved_at` int(10) unsigned DEFAULT NULL,
  `available_at` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `jobs`
--

LOCK TABLES `jobs` WRITE;
/*!40000 ALTER TABLE `jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=42 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `migrations`
--

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` VALUES (1,'0001_01_01_000000_create_users_table',1),(2,'0001_01_01_000001_create_cache_table',1),(3,'0001_01_01_000002_create_jobs_table',1),(4,'2025_08_05_023649_create_habits_table',1),(5,'2025_08_05_051830_create_habit_logs_table',1),(6,'2025_08_06_022026_add_checked_at_to_habit_logs_table',1),(7,'2025_08_07_045534_add_fields_to_habits_table',1),(8,'2025_08_07_045858_add_fields_to_users_table',1),(9,'2025_08_23_114846_adjust_habit_logs_for_timeslot',1),(10,'2025_08_25_141437_add_evaluation_type_to_habits_table',1),(11,'2025_08_25_150307_add_type_to_habits_table',1),(12,'2025_08_28_144233_remove_type_from_habits_table',1),(13,'2025_08_29_134433_change_status_to_string_in_habit_logs',1),(14,'2025_08_29_140557_create_personal_access_tokens_table',1),(15,'2025_09_13_133059_create_habit_times_table',1),(16,'2025_09_13_133441_add_habit_time_id_to_habit_logs_table',1),(17,'2025_09_13_140207_add_remind_offset_to_habit_times_table',1),(18,'2025_09_13_152612_create_remind_tasks_table',1),(19,'2025_09_13_170240_create_push_subscriptions_table',1),(20,'2025_09_17_101453_add_user_id_to_push_subscriptions_table',1),(21,'2025_09_17_102435_modify_push_subscriptions_nullable',1),(22,'2025_09_19_151440_create_push_subscriptions_table',1),(23,'2025_09_22_133110_add_user_id_to_push_subscriptions_table',1),(24,'2025_09_22_134206_drop_user_id_from_push_subscriptions_table',1),(25,'2025_09_25_111217_add_time_slot_to_habit_times_table',1),(26,'2025_09_25_111334_modify_notify_time_nullable_in_habit_times_table',1),(27,'2025_10_01_115558_alter_remind_tasks_add_skipped_status',1),(28,'2025_10_10_113826_add_indexes_to_remind_tasks_table',1),(29,'2025_10_10_114228_alter_reschedule_to_json_on_remind_tasks',1),(30,'2025_10_10_133745_add_parent_task_id_to_remind_tasks_table',1),(31,'2025_10_21_101324_update_status_enum_on_remind_tasks_table',1),(32,'2025_11_06_000001_update_habit_logs_unique',2),(33,'2025_11_06_000002_add_business_unique_to_remind_tasks',2),(34,'2025_11_08_110954_add_unique_to_remind_tasks',3),(35,'2025_11_08_155350_consolidate_push_subscriptions_table',4),(36,'2025_11_08_163241_alter_push_subscriptions_unique',5),(37,'2025_12_20_101211_drop_habit_logs_habit_date_slot_unique_index',6),(38,'2025_12_04_062445_create_personal_access_tokens_table',6),(39,'2025_12_23_102618_drop_legacy_unique_on_habit_logs',7),(40,'2025_12_26_103624_change_habit_logs_unique_to_user_habit_time_date',8),(41,'2026_01_06_134906_make_habit_time_id_not_nullable_on_habit_logs',9);
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_reset_tokens`
--

DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_reset_tokens`
--

LOCK TABLES `password_reset_tokens` WRITE;
/*!40000 ALTER TABLE `password_reset_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_reset_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `personal_access_tokens`
--

DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) NOT NULL,
  `tokenable_id` bigint(20) unsigned NOT NULL,
  `name` text NOT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` text DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  KEY `personal_access_tokens_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `personal_access_tokens`
--

LOCK TABLES `personal_access_tokens` WRITE;
/*!40000 ALTER TABLE `personal_access_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `personal_access_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `push_subscriptions`
--

DROP TABLE IF EXISTS `push_subscriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `push_subscriptions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `endpoint` varchar(500) NOT NULL,
  `endpoint_hash` char(64) NOT NULL,
  `public_key` varchar(255) DEFAULT NULL,
  `auth_token` varchar(255) DEFAULT NULL,
  `content_encoding` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `p256dh` text DEFAULT NULL,
  `auth` text DEFAULT NULL,
  `device` varchar(64) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `device_hint` varchar(255) DEFAULT NULL,
  `last_seen_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `push_subscriptions_endpoint_unique` (`endpoint`),
  UNIQUE KEY `push_user_endpoint_unique` (`user_id`,`endpoint`),
  UNIQUE KEY `ps_user_hash_unique` (`user_id`,`endpoint_hash`),
  KEY `push_subscriptions_user_id_index` (`user_id`),
  KEY `push_subscriptions_last_used_at_index` (`last_used_at`),
  CONSTRAINT `push_subscriptions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `push_subscriptions`
--

LOCK TABLES `push_subscriptions` WRITE;
/*!40000 ALTER TABLE `push_subscriptions` DISABLE KEYS */;
/*!40000 ALTER TABLE `push_subscriptions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `remind_tasks`
--

DROP TABLE IF EXISTS `remind_tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `remind_tasks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `habit_log_id` bigint(20) unsigned NOT NULL,
  `parent_task_id` bigint(20) unsigned DEFAULT NULL,
  `remind_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `reschedule` longtext DEFAULT NULL CHECK (json_valid(`reschedule`)),
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `remind_tasks_business_unique` (`habit_log_id`,`remind_at`),
  UNIQUE KEY `uq_remind_log_at` (`habit_log_id`,`remind_at`),
  KEY `remind_tasks_status_remind_at_index` (`status`,`remind_at`),
  KEY `remind_tasks_habit_log_id_index` (`habit_log_id`),
  KEY `remind_tasks_parent_task_id_index` (`parent_task_id`),
  CONSTRAINT `remind_tasks_habit_log_id_foreign` FOREIGN KEY (`habit_log_id`) REFERENCES `habit_logs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `remind_tasks_parent_task_id_foreign` FOREIGN KEY (`parent_task_id`) REFERENCES `remind_tasks` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `remind_tasks`
--

LOCK TABLES `remind_tasks` WRITE;
/*!40000 ALTER TABLE `remind_tasks` DISABLE KEYS */;
/*!40000 ALTER TABLE `remind_tasks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `role` varchar(255) NOT NULL DEFAULT 'user',
  `mode` varchar(255) NOT NULL DEFAULT 'normal',
  `daily_limit` int(11) DEFAULT NULL,
  `timezone` varchar(255) NOT NULL DEFAULT 'Asia/Tokyo',
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'awdtsc','nkmt4664@gmail.com',NULL,'$2y$12$uDLgYrtuxEYEe40Nnxjxs.tiko4rJrhhni1EWIcaXivZxBmx11LJG','hhquSSneSq24Ujf42HqLreASlrUIdkanDWXJcihPPcZJi97MVjebMji2VPlH','2025-10-28 06:27:23','2025-11-18 06:41:34','user','normal',NULL,'Asia/Tokyo'),(2,'user','user@example.com',NULL,'$2y$12$vmOLyLD4sRG0AnZRBzGhGOSnoFLTzbe.dZ3pwjE6vEq6z8J0bkNxu',NULL,'2025-11-06 05:29:33','2025-11-06 05:29:33','user','normal',NULL,'Asia/Tokyo'),(3,'staff','staff@example.com',NULL,'$2y$12$Crb1qGEQiuzrALAbavKFXul2PJZBQp6x40XaURJ60oe5y8MHFs/7C',NULL,'2025-11-06 05:29:33','2025-11-06 05:29:33','user','normal',NULL,'Asia/Tokyo'),(4,'admin','admin@example.com',NULL,'$2y$12$NUH7L3oBmqJo337IUgaPNOhsqInFoZRnnejXR620E5V0DRtB1V3ou',NULL,'2025-11-06 05:29:33','2025-11-06 05:29:33','user','normal',NULL,'Asia/Tokyo'),(5,'test1','nkmt46642@gmail.com',NULL,'$2y$12$lK.BfT1/qlEy0r5Zycninuvm5lQPu9X7CtsavPMaI/nQeJ6Y9JtVG',NULL,'2025-12-04 21:21:50','2025-12-04 21:21:50','user','normal',NULL,'Asia/Tokyo'),(6,'test2','nkmt46643@gmail.com',NULL,'$2y$12$tVa4gRNkkbCrY/jdNzUDDuxNleg6Y8xIHM5ixmRzX2ffJZg6zrojC',NULL,'2025-12-04 21:30:57','2025-12-04 21:30:57','user','normal',NULL,'Asia/Tokyo'),(7,'test3','nkmt46644@gmail.com',NULL,'$2y$12$cSghp5SnHGb47N2uZ545.eVTRpL7xBNJaBetSDtLyQAPjX9d2p1B.',NULL,'2025-12-04 21:35:18','2025-12-04 21:35:18','user','normal',NULL,'Asia/Tokyo'),(8,'test4','nkmt46645@gmail.com',NULL,'$2y$12$LYUVcWeGUxz8Z1xbBcJTU.5gSwKwVQW5YTNjNeyzDX.fnK2o64Qli',NULL,'2025-12-04 21:36:06','2025-12-04 21:36:06','user','normal',NULL,'Asia/Tokyo'),(9,'test5','nkmt46646@gmail.com',NULL,'$2y$12$JdkS3.oB4wgbCHQZjWLBveEn3/uOwzZ4Gc3eoEuvwqUMWhjyI.fK2',NULL,'2025-12-08 19:39:57','2025-12-08 19:39:57','user','normal',NULL,'Asia/Tokyo');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-01-06 14:04:27
