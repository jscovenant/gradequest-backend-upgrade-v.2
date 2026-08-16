-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: gradeque_portal
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
-- Table structure for table `academic_alerts`
--

DROP TABLE IF EXISTS `academic_alerts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `academic_alerts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint(20) unsigned NOT NULL,
  `batch_id` bigint(20) unsigned DEFAULT NULL,
  `class_id` bigint(20) unsigned DEFAULT NULL,
  `teacher_id` bigint(20) unsigned DEFAULT NULL,
  `student_id` bigint(20) unsigned DEFAULT NULL,
  `subject_id` bigint(20) unsigned DEFAULT NULL,
  `type` varchar(255) NOT NULL,
  `severity` varchar(255) NOT NULL DEFAULT 'medium',
  `status` varchar(255) NOT NULL DEFAULT 'open',
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `context_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`context_json`)),
  `reviewed_by` bigint(20) unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `academic_alerts_school_id_index` (`school_id`),
  KEY `academic_alerts_batch_id_index` (`batch_id`),
  KEY `academic_alerts_class_id_index` (`class_id`),
  KEY `academic_alerts_teacher_id_index` (`teacher_id`),
  KEY `academic_alerts_student_id_index` (`student_id`),
  KEY `academic_alerts_subject_id_index` (`subject_id`),
  KEY `academic_alerts_type_index` (`type`),
  KEY `academic_alerts_severity_index` (`severity`),
  KEY `academic_alerts_status_index` (`status`),
  KEY `academic_alerts_reviewed_by_index` (`reviewed_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `academic_alerts`
--

LOCK TABLES `academic_alerts` WRITE;
/*!40000 ALTER TABLE `academic_alerts` DISABLE KEYS */;
/*!40000 ALTER TABLE `academic_alerts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `academic_sessions`
--

DROP TABLE IF EXISTS `academic_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `academic_sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'Inactive',
  `is_current` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `academic_sessions`
--

LOCK TABLES `academic_sessions` WRITE;
/*!40000 ALTER TABLE `academic_sessions` DISABLE KEYS */;
/*!40000 ALTER TABLE `academic_sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `activity_logs`
--

DROP TABLE IF EXISTS `activity_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `activity_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `school_id` bigint(20) unsigned DEFAULT NULL,
  `action` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `ip_address` varchar(255) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `activity_logs`
--

LOCK TABLES `activity_logs` WRITE;
/*!40000 ALTER TABLE `activity_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `activity_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `affective_domains`
--

DROP TABLE IF EXISTS `affective_domains`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `affective_domains` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) NOT NULL,
  `school_id` bigint(20) NOT NULL,
  `title` varchar(255) NOT NULL,
  `rate` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `affective_domains`
--

LOCK TABLES `affective_domains` WRITE;
/*!40000 ALTER TABLE `affective_domains` DISABLE KEYS */;
/*!40000 ALTER TABLE `affective_domains` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `assessment_scores_v2`
--

DROP TABLE IF EXISTS `assessment_scores_v2`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `assessment_scores_v2` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `subject_result_id` bigint(20) unsigned NOT NULL,
  `component_key` varchar(32) NOT NULL,
  `score` decimal(8,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `assessment_scores_v2_unique` (`subject_result_id`,`component_key`),
  CONSTRAINT `assessment_scores_v2_subject_result_id_foreign` FOREIGN KEY (`subject_result_id`) REFERENCES `subject_results_v2` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `assessment_scores_v2`
--

LOCK TABLES `assessment_scores_v2` WRITE;
/*!40000 ALTER TABLE `assessment_scores_v2` DISABLE KEYS */;
/*!40000 ALTER TABLE `assessment_scores_v2` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `attendance_settings`
--

DROP TABLE IF EXISTS `attendance_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `attendance_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint(20) unsigned NOT NULL,
  `staff_checkin_time` time NOT NULL DEFAULT '08:00:00',
  `grace_minutes` int(10) unsigned NOT NULL DEFAULT 10,
  `staff_checkout_time` time DEFAULT NULL,
  `absent_after_time` time DEFAULT NULL,
  `school_latitude` decimal(10,7) DEFAULT NULL,
  `school_longitude` decimal(10,7) DEFAULT NULL,
  `allowed_radius_meters` int(10) unsigned NOT NULL DEFAULT 100,
  `qr_expires_seconds` int(10) unsigned NOT NULL DEFAULT 60,
  `require_location_verification` tinyint(1) NOT NULL DEFAULT 1,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `attendance_settings_school_id_unique` (`school_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `attendance_settings`
--

LOCK TABLES `attendance_settings` WRITE;
/*!40000 ALTER TABLE `attendance_settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `attendance_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `attendances`
--

DROP TABLE IF EXISTS `attendances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `attendances` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `student_id` bigint(20) unsigned NOT NULL,
  `class_id` bigint(20) NOT NULL,
  `school_id` bigint(20) NOT NULL,
  `date` date NOT NULL,
  `status` enum('present','absent','late','excused') NOT NULL DEFAULT 'absent',
  `remarks` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `attendances_student_id_class_id_date_unique` (`student_id`,`class_id`,`date`),
  CONSTRAINT `attendances_student_id_foreign` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `attendances`
--

LOCK TABLES `attendances` WRITE;
/*!40000 ALTER TABLE `attendances` DISABLE KEYS */;
/*!40000 ALTER TABLE `attendances` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `averages`
--

DROP TABLE IF EXISTS `averages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `averages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `first_term_result_id` bigint(20) NOT NULL,
  `second_term_result_id` bigint(20) NOT NULL,
  `third_term_result_id` bigint(20) NOT NULL,
  `grade` varchar(255) DEFAULT NULL,
  `principal_comment` varchar(255) DEFAULT NULL,
  `class_teacher_comment` varchar(255) DEFAULT NULL,
  `total_average` varchar(255) DEFAULT NULL,
  `school_open` varchar(255) DEFAULT NULL,
  `school_close` varchar(255) DEFAULT NULL,
  `no_present` varchar(255) DEFAULT NULL,
  `no_absent` varchar(255) DEFAULT NULL,
  `general_remark` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `averages_user_id_foreign` (`user_id`),
  CONSTRAINT `averages_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `averages`
--

LOCK TABLES `averages` WRITE;
/*!40000 ALTER TABLE `averages` DISABLE KEYS */;
/*!40000 ALTER TABLE `averages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `biometric_ids`
--

DROP TABLE IF EXISTS `biometric_ids`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `biometric_ids` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `biometric_code` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `biometric_ids_user_id_unique` (`user_id`),
  UNIQUE KEY `biometric_ids_biometric_code_unique` (`biometric_code`),
  CONSTRAINT `biometric_ids_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `biometric_ids`
--

LOCK TABLES `biometric_ids` WRITE;
/*!40000 ALTER TABLE `biometric_ids` DISABLE KEYS */;
/*!40000 ALTER TABLE `biometric_ids` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `blogs`
--

DROP TABLE IF EXISTS `blogs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `blogs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `category` varchar(255) NOT NULL,
  `thumbnail` varchar(255) DEFAULT NULL,
  `excerpt` text DEFAULT NULL,
  `body` longtext NOT NULL,
  `youtube_url` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `blogs_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `blogs`
--

LOCK TABLES `blogs` WRITE;
/*!40000 ALTER TABLE `blogs` DISABLE KEYS */;
/*!40000 ALTER TABLE `blogs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `broadcasts`
--

DROP TABLE IF EXISTS `broadcasts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `broadcasts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint(20) unsigned DEFAULT NULL,
  `audience` enum('parents','admins','both') NOT NULL DEFAULT 'parents',
  `channel` enum('email','whatsapp','both') NOT NULL DEFAULT 'email',
  `subject` varchar(255) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `whatsapp_template_name` varchar(255) DEFAULT NULL,
  `whatsapp_lang` varchar(255) NOT NULL DEFAULT 'en_US',
  `whatsapp_params` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`whatsapp_params`)),
  `scheduled_for` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `status` enum('draft','scheduled','processing','sent','cancelled') NOT NULL DEFAULT 'scheduled',
  `sent_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `broadcasts_scheduled_for_index` (`scheduled_for`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `broadcasts`
--

LOCK TABLES `broadcasts` WRITE;
/*!40000 ALTER TABLE `broadcasts` DISABLE KEYS */;
/*!40000 ALTER TABLE `broadcasts` ENABLE KEYS */;
UNLOCK TABLES;

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
INSERT INTO `cache` VALUES ('gradequestapp@gmail.com|127.0.0.1','i:2;',1784978415),('gradequestapp@gmail.com|127.0.0.1:timer','i:1784978415;',1784978415);
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
-- Table structure for table `checkouts`
--

DROP TABLE IF EXISTS `checkouts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `checkouts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `quantity` int(10) unsigned NOT NULL,
  `price_per_unit` decimal(8,2) NOT NULL DEFAULT 100.00,
  `total_price` decimal(10,2) NOT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'pending',
  `payment_ref` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `checkouts_user_id_foreign` (`user_id`),
  CONSTRAINT `checkouts_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `checkouts`
--

LOCK TABLES `checkouts` WRITE;
/*!40000 ALTER TABLE `checkouts` DISABLE KEYS */;
/*!40000 ALTER TABLE `checkouts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `combined_fee_reminder_logs`
--

