# Deployment Instructions - ST. BENEDICT'S EARLY YEARS BRITISH ACADEMY

## System Requirements

### Server Requirements
- PHP 7.4 or higher
- MySQL 5.7 or higher
- Apache/Nginx web server
- SSL Certificate (HTTPS)
- 2GB RAM minimum
- 20GB disk space minimum

### PHP Extensions Required
- PDO PHP Extension
- MySQLi PHP Extension
- OpenSSL PHP Extension
- GD PHP Extension
- FileInfo PHP Extension
- JSON PHP Extension
- cURL PHP Extension
- ZIP PHP Extension

## Installation Steps

### 1. Upload Files
Upload all files to your web server using FTP or SSH.

### 2. Database Setup
```bash
# Import database schema
mysql -u username -p st_benedicts_academy < sql/database.sql