-- MySQL dump 10.13  Distrib 8.4.8, for Linux (x86_64)
--
-- Host: localhost    Database: ips
-- ------------------------------------------------------
-- Server version	8.4.8

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `nexus_packages`
--

DROP TABLE IF EXISTS `nexus_packages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nexus_packages` (
  `p_id` int NOT NULL AUTO_INCREMENT,
  `p_name` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `p_seo_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `p_group` int NOT NULL DEFAULT '0',
  `p_stock` int NOT NULL DEFAULT '0',
  `p_reg` tinyint NOT NULL DEFAULT '0',
  `p_store` tinyint NOT NULL DEFAULT '0',
  `p_member_groups` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `p_allow_upgrading` tinyint NOT NULL DEFAULT '0',
  `p_upgrade_charge` tinyint NOT NULL DEFAULT '0',
  `p_allow_downgrading` tinyint NOT NULL DEFAULT '0',
  `p_downgrade_refund` tinyint NOT NULL DEFAULT '0',
  `p_base_price` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `p_tax` int NOT NULL DEFAULT '0',
  `p_renewal_days` int NOT NULL DEFAULT '0',
  `p_primary_group` mediumint NOT NULL DEFAULT '0',
  `p_secondary_group` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `p_return_primary` tinyint NOT NULL DEFAULT '0',
  `p_return_secondary` tinyint NOT NULL DEFAULT '0',
  `p_position` int NOT NULL DEFAULT '0',
  `p_associable` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `p_force_assoc` tinyint NOT NULL DEFAULT '0',
  `p_assoc_error` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `p_discounts` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `p_page` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `p_support` tinyint NOT NULL DEFAULT '0',
  `p_support_department` int NOT NULL DEFAULT '0',
  `p_support_severity` int DEFAULT NULL,
  `p_featured` tinyint NOT NULL DEFAULT '0',
  `p_upsell` tinyint NOT NULL DEFAULT '0',
  `p_notify` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `p_type` varchar(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `p_custom` mediumint NOT NULL DEFAULT '0',
  `p_reviewable` tinyint NOT NULL DEFAULT '1',
  `p_review_moderate` tinyint NOT NULL DEFAULT '1',
  `p_image` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `p_methods` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `p_renew_options` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `p_group_renewals` tinyint NOT NULL DEFAULT '0',
  `p_rebuild_thumb` tinyint NOT NULL DEFAULT '0',
  `p_renewal_days_advance` int DEFAULT NULL,
  `p_date_added` int unsigned NOT NULL DEFAULT '0',
  `p_reviews` int NOT NULL DEFAULT '0',
  `p_rating` float NOT NULL DEFAULT '0',
  `p_unapproved_reviews` int unsigned DEFAULT NULL,
  `p_hidden_reviews` int unsigned DEFAULT NULL,
  `p_grace_period` int unsigned DEFAULT '0' COMMENT 'Grace period in days',
  `p_meta_data` tinyint unsigned NOT NULL DEFAULT '0',
  `p_email_purchase` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Email to send on new purchase',
  `p_email_expire_soon` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Email to send when purchase will expire soon',
  `p_email_expire` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Email to send when purchase expires',
  `p_email_purchase_type` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `p_email_expire_soon_type` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `p_email_expire_type` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `p_date_updated` int unsigned DEFAULT '0',
  `p_initial_term` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `p_conv_associable` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '0',
  PRIMARY KEY (`p_id`),
  KEY `p_position` (`p_position`),
  KEY `p_group` (`p_group`),
  KEY `p_store` (`p_store`,`p_date_added`),
  FULLTEXT KEY `p_name` (`p_name`)
) ENGINE=InnoDB AUTO_INCREMENT=71 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nexus_packages`
--

LOCK TABLES `nexus_packages` WRITE;
/*!40000 ALTER TABLE `nexus_packages` DISABLE KEYS */;
INSERT INTO `nexus_packages` VALUES (1,NULL,NULL,1,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"5\",\"currency\":\"USD\"}}',0,0,0,'',0,0,1,'',0,NULL,NULL,NULL,0,0,0,0,0,'','product',0,0,0,NULL,'*','[{\"cost\":{\"USD\":{\"amount\":\"5\",\"currency\":\"USD\"}},\"term\":1,\"unit\":\"m\",\"add\":false}]',0,0,-1,1770667264,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,NULL,NULL,1770740003,NULL,'0'),(3,NULL,NULL,1,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"15\",\"currency\":\"USD\"}}',0,0,0,'',0,0,4,'',0,NULL,NULL,NULL,0,0,0,0,0,'','product',0,0,0,NULL,'*','',0,0,-1,1770739927,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,'','',1770740003,NULL,'0'),(4,NULL,NULL,2,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"1\",\"currency\":\"USD\"}}',0,0,0,'',0,0,3,'',0,NULL,NULL,NULL,0,0,0,0,0,'','product',0,0,0,NULL,'*','',0,0,-1,1770739973,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,'','',1770739973,NULL,'0'),(5,NULL,NULL,1,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"10\",\"currency\":\"USD\"}}',0,0,0,'',0,0,3,'',0,NULL,NULL,NULL,0,0,0,0,0,'','product',0,0,0,NULL,'*','[{\"cost\":{\"USD\":{\"amount\":\"10\",\"currency\":\"USD\"}},\"term\":1,\"unit\":\"m\",\"add\":false}]',0,0,-1,1770739990,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,NULL,NULL,1770740003,NULL,'0'),(6,NULL,NULL,3,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"1\",\"currency\":\"USD\"}}',0,0,0,'',0,0,1,'',0,NULL,NULL,NULL,0,0,0,0,0,'','product',0,0,0,NULL,'*','',0,0,-1,1770809908,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,'','',1770809919,NULL,'0'),(7,NULL,NULL,3,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"1\",\"currency\":\"USD\"}}',0,0,0,NULL,0,0,2,NULL,0,NULL,NULL,NULL,0,0,NULL,0,0,NULL,'product',0,0,1,NULL,NULL,'',0,0,NULL,1770810050,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'0'),(8,NULL,NULL,3,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"1\",\"currency\":\"USD\"}}',0,0,0,NULL,0,0,3,NULL,0,NULL,NULL,NULL,0,0,NULL,0,0,NULL,'product',0,0,1,NULL,NULL,'',0,0,NULL,1770810050,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'0'),(9,NULL,NULL,3,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"1\",\"currency\":\"USD\"}}',0,0,0,NULL,0,0,4,NULL,0,NULL,NULL,NULL,0,0,NULL,0,0,NULL,'product',0,0,1,NULL,NULL,'',0,0,NULL,1770810050,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'0'),(10,NULL,NULL,3,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"1\",\"currency\":\"USD\"}}',0,0,0,NULL,0,0,5,NULL,0,NULL,NULL,NULL,0,0,NULL,0,0,NULL,'product',0,0,1,NULL,NULL,'',0,0,NULL,1770810050,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'0'),(11,NULL,NULL,3,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"1\",\"currency\":\"USD\"}}',0,0,0,NULL,0,0,6,NULL,0,NULL,NULL,NULL,0,0,NULL,0,0,NULL,'product',0,0,1,NULL,NULL,'',0,0,NULL,1770810050,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'0'),(12,NULL,NULL,3,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"1\",\"currency\":\"USD\"}}',0,0,0,NULL,0,0,7,NULL,0,NULL,NULL,NULL,0,0,NULL,0,0,NULL,'product',0,0,1,NULL,NULL,'',0,0,NULL,1770810050,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'0'),(13,NULL,NULL,3,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"1\",\"currency\":\"USD\"}}',0,0,0,NULL,0,0,8,NULL,0,NULL,NULL,NULL,0,0,NULL,0,0,NULL,'product',0,0,1,NULL,NULL,'',0,0,NULL,1770810050,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'0'),(14,NULL,NULL,3,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"1\",\"currency\":\"USD\"}}',0,0,0,NULL,0,0,9,NULL,0,NULL,NULL,NULL,0,0,NULL,0,0,NULL,'product',0,0,1,NULL,NULL,'',0,0,NULL,1770810050,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'0'),(15,NULL,NULL,3,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"1\",\"currency\":\"USD\"}}',0,0,0,NULL,0,0,10,NULL,0,NULL,NULL,NULL,0,0,NULL,0,0,NULL,'product',0,0,1,NULL,NULL,'',0,0,NULL,1770810050,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'0'),(16,NULL,NULL,3,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"1\",\"currency\":\"USD\"}}',0,0,0,NULL,0,0,11,NULL,0,NULL,NULL,NULL,0,0,NULL,0,0,NULL,'product',0,0,1,NULL,NULL,'',0,0,NULL,1770810050,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'0'),(17,NULL,NULL,3,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"1\",\"currency\":\"USD\"}}',0,0,0,NULL,0,0,12,NULL,0,NULL,NULL,NULL,0,0,NULL,0,0,NULL,'product',0,0,1,NULL,NULL,'',0,0,NULL,1770810050,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'0'),(18,NULL,NULL,3,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"1\",\"currency\":\"USD\"}}',0,0,0,NULL,0,0,13,NULL,0,NULL,NULL,NULL,0,0,NULL,0,0,NULL,'product',0,0,1,NULL,NULL,'',0,0,NULL,1770810050,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'0'),(19,NULL,NULL,3,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"1\",\"currency\":\"USD\"}}',0,0,0,NULL,0,0,14,NULL,0,NULL,NULL,NULL,0,0,NULL,0,0,NULL,'product',0,0,1,NULL,NULL,'',0,0,NULL,1770810050,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'0'),(20,NULL,NULL,3,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"1\",\"currency\":\"USD\"}}',0,0,0,NULL,0,0,15,NULL,0,NULL,NULL,NULL,0,0,NULL,0,0,NULL,'product',0,0,1,NULL,NULL,'',0,0,NULL,1770810050,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'0'),(21,NULL,NULL,3,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"1\",\"currency\":\"USD\"}}',0,0,0,NULL,0,0,16,NULL,0,NULL,NULL,NULL,0,0,NULL,0,0,NULL,'product',0,0,1,NULL,NULL,'',0,0,NULL,1770810050,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'0'),(22,NULL,NULL,3,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"1\",\"currency\":\"USD\"}}',0,0,0,NULL,0,0,17,NULL,0,NULL,NULL,NULL,0,0,NULL,0,0,NULL,'product',0,0,1,NULL,NULL,'',0,0,NULL,1770810050,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'0'),(23,NULL,NULL,3,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"1\",\"currency\":\"USD\"}}',0,0,0,NULL,0,0,18,NULL,0,NULL,NULL,NULL,0,0,NULL,0,0,NULL,'product',0,0,1,NULL,NULL,'',0,0,NULL,1770810050,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'0'),(24,NULL,NULL,3,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"1\",\"currency\":\"USD\"}}',0,0,0,NULL,0,0,19,NULL,0,NULL,NULL,NULL,0,0,NULL,0,0,NULL,'product',0,0,1,NULL,NULL,'',0,0,NULL,1770810050,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'0'),(25,NULL,NULL,3,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"1\",\"currency\":\"USD\"}}',0,0,0,NULL,0,0,20,NULL,0,NULL,NULL,NULL,0,0,NULL,0,0,NULL,'product',0,0,1,NULL,NULL,'',0,0,NULL,1770810050,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'0'),(26,NULL,NULL,3,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"1\",\"currency\":\"USD\"}}',0,0,0,NULL,0,0,21,NULL,0,NULL,NULL,NULL,0,0,NULL,0,0,NULL,'product',0,0,1,NULL,NULL,'',0,0,NULL,1770810050,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'0'),(27,NULL,NULL,3,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"1\",\"currency\":\"USD\"}}',0,0,0,NULL,0,0,22,NULL,0,NULL,NULL,NULL,0,0,NULL,0,0,NULL,'product',0,0,1,NULL,NULL,'',0,0,NULL,1770810050,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'0'),(28,NULL,NULL,3,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"1\",\"currency\":\"USD\"}}',0,0,0,NULL,0,0,23,NULL,0,NULL,NULL,NULL,0,0,NULL,0,0,NULL,'product',0,0,1,NULL,NULL,'',0,0,NULL,1770810050,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'0'),(29,NULL,NULL,3,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"1\",\"currency\":\"USD\"}}',0,0,0,NULL,0,0,24,NULL,0,NULL,NULL,NULL,0,0,NULL,0,0,NULL,'product',0,0,1,NULL,NULL,'',0,0,NULL,1770810050,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'0'),(30,NULL,NULL,3,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"1\",\"currency\":\"USD\"}}',0,0,0,NULL,0,0,25,NULL,0,NULL,NULL,NULL,0,0,NULL,0,0,NULL,'product',0,0,1,NULL,NULL,'',0,0,NULL,1770810050,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'0'),(31,NULL,NULL,3,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"1\",\"currency\":\"USD\"}}',0,0,0,NULL,0,0,26,NULL,0,NULL,NULL,NULL,0,0,NULL,0,0,NULL,'product',0,0,1,NULL,NULL,'',0,0,NULL,1770810050,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'0'),(32,NULL,NULL,3,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"1\",\"currency\":\"USD\"}}',0,0,0,NULL,0,0,27,NULL,0,NULL,NULL,NULL,0,0,NULL,0,0,NULL,'product',0,0,1,NULL,NULL,'',0,0,NULL,1770810050,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'0'),(33,NULL,NULL,3,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"1\",\"currency\":\"USD\"}}',0,0,0,NULL,0,0,28,NULL,0,NULL,NULL,NULL,0,0,NULL,0,0,NULL,'product',0,0,1,NULL,NULL,'',0,0,NULL,1770810050,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'0'),(34,NULL,NULL,3,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"1\",\"currency\":\"USD\"}}',0,0,0,NULL,0,0,29,NULL,0,NULL,NULL,NULL,0,0,NULL,0,0,NULL,'product',0,0,1,NULL,NULL,'',0,0,NULL,1770810050,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'0'),(35,NULL,NULL,3,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"1\",\"currency\":\"USD\"}}',0,0,0,NULL,0,0,30,NULL,0,NULL,NULL,NULL,0,0,NULL,0,0,NULL,'product',0,0,1,NULL,NULL,'',0,0,NULL,1770810050,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'0'),(36,NULL,NULL,3,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"1\",\"currency\":\"USD\"}}',0,0,0,NULL,0,0,31,NULL,0,NULL,NULL,NULL,0,0,NULL,0,0,NULL,'product',0,0,1,NULL,NULL,'',0,0,NULL,1770810050,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'0'),(37,NULL,NULL,3,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"1\",\"currency\":\"USD\"}}',0,0,0,NULL,0,0,32,NULL,0,NULL,NULL,NULL,0,0,NULL,0,0,NULL,'product',0,0,1,NULL,NULL,'',0,0,NULL,1770810050,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'0'),(38,NULL,NULL,3,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"1\",\"currency\":\"USD\"}}',0,0,0,NULL,0,0,33,NULL,0,NULL,NULL,NULL,0,0,NULL,0,0,NULL,'product',0,0,1,NULL,NULL,'',0,0,NULL,1770810050,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'0'),(39,NULL,NULL,3,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"1\",\"currency\":\"USD\"}}',0,0,0,NULL,0,0,34,NULL,0,NULL,NULL,NULL,0,0,NULL,0,0,NULL,'product',0,0,1,NULL,NULL,'',0,0,NULL,1770810050,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'0'),(40,NULL,NULL,3,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"1\",\"currency\":\"USD\"}}',0,0,0,NULL,0,0,35,NULL,0,NULL,NULL,NULL,0,0,NULL,0,0,NULL,'product',0,0,1,NULL,NULL,'',0,0,NULL,1770810050,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'0'),(41,NULL,NULL,3,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"1\",\"currency\":\"USD\"}}',0,0,0,NULL,0,0,36,NULL,0,NULL,NULL,NULL,0,0,NULL,0,0,NULL,'product',0,0,1,NULL,NULL,'',0,0,NULL,1770810050,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'0'),(42,NULL,NULL,3,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"1\",\"currency\":\"USD\"}}',0,0,0,NULL,0,0,37,NULL,0,NULL,NULL,NULL,0,0,NULL,0,0,NULL,'product',0,0,1,NULL,NULL,'',0,0,NULL,1770810050,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'0'),(43,NULL,NULL,3,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"1\",\"currency\":\"USD\"}}',0,0,0,NULL,0,0,38,NULL,0,NULL,NULL,NULL,0,0,NULL,0,0,NULL,'product',0,0,1,NULL,NULL,'',0,0,NULL,1770810050,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'0'),(44,NULL,NULL,3,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"1\",\"currency\":\"USD\"}}',0,0,0,NULL,0,0,39,NULL,0,NULL,NULL,NULL,0,0,NULL,0,0,NULL,'product',0,0,1,NULL,NULL,'',0,0,NULL,1770810050,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'0'),(45,NULL,NULL,3,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"1\",\"currency\":\"USD\"}}',0,0,0,NULL,0,0,40,NULL,0,NULL,NULL,NULL,0,0,NULL,0,0,NULL,'product',0,0,1,NULL,NULL,'',0,0,NULL,1770810050,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'0'),(46,NULL,NULL,3,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"1\",\"currency\":\"USD\"}}',0,0,0,NULL,0,0,41,NULL,0,NULL,NULL,NULL,0,0,NULL,0,0,NULL,'product',0,0,1,NULL,NULL,'',0,0,NULL,1770810050,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'0'),(47,NULL,NULL,3,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"1\",\"currency\":\"USD\"}}',0,0,0,NULL,0,0,42,NULL,0,NULL,NULL,NULL,0,0,NULL,0,0,NULL,'product',0,0,1,NULL,NULL,'',0,0,NULL,1770810050,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'0'),(48,NULL,NULL,3,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"1\",\"currency\":\"USD\"}}',0,0,0,NULL,0,0,43,NULL,0,NULL,NULL,NULL,0,0,NULL,0,0,NULL,'product',0,0,1,NULL,NULL,'',0,0,NULL,1770810050,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'0'),(49,NULL,NULL,3,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"1\",\"currency\":\"USD\"}}',0,0,0,NULL,0,0,44,NULL,0,NULL,NULL,NULL,0,0,NULL,0,0,NULL,'product',0,0,1,NULL,NULL,'',0,0,NULL,1770810050,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'0'),(50,NULL,NULL,3,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"1\",\"currency\":\"USD\"}}',0,0,0,NULL,0,0,45,NULL,0,NULL,NULL,NULL,0,0,NULL,0,0,NULL,'product',0,0,1,NULL,NULL,'',0,0,NULL,1770810050,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'0'),(51,NULL,NULL,3,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"1\",\"currency\":\"USD\"}}',0,0,0,NULL,0,0,46,NULL,0,NULL,NULL,NULL,0,0,NULL,0,0,NULL,'product',0,0,1,NULL,NULL,'',0,0,NULL,1770810050,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'0'),(52,NULL,NULL,3,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"1\",\"currency\":\"USD\"}}',0,0,0,NULL,0,0,47,NULL,0,NULL,NULL,NULL,0,0,NULL,0,0,NULL,'product',0,0,1,NULL,NULL,'',0,0,NULL,1770810050,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'0'),(53,NULL,NULL,3,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"1\",\"currency\":\"USD\"}}',0,0,0,NULL,0,0,48,NULL,0,NULL,NULL,NULL,0,0,NULL,0,0,NULL,'product',0,0,1,NULL,NULL,'',0,0,NULL,1770810050,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'0'),(54,NULL,NULL,3,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"1\",\"currency\":\"USD\"}}',0,0,0,NULL,0,0,49,NULL,0,NULL,NULL,NULL,0,0,NULL,0,0,NULL,'product',0,0,1,NULL,NULL,'',0,0,NULL,1770810050,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'0'),(55,NULL,NULL,3,-1,0,1,'*',0,0,0,0,'{\"USD\":{\"amount\":\"1\",\"currency\":\"USD\"}}',0,0,0,NULL,0,0,50,NULL,0,NULL,NULL,NULL,0,0,NULL,0,0,NULL,'product',0,0,1,NULL,NULL,'',0,0,NULL,1770810050,0,0,NULL,NULL,0,0,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'0');
/*!40000 ALTER TABLE `nexus_packages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nexus_package_base_prices`
--

DROP TABLE IF EXISTS `nexus_package_base_prices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nexus_package_base_prices` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '7000ecf615d786cfb5f17c6febb9d858',
  `EUR` float DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=71 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nexus_package_base_prices`
--

LOCK TABLES `nexus_package_base_prices` WRITE;
/*!40000 ALTER TABLE `nexus_package_base_prices` DISABLE KEYS */;
INSERT INTO `nexus_package_base_prices` VALUES (1,NULL),(3,NULL),(4,NULL),(5,NULL),(6,NULL);
/*!40000 ALTER TABLE `nexus_package_base_prices` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-02-12 16:18:49