DROP TABLE IF EXISTS `combined_fee_reminder_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `combined_fee_reminder_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint(20) unsigned NOT NULL,
  `parent_id` bigint(20) unsigned NOT NULL,
  `total_balance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payload` longtext DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `read_at` timestamp NULL DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `combined_fee_reminder_logs_school_id_index` (`school_id`),
  KEY `combined_fee_reminder_logs_parent_id_index` (`parent_id`),
  KEY `combined_fee_reminder_logs_sent_at_index` (`sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `combined_fee_reminder_logs`
--

LOCK TABLES `combined_fee_reminder_logs` WRITE;
/*!40000 ALTER TABLE `combined_fee_reminder_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `combined_fee_reminder_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `correct_options`
--

DROP TABLE IF EXISTS `correct_options`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `correct_options` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `option_id` bigint(20) NOT NULL,
  `correct_option` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `correct_options`
--

LOCK TABLES `correct_options` WRITE;
/*!40000 ALTER TABLE `correct_options` DISABLE KEYS */;
/*!40000 ALTER TABLE `correct_options` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `demo_bookings`
--

DROP TABLE IF EXISTS `demo_bookings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `demo_bookings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `first_name` varchar(255) NOT NULL,
  `last_name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(255) NOT NULL,
  `role` varchar(255) NOT NULL,
  `school_name` varchar(255) NOT NULL,
  `school_type` varchar(255) NOT NULL,
  `student_count` varchar(255) NOT NULL,
  `preferred_date` date NOT NULL,
  `preferred_time` varchar(255) NOT NULL,
  `message` text DEFAULT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'pending',
  `source` varchar(255) NOT NULL DEFAULT 'website',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `demo_bookings_preferred_date_preferred_time_index` (`preferred_date`,`preferred_time`),
  KEY `demo_bookings_status_index` (`status`),
  KEY `demo_bookings_email_index` (`email`),
  KEY `demo_bookings_school_name_index` (`school_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `demo_bookings`
--

LOCK TABLES `demo_bookings` WRITE;
/*!40000 ALTER TABLE `demo_bookings` DISABLE KEYS */;
/*!40000 ALTER TABLE `demo_bookings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `departments`
--

DROP TABLE IF EXISTS `departments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `departments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `departments`
--

LOCK TABLES `departments` WRITE;
/*!40000 ALTER TABLE `departments` DISABLE KEYS */;
/*!40000 ALTER TABLE `departments` ENABLE KEYS */;
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
-- Table structure for table `feature_usages`
--

DROP TABLE IF EXISTS `feature_usages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `feature_usages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `subscription_id` bigint(20) unsigned NOT NULL,
  `feature_key` varchar(255) NOT NULL,
  `used_count` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `feature_usages`
--

LOCK TABLES `feature_usages` WRITE;
/*!40000 ALTER TABLE `feature_usages` DISABLE KEYS */;
/*!40000 ALTER TABLE `feature_usages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fee_types`
--

DROP TABLE IF EXISTS `fee_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fee_types` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint(20) unsigned NOT NULL,
  `section_id` bigint(20) unsigned NOT NULL,
  `session_id` bigint(20) unsigned NOT NULL,
  `term_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fee_types_scope_unique` (`school_id`,`section_id`,`session_id`,`term_id`,`name`),
  KEY `fee_types_section_id_foreign` (`section_id`),
  KEY `fee_types_session_id_foreign` (`session_id`),
  KEY `fee_types_term_id_foreign` (`term_id`),
  CONSTRAINT `fee_types_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `school_settings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fee_types_section_id_foreign` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fee_types_session_id_foreign` FOREIGN KEY (`session_id`) REFERENCES `academic_sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fee_types_term_id_foreign` FOREIGN KEY (`term_id`) REFERENCES `terms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fee_types`
--

LOCK TABLES `fee_types` WRITE;
/*!40000 ALTER TABLE `fee_types` DISABLE KEYS */;
/*!40000 ALTER TABLE `fee_types` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `financial_categories`
--

DROP TABLE IF EXISTS `financial_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `financial_categories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `type` enum('income','expense') NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `financial_categories_school_id_name_unique` (`school_id`,`name`),
  CONSTRAINT `financial_categories_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `school_settings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `financial_categories`
--

LOCK TABLES `financial_categories` WRITE;
/*!40000 ALTER TABLE `financial_categories` DISABLE KEYS */;
/*!40000 ALTER TABLE `financial_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `financial_records`
--

DROP TABLE IF EXISTS `financial_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `financial_records` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint(20) unsigned NOT NULL,
  `category_id` bigint(20) unsigned NOT NULL,
  `date` date NOT NULL,
  `title` varchar(255) NOT NULL,
  `type` enum('income','expense') NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `status` enum('paid','pending') NOT NULL DEFAULT 'paid',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `financial_records_category_id_foreign` (`category_id`),
  KEY `financial_records_school_id_date_index` (`school_id`,`date`),
  KEY `financial_records_school_id_type_index` (`school_id`,`type`),
  CONSTRAINT `financial_records_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `financial_categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `financial_records_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `school_settings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `financial_records`
--

LOCK TABLES `financial_records` WRITE;
/*!40000 ALTER TABLE `financial_records` DISABLE KEYS */;
/*!40000 ALTER TABLE `financial_records` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `first_term_results`
--

DROP TABLE IF EXISTS `first_term_results`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `first_term_results` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `subject_id` bigint(20) NOT NULL,
  `rollno` varchar(255) DEFAULT NULL,
  `ca` varchar(255) DEFAULT NULL,
  `exam` varchar(255) DEFAULT NULL,
  `total` varchar(255) DEFAULT NULL,
  `average` varchar(255) DEFAULT NULL,
  `grade` varchar(255) DEFAULT NULL,
  `remark` varchar(255) DEFAULT NULL,
  `comment` varchar(255) DEFAULT NULL,
  `signature` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `first_term_results_user_id_foreign` (`user_id`),
  CONSTRAINT `first_term_results_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `first_term_results`
--

LOCK TABLES `first_term_results` WRITE;
/*!40000 ALTER TABLE `first_term_results` DISABLE KEYS */;
/*!40000 ALTER TABLE `first_term_results` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `grade_settings`
--

DROP TABLE IF EXISTS `grade_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `grade_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `min` varchar(255) NOT NULL,
  `max` varchar(255) NOT NULL,
  `grade` varchar(255) NOT NULL,
  `remark` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `grade_settings`
--

LOCK TABLES `grade_settings` WRITE;
/*!40000 ALTER TABLE `grade_settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `grade_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `gradequest_billing_policies`
--

DROP TABLE IF EXISTS `gradequest_billing_policies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `gradequest_billing_policies` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `online_grace_days` int(10) unsigned NOT NULL DEFAULT 14,
  `online_minimum_coverage_percent` int(10) unsigned NOT NULL DEFAULT 70,
  `online_whole_school_block_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `online_student_level_block_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `offline_grace_days` int(10) unsigned NOT NULL DEFAULT 7,
  `offline_school_block_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `platform_fee_per_student` decimal(12,2) NOT NULL DEFAULT 1000.00,
  `legacy_subscription_honor_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `per_student_billing_starts_at` datetime DEFAULT NULL,
  `temporary_access_min_days` int(10) unsigned NOT NULL DEFAULT 3,
  `temporary_access_max_days` int(10) unsigned NOT NULL DEFAULT 7,
  `allowed_blocked_actions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`allowed_blocked_actions`)),
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `gradequest_billing_policies`
--

LOCK TABLES `gradequest_billing_policies` WRITE;
/*!40000 ALTER TABLE `gradequest_billing_policies` DISABLE KEYS */;
/*!40000 ALTER TABLE `gradequest_billing_policies` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `gradequest_invoice_payments`
--

DROP TABLE IF EXISTS `gradequest_invoice_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `gradequest_invoice_payments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint(20) unsigned NOT NULL,
  `invoice_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `reference` varchar(255) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'pending',
  `channel` varchar(255) DEFAULT NULL,
  `card_type` varchar(255) DEFAULT NULL,
  `last4` varchar(255) DEFAULT NULL,
  `paystack_id` varchar(255) DEFAULT NULL,
  `paystack_response` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`paystack_response`)),
  `paid_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `gradequest_invoice_payments_reference_unique` (`reference`),
  KEY `gradequest_invoice_payments_school_id_status_index` (`school_id`,`status`),
  KEY `gradequest_invoice_payments_invoice_id_status_index` (`invoice_id`,`status`),
  KEY `gradequest_invoice_payments_user_id_foreign` (`user_id`),
  CONSTRAINT `gradequest_invoice_payments_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `gradequest_term_invoices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `gradequest_invoice_payments_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `school_settings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `gradequest_invoice_payments_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `gradequest_invoice_payments`
--

LOCK TABLES `gradequest_invoice_payments` WRITE;
/*!40000 ALTER TABLE `gradequest_invoice_payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `gradequest_invoice_payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `gradequest_term_invoices`
--

DROP TABLE IF EXISTS `gradequest_term_invoices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `gradequest_term_invoices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint(20) unsigned NOT NULL,
  `session_id` bigint(20) unsigned NOT NULL,
  `term_id` bigint(20) unsigned NOT NULL,
  `invoice_no` varchar(255) NOT NULL,
  `billing_mode` enum('online','offline') NOT NULL DEFAULT 'offline',
  `invoice_type` varchar(255) NOT NULL DEFAULT 'term_invoice',
  `active_students_count` int(10) unsigned NOT NULL DEFAULT 0,
  `amount_due` decimal(12,2) NOT NULL DEFAULT 0.00,
  `amount_paid` decimal(12,2) NOT NULL DEFAULT 0.00,
  `balance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('draft','issued','partial','paid','overdue','cancelled') NOT NULL DEFAULT 'issued',
  `issued_at` date DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `gradequest_term_invoices_invoice_no_unique` (`invoice_no`),
  UNIQUE KEY `gq_term_invoice_type_unique` (`school_id`,`session_id`,`term_id`,`billing_mode`,`invoice_type`),
  KEY `gradequest_term_invoices_school_id_status_index` (`school_id`,`status`),
  KEY `gq_invoice_type_status_idx` (`school_id`,`invoice_type`,`status`),
  CONSTRAINT `gradequest_term_invoices_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `school_settings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `gradequest_term_invoices`
--

LOCK TABLES `gradequest_term_invoices` WRITE;
/*!40000 ALTER TABLE `gradequest_term_invoices` DISABLE KEYS */;
/*!40000 ALTER TABLE `gradequest_term_invoices` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `grading_for_juniors`
--

DROP TABLE IF EXISTS `grading_for_juniors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `grading_for_juniors` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `min` varchar(255) NOT NULL,
  `school_id` bigint(20) NOT NULL,
  `max` varchar(255) NOT NULL,
  `grade` varchar(255) NOT NULL,
  `remark` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `grading_for_juniors`
--

LOCK TABLES `grading_for_juniors` WRITE;
/*!40000 ALTER TABLE `grading_for_juniors` DISABLE KEYS */;
/*!40000 ALTER TABLE `grading_for_juniors` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `grading_for_seniors`
--

DROP TABLE IF EXISTS `grading_for_seniors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `grading_for_seniors` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `min` varchar(255) NOT NULL,
  `school_id` bigint(20) NOT NULL,
  `max` varchar(255) NOT NULL,
  `grade` varchar(255) NOT NULL,
  `remark` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `grading_for_seniors`
--

LOCK TABLES `grading_for_seniors` WRITE;
/*!40000 ALTER TABLE `grading_for_seniors` DISABLE KEYS */;
/*!40000 ALTER TABLE `grading_for_seniors` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inventories`
--

DROP TABLE IF EXISTS `inventories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `plan` varchar(255) NOT NULL,
  `duration` varchar(255) NOT NULL,
  `quantity` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventories`
--

LOCK TABLES `inventories` WRITE;
/*!40000 ALTER TABLE `inventories` DISABLE KEYS */;
/*!40000 ALTER TABLE `inventories` ENABLE KEYS */;
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
-- Table structure for table `lesson_plans`
--

DROP TABLE IF EXISTS `lesson_plans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `lesson_plans` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `subject_id` bigint(20) NOT NULL,
  `description` varchar(255) NOT NULL,
  `level_id` bigint(20) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `lesson_plans`
--

LOCK TABLES `lesson_plans` WRITE;
/*!40000 ALTER TABLE `lesson_plans` DISABLE KEYS */;
/*!40000 ALTER TABLE `lesson_plans` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `levels`
--

DROP TABLE IF EXISTS `levels`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `levels` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) DEFAULT NULL,
  `section_id` bigint(20) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `levels`
--

LOCK TABLES `levels` WRITE;
/*!40000 ALTER TABLE `levels` DISABLE KEYS */;
/*!40000 ALTER TABLE `levels` ENABLE KEYS */;
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
) ENGINE=InnoDB AUTO_INCREMENT=159 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `migrations`
--

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` VALUES (1,'0001_01_01_000000_create_users_table',1),(2,'0001_01_01_000001_create_cache_table',1),(3,'0001_01_01_000002_create_jobs_table',1),(4,'2024_08_22_132059_create_students_table',1),(5,'2024_08_22_134159_create_levels_table',1),(6,'2024_08_22_134828_create_departments_table',1),(7,'2024_08_22_135250_create_sections_table',1),(8,'2024_08_22_140010_create_academic_sessions_table',1),(9,'2024_08_24_111310_create_subjects_table',1),(10,'2024_08_24_111920_create_first_term_results_table',1),(11,'2024_08_24_111944_create_second_term_results_table',1),(12,'2024_08_24_111953_create_third_term_results_table',1),(13,'2024_08_24_113312_create_averages_table',1),(14,'2024_08_24_134134_create_subject_enrolls_table',1),(15,'2024_08_31_131748_create_teacher_enrollments_table',1),(16,'2024_09_02_120526_create_lesson_plans_table',1),(17,'2024_09_10_153748_create_quizzes_table',1),(18,'2024_09_10_155745_create_multiple_choice_questions_table',1),(19,'2024_09_10_155802_create_theory_questions_table',1),(20,'2024_09_10_160755_create_options_table',1),(21,'2024_09_11_170105_create_correct_options_table',1),(22,'2024_09_13_164614_create_quiz_responses_table',1),(23,'2024_09_13_170259_create_quiz_scores_table',1),(24,'2024_09_14_142059_create_quiz_attempts_table',1),(25,'2024_09_16_151259_create_online_admissions_table',1),(26,'2024_09_20_171858_create_settings_table',1),(27,'2024_09_21_114109_create_grade_settings_table',1),(28,'2024_09_28_145819_create_student_levels_table',1),(29,'2024_10_04_145600_create_permission_tables',1),(30,'2026_07_25_000001_add_computed_fields_to_result_v2_tables',2),(31,'2026_02_12_130228_create_assessment_scores_v2_table',3),(32,'2026_02_12_130058_create_result_batches_table',3),(33,'2026_02_12_130202_create_subject_results_v2_table',3),(34,'2026_02_12_130124_create_student_results_v2_table',3),(35,'2026_02_15_172924_add_carry_over_fields_to_subject_results_v2_table',4),(36,'2026_03_12_142225_alter_result_batches_add_monitoring_fields',5),(37,'2026_02_16_145242_add_school_and_attendance_fields_to_student_results_v2_table',5),(38,'2026_07_25_000002_ensure_result_v2_computed_fields',6),(39,'2024_11_01_133032_create_payments_table',7),(40,'2024_11_08_164636_create_inventories_table',7),(41,'2024_11_30_073740_create_password_reset_codes_table',7),(42,'2025_01_07_135430_create_affective_domains_table',7),(43,'2025_01_07_135502_create_psychomotor_domains_table',7),(44,'2025_01_07_172651_create_user_has_affective_domains_table',7),(45,'2025_01_07_172725_create_user_has_psychomotor_domains_table',7),(46,'2025_01_16_115654_create_grading_for_juniors_table',7),(47,'2025_01_16_115720_create_grading_for_seniors_table',7),(48,'2025_02_09_173224_create_show_grades_table',7),(49,'2025_03_21_151614_create_school_settings_table',8),(50,'2025_03_21_151615_add_colors_to_school_settings',8),(51,'2025_07_03_104121_create_terms_table',8),(52,'2025_07_04_152859_change_ca_column_to_json_in_third_term_results',8),(53,'2025_07_06_121217_create_student_classes_table',8),(54,'2025_07_07_175701_create_checkouts_table',8),(55,'2025_07_07_192434_create_products_table',8),(56,'2025_07_08_105514_create_wallets_table',8),(57,'2025_07_08_110017_create_wallet_transactions_table',8),(58,'2025_07_09_204906_add_status_to_terms_table',8),(59,'2025_07_09_205454_add_status_to_academic_sessions_table',8),(60,'2025_07_12_091411_add_email_verification_code_to_users_table',8),(61,'2025_07_12_115719_add_bonus_given_to_users_table',8),(62,'2025_07_12_191515_update_term_unique_constraint',8),(63,'2025_07_14_151509_add_auto_admission_to_school_settings_table',8),(64,'2025_07_14_200848_add_school_id_to_wallets_table',8),(65,'2025_07_14_201400_add_school_id_to_wallet_transactions_table',8),(66,'2025_07_17_142526_create_activity_logs_table',8),(67,'2025_07_19_064515_create_schools_logos_table',8),(68,'2025_07_20_150516_create_testimonials_table',8),(69,'2025_09_28_130550_create_attendances_table',8),(70,'2025_10_01_074303_create_teacher_attendances_table',8),(71,'2025_10_01_074509_create_qr_codes_table',8),(72,'2025_10_02_143407_create_biometric_ids_table',8),(73,'2025_10_02_143742_add_biometric_id_to_qr_codes',8),(74,'2025_10_04_111608_create_teacher_subjects_table',8),(75,'2025_10_05_175244_create_fee_types_table',8),(76,'2025_10_05_175327_create_student_fees_table',8),(77,'2025_10_05_175445_create_payments_table',8),(78,'2025_10_15_132102_create_subscriptions_table',8),(79,'2025_10_15_133751_create_subscription_plans_table',8),(80,'2025_10_17_073007_update_subscriptions_table_for_autodebit',8),(81,'2025_10_17_075436_create_sub_payments_table',8),(82,'2025_10_23_142336_add_auto_renew_source_to_subscriptions_table',8),(83,'2025_10_26_163332_create_subscription_plan_features_table',8),(84,'2025_10_27_122558_add_columns_to_subscription_plans_table',8),(85,'2025_10_27_124210_add_features_to_subscription_plans_table',8),(86,'2025_10_27_130528_add_limits_to_subscription_plan_features_table',8),(87,'2025_10_28_145357_create_feature_usages_table',8),(88,'2025_10_29_100542_add_timestamps_to_feature_usages_table',8),(89,'2025_10_29_195131_create_notifications_table',8),(90,'2025_11_03_111248_create_timetables_table',8),(91,'2025_11_03_174854_add_section_id_to_subjects_table',9),(92,'2025_11_03_195630_add_section_id_to_student_classes_table',9),(93,'2025_11_07_142926_create_payment_gateways_table',9),(94,'2025_11_07_145229_create_parent_students_table',9),(95,'2025_11_19_110904_create_payment_receipts_table',9),(96,'2025_11_30_161411_add_is_current_to_academic_sessions_table',9),(97,'2025_12_01_212510_create_blogs_table',9),(98,'2025_12_18_125103_create_result_pins_table',9),(99,'2025_12_24_144430_add_user_fk_to_averages_table',10),(100,'2025_12_24_144504_add_user_fk_to_second_term_results_table',10),(101,'2025_12_24_144522_add_user_fk_to_first_term_results_table',10),(102,'2025_12_24_144540_add_user_fk_to_third_term_results_table',10),(103,'2026_02_07_192515_add_bloodgroup_religion_nationality_to_users_table',10),(104,'2026_02_11_105537_create_financial_categories_table',10),(105,'2026_02_11_105746_create_financial_records_table',10),(106,'2026_02_14_000001_add_sort_order_to_terms_table',10),(107,'2026_02_20_120743_create_staff_attendances_table',10),(108,'2026_02_20_132511_create_attendance_settings_table',10),(109,'2026_02_20_142511_add_unique_student_to_parent_students',10),(110,'2026_02_23_200520_update_payment_gateways_for_multi_provider',10),(111,'2026_02_24_124707_create_school_bank_accounts_table',10),(112,'2026_03_02_124506_add_reminder_fields_to_subscriptions',11),(113,'2026_03_02_124507_create_broadcasts_table',11),(114,'2026_03_03_274854_add_whatsapp_settings_to_school_settings_table',11),(115,'2026_03_05_153509_add_fee_reminder_settings_to_school_settings_table',11),(116,'2026_03_06_134922_create_combined_fee_reminder_logs_table',11),(117,'2026_03_06_142510_add_read_fields_to_combined_fee_reminder_logs_table',11),(118,'2026_03_09_113053_create_demo_bookings_table',11),(119,'2026_03_12_142427_create_result_submission_monitors_table',11),(120,'2026_03_12_142523_create_academic_alerts_table',11),(121,'2026_03_12_142619_alter_school_settings_add_result_monitoring_fields',11),(122,'2026_03_13_163351_create_school_whatsapp_accounts_table',11),(123,'2026_03_13_163516_create_subscription_whatsapp_usages_table',11),(124,'2026_03_13_163615_create_whatsapp_messages_table',11),(125,'2026_03_13_163656_create_whatsapp_verifications_table',11),(126,'2026_03_13_163841_add_whatsapp_fields_to_users_table',11),(127,'2026_03_13_164027_add_whatsapp_credit_fields_to_subscription_plans_table',11),(128,'2026_03_18_172325_create_school_domains_table',11),(129,'2026_06_08_100121_add_columns_to_schools_table',11),(130,'2026_06_27_160828_create_platform_fee_charges_table',11),(131,'2026_06_27_171253_alter_payments_table_for_online_split_payments',11),(132,'2026_06_28_192632_add_paystack_subaccount_code_to_school_bank_accounts_table',11),(133,'2026_07_01_000001_add_online_payment_enabled_to_school_bank_accounts_table',11),(134,'2026_07_11_174351_add_number_of_students_to_subscriptions_and_sub_payments_tables',11),(135,'2026_07_11_211008_add_billing_cycle_count_to_subscriptions_and_sub_payments_tables',11),(136,'2026_07_16_000001_create_school_billing_foundation_tables',11),(137,'2026_07_18_000001_add_per_student_pricing_to_subscription_plans_table',11),(138,'2026_07_19_000001_repair_missing_subscription_plan_features_table',11),(139,'2026_07_19_000002_add_upgrade_credit_fields_to_sub_payments_table',11),(140,'2026_07_19_000003_add_subscription_snapshot_fields_to_sub_payments_table',11),(141,'2026_07_19_000004_repair_missing_paid_at_on_sub_payments_table',11),(142,'2026_07_20_000001_allow_subscription_payment_entitlement_source',11),(143,'2026_07_21_000001_create_public_fee_payment_intents_table',11),(144,'2026_07_21_000002_add_email_to_payments_table_if_missing',11),(145,'2026_07_21_000003_create_gradequest_invoice_payments_table',11),(146,'2026_07_22_000004_create_gradequest_billing_policies_table',11),(147,'2026_07_22_000005_create_school_billing_temporary_accesses_table',11),(148,'2026_07_22_000006_add_dates_to_terms_table',11),(149,'2026_07_22_000007_create_school_billing_periods_table',11),(150,'2026_07_22_000008_add_suspicious_flags_to_school_billing_periods_table',11),(151,'2026_07_22_000009_add_invoice_type_to_gradequest_term_invoices_table',11),(152,'2026_07_22_000010_update_gradequest_invoice_unique_for_invoice_type',11),(153,'2026_07_24_000001_add_legacy_subscription_policy_fields',11),(154,'2026_07_24_000002_add_live_qr_staff_attendance',11),(155,'2026_07_24_000003_add_token_to_staff_attendance_sessions',11),(156,'2026_07_24_000004_extend_staff_qr_expiry_default',11),(157,'2026_07_25_000003_create_personal_access_tokens_table',12),(158,'2026_07_25_000004_repair_users_portal_columns',13);
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `model_has_permissions`
--

DROP TABLE IF EXISTS `model_has_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `model_has_permissions` (
  `permission_id` bigint(20) unsigned NOT NULL,
  `model_type` varchar(255) NOT NULL,
  `model_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`permission_id`,`model_id`,`model_type`),
  KEY `model_has_permissions_model_id_model_type_index` (`model_id`,`model_type`),
  CONSTRAINT `model_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `model_has_permissions`
--

LOCK TABLES `model_has_permissions` WRITE;
/*!40000 ALTER TABLE `model_has_permissions` DISABLE KEYS */;
/*!40000 ALTER TABLE `model_has_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `model_has_roles`
--

DROP TABLE IF EXISTS `model_has_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `model_has_roles` (
  `role_id` bigint(20) unsigned NOT NULL,
  `model_type` varchar(255) NOT NULL,
  `model_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`role_id`,`model_id`,`model_type`),
  KEY `model_has_roles_model_id_model_type_index` (`model_id`,`model_type`),
  CONSTRAINT `model_has_roles_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `model_has_roles`
--

LOCK TABLES `model_has_roles` WRITE;
/*!40000 ALTER TABLE `model_has_roles` DISABLE KEYS */;
/*!40000 ALTER TABLE `model_has_roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `multiple_choice_questions`
--

