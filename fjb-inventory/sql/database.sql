-- ============================================================
-- FJB Pasir Gudang - IT Store & E-Waste Inventory System
-- Database: fjb_inventory
-- ============================================================

CREATE DATABASE IF NOT EXISTS fjb_inventory CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE fjb_inventory;

-- ============================================================
-- USERS TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    role ENUM('admin', 'user') NOT NULL DEFAULT 'user',
    department VARCHAR(100),
    is_active TINYINT(1) DEFAULT 1,
    last_login DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ============================================================
-- INVENTORY ITEMS TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS inventory_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_number VARCHAR(50),
    asset_class VARCHAR(50) NOT NULL,
    description VARCHAR(255) NOT NULL,
    serial_number VARCHAR(100),
    brand VARCHAR(100),
    model VARCHAR(100),
    location VARCHAR(100),
    condition_status ENUM('Good','Fair','Poor','Damaged') DEFAULT 'Good',
    item_status ENUM('Active','In Repair','Disposed','Reserved') DEFAULT 'Active',
    purchase_date DATE,
    purchase_price DECIMAL(10,2),
    notes TEXT,
    created_by INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- ============================================================
-- E-WASTE ITEMS TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS ewaste_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_number VARCHAR(50),
    asset_class VARCHAR(50) NOT NULL,
    description VARCHAR(255) NOT NULL,
    serial_number VARCHAR(100),
    original_inventory_id INT,
    condition_on_disposal ENUM('Non-functional','Partially Functional','Obsolete','Damaged Beyond Repair') DEFAULT 'Non-functional',
    disposal_status ENUM('Pending','Approved','Collected','Disposed') DEFAULT 'Pending',
    date_flagged DATE,
    date_disposed DATE,
    disposal_method ENUM('Recycled','Donated','Sold for Parts','Landfill','Awaiting Collection') DEFAULT 'Awaiting Collection',
    weight_kg DECIMAL(6,2),
    vendor_collector VARCHAR(100),
    certificate_number VARCHAR(100),
    notes TEXT,
    created_by INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (original_inventory_id) REFERENCES inventory_items(id) ON DELETE SET NULL
);

-- ============================================================
-- ACTIVITY LOG TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(100) NOT NULL,
    item_type ENUM('inventory','ewaste','user') NOT NULL,
    item_id INT,
    description TEXT,
    ip_address VARCHAR(45),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- ============================================================
-- SEED DATA: Default Admin & User Accounts
-- Passwords are bcrypt hashed:
--   admin123  -> for admin account
--   user123   -> for user account
-- ============================================================
INSERT INTO users (username, password, full_name, email, role, department) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System Administrator', 'admin@fjbpg.com', 'admin', 'IT Department'),
('itstaff', '$2y$10$TKh8H1.PQy3zkowcrpjKs.oDA5TNHaX2HJZx7kNQ3pFCPeMZWFNHi', 'IT Staff User', 'itstaff@fjbpg.com', 'user', 'IT Department');
-- Default passwords: admin=password | itstaff=password

-- ============================================================
-- SEED DATA: Inventory Items (from Excel)
-- ============================================================
INSERT INTO inventory_items (asset_number, asset_class, description, serial_number, item_status, created_by) VALUES
('OEPC1401','MONITOR','LENOVO 73Z AIO COMP','S1001DRR','Active',1),
('OEPC1402','MONITOR','LENOVO 73Z AIO COMP','S1001DS3','Active',1),
('OEPC1403','MONITOR','LENOVO 73Z AIO COMP','S101DRT','Active',1),
('OEPC1404','MONITOR','LENOVO 737 AIO COMP','S1001DRL','Active',1),
('OEPC1509','MONITOR','HP AIO DESKTOP','SGH526RPXX','Active',1),
('OEPC1618','MONITOR','HP ELITEONE 800 G2 23','SGH629QBBY','Active',1),
('OEPC1601','MONITOR','HP ELITEONE 800 G2 23','SGH629QB90','Active',1),
('OEPC1815','MONITOR','HP ELITEONE 800 G2 23','SGH831SXOS','Active',1),
('OEPC1801','MONITOR','ACER VERITOR 24640G-01','DQVPGSMO147460527A3000','Active',1),
('OEPC1701','MONITOR','HP ELITEONE 800 G2 23','SGH709RXNF','Active',1),
('OEPC4914','MONITOR','HP PROONE 400 G4 AIO F','8CG9394V87','Active',1),
(NULL,'MONITOR','HP ELITEONE G2 23','SGH629QB85','Active',1),
(NULL,'MONITOR','HP PRO DISPLAY P201','6CM333OX1W','Active',1),
(NULL,'MONITOR','HP PRO DISPLAY P202','CNC6430Z74','Active',1),
(NULL,'MONITOR','HP PRO DISPLAY P203','CNC8251DW5','Active',1),
('OEPC1220','PC','HP PRO 3330','SGH248QY4K','Active',1),
('OEPC0520','PC','HP COMP AO DC7600','SGH54901YT','Active',1),
('OEPC1204','PC','DELL OPTIPLEX 390DT',NULL,'Active',1),
('OEPC1215','PC','HP PRO 3330 DESKTOP','SGH234QXZD','Active',1),
('OEPC1307','PC','HP ELITE 8300 DEKS III','SGH313SJCD','Active',1),
('OEPC1005','PC','LENOVO THINK CENTRE','R8GMPTZ','Active',1),
('OEPC1716','PC','HP Z240 SFF WORK.PC SCANNER','SGH651RHBM','Active',1),
('OEPS17A1','PC','ROAD TINKER SECURITY SYSTEM','SGH645RK47','Active',1),
('OEPC1715','PC','HP Z240 SFF WORK.PC SCANNER','SGH651RHBK','Active',1),
(NULL,'PC','HP PRO DESK 400 G3 MT',NULL,'Active',1),
(NULL,'PC','HP Z240 SFF WORK.PC SCANNER','SGH824PC40','Active',1),
(NULL,'PC','HP ELITE 8300 DEKS III','SGH331R735','Active',1),
(NULL,'PC','HP PRO DESK 400 G3 MT','SGH603RP1R','Active',1),
(NULL,'PC','HP ELITE 800 G2 SFF','SGH552PBF0','Active',1),
(NULL,'PC','HP PRO 3330','SGH345RXT','Active',1);

-- ============================================================
-- SEED DATA: Sample E-Waste Items
-- ============================================================
INSERT INTO ewaste_items (asset_number, asset_class, description, serial_number, condition_on_disposal, disposal_status, date_flagged, disposal_method, notes, created_by) VALUES
('OEPC0102','MONITOR','HP L1906 19IN LCD MONITOR','CNK9280TV3','Non-functional','Pending','2025-11-01','Awaiting Collection','Screen cracked, backlight failed',1),
('OEPC0305','PC','DELL OPTIPLEX 760','8CKZWL1','Obsolete','Approved','2025-10-15','Recycled','CPU failure, too old to repair',1),
('OEPC0210','LAPTOP','HP PROBOOK 450 G3','5CD6480WFQ','Damaged Beyond Repair','Collected','2025-09-20','Recycled','Water damage, HDD corrupted',1),
(NULL,'KEYBOARD','HP USB KEYBOARD (LOT OF 5)',NULL,'Non-functional','Disposed','2025-08-10','Recycled','Multiple units – keys not working',1),
(NULL,'UPS','APC BACK-UPS 650','4B1412T10517','Non-functional','Pending','2025-12-01','Awaiting Collection','Battery swollen, poses safety risk',1);
