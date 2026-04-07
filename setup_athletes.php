<?php
// setup_athletes.php
require_once 'db_config.php';

try {
    // Create athletes table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS athletes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id VARCHAR(50) UNIQUE NOT NULL,
            first_name VARCHAR(100) NOT NULL,
            middle_name VARCHAR(100),
            last_name VARCHAR(100) NOT NULL,
            birthdate DATE NOT NULL,
            gender ENUM('Male', 'Female', 'Other') NOT NULL,
            contact_number VARCHAR(20),
            email VARCHAR(100),
            address TEXT,
            course VARCHAR(100),
            year_level VARCHAR(20),
            sport VARCHAR(100) NOT NULL,
            position VARCHAR(100),
            blood_type VARCHAR(10),
            emergency_contact VARCHAR(100),
            emergency_contact_number VARCHAR(20),
            
            -- Document fields (paths to uploaded files)
            birth_certificate VARCHAR(255),
            form_137 VARCHAR(255),
            good_moral VARCHAR(255),
            medical_certificate VARCHAR(255),
            waiver_form VARCHAR(255),
            photo VARCHAR(255),
            
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_sport (sport),
            INDEX idx_course (course),
            INDEX idx_year (year_level)
        )
    ");
    
    echo "Athletes table created successfully!<br>";
    echo '<a href="athletes.php">Go to Athletes Management</a>';
    
} catch(PDOException $e) {
    die("Error creating table: " . $e->getMessage());
}
?>