/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
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
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
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
  KEY `push_subscriptions_last_seen_at_index` (`last_seen_at`),
  CONSTRAINT `push_subscriptions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `push_subscriptions_backup` (
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
  KEY `push_subscriptions_last_used_at_index` (`last_used_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `remind_tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `remind_tasks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `habit_log_id` bigint(20) unsigned DEFAULT NULL,
  `habit_time_id` bigint(20) unsigned NOT NULL,
  `parent_task_id` bigint(20) unsigned DEFAULT NULL,
  `root_task_id` bigint(20) unsigned DEFAULT NULL,
  `remind_at` datetime NOT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `reschedule` longtext DEFAULT NULL CHECK (json_valid(`reschedule`)),
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `claim_token` varchar(64) DEFAULT NULL,
  `last_error` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `remind_tasks_time_unique` (`habit_time_id`,`remind_at`),
  KEY `remind_tasks_status_remind_at_index` (`status`,`remind_at`),
  KEY `remind_tasks_habit_log_id_index` (`habit_log_id`),
  KEY `remind_tasks_parent_task_id_index` (`parent_task_id`),
  KEY `rt_status_remind_at_idx` (`status`,`remind_at`),
  KEY `rt_parent_idx` (`parent_task_id`),
  KEY `rt_root_idx` (`root_task_id`),
  KEY `rt_claim_token_idx` (`claim_token`),
  KEY `rt_habit_log_idx` (`habit_log_id`),
  KEY `rt_habit_time_idx` (`habit_time_id`),
  CONSTRAINT `remind_tasks_habit_log_id_foreign` FOREIGN KEY (`habit_log_id`) REFERENCES `habit_logs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `remind_tasks_parent_task_id_foreign` FOREIGN KEY (`parent_task_id`) REFERENCES `remind_tasks` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (1,'0001_01_01_000000_create_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (2,'0001_01_01_000001_create_cache_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (3,'0001_01_01_000002_create_jobs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (4,'2025_08_05_023649_create_habits_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (5,'2025_08_05_051830_create_habit_logs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (6,'2025_08_06_022026_add_checked_at_to_habit_logs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (7,'2025_08_07_045534_add_fields_to_habits_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (8,'2025_08_07_045858_add_fields_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (9,'2025_08_23_114846_adjust_habit_logs_for_timeslot',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (10,'2025_08_25_141437_add_evaluation_type_to_habits_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (11,'2025_08_25_150307_add_type_to_habits_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (12,'2025_08_28_144233_remove_type_from_habits_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (13,'2025_08_29_134433_change_status_to_string_in_habit_logs',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (14,'2025_08_29_140557_create_personal_access_tokens_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (15,'2025_09_13_133059_create_habit_times_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (16,'2025_09_13_133441_add_habit_time_id_to_habit_logs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (17,'2025_09_13_140207_add_remind_offset_to_habit_times_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (18,'2025_09_13_152612_create_remind_tasks_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (19,'2025_09_13_170240_create_push_subscriptions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (20,'2025_09_17_101453_add_user_id_to_push_subscriptions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (21,'2025_09_17_102435_modify_push_subscriptions_nullable',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (22,'2025_09_19_151440_create_push_subscriptions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (23,'2025_09_22_133110_add_user_id_to_push_subscriptions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (24,'2025_09_22_134206_drop_user_id_from_push_subscriptions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (25,'2025_09_25_111217_add_time_slot_to_habit_times_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (26,'2025_09_25_111334_modify_notify_time_nullable_in_habit_times_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (27,'2025_10_01_115558_alter_remind_tasks_add_skipped_status',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (28,'2025_10_10_113826_add_indexes_to_remind_tasks_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (29,'2025_10_10_114228_alter_reschedule_to_json_on_remind_tasks',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (30,'2025_10_10_133745_add_parent_task_id_to_remind_tasks_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (31,'2025_10_21_101324_update_status_enum_on_remind_tasks_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (32,'2025_11_06_000001_update_habit_logs_unique',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (33,'2025_11_06_000002_add_business_unique_to_remind_tasks',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (34,'2025_11_08_110954_add_unique_to_remind_tasks',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (35,'2025_11_08_155350_consolidate_push_subscriptions_table',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (36,'2025_11_08_163241_alter_push_subscriptions_unique',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (37,'2025_12_20_101211_drop_habit_logs_habit_date_slot_unique_index',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (38,'2025_12_04_062445_create_personal_access_tokens_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (39,'2025_12_23_102618_drop_legacy_unique_on_habit_logs',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (40,'2025_12_26_103624_change_habit_logs_unique_to_user_habit_time_date',8);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (41,'2026_01_06_134906_make_habit_time_id_not_nullable_on_habit_logs',9);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (42,'2026_01_13_113923_fix_remind_at_column_on_remind_tasks',10);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (43,'2026_01_13_142042_improve_remind_tasks_for_production',11);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (44,'2026_01_15_140014_remind_tasks_add_habit_time_id',12);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (45,'2026_01_17_111120_add_attempts_to_remind_tasks_table',13);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (46,'2026_01_01_000000_create_remind_tasks_table',14);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (47,'2026_01_26_145103_create_remind_tasks_table',14);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (48,'2026_02_04_101919_enforce_unique_remind_tasks_time',15);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (49,'2026_02_09_160558_adopt_push_subscriptions_table',16);
