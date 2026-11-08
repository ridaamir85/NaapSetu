-- NaapSetu database schema
-- Source of truth: NaapSetu Final PRD
-- MySQL 8.0+
-- This file defines structure only. It contains no credentials or user data.

CREATE DATABASE IF NOT EXISTS naapsetu
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE naapsetu;

-- 1. Users
CREATE TABLE users (
    user_id INT NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL,
    phone VARCHAR(20) NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('customer', 'tailor', 'admin') NOT NULL DEFAULT 'customer',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Tailor profiles
CREATE TABLE tailor_profiles (
    tailor_id INT NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    shop_name VARCHAR(120) NOT NULL,
    city VARCHAR(60) NOT NULL,
    address VARCHAR(255) NOT NULL,
    max_active_orders INT NOT NULL,
    PRIMARY KEY (tailor_id),
    UNIQUE KEY uq_tailor_profiles_user_id (user_id),
    CONSTRAINT chk_tailor_profiles_capacity CHECK (max_active_orders >= 1),
    CONSTRAINT fk_tailor_profiles_user
        FOREIGN KEY (user_id) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Tailor services
CREATE TABLE tailor_services (
    service_id INT NOT NULL AUTO_INCREMENT,
    tailor_id INT NOT NULL,
    garment_type VARCHAR(60) NOT NULL,
    base_price DECIMAL(10,2) NOT NULL,
    avg_days INT NOT NULL,
    PRIMARY KEY (service_id),
    CONSTRAINT chk_tailor_services_price CHECK (base_price >= 0),
    CONSTRAINT chk_tailor_services_avg_days CHECK (avg_days >= 1),
    CONSTRAINT fk_tailor_services_tailor
        FOREIGN KEY (tailor_id) REFERENCES tailor_profiles(tailor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Measurement profiles
CREATE TABLE measurement_profiles (
    profile_id INT NOT NULL AUTO_INCREMENT,
    customer_id INT NOT NULL,
    profile_name VARCHAR(80) NOT NULL,
    gender ENUM('male', 'female') NOT NULL,
    PRIMARY KEY (profile_id),
    CONSTRAINT fk_measurement_profiles_customer
        FOREIGN KEY (customer_id) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Measurement versions
CREATE TABLE measurement_versions (
    version_id INT NOT NULL AUTO_INCREMENT,
    profile_id INT NOT NULL,
    version_no INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (version_id),
    UNIQUE KEY uq_measurement_versions_profile_version (profile_id, version_no),
    CONSTRAINT chk_measurement_versions_number CHECK (version_no >= 1),
    CONSTRAINT fk_measurement_versions_profile
        FOREIGN KEY (profile_id) REFERENCES measurement_profiles(profile_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Measurement values
CREATE TABLE measurement_values (
    value_id INT NOT NULL AUTO_INCREMENT,
    version_id INT NOT NULL,
    measurement_name VARCHAR(50) NOT NULL,
    value_cm DECIMAL(5,2) NOT NULL,
    PRIMARY KEY (value_id),
    UNIQUE KEY uq_measurement_values_version_name (version_id, measurement_name),
    CONSTRAINT chk_measurement_values_value CHECK (value_cm > 0),
    CONSTRAINT fk_measurement_values_version
        FOREIGN KEY (version_id) REFERENCES measurement_versions(version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Measurement permissions
CREATE TABLE measurement_permissions (
    permission_id INT NOT NULL AUTO_INCREMENT,
    profile_id INT NOT NULL,
    tailor_id INT NOT NULL,
    granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at DATETIME NULL,
    PRIMARY KEY (permission_id),
    CONSTRAINT chk_measurement_permissions_dates
        CHECK (revoked_at IS NULL OR revoked_at >= granted_at),
    CONSTRAINT fk_measurement_permissions_profile
        FOREIGN KEY (profile_id) REFERENCES measurement_profiles(profile_id),
    CONSTRAINT fk_measurement_permissions_tailor
        FOREIGN KEY (tailor_id) REFERENCES tailor_profiles(tailor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Style profiles
CREATE TABLE style_profiles (
    style_id INT NOT NULL AUTO_INCREMENT,
    customer_id INT NOT NULL,
    name VARCHAR(80) NOT NULL,
    preferences JSON NOT NULL,
    PRIMARY KEY (style_id),
    CONSTRAINT fk_style_profiles_customer
        FOREIGN KEY (customer_id) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Order requests
CREATE TABLE order_requests (
    request_id INT NOT NULL AUTO_INCREMENT,
    customer_id INT NOT NULL,
    tailor_id INT NOT NULL,
    service_id INT NOT NULL,
    profile_id INT NOT NULL,
    style_id INT NULL,
    fabric_source ENUM('customer', 'tailor') NOT NULL DEFAULT 'customer',
    customer_notes TEXT NULL,
    status ENUM('pending', 'quoted', 'declined', 'cancelled')
        NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (request_id),
    CONSTRAINT fk_order_requests_customer
        FOREIGN KEY (customer_id) REFERENCES users(user_id),
    CONSTRAINT fk_order_requests_tailor
        FOREIGN KEY (tailor_id) REFERENCES tailor_profiles(tailor_id),
    CONSTRAINT fk_order_requests_service
        FOREIGN KEY (service_id) REFERENCES tailor_services(service_id),
    CONSTRAINT fk_order_requests_profile
        FOREIGN KEY (profile_id) REFERENCES measurement_profiles(profile_id),
    CONSTRAINT fk_order_requests_style
        FOREIGN KEY (style_id) REFERENCES style_profiles(style_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Quotations
CREATE TABLE quotations (
    quotation_id INT NOT NULL AUTO_INCREMENT,
    request_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    deadline DATE NOT NULL,
    status ENUM(
        'sent',
        'revision_requested',
        'accepted',
        'rejected',
        'expired'
    ) NOT NULL DEFAULT 'sent',
    PRIMARY KEY (quotation_id),
    CONSTRAINT chk_quotations_amount CHECK (amount > 0),
    CONSTRAINT fk_quotations_request
        FOREIGN KEY (request_id) REFERENCES order_requests(request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Orders
CREATE TABLE orders (
    order_id INT NOT NULL AUTO_INCREMENT,
    quotation_id INT NOT NULL,
    measurement_version_id INT NOT NULL,
    style_snapshot JSON NOT NULL,
    status ENUM('confirmed', 'cutting', 'stitching', 'ready', 'delivered')
        NOT NULL DEFAULT 'confirmed',
    confirmed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (order_id),
    UNIQUE KEY uq_orders_quotation_id (quotation_id),
    CONSTRAINT fk_orders_quotation
        FOREIGN KEY (quotation_id) REFERENCES quotations(quotation_id),
    CONSTRAINT fk_orders_measurement_version
        FOREIGN KEY (measurement_version_id)
        REFERENCES measurement_versions(version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Order status history
CREATE TABLE order_status_history (
    history_id INT NOT NULL AUTO_INCREMENT,
    order_id INT NOT NULL,
    status VARCHAR(30) NOT NULL,
    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (history_id),
    CONSTRAINT fk_order_status_history_order
        FOREIGN KEY (order_id) REFERENCES orders(order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. Fabric records
CREATE TABLE fabric_records (
    fabric_id INT NOT NULL AUTO_INCREMENT,
    order_id INT NOT NULL,
    source ENUM('customer', 'tailor') NOT NULL,
    quantity DECIMAL(5,2) NOT NULL,
    received_at DATETIME NULL,
    PRIMARY KEY (fabric_id),
    UNIQUE KEY uq_fabric_records_order_id (order_id),
    CONSTRAINT chk_fabric_records_quantity CHECK (quantity > 0),
    CONSTRAINT fk_fabric_records_order
        FOREIGN KEY (order_id) REFERENCES orders(order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. Fit feedback
CREATE TABLE fit_feedback (
    feedback_id INT NOT NULL AUTO_INCREMENT,
    order_id INT NOT NULL,
    rating TINYINT NOT NULL,
    notes TEXT NULL,
    PRIMARY KEY (feedback_id),
    UNIQUE KEY uq_fit_feedback_order_id (order_id),
    CONSTRAINT chk_fit_feedback_rating CHECK (rating BETWEEN 1 AND 5),
    CONSTRAINT fk_fit_feedback_order
        FOREIGN KEY (order_id) REFERENCES orders(order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. Alteration requests
CREATE TABLE alteration_requests (
    alteration_id INT NOT NULL AUTO_INCREMENT,
    order_id INT NOT NULL,
    issue TEXT NOT NULL,
    status ENUM('open', 'in_progress', 'done') NOT NULL DEFAULT 'open',
    PRIMARY KEY (alteration_id),
    CONSTRAINT fk_alteration_requests_order
        FOREIGN KEY (order_id) REFERENCES orders(order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