DROP TABLE IF EXISTS `multiple_choice_questions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `multiple_choice_questions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `multiple_choice_questions`
--

LOCK TABLES `multiple_choice_questions` WRITE;
/*!40000 ALTER TABLE `multiple_choice_questions` DISABLE KEYS */;
/*!40000 ALTER TABLE `multiple_choice_questions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notifications` (
  `id` char(36) NOT NULL,
  `type` varchar(255) NOT NULL,
  `notifiable_type` varchar(255) NOT NULL,
  `notifiable_id` bigint(20) unsigned NOT NULL,
  `data` text NOT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notifications_notifiable_type_notifiable_id_index` (`notifiable_type`,`notifiable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notifications`
--

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `online_admissions`
--

DROP TABLE IF EXISTS `online_admissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `online_admissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `level_id` bigint(20) NOT NULL,
  `department_id` bigint(20) NOT NULL,
  `firstname` varchar(255) DEFAULT NULL,
  `surname` varchar(255) DEFAULT NULL,
  `dob` varchar(255) DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `reference` varchar(255) DEFAULT NULL,
  `sex` varchar(255) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(255) DEFAULT NULL,
  `status` varchar(255) DEFAULT NULL,
  `parent_first_name` varchar(255) DEFAULT NULL,
  `parent_last_name` varchar(255) DEFAULT NULL,
  `parent_address` varchar(255) DEFAULT NULL,
  `parent_phone` varchar(255) DEFAULT NULL,
  `parent_photo` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `online_admissions`
--

LOCK TABLES `online_admissions` WRITE;
/*!40000 ALTER TABLE `online_admissions` DISABLE KEYS */;
/*!40000 ALTER TABLE `online_admissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `options`
--

DROP TABLE IF EXISTS `options`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `options` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `question_id` bigint(20) unsigned NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `options_question_id_foreign` (`question_id`),
  CONSTRAINT `options_question_id_foreign` FOREIGN KEY (`question_id`) REFERENCES `multiple_choice_questions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `options`
--

LOCK TABLES `options` WRITE;
/*!40000 ALTER TABLE `options` DISABLE KEYS */;
/*!40000 ALTER TABLE `options` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `parent_students`
--

DROP TABLE IF EXISTS `parent_students`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `parent_students` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `parent_id` bigint(20) unsigned NOT NULL,
  `student_id` bigint(20) unsigned NOT NULL,
  `school_id` bigint(20) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `parent_students_parent_id_student_id_unique` (`parent_id`,`student_id`),
  UNIQUE KEY `parent_students_student_id_unique` (`student_id`),
  CONSTRAINT `parent_students_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `parent_students_student_id_foreign` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `parent_students`
--

LOCK TABLES `parent_students` WRITE;
/*!40000 ALTER TABLE `parent_students` DISABLE KEYS */;
/*!40000 ALTER TABLE `parent_students` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_reset_codes`
--

DROP TABLE IF EXISTS `password_reset_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_reset_codes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `reset_code` varchar(255) NOT NULL,
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `password_reset_codes_reset_code_unique` (`reset_code`),
  KEY `password_reset_codes_email_index` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_reset_codes`
--

LOCK TABLES `password_reset_codes` WRITE;
/*!40000 ALTER TABLE `password_reset_codes` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_reset_codes` ENABLE KEYS */;
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
-- Table structure for table `payment_gateways`
--

