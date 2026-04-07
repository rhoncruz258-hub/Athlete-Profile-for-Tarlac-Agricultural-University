<?php
session_start();
// --- Database Connection ---
$conn = new mysqli("localhost", "root", "", "athlete_system");

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

// Create tables if they don't exist
$create_borrowers_table = "CREATE TABLE IF NOT EXISTS borrowers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    borrower_type ENUM('Student', 'Faculty', 'Staff', 'Others') NOT NULL,
    other_type VARCHAR(255),
    college_unit VARCHAR(255),
    contact_number VARCHAR(50),
    id_number VARCHAR(50),
    borrow_date DATE,
    return_date DATE,
    status VARCHAR(50) DEFAULT 'Pending',
    disapproval_remarks TEXT,
    osd_staff VARCHAR(255),
    osd_staff_date DATE,
    osd_director VARCHAR(255),
    director_date DATE,
    borrower_signature VARCHAR(255),
    receiver_signature VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";

$create_items_table = "CREATE TABLE IF NOT EXISTS borrowed_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    borrower_id INT NOT NULL,
    quantity INT,
    equipment_description TEXT,
    purpose TEXT,
    remarks TEXT,
    availability VARCHAR(50),
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id) ON DELETE CASCADE
)";

$conn->query($create_borrowers_table);
$conn->query($create_items_table);

