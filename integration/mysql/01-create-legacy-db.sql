-- The MySQL image creates only MYSQL_DATABASE (wps_hpos). The second store
-- uses its own database so the HPOS and legacy suites cannot contaminate each
-- other's order storage.
CREATE DATABASE IF NOT EXISTS `wps_legacy`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON `wps_legacy`.* TO 'wordpress'@'%';
FLUSH PRIVILEGES;
