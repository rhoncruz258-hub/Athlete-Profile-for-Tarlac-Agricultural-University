<?php
session_start();
require_once 'db_config.php';

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

if (!isset($_GET['id'])) {
    header('Location: athletes.php');
    exit();
}

$id = intval($_GET['id']);

try {
    // Get athlete with competition sport info
    $stmt = $pdo->prepare("
        SELECT a.*, cs.sport_name, cs.competition_id, c.name as competition_name, c.year 
        FROM athletes a 
        LEFT JOIN competition_sports cs ON a.competition_sport_id = cs.id 
        LEFT JOIN competitions c ON cs.competition_id = c.id 
        WHERE a.id = ?
    ");
    $stmt->execute([$id]);
    $athlete = $stmt->fetch();
    
    if (!$athlete) {
        $_SESSION['error'] = "Athlete not found!";
        header('Location: athletes.php');
        exit();
    }
} catch(PDOException $e) {
    $_SESSION['error'] = "Error fetching athlete: " . $e->getMessage();
    header('Location: athletes.php');
    exit();
}

// Function to check if file exists and return valid path
function getValidFilePath($filePath) {
    if (empty($filePath)) {
        return false;
    }
    // Check if file exists
    if (file_exists($filePath)) {
        return $filePath;
    }
    return false;
}

// Function to find athlete folder and update file paths if needed
function fixFilePaths(&$athlete, $pdo) {
    $needUpdate = false;
    $updateFields = [];
    $updateValues = [];
    $fileFields = ['psa_document', 'eligibility_document', 'group2_documents', 'overall_documents', 'photo'];
    
    foreach ($fileFields as $field) {
        if (!empty($athlete[$field]) && !file_exists($athlete[$field])) {
            // Try to find the file in the correct location
            $athleteId = $athlete['id'];
            $lastName = $athlete['last_name'];
            $firstName = $athlete['first_name'];
            $middleInitial = $athlete['middle_initial'];
            $filename = basename($athlete[$field]);
            
            // Determine which subfolder based on the field
            $subfolder = '';
            switch ($field) {
                case 'psa_document':
                    $subfolder = '01_group1_documents/psa';
                    break;
                case 'eligibility_document':
                    $subfolder = '01_group1_documents/eligibility';
                    break;
                case 'group2_documents':
                    $subfolder = '02_group2_documents';
                    break;
                case 'overall_documents':
                    $subfolder = '04_overall_documents';
                    break;
                case 'photo':
                    $subfolder = '03_photo';
                    break;
            }
            
            // Search for the file in the uploads directory
            $searchPattern = "uploads/competitions/*/*/*/{$athleteId}_*/{$subfolder}/{$filename}";
            $foundFiles = glob($searchPattern);
            
            if (!empty($foundFiles)) {
                $newPath = $foundFiles[0];
                $updateFields[] = "$field = ?";
                $updateValues[] = $newPath;
                $athlete[$field] = $newPath;
                $needUpdate = true;
            } else {
                // Try searching without the exact folder structure
                $searchPattern = "uploads/competitions/*/*/*/*/{$subfolder}/{$filename}";
                $foundFiles = glob($searchPattern);
                if (!empty($foundFiles)) {
                    $newPath = $foundFiles[0];
                    $updateFields[] = "$field = ?";
                    $updateValues[] = $newPath;
                    $athlete[$field] = $newPath;
                    $needUpdate = true;
                }
            }
        }
    }
    
    // Update database if needed
    if ($needUpdate) {
        try {
            $updateSql = "UPDATE athletes SET " . implode(', ', $updateFields) . " WHERE id = ?";
            $updateValues[] = $athlete['id'];
            $updateStmt = $pdo->prepare($updateSql);
            $updateStmt->execute($updateValues);
        } catch (PDOException $e) {
            // Silently fail, just use the existing paths
        }
    }
    
    return $needUpdate;
}

// Fix file paths if needed
fixFilePaths($athlete, $pdo);

// Determine the back URL based on where the athlete belongs
$backUrl = 'athletes.php'; // Default to main competition list

if (!empty($athlete['competition_sport_id'])) {
    // If athlete belongs to a specific sport in a competition
    $backUrl = "athletes.php?competition_id={$athlete['competition_id']}&sport_id={$athlete['competition_sport_id']}";
} elseif (!empty($athlete['competition_id'])) {
    // If athlete belongs to a competition but no specific sport (shouldn't happen with new system)
    $backUrl = "athletes.php?competition_id={$athlete['competition_id']}";
}

// Calculate document statistics (updated for 5 documents with separate PSA and Eligibility)
$docStatus = [
    'birth_certificate_status' => $athlete['birth_certificate_status'] ?? 0,
    'eligibility_form_status' => $athlete['eligibility_form_status'] ?? 0,
    'cor_status' => $athlete['cor_status'] ?? 0,
    'tor_status' => $athlete['tor_status'] ?? 0,
    'photo_status' => $athlete['photo_status'] ?? 0
];

$group1Count = $docStatus['birth_certificate_status'] + $docStatus['eligibility_form_status'];
$group2Count = $docStatus['cor_status'] + $docStatus['tor_status'];
$photoStatus = $docStatus['photo_status'];
$totalDocs = $group1Count + $group2Count + $photoStatus;

// Check which documents have attachments (updated for separate fields)
$hasPsaAttachment = !empty($athlete['psa_document']) && file_exists($athlete['psa_document']);
$hasEligibilityAttachment = !empty($athlete['eligibility_document']) && file_exists($athlete['eligibility_document']);
$hasGroup2Attachment = !empty($athlete['group2_documents']) && file_exists($athlete['group2_documents']);
$hasOverallAttachment = !empty($athlete['overall_documents']) && file_exists($athlete['overall_documents']);
$hasPhotoAttachment = !empty($athlete['photo']) && file_exists($athlete['photo']);
$hasGroup1Attachment = $hasPsaAttachment || $hasEligibilityAttachment;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <title>TAU Sports - View Athlete</title>
    <style>
        /* Base styles - Original screen sizes */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: #f5f7fa; 
            color: #333; 
        }
        
        /* Header - Original size */
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
        .header-left { display: flex; align-items: center; gap: 20px; }
        .university-badge { display: flex; align-items: center; gap: 10px; }
        .logo-circle { width: 50px; height: 50px; background: linear-gradient(45deg, #ffd700, #ffed4e); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; color: #0c3a1d; border: 3px solid white; box-shadow: 0 3px 10px rgba(0, 0, 0, 0.2); }
        .logo-circle img { width: 120%; height: 120%; object-fit: cover; }
        .university-info h1 { font-size: 20px; font-weight: 700; margin-bottom: 2px; }
        .university-info .subtitle { font-size: 12px; color: #b0ffc9; }
        .user-info { display: flex; align-items: center; gap: 20px; }
        .user-welcome { text-align: right; }
        .user-name { font-weight: 600; font-size: 16px; }
        .user-role { font-size: 12px; color: #ffd700; background: rgba(0,0,0,0.2); padding: 2px 10px; border-radius: 10px; margin-top: 2px; display: inline-block; }
        .logout-btn { background: white; color: #0c3a1d; border: none; padding: 8px 20px; border-radius: 6px; cursor: pointer; font-weight: 600; text-decoration: none; display: inline-block; transition: all 0.3s; }
        .logout-btn:hover { background: #f0f0f0; transform: translateY(-1px); box-shadow: 0 3px 10px rgba(0,0,0,0.1); }
        
        /* Main container - Original layout */
        .main-container { 
            display: flex; 
            min-height: calc(100vh - 80px); 
        }
        
        /* Sidebar - Fixed position, no scroll */
        .sidebar { 
            width: 250px; 
            background: white; 
            border-right: 1px solid #e0e0e0; 
            padding: 30px 0; 
            box-shadow: 2px 0 10px rgba(0,0,0,0.05);
            height: calc(100vh - 80px);
            position: sticky;
            top: 80px;
            overflow-y: auto;
        }
        .nav-menu { list-style: none; }
        .nav-item { margin-bottom: 5px; }
        .nav-link { 
            display: block; 
            padding: 15px 30px; 
            color: #555; 
            text-decoration: none; 
            font-weight: 500; 
            transition: all 0.3s; 
            border-left: 4px solid transparent; 
        }
        .nav-link:hover { background: #f8f9fa; color: #0c3a1d; border-left: 4px solid #0c3a1d; }
        .nav-link.active { background: linear-gradient(to right, rgba(12, 58, 29, 0.1), transparent); color: #0c3a1d; border-left: 4px solid #0c3a1d; font-weight: 600; }
        
        /* Content area */
        .content { 
            flex: 1; 
            padding: 30px; 
            overflow-y: auto;
        }
        
        .page-title { 
            color: #0c3a1d; 
            font-size: 28px; 
            font-weight: 700; 
            margin-bottom: 20px; 
            padding-bottom: 15px; 
            border-bottom: 2px solid #e0e0e0; 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
        }
        .btn { 
            padding: 10px 20px; 
            border-radius: 6px; 
            font-weight: 600; 
            cursor: pointer; 
            text-decoration: none; 
            display: inline-flex; 
            align-items: center; 
            gap: 8px; 
            transition: all 0.3s; 
            border: none; 
            font-size: 14px; 
        }
        .btn-primary { background: linear-gradient(135deg, #0c3a1d 0%, #1a5c2f 100%); color: white; }
        .btn-primary:hover { background: linear-gradient(135deg, #0a3018 0%, #154d24 100%); transform: translateY(-2px); box-shadow: 0 5px 15px rgba(12, 58, 29, 0.2); }
        .btn-edit { background: #ffc107; color: #212529; }
        .btn-edit:hover { background: #e0a800; }
        .btn-delete { background: #dc3545; color: white; }
        .btn-delete:hover { background: #c82333; }
        .btn-back { background: #6c757d; color: white; }
        .btn-back:hover { background: #5a6268; }
        .btn-micro { padding: 4px 10px; font-size: 0.7rem; border-radius: 20px; text-decoration: none; background: #0c3a1d; color: white; display: inline-flex; align-items: center; gap: 4px; transition: 0.2s; }
        .btn-micro:hover { background: #1a5c2f; }
        
        /* Profile container */
        .profile-container { background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 5px 20px rgba(0,0,0,0.08); }
        
        /* Profile Header - Original size */
        .profile-header { 
            background: linear-gradient(135deg, #0c3a1d 0%, #1a5c2f 100%); 
            color: white; 
            padding: 30px; 
            display: flex; 
            align-items: center; 
            gap: 30px; 
            flex-wrap: wrap; 
        }
        .profile-photo { width: 150px; height: 150px; border-radius: 50%; border: 5px solid white; background: #f0f0f0; display: flex; align-items: center; justify-content: center; overflow: hidden; flex-shrink: 0; }
        .profile-photo img { width: 100%; height: 100%; object-fit: cover; }
        .profile-photo-placeholder { font-size: 48px; color: #0c3a1d; }
        .profile-info { flex: 1; min-width: 0; }
        .profile-info h2 { font-size: 32px; margin-bottom: 5px; word-wrap: break-word; }
        .student-id { font-size: 18px; opacity: 0.9; margin-bottom: 10px; }
        .sport-badge { display: inline-block; background: #ffd700; color: #0c3a1d; padding: 5px 15px; border-radius: 20px; font-weight: 600; font-size: 14px; }
        .competition-info { margin-top: 10px; font-size: 14px; opacity: 0.9; }
        
        /* Side-by-side container for Personal Info + Summary */
        .side-by-side-container { 
            display: flex; 
            gap: 20px; 
            align-items: stretch; 
            padding: 20px 30px 15px 30px; 
        }
        .side-by-side-container .info-card { 
            flex: 0 0 300px; 
            background: #f8f9fa; 
            border-radius: 10px; 
            padding: 15px 20px; 
            box-shadow: 0 2px 8px rgba(0,0,0,0.05); 
            border: 1px solid #e0e0e0; 
            display: flex; 
            flex-direction: column; 
        }
        .side-by-side-container .summary-section { 
            flex: 1; 
            background: #f8f9fa; 
            border-radius: 10px; 
            padding: 15px 20px; 
            box-shadow: 0 2px 8px rgba(0,0,0,0.05); 
            border: 1px solid #e0e0e0; 
            display: flex; 
            flex-direction: column; 
            margin: 0; 
        }
        .card-header { 
            display: flex; 
            align-items: center; 
            gap: 10px; 
            margin-bottom: 10px; 
            padding-bottom: 8px; 
            border-bottom: 2px solid #e0e0e0; 
        }
        .card-header i { font-size: 18px; color: #0c3a1d; }
        .card-header h3 { color: #0c3a1d; font-size: 16px; font-weight: 600; margin: 0; }
        .info-content { display: flex; flex-direction: column; gap: 8px; flex: 1; }
        .info-row { display: flex; flex-direction: column; gap: 2px; }
        .info-label { font-size: 11px; color: #666; text-transform: uppercase; letter-spacing: 0.3px; }
        .info-value { font-size: 13px; color: #333; font-weight: 500; word-wrap: break-word; line-height: 1.3; }
        .summary-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin-top: 5px; }
        .summary-item { text-align: center; padding: 10px 8px; background: white; border-radius: 8px; border: 1px solid #e0e0e0; }
        .summary-value { font-size: 24px; font-weight: 700; line-height: 1.2; }
        .summary-label { font-size: 11px; color: #666; line-height: 1.2; }
        
        /* Documents Row Layout */
        .documents-row {
            display: flex;
            gap: 20px;
            padding: 0 30px 25px 30px;
            flex-wrap: wrap;
        }
        
        /* Group 1 (Combined) - Wider container */
        .document-card.combined-card {
            flex: 2;
            min-width: 400px;
        }
        
        .document-card.small-card {
            flex: 1;
            min-width: 280px;
        }
        
        .document-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            border: 1px solid #e0e0e0;
            transition: all 0.2s;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .document-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(12, 58, 29, 0.1);
        }
        
        /* Card header */
        .card-header-doc {
            padding: 12px 18px;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }
        .card-header-doc.combined-header { 
            background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%); 
            border-left: 4px solid #0c3a1d; 
        }
        .card-header-doc.overall-header { 
            background: #e9ecef; 
            border-left: 4px solid #6c757d; 
        }
        .card-header-doc.photo-header { 
            background: #d1ecf1; 
            border-left: 4px solid #17a2b8; 
        }
        .doc-title { 
            display: flex; 
            align-items: center; 
            gap: 8px; 
            font-weight: 700; 
            font-size: 0.95rem; 
        }
        .doc-title i { 
            font-size: 1rem; 
        }
        .doc-badge { 
            font-size: 0.7rem; 
            padding: 3px 10px; 
            border-radius: 20px; 
            background: white; 
            font-weight: 600; 
        }
        .card-body-doc { 
            padding: 15px 18px; 
            flex: 1; 
        }
        
        /* Two-column layout inside combined card */
        .two-column-docs {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        .doc-subgroup {
            background: #fafbfc;
            border-radius: 8px;
            padding: 12px 14px;
            border: 1px solid #edf2f7;
        }
        .subgroup-title {
            font-weight: 700;
            font-size: 0.8rem;
            padding-bottom: 8px;
            margin-bottom: 10px;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .subgroup-title i { 
            font-size: 0.8rem; 
        }
        .subgroup-title.personal { color: #0c3a1d; }
        .subgroup-title.academic { color: #856404; }
        
        .doc-item-simple { 
            margin-bottom: 12px; 
        }
        .doc-item-simple:last-child { 
            margin-bottom: 0; 
        }
        .doc-name-row { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            margin-bottom: 5px; 
            flex-wrap: wrap; 
            gap: 5px; 
        }
        .doc-name-row strong { 
            font-size: 0.75rem; 
            color: #1e293b; 
        }
        .status-badge { 
            font-size: 0.65rem; 
            padding: 2px 8px; 
            border-radius: 12px; 
            font-weight: 600; 
        }
        .status-checked { 
            background: #e8f5e9; 
            color: #0c3a1d; 
        }
        .status-unchecked { 
            background: #fee2e2; 
            color: #b91c1c; 
        }
        .attachment-row { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            margin-top: 4px; 
            flex-wrap: wrap; 
            gap: 6px; 
        }
        .attach-info { 
            font-size: 0.65rem; 
            display: flex; 
            align-items: center; 
            gap: 4px; 
        }
        .has-attach { 
            color: #0c3a1d; 
        }
        .no-attach { 
            color: #b91c1c; 
        }
        .combined-attachment-footer {
            margin-top: 15px;
            padding-top: 10px;
            border-top: 1px dashed #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            background: #f8f9fa;
            padding: 10px 12px;
            border-radius: 6px;
            font-size: 0.7rem;
        }
        .photo-placeholder-card { 
            text-align: center; 
            padding: 10px 0; 
        }
        .photo-placeholder-card img { 
            max-width: 100%; 
            max-height: 120px; 
            border-radius: 8px; 
            margin-bottom: 10px; 
            object-fit: cover; 
        }
        .overall-message { 
            font-size: 0.7rem; 
            background: #f1f5f9; 
            padding: 10px; 
            border-radius: 8px; 
            margin-top: 10px; 
            color: #334155; 
            line-height: 1.4;
        }
        .profile-actions { 
            padding: 20px 30px; 
            background: #f8f9fa; 
            border-top: 1px solid #e0e0e0; 
            display: flex; 
            gap: 15px; 
            justify-content: flex-end; 
            flex-wrap: wrap; 
        }
        
        /* Responsive */
        @media (max-width: 1100px) {
            .documents-row {
                flex-direction: column;
            }
            .document-card.combined-card,
            .document-card.small-card {
                flex: 1;
                min-width: auto;
            }
            .two-column-docs {
                grid-template-columns: 1fr;
                gap: 12px;
            }
        }
        
        @media (max-width: 768px) {
            .main-container { flex-direction: column; }
            .sidebar { width: 100%; padding: 20px 0; position: static; height: auto; }
            .nav-menu { display: flex; overflow-x: auto; padding: 0 10px; }
            .nav-item { margin-bottom: 0; }
            .nav-link { padding: 10px 15px; white-space: nowrap; }
            .profile-header { flex-direction: column; text-align: center; }
            .side-by-side-container { flex-direction: column; }
            .side-by-side-container .info-card { flex: 1 1 100%; }
            .summary-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
            .side-by-side-container { padding: 15px 20px; }
            .documents-row { padding: 0 20px 20px 20px; }
            .profile-actions { padding: 15px 20px; }
        }
        
        /* File not found warning */
        .file-not-found-warning {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 8px 12px;
            border-radius: 4px;
            font-size: 11px;
            color: #856404;
            margin-top: 5px;
        }
    </style>
</head>
<body>
<div class="header">
    <div class="header-left">
        <div class="university-badge">
            <div class="logo-circle"><img src="taulogo.png" alt=""></div>
            <div class="logo-circle"><img src="sdologo.png" alt=""></div>
            <div class="university-info">
                <h1>TARLAC AGRICULTURAL UNIVERSITY</h1>
                <div class="subtitle">Sports Development Office</div>
            </div>
        </div>
    </div>
    <div class="user-info">
        <div class="user-welcome">
            <div class="user-name">Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?></div>
            <div class="user-role"><?php echo htmlspecialchars($_SESSION['role']); ?></div>
        </div>
        <a href="logout.php" class="logout-btn">Logout</a>
    </div>
</div>

<div class="main-container">
    <nav class="sidebar">
        <ul class="nav-menu">
            <li class="nav-item"><a href="dashboard.php" class="nav-link"><i class="fas fa-home"></i> Dashboard</a></li>
            <li class="nav-item"><a href="athletes.php" class="nav-link active"><i class="fas fa-users"></i> Athlete Profiles</a></li>
            <li class="nav-item"><a href="report.php" class="nav-link"><i class="fas fa-chart-bar"></i> Athletes Report</a></li>
            <li class="nav-item"><a href="borrowers_form.php" class="nav-link"><i class="fas fa-file"></i> Borrowers Form</a></li>
            <li class="nav-item"><a href="borrowers_list.php" class="nav-link"><i class="fas fa-clipboard"></i> Borrowers List</a></li>
        </ul>
    </nav>

    <main class="content">
        <div class="page-title">
            <span>Athlete Profile</span>
            <a href="<?php echo $backUrl; ?>" class="btn btn-back">← Back to List</a>
        </div>

        <div class="profile-container">
            <!-- Profile Header -->
            <div class="profile-header">
                <div class="profile-photo">
                    <?php if ($hasPhotoAttachment): ?>
                        <img src="<?php echo htmlspecialchars($athlete['photo']); ?>" alt="Athlete Photo">
                    <?php else: ?>
                        <div class="profile-photo-placeholder">👤</div>
                    <?php endif; ?>
                </div>
                <div class="profile-info">
                    <h2><?php echo htmlspecialchars($athlete['last_name'] . ', ' . $athlete['first_name']); ?>
                        <?php if (!empty($athlete['middle_initial'])): ?>
                            <?php echo ' ' . substr(htmlspecialchars($athlete['middle_initial']), 0, 1) . '.'; ?>
                        <?php endif; ?>
                    </h2>
                    <div class="student-id">Student ID: <?php echo htmlspecialchars($athlete['student_id']); ?></div>
                    <?php if (!empty($athlete['sport_name'])): ?>
                        <div class="sport-badge"><?php echo htmlspecialchars($athlete['sport_name']); ?></div>
                        <?php if (!empty($athlete['competition_name'])): ?>
                            <div class="competition-info"><?php echo htmlspecialchars($athlete['competition_name'] . ' ' . $athlete['year']); ?></div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Personal Info + Summary Row -->
            <div class="side-by-side-container">
                <div class="info-card">
                    <div class="card-header">
                        <i class="fas fa-user"></i>
                        <h3>Other Information</h3>
                    </div>
                    <div class="info-content">
                        <div class="info-row"><span class="info-label">Gender</span><span class="info-value"><?php echo htmlspecialchars($athlete['gender']); ?></span></div>
                        <div class="info-row"><span class="info-label">Contact Number</span><span class="info-value"><?php echo !empty($athlete['contact_number']) ? htmlspecialchars($athlete['contact_number']) : 'Not specified'; ?></span></div>
                        <div class="info-row"><span class="info-label">Email Address</span><span class="info-value"><?php echo !empty($athlete['email']) ? htmlspecialchars($athlete['email']) : 'No email'; ?></span></div>
                        <?php if (!empty($athlete['sport_name'])): ?>
                        <div class="info-row"><span class="info-label">Sport</span><span class="info-value"><?php echo htmlspecialchars($athlete['sport_name']); ?></span></div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="summary-section">
                    <div class="card-header">
                        <i class="fas fa-file-alt"></i>
                        <h3>Document Summary</h3>
                    </div>
                    <div class="summary-grid">
                        <div class="summary-item"><div class="summary-value" style="color: #0c3a1d;"><?php echo $group1Count; ?>/2</div><div class="summary-label">Group 1<br><small>Personal</small></div></div>
                        <div class="summary-item"><div class="summary-value" style="color: #ffc107;"><?php echo $group2Count; ?>/2</div><div class="summary-label">Group 2<br><small>Academic</small></div></div>
                        <div class="summary-item"><div class="summary-value" style="color: #17a2b8;"><?php echo $photoStatus ? '✓' : '✗'; ?></div><div class="summary-label">Photo</div></div>
                        <div class="summary-item"><div class="summary-value" style="color: <?php echo $totalDocs >= 4 ? '#0c3a1d' : ($totalDocs >= 2 ? '#ffc107' : '#dc3545'); ?>;"><?php echo $totalDocs; ?>/5</div><div class="summary-label">Total Verified</div></div>
                    </div>
                </div>
            </div>

            <!-- REQUIRED DOCUMENTS -->
            <div class="documents-row">
                <!-- Card 1: Combined Group 1 + Group 2 - WIDER -->
                <div class="document-card combined-card">
                    <div class="card-header-doc combined-header">
                        <div class="doc-title">
                            <i class="fas fa-layer-group"></i> 
                            <span>Individual Documents (Option 1)</span>
                        </div>
                        <div class="doc-badge" style="background:#0c3a1d20; color:#0c3a1d;">
                            Personal: <?php echo $group1Count; ?>/2 | Academic: <?php echo $group2Count; ?>/2
                        </div>
                    </div>
                    <div class="card-body-doc">
                        <div class="two-column-docs">
                            <!-- Group 1: Personal Documents -->
                            <div class="doc-subgroup">
                                <div class="subgroup-title personal">
                                    <i class="fas fa-user-circle"></i> Group 1: Personal
                                    <span style="font-size: 0.65rem;"><?php echo $group1Count; ?>/2</span>
                                </div>
                                <!-- PSA Birth Certificate -->
                                <div class="doc-item-simple">
                                    <div class="doc-name-row">
                                        <strong>📄 PSA Birth Certificate</strong>
                                        <span class="status-badge <?php echo (!empty($athlete['birth_certificate_status']) && $athlete['birth_certificate_status'] == 1) ? 'status-checked' : 'status-unchecked'; ?>">
                                            <?php echo (!empty($athlete['birth_certificate_status']) && $athlete['birth_certificate_status'] == 1) ? '✓ Verified' : '✗ Not Verified'; ?>
                                        </span>
                                    </div>
                                    <div class="attachment-row">
                                        <span class="attach-info <?php echo $hasPsaAttachment ? 'has-attach' : 'no-attach'; ?>">
                                            <i class="fas <?php echo $hasPsaAttachment ? 'fa-check-circle' : 'fa-times-circle'; ?>"></i> 
                                            <?php echo $hasPsaAttachment ? 'File uploaded' : 'No file'; ?>
                                        </span>
                                        <?php if ($hasPsaAttachment): ?>
                                            <a href="<?php echo htmlspecialchars($athlete['psa_document']); ?>" target="_blank" class="btn-micro"><i class="fas fa-eye"></i> View</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <!-- Eligibility Form -->
                                <div class="doc-item-simple">
                                    <div class="doc-name-row">
                                        <strong>📝 Eligibility Form</strong>
                                        <span class="status-badge <?php echo (!empty($athlete['eligibility_form_status']) && $athlete['eligibility_form_status'] == 1) ? 'status-checked' : 'status-unchecked'; ?>">
                                            <?php echo (!empty($athlete['eligibility_form_status']) && $athlete['eligibility_form_status'] == 1) ? '✓ Verified' : '✗ Not Verified'; ?>
                                        </span>
                                    </div>
                                    <div class="attachment-row">
                                        <span class="attach-info <?php echo $hasEligibilityAttachment ? 'has-attach' : 'no-attach'; ?>">
                                            <i class="fas <?php echo $hasEligibilityAttachment ? 'fa-check-circle' : 'fa-times-circle'; ?>"></i> 
                                            <?php echo $hasEligibilityAttachment ? 'File uploaded' : 'No file'; ?>
                                        </span>
                                        <?php if ($hasEligibilityAttachment): ?>
                                            <a href="<?php echo htmlspecialchars($athlete['eligibility_document']); ?>" target="_blank" class="btn-micro"><i class="fas fa-eye"></i> View</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Group 2: Academic Documents -->
                            <div class="doc-subgroup">
                                <div class="subgroup-title academic">
                                    <i class="fas fa-graduation-cap"></i> Group 2: Academic
                                    <span style="font-size: 0.65rem;"><?php echo $group2Count; ?>/2</span>
                                </div>
                                <!-- COR -->
                                <div class="doc-item-simple">
                                    <div class="doc-name-row">
                                        <strong>📋 COR (Certificate of Registration)</strong>
                                        <span class="status-badge <?php echo (!empty($athlete['cor_status']) && $athlete['cor_status'] == 1) ? 'status-checked' : 'status-unchecked'; ?>">
                                            <?php echo (!empty($athlete['cor_status']) && $athlete['cor_status'] == 1) ? '✓ Verified' : '✗ Not Verified'; ?>
                                        </span>
                                    </div>
                                </div>
                                <!-- TOR -->
                                <div class="doc-item-simple">
                                    <div class="doc-name-row">
                                        <strong>🎓 TOR (Transcript of Records)</strong>
                                        <span class="status-badge <?php echo (!empty($athlete['tor_status']) && $athlete['tor_status'] == 1) ? 'status-checked' : 'status-unchecked'; ?>">
                                            <?php echo (!empty($athlete['tor_status']) && $athlete['tor_status'] == 1) ? '✓ Verified' : '✗ Not Verified'; ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Combined attachment info for Group 2 -->
                        <div class="combined-attachment-footer">
                            <span class="attach-info <?php echo $hasGroup2Attachment ? 'has-attach' : 'no-attach'; ?>">
                                <i class="fas <?php echo $hasGroup2Attachment ? 'fa-file-pdf' : 'fa-info-circle'; ?>"></i>
                                <?php echo $hasGroup2Attachment ? 'Combined file (COR+TOR) available' : 'No combined file for Group 2'; ?>
                            </span>
                            <?php if ($hasGroup2Attachment): ?>
                                <a href="<?php echo htmlspecialchars($athlete['group2_documents']); ?>" target="_blank" class="btn-micro"><i class="fas fa-file-pdf"></i> View Combined PDF</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Card 2: Overall Documents - SMALLER -->
                <div class="document-card small-card">
                    <div class="card-header-doc overall-header">
                        <div class="doc-title">
                            <i class="fas fa-folder-open"></i> 
                            <span>Overall Documents</span>
                        </div>
                        <div class="doc-badge"><?php echo $hasOverallAttachment ? 'Has File' : 'No File'; ?></div>
                    </div>
                    <div class="card-body-doc">
                        <div class="attachment-row" style="justify-content: flex-start; gap: 10px; margin-bottom: 8px;">
                            <span class="attach-info <?php echo $hasOverallAttachment ? 'has-attach' : 'no-attach'; ?>">
                                <i class="fas <?php echo $hasOverallAttachment ? 'fa-check-circle' : 'fa-info-circle'; ?>"></i> 
                                <?php echo $hasOverallAttachment ? 'Complete set attached' : 'No overall file uploaded'; ?>
                            </span>
                            <?php if ($hasOverallAttachment): ?>
                                <a href="<?php echo htmlspecialchars($athlete['overall_documents']); ?>" target="_blank" class="btn-micro"><i class="fas fa-file-pdf"></i> View Overall</a>
                            <?php endif; ?>
                        </div>
                        <div class="overall-message">
                            <i class="fas fa-info-circle"></i> 
                            <?php if ($hasOverallAttachment): ?>
                                Overall document contains complete set of requirements (PSA, Eligibility, COR, TOR, Photo).
                            <?php else: ?>
                                No overall document uploaded. Please check individual documents above.
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Card 3: 2x2 ID Picture - SMALLER -->
                <div class="document-card small-card">
                    <div class="card-header-doc photo-header">
                        <div class="doc-title">
                            <i class="fas fa-camera"></i> 
                            <span>2x2 ID Picture</span>
                        </div>
                        <div class="doc-badge"><?php echo $photoStatus ? '✓ Verified' : '✗ Pending'; ?></div>
                    </div>
                    <div class="card-body-doc">
                        <div class="photo-placeholder-card">
                            <?php if ($hasPhotoAttachment): ?>
                                <?php $fileExt = strtolower(pathinfo($athlete['photo'], PATHINFO_EXTENSION)); $isImage = in_array($fileExt, ['jpg','jpeg','png','gif','webp']); ?>
                                <?php if ($isImage): ?>
                                    <img src="<?php echo htmlspecialchars($athlete['photo']); ?>" alt="Athlete Photo" style="max-width:100%; max-height:110px; border-radius:8px; margin-bottom:8px;">
                                <?php else: ?>
                                    <div style="padding:10px;"><i class="fas fa-file-image fa-2x" style="color:#17a2b8;"></i><div style="font-size:12px;">Photo file present</div></div>
                                <?php endif; ?>
                                <div class="attachment-row" style="justify-content:center; margin-top: 8px;">
                                    <a href="<?php echo htmlspecialchars($athlete['photo']); ?>" target="_blank" class="btn-micro"><i class="fas fa-external-link-alt"></i> View Full</a>
                                    <span class="attach-info <?php echo $photoStatus ? 'has-attach' : 'no-attach'; ?>">
                                        <i class="fas <?php echo $photoStatus ? 'fa-check-circle' : 'fa-times-circle'; ?>"></i> 
                                        <?php echo $photoStatus ? 'Verified' : 'Not Verified'; ?>
                                    </span>
                                </div>
                            <?php else: ?>
                                <div class="attach-info no-attach" style="justify-content:center; gap:6px; padding:15px;">
                                    <i class="fas fa-camera fa-2x"></i>
                                    <span style="font-size:12px;">No photo uploaded</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Profile Actions -->
            <div class="profile-actions">
                <a href="<?php echo $backUrl; ?>" class="btn btn-back">← Back to List</a>
                <a href="add_athlete.php?id=<?php echo $athlete['id']; ?>" class="btn btn-edit">✏️ Edit Profile</a>
                <?php if ($_SESSION['role'] === 'admin'): ?>
                    <a href="athletes.php?delete=<?php echo $athlete['id']; ?>&competition_id=<?php echo $athlete['competition_id']; ?>&sport_id=<?php echo $athlete['competition_sport_id']; ?>" 
                       class="btn btn-delete"
                       onclick="return confirm('⚠️ WARNING: Are you sure you want to delete this athlete?\n\nThis will permanently delete:\n- All athlete information\n- All uploaded documents\n- Athlete folder\n\nThis action CANNOT be undone!')">
                       🗑️ Delete Profile
                    </a>
                <?php endif; ?>
                <a href="#" class="btn btn-primary" onclick="window.print()">🖨️ Print Profile</a>
            </div>
        </div>
    </main>
</div>
</body>
</html>