$success = false;
$form_data = [];
$items = [];
$borrower_id = null;
$view_mode = false;

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_form'])) {
    // Store submitted data
    $form_data = $_POST;

    // Insert borrower
    $stmt = $conn->prepare("INSERT INTO borrowers 
    (name, borrower_type, other_type, college_unit, contact_number, id_number, borrow_date, return_date, status, disapproval_remarks, osd_staff, osd_staff_date, osd_director, director_date, borrower_signature, receiver_signature) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $status = $_POST['status'] ?? 'Pending';
    $disapproval_remarks = $_POST['disapproval_remarks'] ?? '';
    $osd_staff = $_POST['osd_staff'] ?? '';
    $osd_staff_date = $_POST['osd_staff_date'] ?? date('Y-m-d');
    $osd_director = $_POST['osd_director'] ?? '';
    $director_date = $_POST['director_date'] ?? date('Y-m-d');
    $borrower_signature = $_POST['borrower_signature'] ?? '';
    $receiver_signature = $_POST['receiver_signature'] ?? '';

    $stmt->bind_param("ssssssssssssssss",
        $_POST['name'],
        $_POST['borrower_type'],
        $_POST['other_type'],
        $_POST['college_unit'],
        $_POST['contact_number'],
        $_POST['id_number'],
        $_POST['borrow_date'],
        $_POST['return_date'],
        $status,
        $disapproval_remarks,
        $osd_staff,
        $osd_staff_date,
        $osd_director,
        $director_date,
        $borrower_signature,
        $receiver_signature
    );

    $stmt->execute();
    $borrower_id = $stmt->insert_id;
    $stmt->close();

    // Insert borrowed items
    if (!empty($_POST['quantity'])) {
        for ($i = 0; $i < count($_POST['quantity']); $i++) {
            if (!empty($_POST['equipment_description'][$i])) {
                $stmt = $conn->prepare("INSERT INTO borrowed_items 
                (borrower_id, quantity, equipment_description, purpose, remarks, availability)
                VALUES (?, ?, ?, ?, ?, ?)");

                $availability = $_POST['availability'][$i] ?? null;

                $stmt->bind_param("iissss",
                    $borrower_id,
                    $_POST['quantity'][$i],
                    $_POST['equipment_description'][$i],
                    $_POST['purpose'][$i],
                    $_POST['remarks'][$i],
                    $availability
                );

                $stmt->execute();
                $stmt->close();
            }
        }
    }

    $success = true;
}

// If viewing a specific submitted form
if (isset($_GET['view_id'])) {
    $view_mode = true;
    $view_id = intval($_GET['view_id']);
    
    // Fetch borrower data
    $stmt = $conn->prepare("SELECT * FROM borrowers WHERE id = ?");
    $stmt->bind_param("i", $view_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $form_data = $result->fetch_assoc();
    $stmt->close();
    
    // Fetch borrowed items
    $stmt = $conn->prepare("SELECT * FROM borrowed_items WHERE borrower_id = ?");
    $stmt->bind_param("i", $view_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
    }
    $stmt->close();
    
    $borrower_id = $view_id;
    $success = true;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <title>Borrower's Form | TAU Sports</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
         
        }
        
        body {
           
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f7fa;
            color: #333;
            font-size: 13px;

        }
        
        .header {
            background: linear-gradient(135deg, #0c3a1d 0%, #1a5c2f 100%);
            color: white;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 12px rgba(12, 58, 29, 0.2);
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        
        .header-left {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .university-badge {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .logo-circle {
            width: 50px;
            height: 50px;
            background: linear-gradient(45deg, #ffd700, #ffed4e);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: #0c3a1d;
            border: 3px solid white;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.2);
        }

        .logo-circle img {
            width: 120%;
            height: 120%;
            object-fit: cover;
        }
        
        .university-info h1 {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 2px;
        }
        
        .university-info .subtitle {
            font-size: 12px;
            color: #b0ffc9;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .user-welcome {
            text-align: right;
        }
        
        .user-name {
            font-weight: 600;
            font-size: 16px;
        }
        
        .user-role {
            font-size: 12px;
            color: #ffd700;
            background: rgba(0,0,0,0.2);
            padding: 2px 10px;
            border-radius: 10px;
            margin-top: 2px;
            display: inline-block;
            text-transform: uppercase;
        }
        
        .logout-btn {
            background: white;
            color: #0c3a1d;
            border: none;
            padding: 8px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
        }
        
        .logout-btn:hover {
            background: #f0f0f0;
            transform: translateY(-1px);
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }
        
        .main-container {
            display: flex;
            min-height: calc(100vh - 80px);
        }
        
        .sidebar {
            width: 250px;
            background: white;
            border-right: 1px solid #e0e0e0;
            padding: 30px 0;
            box-shadow: 2px 0 10px rgba(0,0,0,0.05);
            font-size: medium;
        }
        
        .nav-menu {
            list-style: none;
        }
        
        .nav-item {
            margin-bottom: 5px;
        }
        
        .nav-link {
            display: block;
            padding: 15px 30px;
            color: #555;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s;
            border-left: 4px solid transparent;
        }
        
        .nav-link:hover {
            background: #f8f9fa;
            color: #0c3a1d;
            border-left: 4px solid #0c3a1d;
        }
        
        .nav-link.active {
            background: linear-gradient(to right, rgba(12, 58, 29, 0.1), transparent);
            color: #0c3a1d;
            border-left: 4px solid #0c3a1d;
            font-weight: 600;
        }
        
        .content {
            flex: 1;
            padding: 30px;
            background: #f5f7fa;
        }
        
        .page-title {
            color: #0c3a1d;
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e0e0e0;
        }
        
        .form-container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            margin-bottom: 30px;
            font-family: 'Times New Roman', Times, serif;
        }
        
        /* BORROWER'S FORM EXACT STYLES - Matching Word Document */
        .borrower-form {
            max-width: 1100px;
            margin: 0 auto;
            background: white;
            font-size: 10pt;
            line-height: 1.0;
            position: relative;
        }
        
        .borrower-form h2 {
            text-align: center;
            font-size: 12pt;
            font-weight: bold;
            margin: 15px 0 20px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .borrower-form table {
            width: 90%;
            border-collapse: collapse;
            margin: 8px 0;
            margin-left: 7%;
        }
        
        .borrower-form td, .borrower-form th {
           height: 30px;
            vertical-align: top;
            line-height: 1.0;
            
        }
        
        .borrower-form .bordered-table {
            border: 1px solid black;
        }
        
        .borrower-form .bordered-table td,
        .borrower-form .bordered-table th {
            border: 1px solid black;
            height: 30px;
            line-height: 1.0;
        }
        
        .borrower-form th {
            font-weight: bold;
            text-align: center;
            background-color: #f2f2f2;
        }
        
        .borrower-form .no-border td,
        .borrower-form .no-border th {
            border: none;
        }
        
        .borrower-form .center {
            text-align: center;
        }
        
        /* Input fields styling */
        .borrower-form input[type="text"],
        .borrower-form input[type="number"],
        .borrower-form input[type="date"],
        .borrower-form select {
            border: none;
            border-bottom: 1px solid #333;
            background: transparent;
            font-family: 'Times New Roman', Times, serif;
            font-size: 10pt;
            padding: 2px 4px;
            width: 100%;
        }
        
        .borrower-form input[type="radio"] {
            margin: 0 4px;
            transform: scale(1);
        }
        
        .borrower-form .radio-group {
            white-space: nowrap;
        }
        
        .borrower-form .field-line {
            border-bottom: 1px solid black;
            min-width: 180px;
            display: inline-block;
            padding: 0 5px;
            margin: 0 5px;
            height: 18px;
        }
        
        .borrower-form .signature-line {
            border-bottom: 1px solid black;
            min-height: 20px;
            margin: 2px 0 5px;
            width: 100%;
        }

        .signature-line1 {
            border-bottom: 1px solid black;
            min-height: 20px;
            margin: 2px 0 5px;
            width: 40%;
        }
                .cen {
margin-left: 65px;
        }
        .signature-line2 {
            border-bottom: 1px solid black;
            min-height: 20px;
            margin: 0px 0 5px;
            width: 50%;
        }
                .cen2 {
margin-left: 65px;
        }
        
        /* Print styles - Remove browser header/footer and position header image correctly */
        @media print {
            @page {
                size: A4;
                margin: 1.5cm;
                /* Remove browser's default header and footer */
                margin-top: 0.5cm;
                margin-bottom: 0.5cm;
            }
            
            /* Hide browser-generated content */
            @page :left {
                margin: 0.5cm;
            }
            @page :right {
                margin: 0.5cm;
            }
            
            body {
                background: white;
                margin: 0;
                padding: 0;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            /* Hide all non-print elements */
            .header, .sidebar, .page-title, .form-actions, 
            .no-print, .btn, .action-buttons, .view-controls,
            .logout-btn, .user-info, .add-row-btn {
                display: none !important;
            }
            
            .main-container {
                display: block;
                padding: 0;
                margin: 0;
            }
            
            .content {
                padding: 0;
                margin: 0;
                background: white;
            }
            
            .form-container {
                box-shadow: none;
                padding: 0;
                margin: 0;
                border: none;
                background: white;
            }
            
            .borrower-form {
                font-size: 10pt;
                line-height: 1.0;
                max-width: 100%;
                padding: 0;
                margin: 0;
            }
            
            .borrower-form h2 {
                font-size: 12pt;
                margin: 10px 0 15px;
                page-break-after: avoid;
            }
            
/* Position header image at the top of the page */
.print-header {
    display: flex;
    text-align: center;
    width: 105%;    
}

.print-header img {
    width: 105%;
    height: 60%;
    display: block;
}
            
            /* Ensure all form elements print properly */
            .borrower-form input,
            .borrower-form textarea {
                border: none;
                border-bottom: 1px solid black;
                background: transparent;
                font-size: 10pt;
                padding: 2px 4px;
            }
            
            .borrower-form input[type="radio"] {
                border: none;
                -webkit-appearance: radio;
                appearance: radio;
            }
            
            /* Keep table borders */
            .bordered-table {
                border: 1px solid black;
            }
            
            /* Ensure underscores print */
            .field-line {
                border-bottom: 1px solid black;
            }
            
            /* Fix for radio buttons in table */
            .bordered-table input[type="radio"] {
                margin: 0 auto;
                display: inline-block;
            }
            
            /* Ensure proper page breaks */
            .borrower-form table {
                page-break-inside: avoid;
            }
            
            /* Make sure text is black */
            * {
                color: black !important;
                background: transparent !important;
            }
            
            /* Remove any extra spacing */
            .borrower-form > *:first-child {
                margin-top: 0;
            }
        }
        
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            font-family: Arial, sans-serif;
            font-size: 12px;
        }
        
        .btn-primary {
            background: #0c3a1d;
            color: white;
        }
        
        .btn-primary:hover {
            background: #1a5c2f;
        }
        
        .btn-success {
            background: #28a745;
            color: white;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-info {
            background: #17a2b8;
            color: white;
        }
        
        .form-actions {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin: 20px 0 10px;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 15px;
            font-family: Arial, sans-serif;
        }
        
        .view-controls {
            margin-bottom: 15px;
            padding: 12px;
            background: #e9ecef;
            border-radius: 5px;
            font-family: Arial, sans-serif;
        }
        
        .add-row-btn {
            background: #17a2b8;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            cursor: pointer;
            margin: 5px 0;
            font-size: 12px;
        }
        
        .add-row-btn:hover {
            background: #138496;
        }
        .borrower-form label,
        .borrower-form span,
        .borrower-form div,
        .borrower-form p,
        .borrower-form input,
        .borrower-form select,
        .borrower-form .radio-group,
        .borrower-form .field-line,
        .borrower-form .signature-line,
        .borrower-form .signature-line1,
        .borrower-form .signature-line2,
        .borrower-form strong {
            font-size: 16px;
        }

        /* View mode styles - matching print layout */
.borrower-form-view {
    font-family: 'Times New Roman', Times, serif;
    max-width: 1100px;
    margin: 0 auto;
    padding: 20px;
}

.borrower-form-view .field-line {
    border-bottom: 1px solid black;
    display: inline-block;
    padding: 0 5px;
    margin: 0 5px;
    min-height: 20px;
    vertical-align: middle;
}

.borrower-form-view .signature-line {
    border-bottom: 1px solid black;
    min-height: 25px;
    margin: 5px 0;
    width: 100%;
}

.borrower-form-view .signature-line1 {
    border-bottom: 1px solid black;
    min-height: 25px;
    margin: 5px 0;
}

.borrower-form-view table td {
    padding: 5px;
    vertical-align: top;
}

.borrower-form-view .bordered-table td,
.borrower-form-view .bordered-table th {
    border: 1px solid black;
    padding: 8px 5px;
}

.borrower-form-view .center {
    text-align: center;
}

.borrower-form-view input[type="checkbox"]:disabled {
    opacity: 1;
    accent-color: #0c3a1d;
}


.footer-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
    font-family: 'Calisto MT';
    
}

.footer-table td {
    border: 1px solid #999; 
    padding: 5px 6px;
    vertical-align: top;
    
}

/* First row styling - Italic and Bold */
.header-row td {
    font-style: italic;
}

/* Column widths */
.footer-label {
    width: 18%;
}

.footer-content {
    width: 20%;
}

.footer-effectivity {
    width: 20%;
}

.footer-page {
    width: 10%;
    
}

/* Remove any border or line from page-footer */
.page-footer {
    position: absolute;
    bottom: 0.8cm;
    left: 1cm;
    right: 1cm;
    height: auto;
}

    </style>
</head>
<body>
    <div class="header">
        <div class="header-left">
            <div class="university-badge">
                <div class="logo-circle"><img src="taulogo.png" alt="TAU Logo"></div> 
                <div class="logo-circle"><img src="sdologo.png" alt="OSD Logo"></div>
                <div class="university-info">
                    <h1>TARLAC AGRICULTURAL UNIVERSITY</h1>
                    <div class="subtitle">Sports Development Office</div>
                </div>
            </div>
        </div>
        
        <div class="user-info">
            <div class="user-welcome">
                <div class="user-name">Welcome, <?php echo htmlspecialchars($_SESSION['username'] ?? 'User'); ?></div>
                <div class="user-role"><?php echo htmlspecialchars($_SESSION['role'] ?? 'Staff'); ?></div>
            </div>
            <a href="logout.php" class="logout-btn">Logout</a>
        </div>
    </div>
    
    <div class="main-container">
        <nav class="sidebar">
            <ul class="nav-menu">
                <li class="nav-item"><a href="dashboard.php" class="nav-link"><i class="fas fa-home"></i> Dashboard</a></li>
                <li class="nav-item"><a href="athletes.php" class="nav-link"><i class="fas fa-users"></i> Athlete Profiles</a></li>
                <li class="nav-item"><a href="report.php" class="nav-link"><i class="fas fa-chart-bar"></i> Athletes Report</a></li>
                <li class="nav-item"><a href="borrowers_form.php" class="nav-link active"><i class="fas fa-file"></i> Borrowers Form</a></li>
                <li class="nav-item"><a href="borrowers_list.php" class="nav-link"><i class="fas fa-clipboard"></i> Borrowers List</a></li>
            </ul>
        </nav>
        
        <main class="content">
            <h1 class="page-title no-print">Borrower's Form</h1>
            
            <?php if($success && $borrower_id && !$view_mode): ?>
            <div class="alert-success no-print">
                <strong>✓ Form Successfully Submitted!</strong> Reference ID: #<?php echo $borrower_id; ?>
            </div>
            
            <div class="view-controls no-print">
                <strong>Form Submitted Successfully</strong><br>
                <a href="borrowers_form.php?view_id=<?php echo $borrower_id; ?>" class="btn btn-info">View/Print Submitted Form</a>
                <a href="borrowers_form.php" class="btn btn-primary">Create New Form</a>
            </div>
            <?php endif; ?>
            
            <div class="form-container">
                <div class="borrower-form">
                    <!-- Print Header - Now positioned at the very top where the date/URL used to appear -->
<div class="print-header">
    <img src="/Athlete Profile System/assets/images/header2.png" alt="Tarlac Agricultural University Header" hidden>
</div>

                    <br>
                    <br>
                    <h2 style="font-size: 20px;">BORROWER'S FORM</h2>
                    
<?php if($view_mode && !empty($form_data)): ?>
    <!-- VIEW MODE - Display submitted form exactly like print layout -->
    <div class="borrower-form-view">
        <table class="no-border" style="width: 100%; margin-bottom: 5px;">
            <tr>
                <td style="width: 65%; ">Name: <span class="field-line" style="min-width: 310px; text-align:center;"><?php echo htmlspecialchars($form_data['name'] ?? ''); ?></span></td>
                <td style="width: 40%;">Date: <span class="field-line" style="min-width: 150px; text-align:center;"><?php echo htmlspecialchars($form_data['borrow_date'] ?? ''); ?></span></td>
            </tr>
        </table>
        
        <table class="no-border" style="width: 100%; margin-bottom: 5px;">
            <tr>
                <td>
                    <span class="radio-group">
                        <td><input type="checkbox" <?php echo (($form_data['borrower_type'] ?? '') == 'Student') ? 'checked' : ''; ?> disabled> Student</td>
                        <td><input type="checkbox" <?php echo (($form_data['borrower_type'] ?? '') == 'Faculty') ? 'checked' : ''; ?> disabled> Faculty</td>
                        <td><input type="checkbox" <?php echo (($form_data['borrower_type'] ?? '') == 'Staff') ? 'checked' : ''; ?> disabled> Staff </td>
                           <td> <div style="margin-left: 25.7%;"> Others:<span class="field-line" style=" min-width: 200px;"><?php echo htmlspecialchars($form_data['other_type'] ?? ''); ?></span>
                   </td></div> </span>
                </td>
            </tr>
        </table>
        
        <table class="no-border" style="width: 100%; margin-bottom: 5px;">
            <tr>
                <td>College/Unit/Department: <span class="field-line" style="min-width: 300px;"><?php echo htmlspecialchars($form_data['college_unit'] ?? ''); ?></span></td>
            </tr>
        </table>
        
        <table class="no-border" style="width: 100%; margin-bottom: 5px;">
            <tr>
                <td style="width: 60%;">Contact Number: <span class="field-line" style="min-width: 300px;"><?php echo htmlspecialchars($form_data['contact_number'] ?? ''); ?></span></td>
                <td style="width: 40%;">ID Number: <span class="field-line" style="min-width: 150px;"><?php echo htmlspecialchars($form_data['id_number'] ?? ''); ?></span></td>
            </tr>
        </table>
        
        <!-- Equipment Table exactly like print layout -->
        <table class="bordered-table" style="width: 95%; margin-left: 40px; height: 30px;">
            <thead>
                <tr>
                    <th rowspan="2" style="width: 10%;">Unit/<br>Quantity</th>
                    <th rowspan="2" style="width: 25%;">Equipment<br>Description</th>
                    <th rowspan="2" style="width: 25%;">Purpose</th>
                    <th colspan="3" style="width: 40%;">Remarks</th>
                </tr>
                <tr>
                    <th style="width: 13.33%;">Available</th>
                    <th style="width: 13.33%;">Not<br>Available</th>
                    <th style="width: 13.34%;">For<br>Reservation</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                if (!empty($items)): 
                    foreach ($items as $item): ?>
                    <tr>
                        <td class="center"><?php echo htmlspecialchars($item['quantity']); ?></td>
                        <td><?php echo htmlspecialchars($item['equipment_description']); ?></td>
                        <td><?php echo htmlspecialchars($item['purpose']); ?></td>
                        <td class="center"><?php echo ($item['availability'] == 'Available') ? '✓' : ''; ?></td>
                        <td class="center"><?php echo ($item['availability'] == 'Not Available') ? '✓' : ''; ?></td>
                        <td class="center"><?php echo ($item['availability'] == 'For Reservation') ? '✓' : ''; ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                
                <?php 
                $submittedCount = !empty($items) ? count($items) : 0;
                $emptyRowsNeeded = max(0, 5 - $submittedCount);
                
                for ($i = 0; $i < $emptyRowsNeeded; $i++): ?>
                <tr>
                    <td class="center"></td>
                    <td></td>
                    <td></td>
                    <td class="center"></td>
                    <td class="center"></td>
                    <td class="center"></td>
                </tr>
                <?php endfor; ?>
            </tbody>
        </table>
        
        <!-- Signature sections -->
        <table class="no-border" style="width: 76%; margin-left: 45px; margin-top: 5px;">
            <tr>
                <td>
                    <div style="text-align:center;" class="signature-line1"><?php echo htmlspecialchars($form_data['borrower_signature'] ?? ''); ?></div>
                    Signature over printed name of Borrower
                </td>
            </tr>
        </table>
        
        <!-- Received by section -->
        <table class="no-border" style="width: 120%; margin-left: 45px;">
            <tr>
                <td style="width: 50%;"> Received by:
                    <span style="text-align:center; border-bottom: 1px solid black; display: inline-block; width: 30%; min-height: 20px; vertical-align: middle;"><?php echo htmlspecialchars($form_data['osd_staff'] ?? ''); ?></span><br>
                  <div style="margin-left: 20%;">OSD Staff</div>
                </td>
            </tr>
        </table>

        <!-- Date Signed section -->
        <table class="no-border" style="width: 120%; margin-left: 45px;">
            <tr>
                <td style="width: 50%;">
                    Date Signed:
                    <span style="text-align:center; border-bottom: 1px solid black; display: inline-block; width: 30%; min-height: 20px; vertical-align: middle;"><?php echo htmlspecialchars($form_data['osd_staff_date'] ?? ''); ?></span>
                </td>
            </tr>
        </table>
        
        <table class="no-border" style="width: 100%; margin-left: 45px;">
            <tr>
                <td style="width: 20%;">
                    <input type="checkbox" <?php echo (($form_data['status'] ?? '') == 'Approved') ? 'checked' : ''; ?> disabled> Approved<br><br>
                    <div style="width: 80%; "><input type="checkbox" <?php echo (($form_data['status'] ?? '') == 'Disapproved') ? 'checked' : ''; ?> disabled> Disapproved
                       <span style="margin-left: 100px;">Remarks for disapproval:</span><span class="field-line" style="min-width: 310px;"><?php echo htmlspecialchars($form_data['disapproval_remarks'] ?? ''); ?></span>  
                    </div>
                </td>
            </tr>
        </table>
        <br>
        <table class="no-border" style="width: 100%; margin: 5px 0; text-align: center;">
            <tr>
                <td style="width: 0%;"></td>
                <td style="width: 40%;">
                    <div class="signature-line1" style="width: 30%; margin-left: 35%;"><?php echo htmlspecialchars($form_data['osd_director'] ?? ''); ?></div>
                    <div class="cen" style="margin-left: 0%;"><strong>OSD Director</strong></div>
                    Date signed: <span class="field-line" style="min-width: 150px;"><?php echo htmlspecialchars($form_data['director_date'] ?? ''); ?></span>
                </td>
            </tr>
        </table>
        <br>
        
        <table class="no-border" style="width: 100%; margin-left: 45px;">
            <tr>
                <td>
                    <input type="checkbox" <?php echo (!empty($form_data['return_date'])) ? 'checked' : ''; ?> disabled>
                    I agree that the equipment received is/are in good condition and <strong>shall be returned in good condition</strong>/or before<br>
                    <div class="signature-line" style="width: 30%; margin-top: 5px; text-align:center; "><?php echo htmlspecialchars($form_data['return_date'] ?? ''); ?></div>
                </td>
            </tr>
        </table>
        <br>
        

        <table class="no-border" style="width: 100%; margin: 5px 0;">
            <tr>
                <td>
                    <div class="signature-line" style="width: 40%; margin-left: 32.5%; text-align:center;"><?php echo htmlspecialchars($form_data['receiver_signature'] ?? ''); ?></div>
                    <div style="text-align: center; margin-left: 22.8%; width: 60%; text-align:center;">Borrower/Receiver's Signature over printed name</div>
                </td>
            </tr>
        </table>
    </div>
    <br>
    <br>
     <br>
    <br>
        <br>
          <br>   
<div class="page-footer">
    <table style="width: 95%;" class="footer-table">
        <!-- First row with 4 columns - Italic and Bold -->
        <tr class="header-row">
            <td class="footer-label">Form Code:</td>
            <td class="footer-content">Revision No.:</td>
            <td class="footer-effectivity">Effectivity Date: </td>
            <td class="footer-page">Page:</td>
        </tr>
        <!-- Second row with 4 columns - Right aligned -->
        <tr class="code-row">
            <td style="text-align: right;">TAU-OSD-QF-01</td>
            <td style="text-align: right;">01</td>
            <td style="text-align: right;">January 25, 2023</td>
            <td style="text-align: right; font-weight: bold;">1 <span style="font-weight:100;"> of</span> 1 </td>
        </tr>
    </table>
</div>    

                        
                    <?php else: ?>
                        <!-- FILLABLE FORM - New entry exactly like Word document -->
                        <form method="POST" id="borrowerForm">
                            <table class="no-border" style="width: 100%;">
                                <tr>
                                    <td style="width: 30%;">Name: <input type="text" name="name" required style="width: 70%;"></td>
                                    <td style="width: 20%;">Date <input type="date" name="borrow_date" value="<?php echo date('Y-m-d'); ?>" required style="width: 62.5%;"></td>
                                </tr>
                            </table>
                            
                            <table class="no-border">
                                <tr>
                                    <td>
                                        <span class="radio-group">
                                            <td><input type="checkbox" name="borrower_type" value="Student" required> Student</td>
                                            <td><input type="checkbox" name="borrower_type" value="Faculty"> Faculty</td>
                                            <td><input type="checkbox" name="borrower_type" value="Staff"> Staff </td>
                                            <td> <div style="margin-left: 27.5%;"><input type="checkbox"> Others(specify): <input type="text" name="other_type" id="other_type" style="width: 198px;" disabled></td></div>
                                        </span>
                                    </td>
                                </tr>
                            </table>
                            
                            <table class="no-border">
                                <tr>
                                    <td>College/Unit/Department: <input type="text" name="college_unit" required style="width: 40%;"></td>
                                </tr>
                            </table>
                            
                            <table class="no-border">
                                <tr>
                                    <td>Contact Number: <input type="text" name="contact_number" required style="width: 70%;"></td>
                                    <td><div style="margin-left: 30%;"> ID Number <input type="text" name="id_number" required style="width: 65%;"></div></td>
                                </tr>
                            </table>
                            
                            <!-- Equipment Table exactly like the screenshot - Fillable form -->
                            <table class="bordered-table" id="equipmentTable">
                                <thead>
                                    <tr>
                                        <th rowspan="2">Unit/<br>Quantity</th>
                                        <th rowspan="2">Equipment Description</th>
                                        <th rowspan="2">Purpose</th>
                                        <th colspan="3">Remarks</th>
                                        <th rowspan="1" class="no-print">Action</th>
                                    </tr>
                                    <tr>
                                        <th>Available</th>
                                        <th>Not<br>Available</th>
                                        <th>For<br>Reservation</th>
                                        <th class="no-print"></th>
                                    </tr>
                                </thead>
                                <tbody id="equipmentBody">
                                    <?php for($i = 0; $i < 5; $i++): ?>
                                    <tr class="equipment-row">
                                        <td><input type="number" name="quantity[]" min="1" value="" style=" outline: none; border: none; width: 70px;" <?php echo ($i == 0) ? 'required' : ''; ?>></td>
                                        <td><input style=" outline: none; border: none; "type="text" name="equipment_description[]" <?php echo ($i == 0) ? 'required' : ''; ?>></td>
                                        <td><input style=" outline: none; border: none; " type="text" name="purpose[]" <?php echo ($i == 0) ? 'required' : ''; ?>></td>
                                        <td class="center"><input type="checkbox" name="availability[<?php echo $i; ?>]" value="Available" <?php echo ($i == 0) ? 'checked' : ''; ?>></td>
                                        <td class="center"><input type="checkbox" name="availability[<?php echo $i; ?>]" value="Not Available"></td>
                                        <td class="center"><input type="checkbox" name="availability[<?php echo $i; ?>]" value="For Reservation"></td>
                                        <td class="center no-print">
                                            <?php if($i > 0): ?>
                                            <button type="button" class="btn btn-secondary" style="padding: 2px 8px; font-size: 10px;" onclick="removeRow(this)">✖</button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endfor; ?>
                                </tbody>
                            </table>
                            
                            <div class="no-print" style="text-align: right; margin: 5px 0;">
                                <button type="button" class="add-row-btn" onclick="addEquipmentRow()">+ Add More Items</button>
                            </div>
                                                    <br>
                            
                            <!-- Signature sections exactly like Word document -->
                            <table class="no-border" style="width: 100%; margin-top: 10;">
                                <tr>
                                    <td style="width: 53%;">
                                        <input type="text" name="borrower_signature" placeholder="" style="width: 30%;" required><br>
                                        Signature over printed name of Borrower
                                    </td>
                                </tr>
                            </table>
                            
                            <table class="no-border" style="width: 100%; margin-top: 10;">
                                <tr>
                                    <td style="width: 50%;">
                                        Received by:
                                        <input type="text" name="osd_staff" placeholder="" style=" width: 30%;" required><br>
                                        <div style="margin-left: 20%;"> OSD Staff</div>
                                    </td>
                                </tr>
                            </table>
                            <table class="no-border">
                                <tr>
                                    <td style="width: 50%;">
                                        Date Signed:
                                        <input type="date" name="osd_staff_date" value="<?php echo date('Y-m-d'); ?>" style=" width: 30%;" required>
                                    </td>
                                </tr>
                            </table>
                            
                            <table class="no-border" style="width: 100%;">
                                <tr>
                                    <td style="width: 20%;">
                                        <input type="checkbox" name="status" value="Approved" checked> Approved<br> <br>
                                        <input type="checkbox" name="status" value="Disapproved" id="disapprove_radio"> Disapproved 
                                        <td style="width: 53%;">Remarks for disapproval: <input type="text" name="disapproval_remarks" id="disapproval_remarks" style="width: 63.5%;" ></td>
                                    </td>

                                </tr>
                            </table>

                            
                            
                            <table class="no-border" style="margin-top: 15px;">
                                <tr>
                                    <td style="width: 20%;">
                                    <td style="width: 40%;">
                                        <div class="signature-line2"><input type="text" name="osd_director" placeholder="" style="border: none;" required></div>
                                        <div style="margin-left: 15%"class="cen2"><strong>OSD Director</strong> </div>
                                        <div style="margin-left: 8%;">Date signed: <input type="date" name="director_date" value="<?php echo date('Y-m-d'); ?>" style="width: 100px;" required></div>
                                    </td>
                                </tr>
                            </table>
                            <br>
                            
                            <table class="no-border" style="margin-top: 15px;">
                                <tr>
                                    <td>
                                        <input type="checkbox"> I agree that the equipment received is/are in good condition and <strong>shall be returned in good condition</strong>/or before<br>
                                        <div style="width: 53%;"><input type="date" name="return_date" required style="width: 30%;"></div>
                                    </td>
                                </tr>
                            </table>
                            <br>
                             <br>
                            <table class="no-border">
                                <tr>
                                    <td>
                                        <div style="width: 53%; margin-left: 30%" ><input type="text" name="receiver_signature" placeholder="" style=" width: 84%;" required></div>
                                        <div style="margin-left: 31%" >Borrower/Receiver's Signature over printed name</div>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- Form Actions -->
                            <div class="form-actions no-print">
                                <button type="submit" name="submit_form" class="btn btn-primary">Submit Form</button>
                                <button type="button" class="btn btn-secondary" onclick="window.print()">Print Blank Form</button>
                                <button type="reset" class="btn btn-secondary">Reset</button>
                            </div>
                        </form>
                    <?php endif; ?>
                    
                </div>
            </div>
            
            <!-- View mode actions -->
            <?php if($view_mode): ?>
            <div class="form-actions no-print">
                <button onclick="window.print()" class="btn btn-success">Print This Form</button>
                <a href="borrowers_form.php" class="btn btn-primary">New Form</a>
                <a href="borrowers_list.php" class="btn btn-secondary">View All Forms</a>
            </div>
            <?php endif; ?>
            
        </main>
    </div>
    
    <script>
        let rowCount = 5; // Start from 6 since we already have 6 rows
        
        function addEquipmentRow() {
            const tbody = document.getElementById('equipmentBody');
            const newRow = document.createElement('tr');
            newRow.className = 'equipment-row';
            newRow.innerHTML = `
                <td><input type="number" name="quantity[]" min="1" value="" style=" outline: none; border: none; width: 60px;"></td>
                <td><input type="text" name="equipment_description[]"></td>
                <td><input type="text" name="purpose[]"></td>
                <td class="center"><input type="checkbox" name="availability[${rowCount}]" value="Available" checked></td>
                <td class="center"><input type="checkbox" name="availability[${rowCount}]" value="Not Available"></td>
                <td class="center"><input type="checkbox" name="availability[${rowCount}]" value="For Reservation"></td>
                <td class="center no-print"><button type="button" class="btn btn-secondary" style="padding: 2px 8px; font-size: 10px;" onclick="removeRow(this)">✖</button></td>
            `;
            tbody.appendChild(newRow);
            rowCount++;
        }
        
        function removeRow(button) {
            if (document.querySelectorAll('.equipment-row').length > 1) {
                button.closest('tr').remove();
            } else {
                alert('You must have at least one equipment row.');
            }
        }
        
        // Handle Others radio button
        const radios = document.querySelectorAll('input[name="borrower_type"]');
        const otherType = document.getElementById('other_type');
        
        radios.forEach(radio => {
            radio.addEventListener('change', function() {
                if (this.value === 'Others') {
                    otherType.disabled = false;
                    otherType.required = true;
                } else {
                    otherType.disabled = true;
                    otherType.required = false;
                    otherType.value = '';
                }
            });
        });
        
        // Handle disapproval remarks
        const disapproveRadio = document.getElementById('disapprove_radio');
        const disapprovalRemarks = document.getElementById('disapproval_remarks');
        
        if (disapproveRadio) {
            disapproveRadio.addEventListener('change', function() {
                disapprovalRemarks.disabled = !this.checked;
                if (this.checked) {
                    disapprovalRemarks.required = true;
                } else {
                    disapprovalRemarks.required = false;
                    disapprovalRemarks.value = '';
                }
            });
        }
    </script>
</body>
</html>