DROP TABLE IF EXISTS `payment_gateways`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payment_gateways` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint(20) unsigned NOT NULL,
  `provider` varchar(50) DEFAULT NULL,
  `mode` enum('test','live') NOT NULL DEFAULT 'test',
  `public_key` varchar(255) DEFAULT NULL,
  `secret_key` varchar(255) DEFAULT NULL,
  `webhook_secret` varchar(255) DEFAULT NULL,
  `merchant_email` varchar(255) DEFAULT NULL,
  `channels` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`channels`)),
  `currency` varchar(10) NOT NULL DEFAULT 'NGN',
  `country` varchar(2) NOT NULL DEFAULT 'NG',
  `payment_url` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`config`)),
  `last_verified_at` timestamp NULL DEFAULT NULL,
  `last_error` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `pg_school_provider_mode_idx` (`school_id`,`provider`,`mode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payment_gateways`
--

LOCK TABLES `payment_gateways` WRITE;
/*!40000 ALTER TABLE `payment_gateways` DISABLE KEYS */;
/*!40000 ALTER TABLE `payment_gateways` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payment_receipts`
--

DROP TABLE IF EXISTS `payment_receipts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payment_receipts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `student_id` bigint(20) unsigned NOT NULL,
  `school_id` bigint(20) unsigned NOT NULL,
  `payment_id` bigint(20) unsigned DEFAULT NULL,
  `payment_method` varchar(255) NOT NULL,
  `receipt_path` varchar(255) NOT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `payment_receipts_student_id_index` (`student_id`),
  KEY `payment_receipts_school_id_index` (`school_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payment_receipts`
--

LOCK TABLES `payment_receipts` WRITE;
/*!40000 ALTER TABLE `payment_receipts` DISABLE KEYS */;
/*!40000 ALTER TABLE `payment_receipts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payments`
--

DROP TABLE IF EXISTS `payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `student_fee_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `school_id` bigint(20) unsigned NOT NULL,
  `reference` varchar(255) NOT NULL,
  `paystack_response` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`paystack_response`)),
  `received_by` bigint(20) unsigned DEFAULT NULL,
  `paid_by` bigint(20) unsigned DEFAULT NULL,
  `payment_id` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `platform_fee` decimal(10,0) NOT NULL DEFAULT 0,
  `payment_method` varchar(255) DEFAULT NULL,
  `status` enum('pending','success','failed') NOT NULL DEFAULT 'success',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `payments_user_id_foreign` (`user_id`),
  KEY `payments_student_fee_id_foreign` (`student_fee_id`),
  KEY `payments_received_by_foreign` (`received_by`),
  CONSTRAINT `payments_received_by_foreign` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payments_student_fee_id_foreign` FOREIGN KEY (`student_fee_id`) REFERENCES `student_fees` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payments_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payments`
--

LOCK TABLES `payments` WRITE;
/*!40000 ALTER TABLE `payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `permissions`
--

DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `permissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `guard_name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `permissions_name_guard_name_unique` (`name`,`guard_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `permissions`
--

LOCK TABLES `permissions` WRITE;
/*!40000 ALTER TABLE `permissions` DISABLE KEYS */;
/*!40000 ALTER TABLE `permissions` ENABLE KEYS */;
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
  `name` varchar(255) NOT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` text DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`)
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
-- Table structure for table `platform_fee_charges`
--

DROP TABLE IF EXISTS `platform_fee_charges`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `platform_fee_charges` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `student_fee_id` bigint(20) unsigned NOT NULL,
  `status` enum('pending','confirmed') NOT NULL DEFAULT 'pending',
  `paystack_reference` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `platform_fee_charges_student_fee_id_unique` (`student_fee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `platform_fee_charges`
--

LOCK TABLES `platform_fee_charges` WRITE;
/*!40000 ALTER TABLE `platform_fee_charges` DISABLE KEYS */;
/*!40000 ALTER TABLE `platform_fee_charges` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `products`
--

DROP TABLE IF EXISTS `products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `products` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `price` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `products`
--

LOCK TABLES `products` WRITE;
/*!40000 ALTER TABLE `products` DISABLE KEYS */;
/*!40000 ALTER TABLE `products` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `psychomotor_domains`
--

DROP TABLE IF EXISTS `psychomotor_domains`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `psychomotor_domains` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) NOT NULL,
  `school_id` bigint(20) NOT NULL,
  `title` varchar(255) NOT NULL,
  `rate` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `psychomotor_domains`
--

LOCK TABLES `psychomotor_domains` WRITE;
/*!40000 ALTER TABLE `psychomotor_domains` DISABLE KEYS */;
/*!40000 ALTER TABLE `psychomotor_domains` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `public_fee_payment_intents`
--

DROP TABLE IF EXISTS `public_fee_payment_intents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `public_fee_payment_intents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint(20) unsigned NOT NULL,
  `student_id` bigint(20) unsigned NOT NULL,
  `school_code` varchar(80) NOT NULL,
  `student_reg_no` varchar(80) NOT NULL,
  `reference` varchar(255) NOT NULL,
  `payer_email` varchar(255) DEFAULT NULL,
  `payer_name` varchar(255) DEFAULT NULL,
  `payer_phone` varchar(40) DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL,
  `platform_fee` decimal(12,2) NOT NULL DEFAULT 0.00,
  `allocations` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`allocations`)),
  `status` enum('pending','success','failed') NOT NULL DEFAULT 'pending',
  `paystack_response` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`paystack_response`)),
  `paid_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `public_fee_payment_intents_reference_unique` (`reference`),
  KEY `public_fee_payment_intents_student_id_foreign` (`student_id`),
  KEY `public_fee_payment_intents_school_id_student_id_status_index` (`school_id`,`student_id`,`status`),
  KEY `public_fee_payment_intents_school_code_index` (`school_code`),
  KEY `public_fee_payment_intents_student_reg_no_index` (`student_reg_no`),
  CONSTRAINT `public_fee_payment_intents_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `school_settings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `public_fee_payment_intents_student_id_foreign` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `public_fee_payment_intents`
--

LOCK TABLES `public_fee_payment_intents` WRITE;
/*!40000 ALTER TABLE `public_fee_payment_intents` DISABLE KEYS */;
/*!40000 ALTER TABLE `public_fee_payment_intents` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `qr_codes`
--

DROP TABLE IF EXISTS `qr_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `qr_codes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `biometric_id` bigint(20) unsigned DEFAULT NULL,
  `token` varchar(255) NOT NULL,
  `type` enum('teacher_attendance') NOT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `qr_codes_token_unique` (`token`),
  KEY `qr_codes_biometric_id_foreign` (`biometric_id`),
  CONSTRAINT `qr_codes_biometric_id_foreign` FOREIGN KEY (`biometric_id`) REFERENCES `biometric_ids` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `qr_codes`
--

LOCK TABLES `qr_codes` WRITE;
/*!40000 ALTER TABLE `qr_codes` DISABLE KEYS */;
/*!40000 ALTER TABLE `qr_codes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `quiz_attempts`
--

DROP TABLE IF EXISTS `quiz_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `quiz_attempts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) NOT NULL,
  `quiz_id` bigint(20) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `quiz_attempts`
--

LOCK TABLES `quiz_attempts` WRITE;
/*!40000 ALTER TABLE `quiz_attempts` DISABLE KEYS */;
/*!40000 ALTER TABLE `quiz_attempts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `quiz_responses`
--

DROP TABLE IF EXISTS `quiz_responses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `quiz_responses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) NOT NULL,
  `option_id` varchar(255) NOT NULL,
  `question_id` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `quiz_responses`
--

LOCK TABLES `quiz_responses` WRITE;
/*!40000 ALTER TABLE `quiz_responses` DISABLE KEYS */;
/*!40000 ALTER TABLE `quiz_responses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `quiz_scores`
--

DROP TABLE IF EXISTS `quiz_scores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `quiz_scores` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) NOT NULL,
  `score` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `quiz_scores`
--

LOCK TABLES `quiz_scores` WRITE;
/*!40000 ALTER TABLE `quiz_scores` DISABLE KEYS */;
/*!40000 ALTER TABLE `quiz_scores` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `quizzes`
--

DROP TABLE IF EXISTS `quizzes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `quizzes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) DEFAULT NULL,
  `type` varchar(255) DEFAULT NULL,
  `repitions` tinyint(1) NOT NULL DEFAULT 0,
  `duration` int(11) DEFAULT NULL COMMENT 'Duration in minutes',
  `subject_id` varchar(255) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `question_id` bigint(20) NOT NULL,
  `scheduled_at` datetime DEFAULT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `quizzes`
--

LOCK TABLES `quizzes` WRITE;
/*!40000 ALTER TABLE `quizzes` DISABLE KEYS */;
/*!40000 ALTER TABLE `quizzes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `result_batches`
--

DROP TABLE IF EXISTS `result_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `result_batches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint(20) unsigned NOT NULL,
  `class_id` int(11) NOT NULL,
  `term` varchar(255) NOT NULL,
  `session` varchar(255) NOT NULL,
  `submission_deadline` date DEFAULT NULL,
  `status` enum('draft','computed','approved','published') NOT NULL DEFAULT 'draft',
  `computed_at` timestamp NULL DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `published_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `result_batches_unique` (`school_id`,`class_id`,`term`,`session`),
  KEY `result_batches_school_id_index` (`school_id`),
  KEY `result_batches_class_id_index` (`class_id`),
  KEY `result_batches_term_index` (`term`),
  KEY `result_batches_session_index` (`session`),
  KEY `result_batches_created_by_index` (`created_by`),
  KEY `result_batches_submission_deadline_index` (`submission_deadline`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `result_batches`
--

LOCK TABLES `result_batches` WRITE;
/*!40000 ALTER TABLE `result_batches` DISABLE KEYS */;
/*!40000 ALTER TABLE `result_batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `result_pins`
--

DROP TABLE IF EXISTS `result_pins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `result_pins` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint(20) unsigned NOT NULL,
  `pin` varchar(255) NOT NULL,
  `term` varchar(255) NOT NULL,
  `session` varchar(255) NOT NULL,
  `max_uses` int(11) NOT NULL DEFAULT 1,
  `used_count` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `result_pins_pin_unique` (`pin`),
  KEY `result_pins_school_id_foreign` (`school_id`),
  CONSTRAINT `result_pins_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `school_settings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `result_pins`
--

LOCK TABLES `result_pins` WRITE;
/*!40000 ALTER TABLE `result_pins` DISABLE KEYS */;
/*!40000 ALTER TABLE `result_pins` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `result_submission_monitors`
--

DROP TABLE IF EXISTS `result_submission_monitors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `result_submission_monitors` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint(20) unsigned NOT NULL,
  `batch_id` bigint(20) unsigned NOT NULL,
  `class_id` bigint(20) unsigned NOT NULL,
  `teacher_id` bigint(20) unsigned DEFAULT NULL,
  `term` varchar(255) NOT NULL,
  `session` varchar(255) NOT NULL,
  `expected_students_count` int(10) unsigned NOT NULL DEFAULT 0,
  `completed_students_count` int(10) unsigned NOT NULL DEFAULT 0,
  `pending_students_count` int(10) unsigned NOT NULL DEFAULT 0,
  `expected_subject_rows_count` int(10) unsigned NOT NULL DEFAULT 0,
  `completed_subject_rows_count` int(10) unsigned NOT NULL DEFAULT 0,
  `submission_deadline` date DEFAULT NULL,
  `status` enum('pending','partial','complete','overdue') NOT NULL DEFAULT 'pending',
  `last_teacher_reminder_sent_at` timestamp NULL DEFAULT NULL,
  `last_admin_reminder_sent_at` timestamp NULL DEFAULT NULL,
  `last_scanned_at` timestamp NULL DEFAULT NULL,
  `meta_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta_json`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `result_submission_monitors_batch_id_unique` (`batch_id`),
  KEY `result_submission_monitors_school_id_index` (`school_id`),
  KEY `result_submission_monitors_batch_id_index` (`batch_id`),
  KEY `result_submission_monitors_class_id_index` (`class_id`),
  KEY `result_submission_monitors_teacher_id_index` (`teacher_id`),
  KEY `result_submission_monitors_submission_deadline_index` (`submission_deadline`),
  KEY `result_submission_monitors_status_index` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `result_submission_monitors`
--

LOCK TABLES `result_submission_monitors` WRITE;
/*!40000 ALTER TABLE `result_submission_monitors` DISABLE KEYS */;
/*!40000 ALTER TABLE `result_submission_monitors` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `role_has_permissions`
--

DROP TABLE IF EXISTS `role_has_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `role_has_permissions` (
  `permission_id` bigint(20) unsigned NOT NULL,
  `role_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`permission_id`,`role_id`),
  KEY `role_has_permissions_role_id_foreign` (`role_id`),
  CONSTRAINT `role_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_has_permissions_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `role_has_permissions`
--

LOCK TABLES `role_has_permissions` WRITE;
/*!40000 ALTER TABLE `role_has_permissions` DISABLE KEYS */;
/*!40000 ALTER TABLE `role_has_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `roles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `guard_name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `roles_name_guard_name_unique` (`name`,`guard_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roles`
--

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `school_bank_accounts`
--

DROP TABLE IF EXISTS `school_bank_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `school_bank_accounts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint(20) unsigned NOT NULL,
  `bank_name` varchar(255) NOT NULL,
  `bank_code` varchar(20) DEFAULT NULL,
  `account_name` varchar(255) NOT NULL,
  `account_number` varchar(20) NOT NULL,
  `paystack_subaccount_code` varchar(255) DEFAULT NULL,
  `online_payment_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `currency` varchar(8) NOT NULL DEFAULT 'NGN',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `school_bank_accounts_school_id_is_active_index` (`school_id`,`is_active`),
  CONSTRAINT `school_bank_accounts_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `school_settings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `school_bank_accounts`
--

LOCK TABLES `school_bank_accounts` WRITE;
/*!40000 ALTER TABLE `school_bank_accounts` DISABLE KEYS */;
/*!40000 ALTER TABLE `school_bank_accounts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `school_billing_audit_logs`
--

DROP TABLE IF EXISTS `school_billing_audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `school_billing_audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint(20) unsigned NOT NULL,
  `actor_id` bigint(20) unsigned DEFAULT NULL,
  `action` varchar(255) NOT NULL,
  `auditable_type` varchar(255) DEFAULT NULL,
  `auditable_id` bigint(20) unsigned DEFAULT NULL,
  `before` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`before`)),
  `after` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`after`)),
  `reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `school_billing_audit_logs_school_id_action_index` (`school_id`,`action`),
  CONSTRAINT `school_billing_audit_logs_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `school_settings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `school_billing_audit_logs`
--

LOCK TABLES `school_billing_audit_logs` WRITE;
/*!40000 ALTER TABLE `school_billing_audit_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `school_billing_audit_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `school_billing_periods`
--

DROP TABLE IF EXISTS `school_billing_periods`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `school_billing_periods` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint(20) unsigned NOT NULL,
  `session_id` bigint(20) unsigned NOT NULL,
  `term_id` bigint(20) unsigned NOT NULL,
  `academic_start_date` date DEFAULT NULL,
  `billing_started_at` datetime NOT NULL,
  `billing_grace_ends_at` datetime DEFAULT NULL,
  `term_activated_at` datetime DEFAULT NULL,
  `first_protected_activity_at` datetime DEFAULT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'active',
  `source` varchar(255) NOT NULL DEFAULT 'system',
  `locked_at` datetime DEFAULT NULL,
  `locked_by` bigint(20) unsigned DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `suspicious_flags` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`suspicious_flags`)),
  `flagged_at` datetime DEFAULT NULL,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `school_billing_period_unique` (`school_id`,`session_id`,`term_id`),
  KEY `school_billing_period_status_idx` (`school_id`,`status`),
  CONSTRAINT `school_billing_periods_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `school_settings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `school_billing_periods`
--

LOCK TABLES `school_billing_periods` WRITE;
/*!40000 ALTER TABLE `school_billing_periods` DISABLE KEYS */;
/*!40000 ALTER TABLE `school_billing_periods` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `school_billing_settings`
--

DROP TABLE IF EXISTS `school_billing_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `school_billing_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint(20) unsigned NOT NULL,
  `payment_mode` enum('online','offline') NOT NULL DEFAULT 'offline',
  `grace_days` int(10) unsigned NOT NULL DEFAULT 7,
  `platform_fee_per_student` decimal(10,2) NOT NULL DEFAULT 1000.00,
  `block_results_when_unpaid` tinyint(1) NOT NULL DEFAULT 1,
  `switched_at` timestamp NULL DEFAULT NULL,
  `switched_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `school_billing_settings_school_id_unique` (`school_id`),
  CONSTRAINT `school_billing_settings_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `school_settings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `school_billing_settings`
--

LOCK TABLES `school_billing_settings` WRITE;
/*!40000 ALTER TABLE `school_billing_settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `school_billing_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `school_billing_temporary_accesses`
--

DROP TABLE IF EXISTS `school_billing_temporary_accesses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `school_billing_temporary_accesses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint(20) unsigned NOT NULL,
  `scope` varchar(255) NOT NULL DEFAULT 'school_crud',
  `status` varchar(255) NOT NULL DEFAULT 'active',
  `starts_at` datetime DEFAULT NULL,
  `ends_at` datetime NOT NULL,
  `granted_by` bigint(20) unsigned DEFAULT NULL,
  `revoked_by` bigint(20) unsigned DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `school_temp_access_status_idx` (`school_id`,`scope`,`status`),
  KEY `school_temp_access_expiry_idx` (`ends_at`,`status`),
  CONSTRAINT `school_billing_temporary_accesses_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `school_settings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `school_billing_temporary_accesses`
--

LOCK TABLES `school_billing_temporary_accesses` WRITE;
/*!40000 ALTER TABLE `school_billing_temporary_accesses` DISABLE KEYS */;
/*!40000 ALTER TABLE `school_billing_temporary_accesses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `school_domains`
--

DROP TABLE IF EXISTS `school_domains`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `school_domains` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint(20) unsigned NOT NULL,
  `domain` varchar(255) NOT NULL,
  `type` varchar(255) NOT NULL DEFAULT 'custom',
  `status` varchar(255) NOT NULL DEFAULT 'pending',
  `verification_token` varchar(255) DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `school_domains_domain_unique` (`domain`),
  KEY `school_domains_school_id_foreign` (`school_id`),
  CONSTRAINT `school_domains_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `school_settings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `school_domains`
--

LOCK TABLES `school_domains` WRITE;
/*!40000 ALTER TABLE `school_domains` DISABLE KEYS */;
/*!40000 ALTER TABLE `school_domains` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `school_settings`
--

DROP TABLE IF EXISTS `school_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `school_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `school_name` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(255) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `primary_color` varchar(255) NOT NULL DEFAULT '#0d47a1',
  `secondary_color` varchar(255) NOT NULL DEFAULT '#1976d2',
  `background_color` varchar(255) NOT NULL DEFAULT '#e3f2fd',
  `fee_reminders_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `fee_reminder_interval_days` int(10) unsigned NOT NULL DEFAULT 5,
  `fee_reminder_max_count` int(10) unsigned NOT NULL DEFAULT 6,
  `fee_reminder_send_email` tinyint(1) NOT NULL DEFAULT 1,
  `fee_reminder_send_whatsapp` tinyint(1) NOT NULL DEFAULT 0,
  `fee_reminder_quiet_hours_start` varchar(255) DEFAULT NULL,
  `fee_reminder_quiet_hours_end` varchar(255) DEFAULT NULL,
  `prefix` varchar(10) DEFAULT NULL,
  `auto_admission` tinyint(1) NOT NULL DEFAULT 0,
  `whatsapp_enabled` tinyint(4) NOT NULL DEFAULT 0,
  `whatsapp_fee_reminders` tinyint(4) NOT NULL DEFAULT 0,
  `whatsapp_activity_notices` tinyint(4) NOT NULL DEFAULT 0,
  `whatsapp_subscription_reminders` tinyint(4) NOT NULL DEFAULT 0,
  `school_subdomain` varchar(255) DEFAULT NULL,
  `custom_domain` varchar(255) DEFAULT NULL,
  `logo` varchar(255) DEFAULT NULL,
  `principal_signature` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `enable_result_monitoring` tinyint(1) NOT NULL DEFAULT 1,
  `submission_reminder_days_before` tinyint(3) unsigned NOT NULL DEFAULT 3,
  `minimum_history_records_for_outlier` tinyint(3) unsigned NOT NULL DEFAULT 2,
  `student_drop_alert_threshold` decimal(8,2) NOT NULL DEFAULT 35.00,
  `uniformity_stddev_threshold` decimal(8,2) NOT NULL DEFAULT 3.00,
  `uniformity_range_threshold` decimal(8,2) NOT NULL DEFAULT 5.00,
  `block_publish_on_high_alert` tinyint(1) NOT NULL DEFAULT 0,
  `whatsapp_monthly_limit` int(11) NOT NULL DEFAULT 0,
  `whatsapp_messages_sent` int(11) NOT NULL DEFAULT 0,
  `whatsapp_usage_reset_date` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `school_settings_school_subdomain_unique` (`school_subdomain`),
  UNIQUE KEY `school_settings_custom_domain_unique` (`custom_domain`),
  KEY `school_settings_user_id_index` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `school_settings`
--

LOCK TABLES `school_settings` WRITE;
/*!40000 ALTER TABLE `school_settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `school_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `school_whatsapp_accounts`
--

DROP TABLE IF EXISTS `school_whatsapp_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `school_whatsapp_accounts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint(20) unsigned NOT NULL,
  `admin_user_id` bigint(20) unsigned DEFAULT NULL,
  `phone_number_id` varchar(255) NOT NULL,
  `display_phone_number` varchar(255) DEFAULT NULL,
  `verified_name` varchar(255) DEFAULT NULL,
  `status` enum('pending','active','disconnected','suspended') NOT NULL DEFAULT 'pending',
  `connected_at` timestamp NULL DEFAULT NULL,
  `last_health_check_at` timestamp NULL DEFAULT NULL,
  `meta_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta_payload`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `school_whatsapp_accounts_school_id_unique` (`school_id`),
  UNIQUE KEY `school_whatsapp_accounts_phone_number_id_unique` (`phone_number_id`),
  KEY `school_whatsapp_accounts_admin_user_id_index` (`admin_user_id`),
  KEY `school_whatsapp_accounts_status_index` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `school_whatsapp_accounts`
--

LOCK TABLES `school_whatsapp_accounts` WRITE;
/*!40000 ALTER TABLE `school_whatsapp_accounts` DISABLE KEYS */;
/*!40000 ALTER TABLE `school_whatsapp_accounts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `schools_logos`
--

DROP TABLE IF EXISTS `schools_logos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `schools_logos` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) DEFAULT NULL,
  `logo_url` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `schools_logos`
--

LOCK TABLES `schools_logos` WRITE;
/*!40000 ALTER TABLE `schools_logos` DISABLE KEYS */;
/*!40000 ALTER TABLE `schools_logos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `second_term_results`
--

DROP TABLE IF EXISTS `second_term_results`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `second_term_results` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `subject_id` bigint(20) NOT NULL,
  `rollno` varchar(255) DEFAULT NULL,
  `ca` varchar(255) DEFAULT NULL,
  `exam` varchar(255) DEFAULT NULL,
  `firstterm` varchar(255) DEFAULT NULL,
  `total` varchar(255) DEFAULT NULL,
  `average` varchar(255) DEFAULT NULL,
  `grade` varchar(255) DEFAULT NULL,
  `remark` varchar(255) DEFAULT NULL,
  `comment` varchar(255) DEFAULT NULL,
  `signature` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `second_term_results_user_id_foreign` (`user_id`),
  CONSTRAINT `second_term_results_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `second_term_results`
--

LOCK TABLES `second_term_results` WRITE;
/*!40000 ALTER TABLE `second_term_results` DISABLE KEYS */;
/*!40000 ALTER TABLE `second_term_results` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sections`
--

DROP TABLE IF EXISTS `sections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sections` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sections`
--

LOCK TABLES `sections` WRITE;
/*!40000 ALTER TABLE `sections` DISABLE KEYS */;
/*!40000 ALTER TABLE `sections` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sessions`
--

DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sessions`
--

LOCK TABLES `sessions` WRITE;
/*!40000 ALTER TABLE `sessions` DISABLE KEYS */;
/*!40000 ALTER TABLE `sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `settings`
--

DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `address` varchar(255) NOT NULL,
  `phone` varchar(255) NOT NULL,
  `website` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `settings`
--

LOCK TABLES `settings` WRITE;
/*!40000 ALTER TABLE `settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `show_grades`
--

DROP TABLE IF EXISTS `show_grades`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `show_grades` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint(20) NOT NULL,
  `junior` tinyint(1) NOT NULL DEFAULT 1,
  `senior` tinyint(1) NOT NULL DEFAULT 1,
  `primary` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `show_grades`
--

LOCK TABLES `show_grades` WRITE;
/*!40000 ALTER TABLE `show_grades` DISABLE KEYS */;
/*!40000 ALTER TABLE `show_grades` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `staff_attendance_sessions`
--

DROP TABLE IF EXISTS `staff_attendance_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `staff_attendance_sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint(20) unsigned NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `token` varchar(96) DEFAULT NULL,
  `mode` enum('auto','checkin','checkout') NOT NULL DEFAULT 'auto',
  `expires_at` datetime NOT NULL,
  `closed_at` datetime DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `staff_attendance_sessions_token_hash_unique` (`token_hash`),
  KEY `staff_attendance_sessions_school_id_index` (`school_id`),
  KEY `staff_attendance_sessions_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `staff_attendance_sessions`
--

LOCK TABLES `staff_attendance_sessions` WRITE;
/*!40000 ALTER TABLE `staff_attendance_sessions` DISABLE KEYS */;
/*!40000 ALTER TABLE `staff_attendance_sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `staff_attendances`
--

DROP TABLE IF EXISTS `staff_attendances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `staff_attendances` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `attendance_session_id` bigint(20) unsigned DEFAULT NULL,
  `att_date` date NOT NULL,
  `check_in_at` datetime DEFAULT NULL,
  `check_in_latitude` decimal(10,7) DEFAULT NULL,
  `check_in_longitude` decimal(10,7) DEFAULT NULL,
  `check_in_distance_meters` int(10) unsigned DEFAULT NULL,
  `check_out_at` datetime DEFAULT NULL,
  `check_out_latitude` decimal(10,7) DEFAULT NULL,
  `check_out_longitude` decimal(10,7) DEFAULT NULL,
  `check_out_distance_meters` int(10) unsigned DEFAULT NULL,
  `status` enum('present','late','absent','on_leave') NOT NULL DEFAULT 'present',
  `source` varchar(255) DEFAULT NULL,
  `device_id` varchar(255) DEFAULT NULL,
  `location_verified` tinyint(1) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_staff_att_school_user_date` (`school_id`,`user_id`,`att_date`),
  KEY `staff_attendances_school_id_att_date_index` (`school_id`,`att_date`),
  KEY `staff_attendances_school_id_user_id_index` (`school_id`,`user_id`),
  KEY `staff_attendances_attendance_session_id_index` (`attendance_session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `staff_attendances`
--

LOCK TABLES `staff_attendances` WRITE;
/*!40000 ALTER TABLE `staff_attendances` DISABLE KEYS */;
/*!40000 ALTER TABLE `staff_attendances` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `student_billing_entitlements`
--

DROP TABLE IF EXISTS `student_billing_entitlements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `student_billing_entitlements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint(20) unsigned NOT NULL,
  `student_id` bigint(20) unsigned NOT NULL,
  `session_id` bigint(20) unsigned NOT NULL,
  `term_id` bigint(20) unsigned NOT NULL,
  `billing_mode` enum('online','offline') NOT NULL DEFAULT 'offline',
  `status` enum('unpaid','grace','paid','waived','override') NOT NULL DEFAULT 'unpaid',
  `source` enum('online_fee','offline_invoice','subscription_payment','manual_waiver','admin_override','system') NOT NULL DEFAULT 'system',
  `student_fee_id` bigint(20) unsigned DEFAULT NULL,
  `invoice_id` bigint(20) unsigned DEFAULT NULL,
  `covered_at` timestamp NULL DEFAULT NULL,
  `grace_until` timestamp NULL DEFAULT NULL,
  `acted_by` bigint(20) unsigned DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `student_entitlement_unique` (`school_id`,`student_id`,`session_id`,`term_id`),
  KEY `student_entitlement_status_idx` (`school_id`,`session_id`,`term_id`,`status`),
  KEY `student_billing_entitlements_student_id_foreign` (`student_id`),
  CONSTRAINT `student_billing_entitlements_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `school_settings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `student_billing_entitlements_student_id_foreign` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `student_billing_entitlements`
--

LOCK TABLES `student_billing_entitlements` WRITE;
/*!40000 ALTER TABLE `student_billing_entitlements` DISABLE KEYS */;
/*!40000 ALTER TABLE `student_billing_entitlements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `student_classes`
--

DROP TABLE IF EXISTS `student_classes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `student_classes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `section_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `student_classes_section_id_foreign` (`section_id`),
  CONSTRAINT `student_classes_section_id_foreign` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `student_classes`
--

LOCK TABLES `student_classes` WRITE;
/*!40000 ALTER TABLE `student_classes` DISABLE KEYS */;
/*!40000 ALTER TABLE `student_classes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `student_fees`
--

DROP TABLE IF EXISTS `student_fees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `student_fees` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint(20) unsigned NOT NULL,
  `student_id` bigint(20) unsigned NOT NULL,
  `section_id` bigint(20) unsigned NOT NULL,
  `session_id` bigint(20) unsigned NOT NULL,
  `term_id` bigint(20) unsigned NOT NULL,
  `fee_type_id` bigint(20) unsigned NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `amount_paid` decimal(10,2) NOT NULL DEFAULT 0.00,
  `balance` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` varchar(255) NOT NULL DEFAULT 'unpaid',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `student_fees_assignment_unique` (`school_id`,`student_id`,`section_id`,`session_id`,`term_id`,`fee_type_id`),
  KEY `student_fees_student_id_foreign` (`student_id`),
  KEY `student_fees_section_id_foreign` (`section_id`),
  KEY `student_fees_session_id_foreign` (`session_id`),
  KEY `student_fees_term_id_foreign` (`term_id`),
  KEY `student_fees_fee_type_id_foreign` (`fee_type_id`),
  CONSTRAINT `student_fees_fee_type_id_foreign` FOREIGN KEY (`fee_type_id`) REFERENCES `fee_types` (`id`) ON DELETE CASCADE,
  CONSTRAINT `student_fees_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `school_settings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `student_fees_section_id_foreign` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `student_fees_session_id_foreign` FOREIGN KEY (`session_id`) REFERENCES `academic_sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `student_fees_student_id_foreign` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `student_fees_term_id_foreign` FOREIGN KEY (`term_id`) REFERENCES `terms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `student_fees`
--

LOCK TABLES `student_fees` WRITE;
/*!40000 ALTER TABLE `student_fees` DISABLE KEYS */;
/*!40000 ALTER TABLE `student_fees` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `student_levels`
--

DROP TABLE IF EXISTS `student_levels`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `student_levels` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) NOT NULL,
  `level_id` bigint(20) NOT NULL,
  `effective_date` date NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `student_levels`
--

LOCK TABLES `student_levels` WRITE;
/*!40000 ALTER TABLE `student_levels` DISABLE KEYS */;
/*!40000 ALTER TABLE `student_levels` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `student_results_v2`
--

DROP TABLE IF EXISTS `student_results_v2`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `student_results_v2` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `batch_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `average_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `rollno` varchar(255) DEFAULT NULL,
  `department` varchar(255) DEFAULT NULL,
  `section_id` int(11) DEFAULT NULL,
  `position` varchar(20) DEFAULT NULL,
  `class_teacher` varchar(255) DEFAULT NULL,
  `class_size` varchar(255) DEFAULT NULL,
  `total_score` decimal(10,2) DEFAULT NULL,
  `total_grade` varchar(255) DEFAULT NULL,
  `total_average` varchar(255) DEFAULT NULL,
  `principal_comment` varchar(255) DEFAULT NULL,
  `class_teacher_comment` varchar(255) DEFAULT NULL,
  `general_remark` varchar(255) DEFAULT NULL,
  `meta_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta_json`)),
  `computed_at` timestamp NULL DEFAULT NULL,
  `school_open` varchar(255) DEFAULT NULL,
  `school_close` varchar(255) DEFAULT NULL,
  `no_present` varchar(255) DEFAULT NULL,
  `no_absent` varchar(255) DEFAULT NULL,
  `resumption_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `student_results_v2_unique` (`batch_id`,`user_id`),
  KEY `student_results_v2_user_id_index` (`user_id`),
  KEY `student_results_v2_average_legacy_id_index` (`average_legacy_id`),
  CONSTRAINT `student_results_v2_batch_id_foreign` FOREIGN KEY (`batch_id`) REFERENCES `result_batches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `student_results_v2`
--

LOCK TABLES `student_results_v2` WRITE;
/*!40000 ALTER TABLE `student_results_v2` DISABLE KEYS */;
/*!40000 ALTER TABLE `student_results_v2` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `students`
--

DROP TABLE IF EXISTS `students`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `students` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `level_id` bigint(20) NOT NULL,
  `department_id` bigint(20) NOT NULL,
  `firstname` varchar(255) DEFAULT NULL,
  `surname` varchar(255) DEFAULT NULL,
  `dob` varchar(255) DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `sex` varchar(255) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(255) DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `students`
--

LOCK TABLES `students` WRITE;
/*!40000 ALTER TABLE `students` DISABLE KEYS */;
/*!40000 ALTER TABLE `students` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sub_payments`
--

DROP TABLE IF EXISTS `sub_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sub_payments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `subscription_plan_id` bigint(20) unsigned DEFAULT NULL,
  `previous_subscription_plan_id` bigint(20) unsigned DEFAULT NULL,
  `billing_cycle_count` int(10) unsigned NOT NULL DEFAULT 1,
  `duration_in_days` int(10) unsigned DEFAULT NULL,
  `active_students` int(10) unsigned DEFAULT NULL,
  `price_per_student` decimal(12,2) DEFAULT NULL,
  `number_of_students` int(10) unsigned DEFAULT NULL,
  `subscription_id` bigint(20) DEFAULT NULL,
  `reference` varchar(255) NOT NULL,
  `paystack_id` varchar(255) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `subtotal_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `upgrade_credit_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` varchar(255) NOT NULL,
  `subscription_action` varchar(255) NOT NULL DEFAULT 'purchase',
  `channel` varchar(255) DEFAULT NULL,
  `card_type` varchar(255) DEFAULT NULL,
  `last4` varchar(255) DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `starts_at` timestamp NULL DEFAULT NULL,
  `previous_subscription_ends_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sub_payments_reference_unique` (`reference`),
  UNIQUE KEY `sub_payments_paystack_id_unique` (`paystack_id`),
  KEY `sub_payments_user_id_foreign` (`user_id`),
  KEY `sub_payments_subscription_plan_id_foreign` (`subscription_plan_id`),
  KEY `sub_payments_previous_subscription_plan_id_foreign` (`previous_subscription_plan_id`),
  CONSTRAINT `sub_payments_previous_subscription_plan_id_foreign` FOREIGN KEY (`previous_subscription_plan_id`) REFERENCES `subscription_plans` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sub_payments_subscription_plan_id_foreign` FOREIGN KEY (`subscription_plan_id`) REFERENCES `subscription_plans` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sub_payments_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sub_payments`
--

LOCK TABLES `sub_payments` WRITE;
/*!40000 ALTER TABLE `sub_payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `sub_payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `subject_enrolls`
--

DROP TABLE IF EXISTS `subject_enrolls`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `subject_enrolls` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `enroll` tinyint(1) NOT NULL,
  `user_id` bigint(20) NOT NULL,
  `subject_id` bigint(20) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `subject_enrolls`
--

LOCK TABLES `subject_enrolls` WRITE;
/*!40000 ALTER TABLE `subject_enrolls` DISABLE KEYS */;
/*!40000 ALTER TABLE `subject_enrolls` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `subject_results_v2`
--

DROP TABLE IF EXISTS `subject_results_v2`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `subject_results_v2` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `student_result_id` bigint(20) unsigned NOT NULL,
  `subject_id` bigint(20) unsigned NOT NULL,
  `legacy_table` varchar(64) DEFAULT NULL,
  `legacy_id` bigint(20) unsigned DEFAULT NULL,
  `ca_raw` longtext DEFAULT NULL,
  `ca_total` decimal(8,2) DEFAULT NULL,
  `exam` varchar(255) DEFAULT NULL,
  `total` varchar(255) DEFAULT NULL,
  `grade` varchar(255) DEFAULT NULL,
  `remark` varchar(255) DEFAULT NULL,
  `subject_position` varchar(20) DEFAULT NULL,
  `comment` varchar(255) DEFAULT NULL,
  `signature` varchar(255) DEFAULT NULL,
  `computed_at` timestamp NULL DEFAULT NULL,
  `carry_over_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`carry_over_json`)),
  `carry_over_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `cumulative_total` decimal(8,2) DEFAULT NULL,
  `cumulative_average` decimal(8,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `subject_results_v2_unique` (`student_result_id`,`subject_id`),
  KEY `subject_results_v2_subject_id_index` (`subject_id`),
  KEY `subject_results_v2_legacy_table_index` (`legacy_table`),
  KEY `subject_results_v2_legacy_id_index` (`legacy_id`),
  CONSTRAINT `subject_results_v2_student_result_id_foreign` FOREIGN KEY (`student_result_id`) REFERENCES `student_results_v2` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `subject_results_v2`
--

LOCK TABLES `subject_results_v2` WRITE;
/*!40000 ALTER TABLE `subject_results_v2` DISABLE KEYS */;
/*!40000 ALTER TABLE `subject_results_v2` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `subjects`
--

DROP TABLE IF EXISTS `subjects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `subjects` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `section_id` bigint(20) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `subjects`
--

LOCK TABLES `subjects` WRITE;
/*!40000 ALTER TABLE `subjects` DISABLE KEYS */;
/*!40000 ALTER TABLE `subjects` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `subscription_plan_features`
--

DROP TABLE IF EXISTS `subscription_plan_features`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `subscription_plan_features` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `subscription_plan_id` bigint(20) unsigned NOT NULL,
  `feature_key` varchar(100) NOT NULL,
  `feature_name` varchar(255) NOT NULL,
  `limit_type` varchar(255) DEFAULT NULL,
  `limit_count` int(11) NOT NULL DEFAULT 0,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `plan_feature_unique` (`subscription_plan_id`,`feature_key`),
  CONSTRAINT `subscription_plan_features_subscription_plan_id_foreign` FOREIGN KEY (`subscription_plan_id`) REFERENCES `subscription_plans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `subscription_plan_features`
--

LOCK TABLES `subscription_plan_features` WRITE;
/*!40000 ALTER TABLE `subscription_plan_features` DISABLE KEYS */;
/*!40000 ALTER TABLE `subscription_plan_features` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `subscription_plans`
--

DROP TABLE IF EXISTS `subscription_plans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `subscription_plans` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `paystack_plan_code` varchar(255) DEFAULT NULL COMMENT 'Paystack plan code for recurring billing',
  `price` decimal(10,2) NOT NULL,
  `price_per_student` decimal(10,2) NOT NULL DEFAULT 0.00,
  `duration_in_days` int(11) NOT NULL,
  `billing_interval` varchar(30) NOT NULL DEFAULT 'term',
  `description` text DEFAULT NULL,
  `features` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`features`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `max_teachers` int(11) DEFAULT NULL,
  `max_students` int(11) DEFAULT NULL,
  `whatsapp_monthly_credits` int(10) unsigned NOT NULL DEFAULT 0,
  `whatsapp_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `currency` varchar(10) NOT NULL DEFAULT 'NGN',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `subscription_plans`
--

LOCK TABLES `subscription_plans` WRITE;
/*!40000 ALTER TABLE `subscription_plans` DISABLE KEYS */;
/*!40000 ALTER TABLE `subscription_plans` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `subscription_whatsapp_usages`
--

DROP TABLE IF EXISTS `subscription_whatsapp_usages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `subscription_whatsapp_usages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `subscription_id` bigint(20) unsigned NOT NULL,
  `school_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `cycle_start` date NOT NULL,
  `cycle_end` date NOT NULL,
  `allocated_credits` int(10) unsigned NOT NULL DEFAULT 0,
  `used_credits` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sub_whatsapp_usage_cycle_unique` (`subscription_id`,`cycle_start`,`cycle_end`),
  KEY `subscription_whatsapp_usages_school_id_user_id_index` (`school_id`,`user_id`),
  KEY `sub_whatsapp_usage_cycle_idx` (`subscription_id`,`cycle_start`,`cycle_end`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `subscription_whatsapp_usages`
--

LOCK TABLES `subscription_whatsapp_usages` WRITE;
/*!40000 ALTER TABLE `subscription_whatsapp_usages` DISABLE KEYS */;
/*!40000 ALTER TABLE `subscription_whatsapp_usages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `subscriptions`
--

DROP TABLE IF EXISTS `subscriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `subscriptions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `subscription_plan_id` bigint(20) NOT NULL,
  `billing_cycle_count` int(10) unsigned NOT NULL DEFAULT 1,
  `number_of_students` int(10) unsigned DEFAULT NULL,
  `plan_name` varchar(255) DEFAULT NULL,
  `plan_code` varchar(255) DEFAULT NULL,
  `reference` varchar(255) DEFAULT NULL,
  `authorization_code` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `amount` decimal(10,2) DEFAULT NULL,
  `currency` varchar(10) NOT NULL DEFAULT 'NGN',
  `paystack_customer_code` varchar(255) DEFAULT NULL,
  `paystack_subscription_code` varchar(255) DEFAULT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'inactive',
  `notified_about_expiry` tinyint(1) NOT NULL DEFAULT 0,
  `start_date` datetime DEFAULT NULL,
  `end_date` datetime DEFAULT NULL,
  `next_billing_date` datetime DEFAULT NULL,
  `auto_renew` tinyint(1) NOT NULL DEFAULT 0,
  `auto_renew_source` varchar(255) NOT NULL DEFAULT 'paystack',
  `starts_at` timestamp NULL DEFAULT NULL,
  `ends_at` timestamp NULL DEFAULT NULL,
  `grace_days` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `reminder_stage` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `last_reminded_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `subscriptions_user_id_foreign` (`user_id`),
  CONSTRAINT `subscriptions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `subscriptions`
--

LOCK TABLES `subscriptions` WRITE;
/*!40000 ALTER TABLE `subscriptions` DISABLE KEYS */;
/*!40000 ALTER TABLE `subscriptions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `teacher_attendances`
--

DROP TABLE IF EXISTS `teacher_attendances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `teacher_attendances` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `status` enum('sign_in','sign_out') NOT NULL,
  `attendance_time` timestamp NOT NULL DEFAULT current_timestamp(),
  `qr_code_id` bigint(20) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `teacher_attendances_user_id_foreign` (`user_id`),
  CONSTRAINT `teacher_attendances_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `teacher_attendances`
--

LOCK TABLES `teacher_attendances` WRITE;
/*!40000 ALTER TABLE `teacher_attendances` DISABLE KEYS */;
/*!40000 ALTER TABLE `teacher_attendances` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `teacher_enrollments`
--

DROP TABLE IF EXISTS `teacher_enrollments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `teacher_enrollments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `enroll` tinyint(1) NOT NULL,
  `user_id` bigint(20) NOT NULL,
  `level_id` bigint(20) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `teacher_enrollments`
--

LOCK TABLES `teacher_enrollments` WRITE;
/*!40000 ALTER TABLE `teacher_enrollments` DISABLE KEYS */;
/*!40000 ALTER TABLE `teacher_enrollments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `teacher_subjects`
--

DROP TABLE IF EXISTS `teacher_subjects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `teacher_subjects` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `teacher_id` bigint(20) unsigned NOT NULL,
  `subject_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `teacher_subjects_teacher_id_foreign` (`teacher_id`),
  KEY `teacher_subjects_subject_id_foreign` (`subject_id`),
  CONSTRAINT `teacher_subjects_subject_id_foreign` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `teacher_subjects_teacher_id_foreign` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `teacher_subjects`
--

LOCK TABLES `teacher_subjects` WRITE;
/*!40000 ALTER TABLE `teacher_subjects` DISABLE KEYS */;
/*!40000 ALTER TABLE `teacher_subjects` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `terms`
--

DROP TABLE IF EXISTS `terms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `terms` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `school_id` bigint(20) unsigned NOT NULL,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'Inactive',
  PRIMARY KEY (`id`),
  UNIQUE KEY `terms_name_school_id_unique` (`name`,`school_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `terms`
--

LOCK TABLES `terms` WRITE;
/*!40000 ALTER TABLE `terms` DISABLE KEYS */;
/*!40000 ALTER TABLE `terms` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `testimonials`
--

DROP TABLE IF EXISTS `testimonials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `testimonials` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `quote` varchar(255) NOT NULL,
  `author` varchar(255) NOT NULL,
  `org` varchar(255) NOT NULL,
  `img` varchar(255) DEFAULT NULL,
  `color` varchar(255) NOT NULL DEFAULT 'primary',
  `rating` int(11) NOT NULL DEFAULT 5,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `testimonials`
--

LOCK TABLES `testimonials` WRITE;
/*!40000 ALTER TABLE `testimonials` DISABLE KEYS */;
/*!40000 ALTER TABLE `testimonials` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `theory_questions`
--

DROP TABLE IF EXISTS `theory_questions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `theory_questions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `answers` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `theory_questions`
--

LOCK TABLES `theory_questions` WRITE;
/*!40000 ALTER TABLE `theory_questions` DISABLE KEYS */;
/*!40000 ALTER TABLE `theory_questions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `third_term_results`
--

DROP TABLE IF EXISTS `third_term_results`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `third_term_results` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `subject_id` bigint(20) NOT NULL,
  `rollno` varchar(255) DEFAULT NULL,
  `ca` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`ca`)),
  `exam` varchar(255) DEFAULT NULL,
  `secondterm` varchar(255) DEFAULT NULL,
  `total` varchar(255) DEFAULT NULL,
  `average` varchar(255) DEFAULT NULL,
  `grade` varchar(255) DEFAULT NULL,
  `remark` varchar(255) DEFAULT NULL,
  `comment` varchar(255) DEFAULT NULL,
  `signature` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `third_term_results_user_id_foreign` (`user_id`),
  CONSTRAINT `third_term_results_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `third_term_results`
--

LOCK TABLES `third_term_results` WRITE;
/*!40000 ALTER TABLE `third_term_results` DISABLE KEYS */;
/*!40000 ALTER TABLE `third_term_results` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `timetables`
--

DROP TABLE IF EXISTS `timetables`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `timetables` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `class_id` bigint(20) unsigned NOT NULL,
  `day` varchar(255) NOT NULL,
  `period_number` int(11) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `school_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `timetables_class_id_foreign` (`class_id`),
  CONSTRAINT `timetables_class_id_foreign` FOREIGN KEY (`class_id`) REFERENCES `student_classes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `timetables`
--

LOCK TABLES `timetables` WRITE;
/*!40000 ALTER TABLE `timetables` DISABLE KEYS */;
/*!40000 ALTER TABLE `timetables` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_has_affective_domains`
--

DROP TABLE IF EXISTS `user_has_affective_domains`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_has_affective_domains` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) DEFAULT NULL,
  `rate` int(11) DEFAULT NULL,
  `user_id` bigint(20) DEFAULT NULL,
  `school_id` bigint(20) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_has_affective_domains`
--

LOCK TABLES `user_has_affective_domains` WRITE;
/*!40000 ALTER TABLE `user_has_affective_domains` DISABLE KEYS */;
/*!40000 ALTER TABLE `user_has_affective_domains` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_has_psychomotor_domains`
--

DROP TABLE IF EXISTS `user_has_psychomotor_domains`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_has_psychomotor_domains` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) DEFAULT NULL,
  `rate` int(11) DEFAULT NULL,
  `user_id` bigint(20) DEFAULT NULL,
  `school_id` bigint(20) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_has_psychomotor_domains`
--

LOCK TABLES `user_has_psychomotor_domains` WRITE;
/*!40000 ALTER TABLE `user_has_psychomotor_domains` DISABLE KEYS */;
/*!40000 ALTER TABLE `user_has_psychomotor_domains` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `username` varchar(255) DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 0,
  `phone` varchar(255) DEFAULT NULL,
  `whatsapp_no` varchar(255) DEFAULT NULL,
  `whatsapp_verified_at` timestamp NULL DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `role` varchar(255) DEFAULT NULL,
  `blood_group` varchar(255) DEFAULT NULL,
  `religion` varchar(255) DEFAULT NULL,
  `nationality` varchar(255) DEFAULT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `email_verification_code` varchar(10) DEFAULT NULL,
  `bonus_given` tinyint(1) NOT NULL DEFAULT 0,
  `whatsapp_number` varchar(255) DEFAULT NULL,
  `firstname` varchar(255) DEFAULT NULL,
  `surname` varchar(255) DEFAULT NULL,
  `third_name` varchar(255) DEFAULT NULL,
  `reg_no` varchar(255) DEFAULT NULL,
  `school_id` bigint(20) unsigned DEFAULT NULL,
  `dob` date DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `sex` varchar(20) DEFAULT NULL,
  `level_id` bigint(20) unsigned DEFAULT NULL,
  `section_id` bigint(20) unsigned DEFAULT NULL,
  `department_id` bigint(20) unsigned DEFAULT NULL,
  `default_password` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  UNIQUE KEY `users_reg_no_unique` (`reg_no`),
  KEY `users_school_id_index` (`school_id`),
  KEY `users_level_id_index` (`level_id`),
  KEY `users_section_id_index` (`section_id`),
  KEY `users_department_id_index` (`department_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `wallet_transactions`
--

DROP TABLE IF EXISTS `wallet_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `wallet_transactions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `type` enum('credit','debit') NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `reference_id` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `school_id` bigint(20) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `wallet_transactions_user_id_foreign` (`user_id`),
  CONSTRAINT `wallet_transactions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `wallet_transactions`
--

LOCK TABLES `wallet_transactions` WRITE;
/*!40000 ALTER TABLE `wallet_transactions` DISABLE KEYS */;
/*!40000 ALTER TABLE `wallet_transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `wallets`
--

DROP TABLE IF EXISTS `wallets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `wallets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `balance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `school_id` bigint(20) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `wallets_user_id_unique` (`user_id`),
  CONSTRAINT `wallets_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `wallets`
--

LOCK TABLES `wallets` WRITE;
/*!40000 ALTER TABLE `wallets` DISABLE KEYS */;
/*!40000 ALTER TABLE `wallets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `whatsapp_messages`
--

DROP TABLE IF EXISTS `whatsapp_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `whatsapp_messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint(20) unsigned NOT NULL,
  `subscription_id` bigint(20) unsigned DEFAULT NULL,
  `parent_user_id` bigint(20) unsigned DEFAULT NULL,
  `student_user_id` bigint(20) unsigned DEFAULT NULL,
  `school_whatsapp_account_id` bigint(20) unsigned NOT NULL,
  `to_phone` varchar(255) NOT NULL,
  `normalized_phone` varchar(255) NOT NULL,
  `template_name` varchar(255) DEFAULT NULL,
  `template_lang` varchar(255) NOT NULL DEFAULT 'en',
  `status` enum('queued','sent','delivered','read','failed') NOT NULL DEFAULT 'queued',
  `meta_message_id` varchar(255) DEFAULT NULL,
  `credit_cost` int(10) unsigned NOT NULL DEFAULT 1,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload`)),
  `meta_response` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta_response`)),
  `failure_reason` text DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `delivered_at` timestamp NULL DEFAULT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `whatsapp_messages_school_id_parent_user_id_index` (`school_id`,`parent_user_id`),
  KEY `whatsapp_messages_school_id_student_user_id_index` (`school_id`,`student_user_id`),
  KEY `whatsapp_messages_school_id_status_index` (`school_id`,`status`),
  KEY `whatsapp_messages_meta_message_id_index` (`meta_message_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `whatsapp_messages`
--

LOCK TABLES `whatsapp_messages` WRITE;
/*!40000 ALTER TABLE `whatsapp_messages` DISABLE KEYS */;
/*!40000 ALTER TABLE `whatsapp_messages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `whatsapp_verifications`
--

DROP TABLE IF EXISTS `whatsapp_verifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `whatsapp_verifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `actor_type` enum('admin','parent') NOT NULL,
  `phone` varchar(255) NOT NULL,
  `normalized_phone` varchar(255) NOT NULL,
  `code_hash` varchar(255) NOT NULL,
  `channel` varchar(255) NOT NULL DEFAULT 'mixed',
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `verified_at` timestamp NULL DEFAULT NULL,
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `status` enum('pending','verified','expired','failed') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `whatsapp_verifications_school_id_user_id_actor_type_index` (`school_id`,`user_id`,`actor_type`),
  KEY `whatsapp_verifications_normalized_phone_status_index` (`normalized_phone`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `whatsapp_verifications`
--

LOCK TABLES `whatsapp_verifications` WRITE;
/*!40000 ALTER TABLE `whatsapp_verifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `whatsapp_verifications` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-07-25 12:35:24
