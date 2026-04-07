<?php
session_start();
require_once 'db_config.php';

// Handle AJAX request for saving new course
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_course') {
    header('Content-Type: application/json');

    // Check if user is logged in
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit();
    }

    $course_code = isset($_POST['course_code']) ? trim($_POST['course_code']) : '';
    $course_name = isset($_POST['course_name']) ? trim($_POST['course_name']) : '';

    if (empty($course_code) || empty($course_name)) {
        echo json_encode(['success' => false, 'error' => 'Course code and name are required']);
        exit();
    }

    try {
        // Check if course already exists
        $checkStmt = $pdo->prepare("SELECT id FROM courses WHERE course_code = ?");
        $checkStmt->execute([$course_code]);

        if ($checkStmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Course code already exists']);
            exit();
        }

        // Insert new course
        $stmt = $pdo->prepare("INSERT INTO courses (course_code, course_name, is_active) VALUES (?, ?, 1)");
        $stmt->execute([$course_code, $course_name]);

        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
        exit();
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit();
    }
}

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

$isEdit = false;
$athlete = null;
$competitionSportId = isset($_GET['competition_sport_id']) ? intval($_GET['competition_sport_id']) : 0;
$sportInfo = null;

// Get sport info if competition_sport_id is provided
if ($competitionSportId) {
    try {
        $stmt = $pdo->prepare("
            SELECT cs.*, c.name as competition_name, c.year, c.id as competition_id,
                   u.name as university_name, u.id as university_id
            FROM competition_sports cs
            JOIN competitions c ON cs.competition_id = c.id
            JOIN universities u ON cs.university_id = u.id
            WHERE cs.id = ?
        ");
        $stmt->execute([$competitionSportId]);
        $sportInfo = $stmt->fetch();
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error fetching sport info: " . $e->getMessage();
        header('Location: athletes.php');
        exit();
    }
}

// Check if editing existing athlete
if (isset($_GET['id'])) {
    $isEdit = true;
    $id = intval($_GET['id']);

    try {
        $stmt = $pdo->prepare("SELECT * FROM athletes WHERE id = ?");
        $stmt->execute([$id]);
        $athlete = $stmt->fetch();

        if (!$athlete) {
            $_SESSION['error'] = "Athlete not found!";
            header('Location: athletes.php');
            exit();
        }

        // Get sport info for editing
        if (!empty($athlete['competition_sport_id'])) {
            $stmt = $pdo->prepare("
                SELECT cs.*, c.name as competition_name, c.year, c.id as competition_id,
                       u.name as university_name, u.id as university_id
                FROM competition_sports cs
                JOIN competitions c ON cs.competition_id = c.id
                JOIN universities u ON cs.university_id = u.id
                WHERE cs.id = ?
            ");
            $stmt->execute([$athlete['competition_sport_id']]);
            $sportInfo = $stmt->fetch();
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error fetching athlete: " . $e->getMessage();
        header('Location: athletes.php');
        exit();
    }
}

// If editing, also get the competitionSportId from the athlete
if ($isEdit && !$competitionSportId && $athlete) {
    $competitionSportId = $athlete['competition_sport_id'];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Create main uploads directory if it doesn't exist
    $mainUploadDir = 'uploads/';
    if (!file_exists($mainUploadDir)) {
        mkdir($mainUploadDir, 0777, true);
    }

    // Create competitions directory if it doesn't exist
    if (!file_exists($mainUploadDir . 'competitions/')) {
        mkdir($mainUploadDir . 'competitions/', 0777, true);
    }

    // Helper function to recursively delete a directory
    function deleteDirectory($dir)
    {
        if (!file_exists($dir)) {
            return true;
        }
        if (!is_dir($dir)) {
            return unlink($dir);
        }
        foreach (scandir($dir) as $item) {
            if ($item == '.' || $item == '..') {
                continue;
            }
            if (!deleteDirectory($dir . DIRECTORY_SEPARATOR . $item)) {
                return false;
            }
        }
        return rmdir($dir);
    }

// In add_athlete.php, find and replace the handleFileUpload function:

// Function to handle file upload with original filename preservation
function handleFileUpload($fieldName, $athleteFolderPath, $existingFile = '')
{
    if (isset($_FILES[$fieldName]) && $_FILES[$fieldName]['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES[$fieldName];
        $originalName = $file['name'];
        $fileExt = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
       
        // Allowed file types for PSA and Eligibility (PDF, JPG, JPEG, PNG)
        $allowedExt = ['pdf', 'jpg', 'jpeg', 'png'];
       
        // For group2_documents and overall_documents, only allow PDF
        if (in_array($fieldName, ['group2_documents', 'overall_documents'])) {
            if ($fileExt !== 'pdf') {
                return $existingFile;
            }
        } else if ($fieldName === 'photo') {
            // For photo, allow image formats
            $allowedImageExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            if (!in_array($fileExt, $allowedImageExt)) {
                return $existingFile;
            }
        } else {
            // For PSA and Eligibility, allow PDF and images
            if (!in_array($fileExt, $allowedExt)) {
                return $existingFile;
            }
        }

        // Determine folder based on document type
        $typeFolder = '';
        switch ($fieldName) {
            case 'psa_document':
                $typeFolder = '01_group1_documents/psa/';
                break;
            case 'eligibility_document':
                $typeFolder = '01_group1_documents/eligibility/';
                break;
            case 'group2_documents':
                $typeFolder = '02_group2_documents/';
                break;
            case 'overall_documents':
                $typeFolder = '04_overall_documents/';
                break;
            case 'photo':
                $typeFolder = '03_photo/';
                break;
        }

        // Create type-specific folder if it doesn't exist
        $typeFolderPath = $athleteFolderPath . $typeFolder;
        if (!file_exists($typeFolderPath)) {
            mkdir($typeFolderPath, 0777, true);
        }

        // Keep the original filename
        $safeFilename = $originalName;
       
        // If file with same name exists, add a counter
        $filePath = $typeFolderPath . $safeFilename;
        $counter = 1;
        $pathInfo = pathinfo($filePath);
        while (file_exists($filePath)) {
            $filePath = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '_' . $counter . '.' . $pathInfo['extension'];
            $counter++;
        }

        if (move_uploaded_file($file['tmp_name'], $filePath)) {
            // Delete old file if exists and it's different from the new one
            if (!empty($existingFile) && file_exists($existingFile) && $existingFile !== $filePath) {
                unlink($existingFile);
            }
            return $filePath;
        }
    }
    return $existingFile;
}

    // Collect form data
    $course_id = !empty($_POST['course_id']) && is_numeric($_POST['course_id']) ? intval($_POST['course_id']) : null;

    // Get course name for display if course_id is provided
    $course_display = '';
    if ($course_id) {
        $courseStmt = $pdo->prepare("SELECT CONCAT(course_code, ' - ', course_name) as display FROM courses WHERE id = ?");
        $courseStmt->execute([$course_id]);
        $course_display = $courseStmt->fetchColumn();
    }

    $data = [
        'student_id' => trim($_POST['student_id']),
        'first_name' => trim($_POST['first_name']),
        'middle_initial' => trim($_POST['middle_initial'] ?? ''),
        'last_name' => trim($_POST['last_name']),
        'gender' => $_POST['gender'],
        'contact_number' => trim($_POST['contact_number'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'competition_sport_id' => intval($_POST['competition_sport_id'])
    ];

    // Collect document status checkboxes
    $docStatus = [
        // Group 1 documents
        'birth_certificate_status' => isset($_POST['birth_certificate_status']) ? 1 : 0,
        'eligibility_form_status' => isset($_POST['eligibility_form_status']) ? 1 : 0,
        // Group 2 documents
        'cor_status' => isset($_POST['cor_status']) ? 1 : 0,
        'tor_status' => isset($_POST['tor_status']) ? 1 : 0,
        // Photo is separate
        'photo_status' => isset($_POST['photo_status']) ? 1 : 0
    ];

    try {
        // Get competition, university and sport details for folder structure
        $stmt = $pdo->prepare("
            SELECT cs.*, c.name as competition_name, c.year,
                   u.name as university_name, u.id as university_id
            FROM competition_sports cs
            JOIN competitions c ON cs.competition_id = c.id
            JOIN universities u ON cs.university_id = u.id
            WHERE cs.id = ?
        ");
        $stmt->execute([$data['competition_sport_id']]);
        $sportDetails = $stmt->fetch();

        if (!$sportDetails) {
            throw new Exception("Invalid competition sport selected");
        }

        // Force the gender to match the sport's gender category
        // For Mixed gender sports, allow user to select gender
        if ($sportDetails['gender'] === 'Mixed') {
            // Use the submitted gender value for Mixed sports
            $data['gender'] = $_POST['gender'];
        } else {
            // Force the gender to match the sport's gender category for Male/Female sports
            $data['gender'] = $sportDetails['gender'];
        }

        // Create folder structure: uploads/competitions/{competition_name_year}/{university_name}/{sport_name_gender}/

        // 1. Competition folder name
        $competitionFolder = preg_replace('/[^a-zA-Z0-9_-]/', '', str_replace(' ', '_', $sportDetails['competition_name'])) . '_' . $sportDetails['year'];

        // 2. University folder name
        $universityFolder = preg_replace('/[^a-zA-Z0-9_-]/', '', str_replace(' ', '_', $sportDetails['university_name']));

        // 3. Sport folder name with gender suffix
        $sportFolderName = preg_replace('/[^a-zA-Z0-9_-]/', '', str_replace(' ', '_', $sportDetails['sport_name']));
        $genderSuffix = '';
        if ($sportDetails['gender'] === 'Male') {
            $genderSuffix = '_M';
        } elseif ($sportDetails['gender'] === 'Female') {
            $genderSuffix = '_F';
        } elseif ($sportDetails['gender'] === 'Mixed') {
            $genderSuffix = '_X';
        }
        $sportFolder = $sportFolderName . $genderSuffix;

        // Full base path for this sport (including university)
        $sportBasePath = $mainUploadDir . 'competitions/' . $competitionFolder . '/' . $universityFolder . '/' . $sportFolder . '/';

        // Create sport folder if it doesn't exist
        if (!file_exists($sportBasePath)) {
            mkdir($sportBasePath, 0777, true);
        }

        $fileData = [
            'psa_document' => '',
            'eligibility_document' => '',
            'group2_documents' => '',
            'overall_documents' => '',
            'photo' => ''
        ];

// EDITING EXISTING ATHLETE - COMPLETELY REWRITTEN
if ($isEdit) {
    // Get the original athlete data for comparison
    $originalAthlete = $athlete;
   
    // Create the new folder name based on updated information
    $newFolderName = $id . '_' . preg_replace(
        '/[^a-zA-Z0-9_-]/',
        '_',
        $data['last_name'] . '_' . $data['first_name'] . '_' . $data['middle_initial']
    );
    $targetAthleteFolderPath = $sportBasePath . $newFolderName . '/';
   
    // Find the current athlete folder
    $currentAthleteFolder = null;
    $hasExistingFiles = false;
   
    // Try to find existing folder from file paths
    $fileFieldsToCheck = ['psa_document', 'eligibility_document', 'group2_documents', 'overall_documents', 'photo'];
    foreach ($fileFieldsToCheck as $field) {
        if (!empty($athlete[$field]) && file_exists($athlete[$field])) {
            $hasExistingFiles = true;
            // Get the athlete folder path (go up 2-3 levels depending on the file)
            $fileDir = dirname($athlete[$field]);
            // For PSA/Eligibility: need to go up 2 levels (from psa/ to athlete folder)
            if (strpos($fileDir, '01_group1_documents') !== false) {
                $currentAthleteFolder = dirname(dirname($fileDir)) . '/';
            }
            // For Group2: go up 1 level
            elseif (strpos($fileDir, '02_group2_documents') !== false) {
                $currentAthleteFolder = dirname($fileDir) . '/';
            }
            // For Photo: go up 1 level
            elseif (strpos($fileDir, '03_photo') !== false) {
                $currentAthleteFolder = dirname($fileDir) . '/';
            }
            // For Overall: go up 1 level
            elseif (strpos($fileDir, '04_overall_documents') !== false) {
                $currentAthleteFolder = dirname($fileDir) . '/';
            }
            break;
        }
    }
   
    // If no folder found, try pattern matching
    if (!$hasExistingFiles) {
        $possibleFolderPattern = $sportBasePath . $id . '_*';
        $matchingFolders = glob($possibleFolderPattern, GLOB_ONLYDIR);
        if (!empty($matchingFolders)) {
            $currentAthleteFolder = $matchingFolders[0] . '/';
            $hasExistingFiles = true;
        }
    }
   
    // Determine if we need to rename the folder
    $nameChanged = false;
    if ($hasExistingFiles && $currentAthleteFolder && file_exists($currentAthleteFolder)) {
        $currentFolderName = basename(rtrim($currentAthleteFolder, '/'));
        $expectedFolderName = $id . '_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $originalAthlete['last_name'] . '_' . $originalAthlete['first_name'] . '_' . ($originalAthlete['middle_initial'] ?? ''));
       
        // Check if name or sport changed
        $sportChanged = ($originalAthlete['competition_sport_id'] != $data['competition_sport_id']);
        $nameChanged = ($currentFolderName !== $newFolderName);
       
        if ($sportChanged || $nameChanged) {
            // Need to move/rename the folder
            if (!file_exists($sportBasePath)) {
                mkdir($sportBasePath, 0777, true);
            }
           
            // If target folder exists, delete it first
            if (file_exists($targetAthleteFolderPath)) {
                deleteDirectory($targetAthleteFolderPath);
            }
           
            // Move or rename the folder
            if (rename($currentAthleteFolder, $targetAthleteFolderPath)) {
                $currentAthleteFolder = $targetAthleteFolderPath;
                // Update file paths in memory
                foreach ($fileFieldsToCheck as $field) {
                    if (!empty($originalAthlete[$field]) && file_exists($originalAthlete[$field])) {
                        $fileData[$field] = str_replace($currentAthleteFolder, $targetAthleteFolderPath, $originalAthlete[$field]);
                    } else {
                        $fileData[$field] = $originalAthlete[$field] ?? '';
                    }
                }
            } else {
                // Failed to move, keep original paths
                foreach ($fileFieldsToCheck as $field) {
                    $fileData[$field] = $originalAthlete[$field] ?? '';
                }
                $targetAthleteFolderPath = $currentAthleteFolder;
            }
        } else {
            // No changes needed, use existing paths
            foreach ($fileFieldsToCheck as $field) {
                $fileData[$field] = $originalAthlete[$field] ?? '';
            }
            $targetAthleteFolderPath = $currentAthleteFolder;
        }
    } else {
        // No existing folder, create new one
        if (!file_exists($targetAthleteFolderPath)) {
            mkdir($targetAthleteFolderPath, 0777, true);
            $subfolders = [
                '01_group1_documents/psa',
                '01_group1_documents/eligibility',
                '02_group2_documents',
                '03_photo',
                '04_overall_documents'
            ];
            foreach ($subfolders as $subfolder) {
                mkdir($targetAthleteFolderPath . $subfolder, 0777, true);
            }
        }
        foreach ($fileFieldsToCheck as $field) {
            $fileData[$field] = $originalAthlete[$field] ?? '';
        }
    }
   
    // Process file uploads (new files will replace old ones)
    $fileData['psa_document'] = handleFileUpload('psa_document', $targetAthleteFolderPath, $fileData['psa_document']);
    $fileData['eligibility_document'] = handleFileUpload('eligibility_document', $targetAthleteFolderPath, $fileData['eligibility_document']);
    $fileData['group2_documents'] = handleFileUpload('group2_documents', $targetAthleteFolderPath, $fileData['group2_documents']);
    $fileData['overall_documents'] = handleFileUpload('overall_documents', $targetAthleteFolderPath, $fileData['overall_documents']);
    $fileData['photo'] = handleFileUpload('photo', $targetAthleteFolderPath, $fileData['photo']);
   
    // Update athlete in database
    $sql = "UPDATE athletes SET
            student_id = ?, first_name = ?, middle_initial = ?, last_name = ?,
            gender = ?, contact_number = ?, email = ?,
            competition_sport_id = ?,
            psa_document = ?, eligibility_document = ?, group2_documents = ?, overall_documents = ?, photo = ?,
            birth_certificate_status = ?, eligibility_form_status = ?,
            cor_status = ?, tor_status = ?, photo_status = ?
            WHERE id = ?";
   
    $params = [
        $data['student_id'],
        $data['first_name'],
        $data['middle_initial'],
        $data['last_name'],
        $data['gender'],
        $data['contact_number'],
        $data['email'],
        $data['competition_sport_id'],
        $fileData['psa_document'],
        $fileData['eligibility_document'],
        $fileData['group2_documents'],
        $fileData['overall_documents'],
        $fileData['photo'],
        $docStatus['birth_certificate_status'],
        $docStatus['eligibility_form_status'],
        $docStatus['cor_status'],
        $docStatus['tor_status'],
        $docStatus['photo_status'],
        $id
    ];
   
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
   
    // After update, if folder path changed but we didn't move files (due to failed rename),
    // we need to update the folder name to match the new athlete name
    if ($hasExistingFiles && $currentAthleteFolder && file_exists($currentAthleteFolder) && !$sportChanged && $nameChanged) {
        $finalFolderPath = $sportBasePath . $newFolderName . '/';
        if ($currentAthleteFolder !== $finalFolderPath && !file_exists($finalFolderPath)) {
            if (rename($currentAthleteFolder, $finalFolderPath)) {
                // Update file paths in database again
                $updates = [];
                $updateParams = [];
               
                foreach ($fileFieldsToCheck as $field) {
                    if (!empty($fileData[$field]) && strpos($fileData[$field], $currentAthleteFolder) !== false) {
                        $newPath = str_replace($currentAthleteFolder, $finalFolderPath, $fileData[$field]);
                        $updates[] = "$field = ?";
                        $updateParams[] = $newPath;
                    }
                }
               
                if (!empty($updates)) {
                    $updateSql = "UPDATE athletes SET " . implode(', ', $updates) . " WHERE id = ?";
                    $updateParams[] = $id;
                    $updateStmt = $pdo->prepare($updateSql);
                    $updateStmt->execute($updateParams);
                }
            }
        }
    }
   
    $_SESSION['success'] = "Athlete updated successfully!";
} else {
            // ADDING NEW ATHLETE

            // Create athlete folder name (without ID yet)
            $tempAthleteFolderName = 'temp_' . time() . '_' . preg_replace(
                '/[^a-zA-Z0-9_-]/',
                '_',
                $data['last_name'] . '_' . $data['first_name'] . '_' . $data['middle_initial']
            );
            $tempAthleteFolderPath = $sportBasePath . $tempAthleteFolderName . '/';

            // Create athlete folder and subfolders
            if (!file_exists($tempAthleteFolderPath)) {
                mkdir($tempAthleteFolderPath, 0777, true);

                // Create subfolders
                $subfolders = [
                    '01_group1_documents/psa',
                    '01_group1_documents/eligibility',
                    '02_group2_documents',
                    '03_photo',
                    '04_overall_documents'
                ];

                foreach ($subfolders as $subfolder) {
                    mkdir($tempAthleteFolderPath . $subfolder, 0777, true);
                }
            }

            // Process file uploads into temporary folder
            $fileData['psa_document'] = handleFileUpload('psa_document', $tempAthleteFolderPath, '');
            $fileData['eligibility_document'] = handleFileUpload('eligibility_document', $tempAthleteFolderPath, '');
            $fileData['group2_documents'] = handleFileUpload('group2_documents', $tempAthleteFolderPath, '');
            $fileData['overall_documents'] = handleFileUpload('overall_documents', $tempAthleteFolderPath, '');
            $fileData['photo'] = handleFileUpload('photo', $tempAthleteFolderPath, '');

            $sql = "INSERT INTO athletes (
                    student_id, first_name, middle_initial, last_name, gender,
                    contact_number, email, competition_sport_id,
                    psa_document, eligibility_document, group2_documents, overall_documents, photo,
                    birth_certificate_status, eligibility_form_status,
                    cor_status, tor_status, photo_status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $params = [
                $data['student_id'],
                $data['first_name'],
                $data['middle_initial'],
                $data['last_name'],
                $data['gender'],
                $data['contact_number'],
                $data['email'],
                $data['competition_sport_id'],
                $fileData['psa_document'],
                $fileData['eligibility_document'],
                $fileData['group2_documents'],
                $fileData['overall_documents'],
                $fileData['photo'],
                $docStatus['birth_certificate_status'],
                $docStatus['eligibility_form_status'],
                $docStatus['cor_status'],
                $docStatus['tor_status'],
                $docStatus['photo_status']
            ];

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            // Get the auto-generated athlete ID
            $newAthleteId = $pdo->lastInsertId();

            // Rename the temporary folder to include the actual ID
            $finalAthleteFolderName = $newAthleteId . '_' . preg_replace(
                '/[^a-zA-Z0-9_-]/',
                '_',
                $data['last_name'] . '_' . $data['first_name'] . '_' . $data['middle_initial']
            );
            $finalAthleteFolderPath = $sportBasePath . $finalAthleteFolderName . '/';

            if (file_exists($tempAthleteFolderPath) && $tempAthleteFolderPath != $finalAthleteFolderPath) {
                // Check if final folder already exists
                if (file_exists($finalAthleteFolderPath)) {
                    // Add timestamp to make it unique
                    $timestamp = time();
                    $finalAthleteFolderName = $newAthleteId . '_' . preg_replace(
                        '/[^a-zA-Z0-9_-]/',
                        '_',
                        $data['last_name'] . '_' . $data['first_name'] . '_' . $data['middle_initial']
                    ) . '_' . $timestamp;
                    $finalAthleteFolderPath = $sportBasePath . $finalAthleteFolderName . '/';
                }

                // Rename the folder
                rename($tempAthleteFolderPath, $finalAthleteFolderPath);

                // Update file paths in database with new folder path
                if (!empty($fileData['psa_document']) && strpos($fileData['psa_document'], $tempAthleteFolderPath) !== false) {
                    $newFilePath = str_replace($tempAthleteFolderPath, $finalAthleteFolderPath, $fileData['psa_document']);
                    $updateSql = "UPDATE athletes SET psa_document = ? WHERE id = ?";
                    $updateStmt = $pdo->prepare($updateSql);
                    $updateStmt->execute([$newFilePath, $newAthleteId]);
                }

                if (!empty($fileData['eligibility_document']) && strpos($fileData['eligibility_document'], $tempAthleteFolderPath) !== false) {
                    $newFilePath = str_replace($tempAthleteFolderPath, $finalAthleteFolderPath, $fileData['eligibility_document']);
                    $updateSql = "UPDATE athletes SET eligibility_document = ? WHERE id = ?";
                    $updateStmt = $pdo->prepare($updateSql);
                    $updateStmt->execute([$newFilePath, $newAthleteId]);
                }

                if (!empty($fileData['group2_documents']) && strpos($fileData['group2_documents'], $tempAthleteFolderPath) !== false) {
                    $newFilePath = str_replace($tempAthleteFolderPath, $finalAthleteFolderPath, $fileData['group2_documents']);
                    $updateSql = "UPDATE athletes SET group2_documents = ? WHERE id = ?";
                    $updateStmt = $pdo->prepare($updateSql);
                    $updateStmt->execute([$newFilePath, $newAthleteId]);
                }

                if (!empty($fileData['overall_documents']) && strpos($fileData['overall_documents'], $tempAthleteFolderPath) !== false) {
                    $newFilePathOverall = str_replace($tempAthleteFolderPath, $finalAthleteFolderPath, $fileData['overall_documents']);
                    $updateSql = "UPDATE athletes SET overall_documents = ? WHERE id = ?";
                    $updateStmt = $pdo->prepare($updateSql);
                    $updateStmt->execute([$newFilePathOverall, $newAthleteId]);
                }

                if (!empty($fileData['photo']) && strpos($fileData['photo'], $tempAthleteFolderPath) !== false) {
                    $newFilePath = str_replace($tempAthleteFolderPath, $finalAthleteFolderPath, $fileData['photo']);
                    $updateSql = "UPDATE athletes SET photo = ? WHERE id = ?";
                    $updateStmt = $pdo->prepare($updateSql);
                    $updateStmt->execute([$newFilePath, $newAthleteId]);
                }
            }

            $_SESSION['success'] = "Athlete added successfully!";
        }

        // Redirect back to the correct sport page
        $redirectSportId = $data['competition_sport_id'];
        $stmt = $pdo->prepare("
            SELECT cs.competition_id, cs.university_id
            FROM competition_sports cs
            WHERE cs.id = ?
        ");
        $stmt->execute([$redirectSportId]);
        $redirectInfo = $stmt->fetch();

        if ($redirectInfo) {
            header("Location: athletes.php?competition_id={$redirectInfo['competition_id']}&university_id={$redirectInfo['university_id']}&sport_id={$redirectSportId}");
        } else {
            header('Location: athletes.php');
        }
        exit();
    } catch (PDOException $e) {
        // Clean up temporary folder if creation failed
        if (!$isEdit && isset($tempAthleteFolderPath) && file_exists($tempAthleteFolderPath)) {
            // Delete all files in the folder first
            $files = glob($tempAthleteFolderPath . '*', GLOB_MARK);
            foreach ($files as $file) {
                if (is_dir($file)) {
                    $subfiles = glob($file . '*', GLOB_MARK);
                    foreach ($subfiles as $subfile) {
                        unlink($subfile);
                    }
                    rmdir($file);
                } else {
                    unlink($file);
                }
            }
            rmdir($tempAthleteFolderPath);
        }

        if ($e->getCode() == 23000) { // Duplicate entry
            $error = "Student ID already exists!";
        } else {
            $error = "Error saving athlete: " . $e->getMessage();
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Fetch all active courses for the dropdown
$courses = [];
try {
    $courseStmt = $pdo->query("SELECT id, course_code, course_name FROM courses WHERE is_active = 1 ORDER BY course_code, course_name");
    $courses = $courseStmt->fetchAll();
} catch (PDOException $e) {
    // Silently fail - courses will be empty array
}

// If editing, get the current course_id
$selectedCourseId = null;
if ($isEdit && $athlete) {
    $selectedCourseId = $athlete['course_id'] ?? null;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <title>TAU Sports - <?php echo $isEdit ? 'Edit' : 'Add'; ?> Athlete</title>
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
            background: rgba(0, 0, 0, 0.2);
            padding: 2px 10px;
            border-radius: 10px;
            margin-top: 2px;
            display: inline-block;
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
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
        }

        .main-container {
            display: flex;
            min-height: calc(100vh - 80px);
            position: relative;
        }

.sidebar {
    width: 250px;
    background: white;
    border-right: 1px solid #e0e0e0;
    padding: 30px 0;
    box-shadow: 2px 0 10px rgba(0, 0, 0, 0.05);
    position: sticky;
    top: 80px; /* Matches the header height */
    height: calc(100vh - 80px);
    overflow-y: auto;
    align-self: flex-start;
}


@media (max-width: 768px) {
    .main-container {
        flex-direction: column;
    }

    .sidebar {
        width: 100%;
        padding: 20px 0;
        position: relative;
        top: 0;
        height: auto;
        overflow-y: visible;
    }

    .nav-menu {
        display: flex;
        overflow-x: auto;
        padding: 0 10px;
    }

    .nav-item {
        margin-bottom: 0;
    }

    .nav-link {
        padding: 10px 15px;
        white-space: nowrap;
    }

    .content {
        padding: 20px;
    }

    .form-row {
        grid-template-columns: 1fr;
    }

    .form-actions {
        flex-direction: column;
    }

    .form-actions .btn {
        width: 100%;
        justify-content: center;
    }

    .doc-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }

    .checklist-grid {
        grid-template-columns: 1fr;
    }

    .form-row .form-group:first-child div {
        flex-direction: column;
    }

    .form-row .form-group:first-child button {
        width: 100%;
    }

    /* Make Group 1 uploads stack vertically on mobile */
    div[style*="grid-template-columns: 1fr 1fr"] {
        grid-template-columns: 1fr !important;
    }
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
                overflow-y: auto;
    min-height: calc(100vh - 80px);
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
            border-radius: 10px;
            padding: 30px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
        }

        .form-section {
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e0e0e0;
        }

        .section-title {
            color: #0c3a1d;
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title::before {
            content: '';
            display: block;
            width: 4px;
            height: 20px;
            background: #0c3a1d;
            border-radius: 2px;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }

        .form-group label.required::after {
            content: ' *';
            color: #dc3545;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 16px;
            transition: all 0.3s;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #0c3a1d;
            box-shadow: 0 0 0 3px rgba(12, 58, 29, 0.1);
        }

        .form-group input:disabled,
        .form-group select:disabled {
            background-color: #f5f5f5;
            cursor: not-allowed;
            opacity: 0.7;
        }

        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }

        .file-upload {
            border: 2px dashed #e0e0e0;
            border-radius: 6px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s;
            margin-bottom: 10px;
        }

        .file-upload:hover {
            border-color: #0c3a1d;
            background: #f8f9fa;
        }

        .file-upload label {
            display: block;
            cursor: pointer;
            color: #666;
        }

        .file-upload input[type="file"] {
            display: none;
        }

        .file-preview {
            margin-top: 10px;
            font-size: 14px;
            color: #666;
        }

        .existing-file {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #f0f8ff;
            padding: 5px 10px;
            border-radius: 4px;
            margin-top: 5px;
        }

        .existing-file img {
            width: 30px;
            height: 30px;
            object-fit: cover;
            border-radius: 4px;
        }

        /* Document Status Checkbox Styles */
        .doc-status-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 8px 0;
            padding: 8px;
            background: #f8f9fa;
            border-radius: 6px;
            border-left: 3px solid #0c3a1d;
        }

        .doc-status-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: #0c3a1d;
        }

        .doc-status-group label {
            display: inline;
            margin: 0;
            font-weight: 500;
            color: #333;
            cursor: pointer;
        }

        .status-note {
            font-size: 12px;
            color: #666;
            margin-left: auto;
        }

        .doc-container {
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            background: white;
        }

        .doc-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .doc-title {
            font-weight: 600;
            color: #0c3a1d;
        }

        .btn {
            padding: 12px 24px;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            border: none;
            font-size: 16px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #0c3a1d 0%, #1a5c2f 100%);
            color: white;
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #0a3018 0%, #154d24 100%);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(12, 58, 29, 0.2);
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-1px);
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
        }

        .btn-danger {
            background: #dc3545;
            color: white;
        }

        .btn-danger:hover {
            background: #c82333;
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
        }

        .alert {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            border-left: 4px solid;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left-color: #721c24;
        }

        .form-note {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            border-left: 4px solid #0c3a1d;
            font-size: 14px;
            color: #666;
        }

        .folder-structure {
            background: #f0f8ff;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            border-left: 4px solid #17a2b8;
            font-family: monospace;
            font-size: 13px;
        }

        .info-badge {
            background: #e8f5e9;
            color: #0c3a1d;
            padding: 10px 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            border-left: 4px solid #0c3a1d;
            font-size: 14px;
        }

        .pdf-only-badge {
            display: inline-block;
            background: #dc3545;
            color: white;
            font-size: 11px;
            padding: 2px 8px;
            border-radius: 12px;
            margin-left: 10px;
        }

        .image-badge {
            display: inline-block;
            background: #17a2b8;
            color: white;
            font-size: 11px;
            padding: 2px 8px;
            border-radius: 12px;
            margin-left: 10px;
        }

        .group-container {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 25px;
        }

        .group-title {
            font-size: 18px;
            font-weight: 600;
            color: #0c3a1d;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .group-title::before {
            content: '';
            display: block;
            width: 4px;
            height: 20px;
            background: #0c3a1d;
            border-radius: 2px;
        }

        .checklist-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 10px;
            margin-top: 15px;
        }

        /* Additional styles for the course functionality */
        #course:disabled {
            background-color: #f5f5f5;
            cursor: not-allowed;
            opacity: 0.7;
        }

        #addCourseModal .form-group {
            margin-bottom: 0;
        }

        #addCourseModal input:focus {
            outline: none;
            border-color: #0c3a1d !important;
            box-shadow: 0 0 0 3px rgba(12, 58, 29, 0.1);
        }

        @media (max-width: 768px) {
            .main-container {
                flex-direction: column;
            }

            .sidebar {
                width: 100%;
                padding: 20px 0;
            }

            .nav-menu {
                display: flex;
                overflow-x: auto;
                padding: 0 10px;
            }

            .nav-item {
                margin-bottom: 0;
            }

            .nav-link {
                padding: 10px 15px;
                white-space: nowrap;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .form-actions {
                flex-direction: column;
            }

            .form-actions .btn {
                width: 100%;
                justify-content: center;
            }

            .doc-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .checklist-grid {
                grid-template-columns: 1fr;
            }

            .form-row .form-group:first-child div {
                flex-direction: column;
            }

            .form-row .form-group:first-child button {
                width: 100%;
            }
        }

        /* Toast Notification Styles */
        .custom-notification {
            position: fixed;
            top: 20px;
            right: 20px;
            min-width: 300px;
            max-width: 400px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            display: flex;
            align-items: center;
            padding: 16px 20px;
            gap: 15px;
            transform: translateX(120%);
            transition: transform 0.3s ease;
            z-index: 9999;
            border-left: 4px solid;
        }

        .custom-notification.show {
            transform: translateX(0);
        }

        .custom-notification.success {
            border-left-color: #0c3a1d;
        }

        .custom-notification.error {
            border-left-color: #dc3545;
        }

        .notification-icon {
            font-size: 24px;
        }

        .notification-message {
            flex: 1;
            font-size: 14px;
            color: #333;
        }

        .notification-progress {
            position: absolute;
            bottom: 0;
            left: 0;
            height: 3px;
            width: 100%;
            background: rgba(0, 0, 0, 0.1);
            animation: progress 3s linear;
        }

        @keyframes progress {
            from {
                width: 100%;
            }

            to {
                width: 0%;
            }
        }

        /* Fix for select dropdown to show max 8 items with scroll */
        #course_select {
            max-height: 200px;
            overflow-y: auto;
        }

        /* This ensures the dropdown itself has scroll */
        #course_select[size] {
            max-height: 200px;
        }

        /* For browsers that support it */
        @supports (scrollbar-width: thin) {
            #course_select {
                scrollbar-width: thin;
                scrollbar-color: #0c3a1d #e0e0e0;
            }
        }

        /* Custom scrollbar for Chrome/Safari/Edge */
        #course_select::-webkit-scrollbar {
            width: 8px;
        }

        #course_select::-webkit-scrollbar-track {
            background: #e0e0e0;
            border-radius: 4px;
        }

        #course_select::-webkit-scrollbar-thumb {
            background: #0c3a1d;
            border-radius: 4px;
        }

        #course_select::-webkit-scrollbar-thumb:hover {
            background: #1a5c2f;
        }

        /* View button styles */
        .btn-view-file {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #0c3a1d;
            color: white;
            padding: 5px 12px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.3s;
            border: none;
            cursor: pointer;
            margin-left: 10px;
        }

        .btn-view-file:hover {
            background: #1a5c2f;
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(12, 58, 29, 0.3);
            color: white;
            text-decoration: none;
        }

        .btn-view-file i {
            font-size: 14px;
        }

        .existing-file {
            background: #f0f8ff;
            padding: 10px 15px;
            border-radius: 6px;
            margin-top: 10px;
            border-left: 3px solid #0c3a1d;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
        }

        .file-info {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .file-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .file-name {
            color: #333;
            font-size: 14px;
            word-break: break-all;
            max-width: 300px;
        }

        .photo-thumbnail {
            width: 40px;
            height: 40px;
            object-fit: cover;
            border-radius: 4px;
            border: 2px solid #fff;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        /* For mobile responsiveness */
        @media (max-width: 768px) {
            .existing-file {
                flex-direction: column;
                align-items: flex-start;
            }

            .file-actions {
                width: 100%;
                justify-content: flex-end;
            }

            .btn-view-file {
                padding: 4px 10px;
                font-size: 12px;
            }
        }

        /* Responsive design for side-by-side uploads */
@media (max-width: 768px) {
    /* Make Group 1 uploads stack vertically on mobile */
    div[style*="grid-template-columns: 1fr 1fr"] {
        grid-template-columns: 1fr !important;
    }
}

/* Add hover effect for side-by-side containers */
.group-container {
    transition: all 0.3s ease;
}

.group-container:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
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
                <li class="nav-item"><a href="athletes.php" class="nav-link"><i class="fas fa-users"></i> Athlete Profiles</a></li>
                <li class="nav-item"><a href="report.php" class="nav-link"><i class="fas fa-chart-bar"></i> Athletes Report</a></li>
                <li class="nav-item"><a href="borrowers_form.php" class="nav-link"><i class="fas fa-file"></i> Borrowers Form</a></li>
                <li class="nav-item"><a href="borrowers_list.php" class="nav-link"><i class="fas fa-clipboard"></i> Borrowers List</a></li>
            </ul>
        </nav>

        <main class="content">
            <div class="page-title">
                <?php echo $isEdit ? 'Edit Athlete Profile' : 'Add New Athlete'; ?>
                <?php if ($sportInfo): ?>
                    <div style="font-size: 16px; color: #666; margin-top: 5px;">
                        For: <?php echo htmlspecialchars($sportInfo['competition_name'] . ' ' . $sportInfo['year'] . ' - ' . $sportInfo['university_name'] . ' - ' . $sportInfo['sport_name'] . ' (' . $sportInfo['gender'] . ')'); ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (isset($error)): ?>
                <div class="alert alert-error"><?php echo $error; ?></div>
            <?php endif; ?>

            <?php if (isset($_SESSION['folder_message'])): ?>
                <div class="alert alert-success"><?php echo $_SESSION['folder_message'];
                                                    unset($_SESSION['folder_message']); ?></div>
            <?php endif; ?>

            <div class="form-container">
                <!-- Show folder structure info -->
                <?php if ($sportInfo): ?>
                    <div class="folder-structure">
                        <strong>📁 Files will be saved in:</strong><br>
                        <code>uploads/competitions/<?php
                                                    echo preg_replace('/[^a-zA-Z0-9_-]/', '', str_replace(' ', '_', $sportInfo['competition_name'])) . '_' . $sportInfo['year'];
                                                    ?>/<?php
                                                        echo preg_replace('/[^a-zA-Z0-9_-]/', '', str_replace(' ', '_', $sportInfo['university_name']));
                                                        ?>/<?php
                                                            $sportFolder = preg_replace('/[^a-zA-Z0-9_-]/', '', str_replace(' ', '_', $sportInfo['sport_name']));
                                                            if ($sportInfo['gender'] === 'Male') $sportFolder .= '_M';
                                                            elseif ($sportInfo['gender'] === 'Female') $sportFolder .= '_F';
                                                            elseif ($sportInfo['gender'] === 'Mixed') $sportFolder .= '_X';
                                                            echo $sportFolder;
                                                            ?>/[athlete_id]_[name]/</code>
                        <br>├── 01_group1_documents/
                        <br>│   ├── psa/ (PSA Birth Certificate - PDF, JPG, JPEG, PNG)
                        <br>│   └── eligibility/ (Eligibility Form - PDF, JPG, JPEG, PNG)
                        <br>├── 02_group2_documents/ (COR, TOR - PDF only)
                        <br>├── 03_photo/ (2x2 Photo - Image)
                        <br>└── 04_overall_documents/ (Optional Combined Documents - PDF)
                    </div>
                <?php endif; ?>

                <!-- Show sport gender info -->
                <?php if ($sportInfo): ?>
                    <div class="info-badge">
                        <strong><i class="fas fa-futbol"></i> Sport Information:</strong> This is a <strong><?php echo $sportInfo['gender']; ?></strong> sport.
                        <?php if ($sportInfo['gender'] === 'Mixed'): ?>
                            Athlete gender can be selected from the options below.
                        <?php else: ?>
                            Athlete gender will be automatically set to <strong><?php echo $sportInfo['gender']; ?></strong>.
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" enctype="multipart/form-data" id="athleteForm">
                    <!-- Add hidden field for sport info if coming from sport page -->
                    <?php if ($sportInfo): ?>
                        <input type="hidden" name="from_sport" value="<?php echo $sportInfo['id']; ?>">
                    <?php endif; ?>

                    <div class="form-section">
                        <h3 class="section-title">Personal Information</h3>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="student_id" class="required">Student ID</label>
                                <input type="text" id="student_id" name="student_id" required
                                    value="<?php echo htmlspecialchars($athlete['student_id'] ?? ''); ?>">
                            </div>

                            <div class="form-group">
                                <label for="email">Email Address</label>
                                <input type="email" id="email" name="email"
                                    value="<?php echo htmlspecialchars($athlete['email'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="last_name" class="required">Last Name</label>
                                <input type="text" id="last_name" name="last_name" required
                                    value="<?php echo htmlspecialchars($athlete['last_name'] ?? ''); ?>">
                            </div>

                            <div class="form-group">
                                <label for="first_name" class="required">First Name</label>
                                <input type="text" id="first_name" name="first_name" required
                                    value="<?php echo htmlspecialchars($athlete['first_name'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="middle_initial">Middle Initial</label>
                                <input type="text"
                                       id="middle_initial"
                                       name="middle_initial"
                                       maxlength="3"
                                       placeholder="e.g., JR."
                                       value="<?php echo htmlspecialchars($athlete['middle_initial'] ?? ''); ?>"
                                       style="text-transform: uppercase;"
                                       oninput="this.value = this.value.toUpperCase().replace(/[^A-Z.]/g, '').substring(0, 3)">
                                <small style="color: #666; display: block; margin-top: 5px;">
                                    Enter middle initial (e.g., D., JR., SR.)
                                </small>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="gender" class="required">Gender</label>
                                <?php if ($sportInfo): ?>
                                    <?php if ($sportInfo['gender'] === 'Mixed'): ?>
                                        <!-- For Mixed sports, allow user to select gender -->
                                        <select id="gender" name="gender" required>
                                            <option value="">Select Gender</option>
                                            <option value="Male" <?php echo ($athlete['gender'] ?? '') === 'Male' ? 'selected' : ''; ?>>Male</option>
                                            <option value="Female" <?php echo ($athlete['gender'] ?? '') === 'Female' ? 'selected' : ''; ?>>Female</option>
                                        </select>
                                        <small style="color: #666; display: block; margin-top: 5px;">
                                            This is a Mixed gender sport. Please select the athlete's gender.
                                        </small>
                                    <?php else: ?>
                                        <!-- For Male/Female sports, gender is automatically set and disabled -->
                                        <input type="text" id="gender_display" class="gender-display"
                                            value="<?php echo $sportInfo['gender']; ?>" disabled
                                            style="background-color: #f5f5f5; cursor: not-allowed;">
                                        <input type="hidden" name="gender" value="<?php echo $sportInfo['gender']; ?>">
                                        <small style="color: #666; display: block; margin-top: 5px;">
                                            Gender is automatically set to match the sport category (<?php echo $sportInfo['gender']; ?>)
                                        </small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <!-- Fallback if no sport is selected -->
                                    <select id="gender" name="gender" required>
                                        <option value="">Select Gender</option>
                                        <option value="Male" <?php echo ($athlete['gender'] ?? '') === 'Male' ? 'selected' : ''; ?>>Male</option>
                                        <option value="Female" <?php echo ($athlete['gender'] ?? '') === 'Female' ? 'selected' : ''; ?>>Female</option>
                                        <option value="Other" <?php echo ($athlete['gender'] ?? '') === 'Other' ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                <?php endif; ?>
                            </div>

                            <div class="form-group">
                                <label for="contact_number">Contact Number</label>
                                <input type="tel" id="contact_number" name="contact_number"
                                    value="<?php echo htmlspecialchars($athlete['contact_number'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3 class="section-title">Sports Information</h3>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="competition_sport_id" class="required">Competition & Sport</label>
                                <?php if ($sportInfo && !$isEdit): ?>
                                    <!-- For new athletes coming from a specific sport, show as disabled text -->
                                    <input type="text" id="competition_sport_display"
                                        value="<?php echo htmlspecialchars($sportInfo['competition_name'] . ' ' . $sportInfo['year'] . ' - ' . $sportInfo['university_name'] . ' - ' . $sportInfo['sport_name'] . ' (' . $sportInfo['gender'] . ')'); ?>"
                                        disabled style="background-color: #f5f5f5; cursor: not-allowed;">
                                    <input type="hidden" name="competition_sport_id" value="<?php echo $sportInfo['id']; ?>">
                                    <small style="color: #666; display: block; margin-top: 5px;">
                                        You are adding an athlete to this specific sport. To change the sport, please go back and select a different sport.
                                    </small>
                                <?php elseif ($isEdit): ?>
                                    <!-- For editing, allow changing sport but show current one as selected -->
                                    <select id="competition_sport_id" name="competition_sport_id" required>
                                        <option value="">Select Competition, University & Sport</option>
                                        <?php
                                        // Fetch competitions with their universities and sports
                                        $stmt = $pdo->query("
                                            SELECT cs.id, cs.sport_name, cs.gender,
                                                   c.name as competition_name, c.year,
                                                   u.name as university_name
                                            FROM competition_sports cs
                                            JOIN competitions c ON cs.competition_id = c.id
                                            JOIN universities u ON cs.university_id = u.id
                                            WHERE cs.status = 'active'
                                            ORDER BY c.year DESC, c.name, u.name, cs.sport_name
                                        ");
                                        $sports = $stmt->fetchAll();
                                        foreach ($sports as $sport):
                                        ?>
                                            <option value="<?php echo $sport['id']; ?>"
                                                <?php echo ($athlete['competition_sport_id'] ?? '') == $sport['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($sport['competition_name'] . ' ' . $sport['year'] . ' - ' . $sport['university_name'] . ' - ' . $sport['sport_name'] . ' (' . $sport['gender'] . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <!-- Fallback if no sport is preselected (should not happen normally) -->
                                    <select id="competition_sport_id" name="competition_sport_id" required>
                                        <option value="">Select Competition, University & Sport</option>
                                        <?php
                                        $stmt = $pdo->query("
                                            SELECT cs.id, cs.sport_name, cs.gender,
                                                   c.name as competition_name, c.year,
                                                   u.name as university_name
                                            FROM competition_sports cs
                                            JOIN competitions c ON cs.competition_id = c.id
                                            JOIN universities u ON cs.university_id = u.id
                                            WHERE cs.status = 'active'
                                            ORDER BY c.year DESC, c.name, u.name, cs.sport_name
                                        ");
                                        $sports = $stmt->fetchAll();
                                        foreach ($sports as $sport):
                                        ?>
                                            <option value="<?php echo $sport['id']; ?>">
                                                <?php echo htmlspecialchars($sport['competition_name'] . ' ' . $sport['year'] . ' - ' . $sport['university_name'] . ' - ' . $sport['sport_name'] . ' (' . $sport['gender'] . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
    <h3 class="section-title">Required Documents</h3>
    <div class="form-note">
        <strong>Note:</strong>
        <ul style="margin-top: 5px; margin-left: 20px;">
            <li><strong>Group 1 (PSA & Eligibility):</strong> Upload PDF, JPG, JPEG, or PNG files - each document separately</li>
            <li><strong>Group 2 (COR & TOR):</strong> Upload PDF files only - combine both in one PDF</li>
            <li><strong>Photo:</strong> Upload image files (JPG, PNG, GIF, WEBP)</li>
            <li><strong>Files will keep their original names</strong> (spaces and special characters preserved)</li>
        </ul>
        <br><strong>Folder Structure:</strong>
        <ul style="margin-top: 5px; padding-left: 20px;">
            <li>📁 uploads/</li>
            <li>├── competitions/</li>
            <li>│ ├── [Competition Name_Year]/</li>
            <li>│ │ ├── [University Name]/</li>
            <li>│ │ │ ├── [Sport Name]_[M/F/X]/</li>
            <li>│ │ │ │ ├── [Athlete ID]_[Name]/</li>
            <li>│ │ │ │ │ ├── 01_group1_documents/</li>
            <li>│ │ │ │ │ │ ├── psa/ (PSA Birth Certificate) - PDF, JPG, JPEG, PNG</li>
            <li>│ │ │ │ │ │ └── eligibility/ (Eligibility Form) - PDF, JPG, JPEG, PNG</li>
            <li>│ │ │ │ │ ├── 02_group2_documents/ (COR, TOR) - PDF only</li>
            <li>│ │ │ │ │ ├── 03_photo/ (2x2 Photo) - JPG, PNG, etc.</li>
            <li>│ │ │ │ │ └── 04_overall_documents/ (Optional Combined) - PDF only</li>
        </ul>
        Maximum file size: 10MB per file.
    </div>

    <!-- Group 1 Documents - Side by Side -->
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
        <!-- PSA Birth Certificate Upload -->
        <div class="group-container" style="margin: 0;">
            <div class="group-title">
                <span>PSA Birth Certificate</span>
                <span class="pdf-only-badge" style="background: #17a2b8;">PDF, JPG, PNG</span>
            </div>
            <p style="color: #666; margin-bottom: 15px; font-size: 14px;">Upload PSA Birth Certificate (PDF, JPG, JPEG, or PNG format)</p>

            <div class="file-upload">
                <label for="psa_document">
                    📁 Click to upload PSA Birth Certificate
                    <input type="file" id="psa_document" name="psa_document"
                        accept=".pdf,.jpg,.jpeg,.png" onchange="previewFile(this, 'psa_document', 'document')">
                </label>
                <div class="file-preview" id="preview-psa_document">
                    <?php if (!empty($athlete['psa_document']) && file_exists($athlete['psa_document'])):
                        $filename = basename($athlete['psa_document']);
                        $fileExt = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                    ?>
                        <div class="existing-file" id="existing-psa_document">
                            <div class="file-info">
                                <?php if (in_array($fileExt, ['jpg', 'jpeg', 'png'])): ?>
                                    <img src="<?php echo htmlspecialchars($athlete['psa_document']); ?>" class="photo-thumbnail" alt="PSA Document">
                                <?php else: ?>
                                    <span>📄</span>
                                <?php endif; ?>
                                <span class="file-name"><?php echo htmlspecialchars($filename); ?></span>
                            </div>
                            <div class="file-actions">
                                <a href="<?php echo htmlspecialchars($athlete['psa_document']); ?>" target="_blank" class="btn-view-file">
                                    <i class="fas fa-eye"></i> View
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Eligibility Form Upload -->
        <div class="group-container" style="margin: 0;">
            <div class="group-title">
                <span>Eligibility Form</span>
                <span class="pdf-only-badge" style="background: #17a2b8;">PDF, JPG, PNG</span>
            </div>
            <p style="color: #666; margin-bottom: 15px; font-size: 14px;">Upload Eligibility Form (PDF, JPG, JPEG, or PNG format)</p>

            <div class="file-upload">
                <label for="eligibility_document">
                    📁 Click to upload Eligibility Form
                    <input type="file" id="eligibility_document" name="eligibility_document"
                        accept=".pdf,.jpg,.jpeg,.png" onchange="previewFile(this, 'eligibility_document', 'document')">
                </label>
                <div class="file-preview" id="preview-eligibility_document">
                    <?php if (!empty($athlete['eligibility_document']) && file_exists($athlete['eligibility_document'])):
                        $filename = basename($athlete['eligibility_document']);
                        $fileExt = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                    ?>
                        <div class="existing-file" id="existing-eligibility_document">
                            <div class="file-info">
                                <?php if (in_array($fileExt, ['jpg', 'jpeg', 'png'])): ?>
                                    <img src="<?php echo htmlspecialchars($athlete['eligibility_document']); ?>" class="photo-thumbnail" alt="Eligibility Document">
                                <?php else: ?>
                                    <span>📄</span>
                                <?php endif; ?>
                                <span class="file-name"><?php echo htmlspecialchars($filename); ?></span>
                            </div>
                            <div class="file-actions">
                                <a href="<?php echo htmlspecialchars($athlete['eligibility_document']); ?>" target="_blank" class="btn-view-file">
                                    <i class="fas fa-eye"></i> View
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Group 2 Documents -->
    <div class="group-container">
        <div class="group-title">
            <span>Group 2: Academic Documents (COR & TOR)</span>
            <span class="pdf-only-badge">PDF Only</span>
        </div>
        <p style="color: #666; margin-bottom: 15px; font-size: 14px;">Upload PDF containing: <strong>COR and TOR</strong> (combine both in one PDF file)</p>

        <div class="file-upload">
            <label for="group2_documents">
                📁 Click to upload Group 2 PDF
                <input type="file" id="group2_documents" name="group2_documents"
                    accept=".pdf" onchange="previewFile(this, 'group2_documents', 'document')">
            </label>
            <div class="file-preview" id="preview-group2_documents">
                <?php if (!empty($athlete['group2_documents']) && file_exists($athlete['group2_documents'])):
                    $filename = basename($athlete['group2_documents']);
                ?>
                    <div class="existing-file" id="existing-group2_documents">
                        <div class="file-info">
                            <span>📄</span>
                            <span class="file-name"><?php echo htmlspecialchars($filename); ?></span>
                        </div>
                        <div class="file-actions">
                            <a href="<?php echo htmlspecialchars($athlete['group2_documents']); ?>" target="_blank" class="btn-view-file">
                                <i class="fas fa-eye"></i> View
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Overall Documents (Optional) -->
    <div class="group-container" style="border-left: 4px solid #ffc107;">
        <div class="group-title">
            <span>Overall Documents (Option 2)</span>
            <span class="pdf-only-badge">PDF Only</span>
        </div>
        <p style="color: #666; margin-bottom: 15px;">Upload any additional documents or complete set of requirements (Optional - PDF only)</p>

        <div class="file-upload">
            <label for="overall_documents">
                📁 Click to upload Overall Documents PDF file (Optional)
                <input type="file" id="overall_documents" name="overall_documents"
                    accept=".pdf" onchange="previewFile(this, 'overall_documents', 'document')">
            </label>
            <div class="file-preview" id="preview-overall_documents">
                <?php if (!empty($athlete['overall_documents']) && file_exists($athlete['overall_documents'])):
                    $filename = basename($athlete['overall_documents']);
                ?>
                    <div class="existing-file" id="existing-overall_documents">
                        <div class="file-info">
                            <span>📄</span>
                            <span class="file-name"><?php echo htmlspecialchars($filename); ?></span>
                        </div>
                        <div class="file-actions">
                            <a href="<?php echo htmlspecialchars($athlete['overall_documents']); ?>" target="_blank" class="btn-view-file">
                                <i class="fas fa-eye"></i> View
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <small style="color: #666; display: block; margin-top: 5px;">
            <i class="fas fa-info-circle"></i> This is optional. You can upload a complete set of all documents combined here.
        </small>
    </div>

    <!-- Photo Upload -->
    <div class="group-container">
        <div class="group-title">
            <span>2x2 ID Picture</span>
            <span class="image-badge">JPG, PNG, GIF, WEBP</span>
        </div>
        <p style="color: #666; margin-bottom: 15px;">Upload 2x2 ID picture (JPG, PNG, GIF, or WEBP format)</p>

        <div class="file-upload">
            <label for="photo">
                📁 Click to upload Photo
                <input type="file" id="photo" name="photo"
                    accept=".jpg,.jpeg,.png,.gif,.webp" onchange="previewFile(this, 'photo', 'image')">
            </label>
            <div class="file-preview" id="preview-photo">
                <?php if (!empty($athlete['photo']) && file_exists($athlete['photo'])):
                    $filename = basename($athlete['photo']);
                    $fileExt = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                ?>
                    <div class="existing-file" id="existing-photo">
                        <div class="file-info">
                            <?php if (in_array($fileExt, ['jpg', 'jpeg', 'png', 'gif', 'webp'])): ?>
                                <img src="<?php echo htmlspecialchars($athlete['photo']); ?>" alt="Current photo" class="photo-thumbnail">
                            <?php else: ?>
                                <span>📄</span>
                            <?php endif; ?>
                            <span class="file-name"><?php echo htmlspecialchars($filename); ?></span>
                        </div>
                        <div class="file-actions">
                            <a href="<?php echo htmlspecialchars($athlete['photo']); ?>" target="_blank" class="btn-view-file">
                                <i class="fas fa-eye"></i> View
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Document Status Checkboxes (Separated from uploads) -->
    <div class="group-container" style="background: #f0f8ff; border-left: 4px solid #0c3a1d;">
        <div class="group-title">
            <span>Document Verification Status</span>
        </div>
        <p style="color: #666; margin-bottom: 15px;">Check the boxes below to verify which documents have been submitted and approved. Documents will be automatically checked when uploaded.</p>
       
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 15px;">
            <!-- Group 1 Documents (2 items) -->
            <div style="background: white; padding: 15px; border-radius: 8px;">
                <h5 style="color: #0c3a1d; margin-bottom: 10px;">Group 1 Documents</h5>
                <div class="doc-status-group">
                    <input type="checkbox" id="birth_certificate_status" name="birth_certificate_status"
                        <?php echo (!empty($athlete['birth_certificate_status']) && $athlete['birth_certificate_status'] == 1) ? 'checked' : ''; ?>>
                    <label for="birth_certificate_status">PSA Birth Certificate</label>
                </div>
                <div class="doc-status-group">
                    <input type="checkbox" id="eligibility_form_status" name="eligibility_form_status"
                        <?php echo (!empty($athlete['eligibility_form_status']) && $athlete['eligibility_form_status'] == 1) ? 'checked' : ''; ?>>
                    <label for="eligibility_form_status">Eligibility Form</label>
                </div>
            </div>
           
            <!-- Group 2 Documents (2 items) -->
            <div style="background: white; padding: 15px; border-radius: 8px;">
                <h5 style="color: #0c3a1d; margin-bottom: 10px;">Group 2 Documents</h5>
                <div class="doc-status-group">
                    <input type="checkbox" id="cor_status" name="cor_status"
                        <?php echo (!empty($athlete['cor_status']) && $athlete['cor_status'] == 1) ? 'checked' : ''; ?>>
                    <label for="cor_status">COR (Certificate of Registration)</label>
                </div>
                <div class="doc-status-group">
                    <input type="checkbox" id="tor_status" name="tor_status"
                        <?php echo (!empty($athlete['tor_status']) && $athlete['tor_status'] == 1) ? 'checked' : ''; ?>>
                    <label for="tor_status">TOR (Transcript of Records)</label>
                </div>
            </div>
           
            <!-- Photo (1 item) -->
            <div style="background: white; padding: 15px; border-radius: 8px;">
                <h5 style="color: #0c3a1d; margin-bottom: 10px;">Photo</h5>
                <div class="doc-status-group">
                    <input type="checkbox" id="photo_status" name="photo_status"
                        <?php echo (!empty($athlete['photo_status']) && $athlete['photo_status'] == 1) ? 'checked' : ''; ?>>
                    <label for="photo_status">2x2 ID Picture</label>
                </div>
            </div>
        </div>
       
        <div style="margin-top: 20px; padding: 15px; background: #fff3cd; border-radius: 6px; border-left: 4px solid #ffc107;">
            <strong>📌 Document Status Summary:</strong>
            <span id="docCountDisplay">0/5 documents verified</span>
        </div>
    </div>
</div>

                        <div class="form-actions">
                            <!-- Go back to the correct page -->
                            <?php if ($sportInfo): ?>
                                <a href="athletes.php?competition_id=<?php echo $sportInfo['competition_id']; ?>&university_id=<?php echo $sportInfo['university_id']; ?>&sport_id=<?php echo $sportInfo['id']; ?>" class="btn btn-secondary">❌ Cancel</a>
                            <?php else: ?>
                                <a href="athletes.php" class="btn btn-secondary">❌ Cancel</a>
                            <?php endif; ?>
                            <button type="submit" class="btn btn-primary">
                                <?php echo $isEdit ? '💾 Update Athlete' : '➕ Add Athlete'; ?>
                            </button>
                        </div>
                </form>
            </div>
        </main>
    </div>

    <script>
        // Unified file preview function
        function previewFile(input, fieldName, fileType) {
            const previewId = 'preview-' + fieldName;
            const previewDiv = document.getElementById(previewId);
           
            if (!previewDiv) return;

            if (input.files && input.files[0]) {
                const file = input.files[0];
                const fileName = file.name;
                const fileExt = fileName.split('.').pop().toLowerCase();

                // Validate file type
                if (fieldName === 'psa_document' || fieldName === 'eligibility_document') {
                    // Allow PDF, JPG, JPEG, PNG for PSA and Eligibility
                    const allowedExt = ['pdf', 'jpg', 'jpeg', 'png'];
                    if (!allowedExt.includes(fileExt)) {
                        alert('Please upload only PDF, JPG, JPEG, or PNG files for ' + (fieldName === 'psa_document' ? 'PSA' : 'Eligibility') + ' document.');
                        input.value = '';
                        return;
                    }
                } else if (fieldName === 'group2_documents' || fieldName === 'overall_documents') {
                    // For Group 2 and Overall, only allow PDF
                    if (fileExt !== 'pdf') {
                        alert('Please upload only PDF files for this document.');
                        input.value = '';
                        return;
                    }
                } else if (fieldName === 'photo') {
                    const allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                    if (!allowedExt.includes(fileExt)) {
                        alert('Please upload only image files (JPG, PNG, GIF, WEBP) for photos.');
                        input.value = '';
                        return;
                    }
                }

                // Create a temporary object URL for preview
                const fileUrl = URL.createObjectURL(file);
               
                // Build preview HTML
                let previewHTML = '<div class="existing-file">';
               
                if (fileType === 'image' || (['jpg', 'jpeg', 'png'].includes(fileExt) && (fieldName === 'psa_document' || fieldName === 'eligibility_document'))) {
                    // For images, show thumbnail
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        previewHTML = '<div class="existing-file">' +
                            '<div class="file-info">' +
                            '<img src="' + e.target.result + '" class="photo-thumbnail">' +
                            '<span class="file-name">' + fileName + '</span>' +
                            '</div>' +
                            '<div class="file-actions">' +
                            '<a href="' + fileUrl + '" target="_blank" class="btn-view-file" onclick="event.stopPropagation()">' +
                            '<i class="fas fa-eye"></i> Preview</a>' +
                            '</div>' +
                            '</div>';
                        previewDiv.innerHTML = previewHTML;
                    };
                    reader.readAsDataURL(file);
                } else {
                    // For PDFs and other files
                    previewHTML += '<div class="file-info">' +
                        '<span>📄</span>' +
                        '<span class="file-name">' + fileName + '</span>' +
                        '</div>' +
                        '<div class="file-actions">' +
                        '<a href="' + fileUrl + '" target="_blank" class="btn-view-file" onclick="event.stopPropagation()">' +
                        '<i class="fas fa-eye"></i> Preview</a>' +
                        '</div>' +
                        '</div>';
                    previewDiv.innerHTML = previewHTML;
                }

                // Auto-check status based on document type
                if (fieldName === 'psa_document') {
                    const psaStatus = document.getElementById('birth_certificate_status');
                    if (psaStatus) {
                        psaStatus.checked = true;
                        updateDocumentCount();
                    }
                } else if (fieldName === 'eligibility_document') {
                    const eligibilityStatus = document.getElementById('eligibility_form_status');
                    if (eligibilityStatus) {
                        eligibilityStatus.checked = true;
                        updateDocumentCount();
                    }
                } else if (fieldName === 'group2_documents') {
                    const corStatus = document.getElementById('cor_status');
                    const torStatus = document.getElementById('tor_status');
                    if (corStatus && torStatus) {
                        corStatus.checked = true;
                        torStatus.checked = true;
                        updateDocumentCount();
                    }
                } else if (fieldName === 'photo') {
                    const photoStatus = document.getElementById('photo_status');
                    if (photoStatus) {
                        photoStatus.checked = true;
                        updateDocumentCount();
                    }
                }
            }
        }

        // Update document count function
        function updateDocumentCount() {
            const checkboxes = document.querySelectorAll('input[type="checkbox"][name$="_status"], input[type="checkbox"][name="photo_status"]');
            const checkedCount = Array.from(checkboxes).filter(cb => cb.checked).length;
            const countDisplay = document.getElementById('docCountDisplay');
            if (countDisplay) {
                countDisplay.textContent = checkedCount + '/5 documents verified';

                // Change color based on count
                if (checkedCount === 5) {
                    countDisplay.style.color = '#0c3a1d';
                    countDisplay.style.fontWeight = 'bold';
                } else if (checkedCount >= 2) {
                    countDisplay.style.color = '#ffc107';
                    countDisplay.style.fontWeight = 'normal';
                } else {
                    countDisplay.style.color = '#dc3545';
                    countDisplay.style.fontWeight = 'normal';
                }
            }
        }

        // Make sure DOM is loaded before attaching events
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize document count
            updateDocumentCount();

            // Add change event to all document status checkboxes
            const statusCheckboxes = document.querySelectorAll('input[type="checkbox"][name$="_status"], input[type="checkbox"][name="photo_status"]');
            statusCheckboxes.forEach(checkbox => {
                checkbox.addEventListener('change', updateDocumentCount);
            });
        });

        // Update the middle initial validation function
        function validateMiddleInitial(input) {
            // Remove any invalid characters first (only allow letters and period)
            let value = input.value.replace(/[^A-Za-z.]/g, '');
           
            // Ensure it starts with a letter
            if (value.length > 0 && !value.match(/^[A-Za-z]/)) {
                value = '';
            }
           
            // Check if there's a period at the end
            if (value.length > 0) {
                // If there's no period at the end and we have at least one letter
                if (!value.endsWith('.') && value.match(/[A-Za-z]/)) {
                    // If we have more than one character without a period, add period to the end
                    if (value.length >= 1) {
                        // Remove any existing period in the middle
                        value = value.replace(/\./g, '');
                        value = value.toUpperCase() + '.';
                    }
                } else {
                    // Keep only the last period, remove any others
                    const parts = value.split('.');
                    if (parts.length > 2) {
                        // More than one period, keep only the first part + last period
                        value = parts[0] + '.' + parts.slice(1).join('').replace(/\./g, '');
                    }
                }
            }
           
            // Limit to 3 characters max (like "JR.", "D.", "JR")
            if (value.length > 3) {
                // If it ends with period, keep 2 letters + period
                if (value.endsWith('.')) {
                    value = value.substring(0, 2) + '.';
                } else {
                    // Otherwise just take first 3 characters
                    value = value.substring(0, 3);
                }
            }
           
            // Ensure it's uppercase
            input.value = value.toUpperCase();
        }

        // Notification function with animation
        function showNotification(message, type = 'success') {
            // Remove existing notification if any
            const existingNotification = document.querySelector('.custom-notification');
            if (existingNotification) {
                existingNotification.remove();
            }

            // Create notification element
            const notification = document.createElement('div');
            notification.className = 'custom-notification ' + type;
            notification.innerHTML = `
                <div class="notification-icon">${type === 'success' ? '✅' : '❌'}</div>
                <div class="notification-message">${message}</div>
                <div class="notification-progress"></div>
            `;

            // Add to body
            document.body.appendChild(notification);

            // Trigger animation
            setTimeout(() => {
                notification.classList.add('show');
            }, 10);

            // Auto remove after 3 seconds
            setTimeout(() => {
                notification.classList.remove('show');
                setTimeout(() => {
                    notification.remove();
                }, 300);
            }, 3000);
        }

        // Add modal functionality for course if needed
        function showAddCourseModal() {
            // This function is kept for compatibility but may not be used
            showNotification('Course management functionality is available.', 'info');
        }

        function closeAddCourseModal() {
            // Close modal if it exists
            const modal = document.getElementById('addCourseModal');
            if (modal) {
                modal.style.display = 'none';
            }
        }

        // Form validation
        const form = document.getElementById('athleteForm');
        if (form) {
            form.addEventListener('submit', function(e) {
                const studentId = document.getElementById('student_id').value;
                const firstName = document.getElementById('first_name').value;
                const lastName = document.getElementById('last_name').value;
                const gender = document.getElementById('gender').value;
                const competitionSportId = document.getElementById('competition_sport_id').value;

                if (!studentId || !firstName || !lastName || !gender || !competitionSportId) {
                    e.preventDefault();
                    alert('Please fill in all required fields (marked with *)');
                    return false;
                }

                return true;
            });
        }
    </script>
</body>

</html>
