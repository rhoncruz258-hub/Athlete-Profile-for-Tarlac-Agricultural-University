<?php
session_start();
require_once 'db_config.php';

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

// Handle delete athlete
if (isset($_GET['delete']) && isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
    $id = intval($_GET['delete']);
    $competition_id = isset($_GET['competition_id']) ? intval($_GET['competition_id']) : 0;
    $university_id = isset($_GET['university_id']) ? intval($_GET['university_id']) : 0;
    $sport_id = isset($_GET['sport_id']) ? intval($_GET['sport_id']) : 0;

    try {
        // Start transaction
        $pdo->beginTransaction();

        // Get athlete details to delete files
        $stmt = $pdo->prepare("SELECT * FROM athletes WHERE id = ?");
        $stmt->execute([$id]);
        $athlete = $stmt->fetch();

        if ($athlete) {
            // Delete athlete's files if they exist
            $fileFields = ['psa_document', 'eligibility_document', 'group2_documents', 'overall_documents', 'photo'];
            foreach ($fileFields as $field) {
                if (!empty($athlete[$field]) && file_exists($athlete[$field])) {
                    unlink($athlete[$field]);
                }
            }

            // Delete athlete's folder
            if (!empty($athlete['competition_sport_id'])) {
                // Get sport, university and competition info
                $sportStmt = $pdo->prepare("
                    SELECT cs.*, c.name as competition_name, c.year,
                           u.name as university_name, u.id as university_id
                    FROM competition_sports cs
                    JOIN competitions c ON cs.competition_id = c.id
                    JOIN universities u ON cs.university_id = u.id
                    WHERE cs.id = ?
                ");
                $sportStmt->execute([$athlete['competition_sport_id']]);
                $sportInfo = $sportStmt->fetch();

                if ($sportInfo) {
                    $competitionFolder = preg_replace('/[^a-zA-Z0-9_-]/', '', str_replace(' ', '_', $sportInfo['competition_name'])) . '_' . $sportInfo['year'];
                    $universityFolder = preg_replace('/[^a-zA-Z0-9_-]/', '', str_replace(' ', '_', $sportInfo['university_name']));
                    $sportFolderName = preg_replace('/[^a-zA-Z0-9_-]/', '', str_replace(' ', '_', $sportInfo['sport_name']));

                    $genderSuffix = '';
                    if ($sportInfo['gender'] === 'Male') {
                        $genderSuffix = '_M';
                    } elseif ($sportInfo['gender'] === 'Female') {
                        $genderSuffix = '_F';
                    } elseif ($sportInfo['gender'] === 'Mixed') {
                        $genderSuffix = '_X';
                    }

                    $athleteFolderName = $id . '_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $athlete['last_name'] . '_' . $athlete['first_name'] . '_' . ($athlete['middle_initial'] ?? ''));
                    $athleteFolderPath = 'uploads/competitions/' . $competitionFolder . '/' . $universityFolder . '/' . $sportFolderName . $genderSuffix . '/' . $athleteFolderName;

                    // Function to recursively delete directory
                    function deleteAthleteDirectory($dir)
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
                            if (!deleteAthleteDirectory($dir . DIRECTORY_SEPARATOR . $item)) {
                                return false;
                            }
                        }
                        return rmdir($dir);
                    }

                    if (file_exists($athleteFolderPath)) {
                        deleteAthleteDirectory($athleteFolderPath);
                    }
                }
            }
        }

        // Delete athlete from database
        $stmt = $pdo->prepare("DELETE FROM athletes WHERE id = ?");
        $stmt->execute([$id]);

        // Commit transaction
        $pdo->commit();

        $_SESSION['success'] = "Athlete deleted successfully!";
    } catch (PDOException $e) {
        // Rollback transaction on error
        $pdo->rollBack();
        $_SESSION['error'] = "Error deleting athlete: " . $e->getMessage();
    }

    // Redirect back to the appropriate page
    if ($competition_id && $university_id && $sport_id) {
        header("Location: athletes.php?competition_id={$competition_id}&university_id={$university_id}&sport_id={$sport_id}");
    } elseif ($competition_id && $university_id) {
        header("Location: athletes.php?competition_id={$competition_id}&university_id={$university_id}");
    } elseif ($competition_id) {
        header("Location: athletes.php?competition_id={$competition_id}");
    } else {
        header('Location: athletes.php');
    }
    exit();
}

// In the athletes.php file, find and replace the copy and bulk copy sections with these updated versions:

// Handle copy/export athlete to another competition (UPDATED)
if (isset($_POST['copy_athlete_action']) && $_POST['copy_athlete_action'] === 'copy' && isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
    $sourceAthleteId = intval($_POST['source_athlete_id']);
    $targetSportId = intval($_POST['target_sport_id']);
    $currentCompetitionId = isset($_POST['current_competition_id']) ? intval($_POST['current_competition_id']) : 0;
    $currentUniversityId = isset($_POST['current_university_id']) ? intval($_POST['current_university_id']) : 0;
    $currentSportId = isset($_POST['current_sport_id']) ? intval($_POST['current_sport_id']) : 0;

    if ($sourceAthleteId > 0 && $targetSportId > 0) {
        try {
            // Start transaction
            $pdo->beginTransaction();

            // Get source athlete data
            $stmt = $pdo->prepare("SELECT * FROM athletes WHERE id = ?");
            $stmt->execute([$sourceAthleteId]);
            $sourceAthlete = $stmt->fetch();

            if (!$sourceAthlete) {
                throw new Exception("Source athlete not found");
            }

            // Get target sport details for folder creation
            $stmt = $pdo->prepare("
                SELECT cs.*, c.name as competition_name, c.year, c.id as competition_id,
                       u.name as university_name, u.id as university_id
                FROM competition_sports cs
                JOIN competitions c ON cs.competition_id = c.id
                JOIN universities u ON cs.university_id = u.id
                WHERE cs.id = ?
            ");
            $stmt->execute([$targetSportId]);
            $targetSport = $stmt->fetch();

            if (!$targetSport) {
                throw new Exception("Target sport not found");
            }

            // Check if athlete already exists in target sport (by student_id and sport)
            $checkStmt = $pdo->prepare("
                SELECT a.id FROM athletes a
                WHERE a.student_id = ? AND a.competition_sport_id = ?
            ");
            $checkStmt->execute([$sourceAthlete['student_id'], $targetSportId]);

            if ($checkStmt->fetch()) {
                throw new Exception("This athlete is already registered in the target sport!");
            }

            // Create folder structure for the new athlete copy
            $mainUploadDir = 'uploads/';

            // 1. Competition folder name
            $competitionFolder = preg_replace('/[^a-zA-Z0-9_-]/', '', str_replace(' ', '_', $targetSport['competition_name'])) . '_' . $targetSport['year'];

            // 2. University folder name
            $universityFolder = preg_replace('/[^a-zA-Z0-9_-]/', '', str_replace(' ', '_', $targetSport['university_name']));

            // 3. Sport folder name with gender suffix
            $sportFolderName = preg_replace('/[^a-zA-Z0-9_-]/', '', str_replace(' ', '_', $targetSport['sport_name']));
            $genderSuffix = '';
            if ($targetSport['gender'] === 'Male') {
                $genderSuffix = '_M';
            } elseif ($targetSport['gender'] === 'Female') {
                $genderSuffix = '_F';
            } elseif ($targetSport['gender'] === 'Mixed') {
                $genderSuffix = '_X';
            }
            $sportFolder = $sportFolderName . $genderSuffix;

            // Full base path for target sport
            $sportBasePath = $mainUploadDir . 'competitions/' . $competitionFolder . '/' . $universityFolder . '/' . $sportFolder . '/';

            // Create sport folder if it doesn't exist
            if (!file_exists($sportBasePath)) {
                mkdir($sportBasePath, 0777, true);
            }

            // Create temporary athlete folder (will be renamed after insert)
            $tempAthleteFolderName = 'temp_' . time() . '_' . uniqid() . '_' . preg_replace(
                '/[^a-zA-Z0-9_-]/',
                '_',
                $sourceAthlete['last_name'] . '_' . $sourceAthlete['first_name'] . '_' . ($sourceAthlete['middle_initial'] ?? '')
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
                    if (!file_exists($tempAthleteFolderPath . $subfolder)) {
                        mkdir($tempAthleteFolderPath . $subfolder, 0777, true);
                    }
                }
            }

            // Function to copy files preserving original names
            function copyAthleteFilesWithOriginalName($sourcePath, $targetFolder)
            {
                if (!empty($sourcePath) && file_exists($sourcePath)) {
                    $originalFilename = basename($sourcePath);
                    $targetFilePath = $targetFolder . $originalFilename;
                   
                    // If file already exists, add a counter
                    $counter = 1;
                    $pathInfo = pathinfo($targetFilePath);
                    while (file_exists($targetFilePath)) {
                        $targetFilePath = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '_' . $counter . '.' . $pathInfo['extension'];
                        $counter++;
                    }
                   
                    if (copy($sourcePath, $targetFilePath)) {
                        return $targetFilePath;
                    }
                }
                return '';
            }

            // Copy files with original names
            $newPsaPath = '';
            $newEligibilityPath = '';
            $newGroup2Path = '';
            $newOverallPath = '';
            $newPhotoPath = '';

            // Copy PSA document if exists
            if (!empty($sourceAthlete['psa_document']) && file_exists($sourceAthlete['psa_document'])) {
                $targetFolder = $tempAthleteFolderPath . '01_group1_documents/psa/';
                $newPsaPath = copyAthleteFilesWithOriginalName($sourceAthlete['psa_document'], $targetFolder);
            }

            // Copy Eligibility document if exists
            if (!empty($sourceAthlete['eligibility_document']) && file_exists($sourceAthlete['eligibility_document'])) {
                $targetFolder = $tempAthleteFolderPath . '01_group1_documents/eligibility/';
                $newEligibilityPath = copyAthleteFilesWithOriginalName($sourceAthlete['eligibility_document'], $targetFolder);
            }

            // Copy group2 documents if exists
            if (!empty($sourceAthlete['group2_documents']) && file_exists($sourceAthlete['group2_documents'])) {
                $targetFolder = $tempAthleteFolderPath . '02_group2_documents/';
                $newGroup2Path = copyAthleteFilesWithOriginalName($sourceAthlete['group2_documents'], $targetFolder);
            }

            // Copy overall documents if exists
            if (!empty($sourceAthlete['overall_documents']) && file_exists($sourceAthlete['overall_documents'])) {
                $targetFolder = $tempAthleteFolderPath . '04_overall_documents/';
                $newOverallPath = copyAthleteFilesWithOriginalName($sourceAthlete['overall_documents'], $targetFolder);
            }

            // Copy photo if exists
            if (!empty($sourceAthlete['photo']) && file_exists($sourceAthlete['photo'])) {
                $targetFolder = $tempAthleteFolderPath . '03_photo/';
                $newPhotoPath = copyAthleteFilesWithOriginalName($sourceAthlete['photo'], $targetFolder);
            }

            // Force gender to match target sport
            $targetGender = $targetSport['gender'];
            if ($targetGender === 'Mixed') {
                // For Mixed sports, use the original athlete's gender
                $targetGender = $sourceAthlete['gender'];
            }

            // Prepare data for new athlete (copy all fields)
            $data = [
                'student_id' => $sourceAthlete['student_id'],
                'first_name' => $sourceAthlete['first_name'],
                'middle_initial' => $sourceAthlete['middle_initial'],
                'last_name' => $sourceAthlete['last_name'],
                'gender' => $targetGender,
                'contact_number' => $sourceAthlete['contact_number'],
                'email' => $sourceAthlete['email'],
                'competition_sport_id' => $targetSportId,
                'course_id' => $sourceAthlete['course_id']
            ];

            // Copy ALL document status (including the ones from source)
            $docStatus = [
                'birth_certificate_status' => $sourceAthlete['birth_certificate_status'] ?? 0,
                'eligibility_form_status' => $sourceAthlete['eligibility_form_status'] ?? 0,
                'cor_status' => $sourceAthlete['cor_status'] ?? 0,
                'tor_status' => $sourceAthlete['tor_status'] ?? 0,
                'photo_status' => $sourceAthlete['photo_status'] ?? 0
            ];

            $sql = "INSERT INTO athletes (
                student_id, first_name, middle_initial, last_name, gender,
                contact_number, email, competition_sport_id, course_id,
                psa_document, eligibility_document, group2_documents, overall_documents, photo,
                birth_certificate_status, eligibility_form_status,
                cor_status, tor_status, photo_status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $params = [
                $data['student_id'],
                $data['first_name'],
                $data['middle_initial'],
                $data['last_name'],
                $data['gender'],
                $data['contact_number'],
                $data['email'],
                $data['competition_sport_id'],
                $data['course_id'],
                $newPsaPath,
                $newEligibilityPath,
                $newGroup2Path,
                $newOverallPath,
                $newPhotoPath,
                $docStatus['birth_certificate_status'],
                $docStatus['eligibility_form_status'],
                $docStatus['cor_status'],
                $docStatus['tor_status'],
                $docStatus['photo_status']
            ];

            $stmt = $pdo->prepare($sql);
            $result = $stmt->execute($params);

            if (!$result) {
                throw new Exception("Failed to insert athlete record");
            }

            // Get the new athlete ID
            $newAthleteId = $pdo->lastInsertId();

            // Rename the temporary folder to include the actual ID
            if (file_exists($tempAthleteFolderPath)) {
                $finalAthleteFolderName = $newAthleteId . '_' . preg_replace(
                    '/[^a-zA-Z0-9_-]/',
                    '_',
                    $data['last_name'] . '_' . $data['first_name'] . '_' . ($data['middle_initial'] ?? '')
                );
                $finalAthleteFolderPath = $sportBasePath . $finalAthleteFolderName . '/';

                // Check if final folder already exists
                if (file_exists($finalAthleteFolderPath)) {
                    // Add timestamp to make it unique
                    $timestamp = time();
                    $finalAthleteFolderName = $newAthleteId . '_' . preg_replace(
                        '/[^a-zA-Z0-9_-]/',
                        '_',
                        $data['last_name'] . '_' . $data['first_name'] . '_' . ($data['middle_initial'] ?? '')
                    ) . '_' . $timestamp;
                    $finalAthleteFolderPath = $sportBasePath . $finalAthleteFolderName . '/';
                }

                // Rename the folder
                if (rename($tempAthleteFolderPath, $finalAthleteFolderPath)) {
                    // Update file paths in database with new folder path
                    $updates = [];
                    $updateParams = [];

                    if (!empty($newPsaPath) && strpos($newPsaPath, $tempAthleteFolderPath) !== false) {
                        $newPsaPathUpdated = str_replace($tempAthleteFolderPath, $finalAthleteFolderPath, $newPsaPath);
                        $updates[] = "psa_document = ?";
                        $updateParams[] = $newPsaPathUpdated;
                    }

                    if (!empty($newEligibilityPath) && strpos($newEligibilityPath, $tempAthleteFolderPath) !== false) {
                        $newEligibilityPathUpdated = str_replace($tempAthleteFolderPath, $finalAthleteFolderPath, $newEligibilityPath);
                        $updates[] = "eligibility_document = ?";
                        $updateParams[] = $newEligibilityPathUpdated;
                    }

                    if (!empty($newGroup2Path) && strpos($newGroup2Path, $tempAthleteFolderPath) !== false) {
                        $newGroup2PathUpdated = str_replace($tempAthleteFolderPath, $finalAthleteFolderPath, $newGroup2Path);
                        $updates[] = "group2_documents = ?";
                        $updateParams[] = $newGroup2PathUpdated;
                    }

                    if (!empty($newOverallPath) && strpos($newOverallPath, $tempAthleteFolderPath) !== false) {
                        $newOverallPathUpdated = str_replace($tempAthleteFolderPath, $finalAthleteFolderPath, $newOverallPath);
                        $updates[] = "overall_documents = ?";
                        $updateParams[] = $newOverallPathUpdated;
                    }

                    if (!empty($newPhotoPath) && strpos($newPhotoPath, $tempAthleteFolderPath) !== false) {
                        $newPhotoPathUpdated = str_replace($tempAthleteFolderPath, $finalAthleteFolderPath, $newPhotoPath);
                        $updates[] = "photo = ?";
                        $updateParams[] = $newPhotoPathUpdated;
                    }

                    if (!empty($updates)) {
                        $updateSql = "UPDATE athletes SET " . implode(', ', $updates) . " WHERE id = ?";
                        $updateParams[] = $newAthleteId;
                        $updateStmt = $pdo->prepare($updateSql);
                        $updateStmt->execute($updateParams);
                    }
                }
            }

            // Commit transaction
            $pdo->commit();

            $_SESSION['success'] = "Athlete copied successfully to " . htmlspecialchars($targetSport['competition_name'] . ' ' . $targetSport['year'] . ' - ' . $targetSport['university_name'] . ' - ' . $targetSport['sport_name']);

            // Redirect to the target sport page
            header("Location: athletes.php?competition_id={$targetSport['competition_id']}&university_id={$targetSport['university_id']}&sport_id={$targetSportId}");
            exit();
        } catch (Exception $e) {
            // Rollback transaction on error
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            // Clean up temporary folder if it exists
            if (isset($tempAthleteFolderPath) && file_exists($tempAthleteFolderPath)) {
                function deleteTempDir($dir)
                {
                    if (!file_exists($dir)) return;
                    $files = array_diff(scandir($dir), array('.', '..'));
                    foreach ($files as $file) {
                        $path = $dir . DIRECTORY_SEPARATOR . $file;
                        if (is_dir($path)) {
                            deleteTempDir($path);
                        } else {
                            unlink($path);
                        }
                    }
                    rmdir($dir);
                }
                deleteTempDir($tempAthleteFolderPath);
            }

            $_SESSION['error'] = "Error copying athlete: " . $e->getMessage();
            error_log("Copy athlete error: " . $e->getMessage());

            // Redirect back to the original page
            $redirect = 'athletes.php';
            if ($currentCompetitionId) {
                $redirect .= '?competition_id=' . $currentCompetitionId;
                if ($currentUniversityId) {
                    $redirect .= '&university_id=' . $currentUniversityId;
                }
                if ($currentSportId) {
                    $redirect .= '&sport_id=' . $currentSportId;
                }
            }
            header("Location: $redirect");
            exit();
        }
    } else {
        $_SESSION['error'] = "Invalid athlete or target sport selected";
        $redirect = 'athletes.php';
        if ($currentCompetitionId) {
            $redirect .= '?competition_id=' . $currentCompetitionId;
            if ($currentUniversityId) {
                $redirect .= '&university_id=' . $currentUniversityId;
            }
            if ($currentSportId) {
                $redirect .= '&sport_id=' . $currentSportId;
            }
        }
        header("Location: $redirect");
        exit();
    }
}
// Handle bulk copy/export athletes to another competition (UPDATED AND FIXED)
if (isset($_POST['bulk_copy_action']) && $_POST['bulk_copy_action'] === 'bulk_copy' && isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
    $selectedAthleteIds = isset($_POST['selected_athletes']) ? $_POST['selected_athletes'] : [];
    $targetSportId = intval($_POST['target_sport_id']);
    $currentCompetitionId = isset($_POST['current_competition_id']) ? intval($_POST['current_competition_id']) : 0;
    $currentUniversityId = isset($_POST['current_university_id']) ? intval($_POST['current_university_id']) : 0;
    $currentSportId = isset($_POST['current_sport_id']) ? intval($_POST['current_sport_id']) : 0;

    if (empty($selectedAthleteIds)) {
        $_SESSION['error'] = "No athletes selected for copying";
        header("Location: athletes.php?competition_id={$currentCompetitionId}&university_id={$currentUniversityId}&sport_id={$currentSportId}");
        exit();
    }

    if ($targetSportId > 0) {
        try {
            // Start transaction
            $pdo->beginTransaction();

            // Get target sport details for folder creation
            $stmt = $pdo->prepare("
                SELECT cs.*, c.name as competition_name, c.year, c.id as competition_id,
                       u.name as university_name, u.id as university_id
                FROM competition_sports cs
                JOIN competitions c ON cs.competition_id = c.id
                JOIN universities u ON cs.university_id = u.id
                WHERE cs.id = ?
            ");
            $stmt->execute([$targetSportId]);
            $targetSport = $stmt->fetch();

            if (!$targetSport) {
                throw new Exception("Target sport not found");
            }

            $successCount = 0;
            $errorCount = 0;
            $errors = [];

            // Function to copy files with original name
            function bulkCopyAthleteFileWithOriginalName($sourcePath, $targetFolder)
            {
                if (!empty($sourcePath) && file_exists($sourcePath)) {
                    $originalFilename = basename($sourcePath);
                    $targetFilePath = $targetFolder . $originalFilename;
                   
                    // If file already exists, add a counter
                    $counter = 1;
                    $pathInfo = pathinfo($targetFilePath);
                    while (file_exists($targetFilePath)) {
                        $targetFilePath = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '_' . $counter . '.' . $pathInfo['extension'];
                        $counter++;
                    }
                   
                    if (copy($sourcePath, $targetFilePath)) {
                        return $targetFilePath;
                    }
                }
                return '';
            }

            foreach ($selectedAthleteIds as $sourceAthleteId) {
                try {
                    // Get source athlete data
                    $stmt = $pdo->prepare("SELECT * FROM athletes WHERE id = ?");
                    $stmt->execute([$sourceAthleteId]);
                    $sourceAthlete = $stmt->fetch();

                    if (!$sourceAthlete) {
                        $errors[] = "Athlete ID {$sourceAthleteId} not found";
                        $errorCount++;
                        continue;
                    }

                    // Check if athlete already exists in target sport
                    $checkStmt = $pdo->prepare("
                        SELECT a.id FROM athletes a
                        WHERE a.student_id = ? AND a.competition_sport_id = ?
                    ");
                    $checkStmt->execute([$sourceAthlete['student_id'], $targetSportId]);

                    if ($checkStmt->fetch()) {
                        $errors[] = "Athlete {$sourceAthlete['first_name']} {$sourceAthlete['last_name']} (ID: {$sourceAthlete['student_id']}) already exists in target sport";
                        $errorCount++;
                        continue;
                    }

                    // Create folder structure
                    $mainUploadDir = 'uploads/';
                    $competitionFolder = preg_replace('/[^a-zA-Z0-9_-]/', '', str_replace(' ', '_', $targetSport['competition_name'])) . '_' . $targetSport['year'];
                    $universityFolder = preg_replace('/[^a-zA-Z0-9_-]/', '', str_replace(' ', '_', $targetSport['university_name']));
                    $sportFolderName = preg_replace('/[^a-zA-Z0-9_-]/', '', str_replace(' ', '_', $targetSport['sport_name']));
                   
                    $genderSuffix = '';
                    if ($targetSport['gender'] === 'Male') {
                        $genderSuffix = '_M';
                    } elseif ($targetSport['gender'] === 'Female') {
                        $genderSuffix = '_F';
                    } elseif ($targetSport['gender'] === 'Mixed') {
                        $genderSuffix = '_X';
                    }
                    $sportFolder = $sportFolderName . $genderSuffix;
                    $sportBasePath = $mainUploadDir . 'competitions/' . $competitionFolder . '/' . $universityFolder . '/' . $sportFolder . '/';

                    if (!file_exists($sportBasePath)) {
                        mkdir($sportBasePath, 0777, true);
                    }

                    // Create temporary athlete folder
                    $tempAthleteFolderName = 'temp_' . time() . '_' . uniqid() . '_' . preg_replace(
                        '/[^a-zA-Z0-9_-]/',
                        '_',
                        $sourceAthlete['last_name'] . '_' . $sourceAthlete['first_name'] . '_' . ($sourceAthlete['middle_initial'] ?? '')
                    );
                    $tempAthleteFolderPath = $sportBasePath . $tempAthleteFolderName . '/';

                    if (!file_exists($tempAthleteFolderPath)) {
                        mkdir($tempAthleteFolderPath, 0777, true);
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

                    // Copy all files
                    $newPsaPath = '';
                    $newEligibilityPath = '';
                    $newGroup2Path = '';
                    $newOverallPath = '';
                    $newPhotoPath = '';

                    if (!empty($sourceAthlete['psa_document']) && file_exists($sourceAthlete['psa_document'])) {
                        $newPsaPath = bulkCopyAthleteFileWithOriginalName($sourceAthlete['psa_document'], $tempAthleteFolderPath . '01_group1_documents/psa/');
                    }

                    if (!empty($sourceAthlete['eligibility_document']) && file_exists($sourceAthlete['eligibility_document'])) {
                        $newEligibilityPath = bulkCopyAthleteFileWithOriginalName($sourceAthlete['eligibility_document'], $tempAthleteFolderPath . '01_group1_documents/eligibility/');
                    }

                    if (!empty($sourceAthlete['group2_documents']) && file_exists($sourceAthlete['group2_documents'])) {
                        $newGroup2Path = bulkCopyAthleteFileWithOriginalName($sourceAthlete['group2_documents'], $tempAthleteFolderPath . '02_group2_documents/');
                    }

                    if (!empty($sourceAthlete['overall_documents']) && file_exists($sourceAthlete['overall_documents'])) {
                        $newOverallPath = bulkCopyAthleteFileWithOriginalName($sourceAthlete['overall_documents'], $tempAthleteFolderPath . '04_overall_documents/');
                    }

                    if (!empty($sourceAthlete['photo']) && file_exists($sourceAthlete['photo'])) {
                        $newPhotoPath = bulkCopyAthleteFileWithOriginalName($sourceAthlete['photo'], $tempAthleteFolderPath . '03_photo/');
                    }

                    // Set gender based on target sport
                    $targetGender = $targetSport['gender'];
                    if ($targetGender === 'Mixed') {
                        $targetGender = $sourceAthlete['gender'];
                    }

                    // Prepare data
                    $data = [
                        'student_id' => $sourceAthlete['student_id'],
                        'first_name' => $sourceAthlete['first_name'],
                        'middle_initial' => $sourceAthlete['middle_initial'],
                        'last_name' => $sourceAthlete['last_name'],
                        'gender' => $targetGender,
                        'contact_number' => $sourceAthlete['contact_number'],
                        'email' => $sourceAthlete['email'],
                        'competition_sport_id' => $targetSportId,
                        'course_id' => $sourceAthlete['course_id']
                    ];

                    // Copy all document statuses
                    $docStatus = [
                        'birth_certificate_status' => $sourceAthlete['birth_certificate_status'] ?? 0,
                        'eligibility_form_status' => $sourceAthlete['eligibility_form_status'] ?? 0,
                        'cor_status' => $sourceAthlete['cor_status'] ?? 0,
                        'tor_status' => $sourceAthlete['tor_status'] ?? 0,
                        'photo_status' => $sourceAthlete['photo_status'] ?? 0
                    ];

                    // Insert new athlete
                    $sql = "INSERT INTO athletes (
                        student_id, first_name, middle_initial, last_name, gender,
                        contact_number, email, competition_sport_id, course_id,
                        psa_document, eligibility_document, group2_documents, overall_documents, photo,
                        birth_certificate_status, eligibility_form_status,
                        cor_status, tor_status, photo_status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

                    $params = [
                        $data['student_id'],
                        $data['first_name'],
                        $data['middle_initial'],
                        $data['last_name'],
                        $data['gender'],
                        $data['contact_number'],
                        $data['email'],
                        $data['competition_sport_id'],
                        $data['course_id'],
                        $newPsaPath,
                        $newEligibilityPath,
                        $newGroup2Path,
                        $newOverallPath,
                        $newPhotoPath,
                        $docStatus['birth_certificate_status'],
                        $docStatus['eligibility_form_status'],
                        $docStatus['cor_status'],
                        $docStatus['tor_status'],
                        $docStatus['photo_status']
                    ];

                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    $newAthleteId = $pdo->lastInsertId();

                    // Rename folder to include athlete ID
                    if (file_exists($tempAthleteFolderPath)) {
                        $finalAthleteFolderName = $newAthleteId . '_' . preg_replace(
                            '/[^a-zA-Z0-9_-]/',
                            '_',
                            $data['last_name'] . '_' . $data['first_name'] . '_' . ($data['middle_initial'] ?? '')
                        );
                        $finalAthleteFolderPath = $sportBasePath . $finalAthleteFolderName . '/';

                        if (file_exists($finalAthleteFolderPath)) {
                            $timestamp = time();
                            $finalAthleteFolderName = $newAthleteId . '_' . preg_replace(
                                '/[^a-zA-Z0-9_-]/',
                                '_',
                                $data['last_name'] . '_' . $data['first_name'] . '_' . ($data['middle_initial'] ?? '')
                            ) . '_' . $timestamp;
                            $finalAthleteFolderPath = $sportBasePath . $finalAthleteFolderName . '/';
                        }

                        if (rename($tempAthleteFolderPath, $finalAthleteFolderPath)) {
                            // Update file paths
                            $updates = [];
                            $updateParams = [];

                            if (!empty($newPsaPath) && strpos($newPsaPath, $tempAthleteFolderPath) !== false) {
                                $updates[] = "psa_document = ?";
                                $updateParams[] = str_replace($tempAthleteFolderPath, $finalAthleteFolderPath, $newPsaPath);
                            }

                            if (!empty($newEligibilityPath) && strpos($newEligibilityPath, $tempAthleteFolderPath) !== false) {
                                $updates[] = "eligibility_document = ?";
                                $updateParams[] = str_replace($tempAthleteFolderPath, $finalAthleteFolderPath, $newEligibilityPath);
                            }

                            if (!empty($newGroup2Path) && strpos($newGroup2Path, $tempAthleteFolderPath) !== false) {
                                $updates[] = "group2_documents = ?";
                                $updateParams[] = str_replace($tempAthleteFolderPath, $finalAthleteFolderPath, $newGroup2Path);
                            }

                            if (!empty($newOverallPath) && strpos($newOverallPath, $tempAthleteFolderPath) !== false) {
                                $updates[] = "overall_documents = ?";
                                $updateParams[] = str_replace($tempAthleteFolderPath, $finalAthleteFolderPath, $newOverallPath);
                            }

                            if (!empty($newPhotoPath) && strpos($newPhotoPath, $tempAthleteFolderPath) !== false) {
                                $updates[] = "photo = ?";
                                $updateParams[] = str_replace($tempAthleteFolderPath, $finalAthleteFolderPath, $newPhotoPath);
                            }

                            if (!empty($updates)) {
                                $updateSql = "UPDATE athletes SET " . implode(', ', $updates) . " WHERE id = ?";
                                $updateParams[] = $newAthleteId;
                                $updateStmt = $pdo->prepare($updateSql);
                                $updateStmt->execute($updateParams);
                            }
                        }
                    }

                    $successCount++;
                } catch (Exception $e) {
                    $errorCount++;
                    $errors[] = "Error copying athlete ID {$sourceAthleteId}: " . $e->getMessage();
                   
                    // Clean up temp folder if exists
                    if (isset($tempAthleteFolderPath) && file_exists($tempAthleteFolderPath)) {
                        function cleanTempDir($dir) {
                            if (!file_exists($dir)) return;
                            $files = array_diff(scandir($dir), array('.', '..'));
                            foreach ($files as $file) {
                                $path = $dir . DIRECTORY_SEPARATOR . $file;
                                if (is_dir($path)) {
                                    cleanTempDir($path);
                                } else {
                                    unlink($path);
                                }
                            }
                            rmdir($dir);
                        }
                        cleanTempDir($tempAthleteFolderPath);
                    }
                }
            }

            // Commit transaction
            $pdo->commit();

            // Set session messages
            if ($successCount > 0) {
                $message = "Successfully copied {$successCount} athlete(s) to " . htmlspecialchars($targetSport['competition_name'] . ' ' . $targetSport['year'] . ' - ' . $targetSport['university_name'] . ' - ' . $targetSport['sport_name']);
                if ($errorCount > 0) {
                    $message .= " | Failed to copy {$errorCount} athlete(s).";
                    if (!empty($errors)) {
                        $_SESSION['warning'] = "Errors: " . implode(", ", array_slice($errors, 0, 5));
                    }
                }
                $_SESSION['success'] = $message;
            } else {
                $_SESSION['error'] = "Failed to copy any athletes. " . (!empty($errors) ? implode(", ", $errors) : "");
            }

            // Redirect to target sport page
            header("Location: athletes.php?competition_id={$targetSport['competition_id']}&university_id={$targetSport['university_id']}&sport_id={$targetSportId}");
            exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['error'] = "Bulk copy error: " . $e->getMessage();
           
            $redirect = 'athletes.php';
            if ($currentCompetitionId) {
                $redirect .= '?competition_id=' . $currentCompetitionId;
                if ($currentUniversityId) $redirect .= '&university_id=' . $currentUniversityId;
                if ($currentSportId) $redirect .= '&sport_id=' . $currentSportId;
            }
            header("Location: $redirect");
            exit();
        }
    } else {
        $_SESSION['error'] = "Invalid target sport selected";
        $redirect = 'athletes.php';
        if ($currentCompetitionId) {
            $redirect .= '?competition_id=' . $currentCompetitionId;
            if ($currentUniversityId) $redirect .= '&university_id=' . $currentUniversityId;
            if ($currentSportId) $redirect .= '&sport_id=' . $currentSportId;
        }
        header("Location: $redirect");
        exit();
    }
}

// Add this new AJAX endpoint after the existing ones (around line 340)
// Handle AJAX request for getting universities by competition
if (isset($_GET['get_universities_by_competition'])) {
    $competitionId = intval($_GET['competition_id']);

    try {
        $stmt = $pdo->prepare("
            SELECT id, name, code
            FROM universities
            WHERE competition_id = ?
            ORDER BY name
        ");
        $stmt->execute([$competitionId]);
        $universities = $stmt->fetchAll();

        echo json_encode([
            'success' => true,
            'universities' => $universities
        ]);
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => "Error fetching universities: " . $e->getMessage()
        ]);
    }
    exit();
}

// Handle AJAX request for getting sports by university
if (isset($_GET['get_sports_by_university'])) {
    $universityId = intval($_GET['university_id']);
    $competitionId = intval($_GET['competition_id']);

    try {
        $stmt = $pdo->prepare("
            SELECT cs.id, cs.sport_name, cs.gender, cs.status,
                   u.name as university_name
            FROM competition_sports cs
            JOIN universities u ON cs.university_id = u.id
            WHERE cs.university_id = ? AND cs.competition_id = ? AND cs.status = 'active'
            ORDER BY cs.sport_name
        ");
        $stmt->execute([$universityId, $competitionId]);
        $sports = $stmt->fetchAll();

        echo json_encode([
            'success' => true,
            'sports' => $sports
        ]);
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => "Error fetching sports: " . $e->getMessage()
        ]);
    }
    exit();
}


// Handle AJAX global search (no page reload)
if (isset($_GET['ajax_global_search'])) {
    $searchTerm = trim($_GET['ajax_global_search']);
   
    if (empty($searchTerm)) {
        echo json_encode(['success' => true, 'results' => []]);
        exit();
    }
   
    $searchParam = '%' . $searchTerm . '%';
   
    try {
        $stmt = $pdo->prepare("
            SELECT
                a.id,
                a.student_id,
                a.first_name,
                a.middle_initial,
                a.last_name,
                a.gender,
                a.contact_number,
                a.email,
                c.name as competition_name,
                c.year as competition_year,
                c.id as competition_id,
                u.name as university_name,
                u.id as university_id,
                cs.sport_name,
                cs.id as sport_id,
                cs.gender as sport_gender,
                co.course_code,
                co.course_name
            FROM athletes a
            JOIN competition_sports cs ON a.competition_sport_id = cs.id
            JOIN universities u ON cs.university_id = u.id
            JOIN competitions c ON cs.competition_id = c.id
            LEFT JOIN courses co ON a.course_id = co.id
            WHERE a.student_id LIKE ?
               OR a.first_name LIKE ?
               OR a.last_name LIKE ?
               OR a.email LIKE ?
               OR CONCAT(a.first_name, ' ', a.last_name) LIKE ?
               OR CONCAT(a.last_name, ' ', a.first_name) LIKE ?
               OR co.course_code LIKE ?
               OR co.course_name LIKE ?
               OR u.name LIKE ?
               OR c.name LIKE ?
            ORDER BY c.year DESC, u.name, cs.sport_name, a.last_name, a.first_name
            LIMIT 50
        ");
       
        $stmt->execute([
            $searchParam, $searchParam, $searchParam, $searchParam,
            $searchParam, $searchParam, $searchParam, $searchParam,
            $searchParam, $searchParam
        ]);
       
        $results = $stmt->fetchAll();
       
        // Group results by competition
        $groupedResults = [];
        foreach ($results as $result) {
            $compKey = $result['competition_id'] . '_' . $result['competition_name'] . '_' . $result['competition_year'];
            if (!isset($groupedResults[$compKey])) {
                $groupedResults[$compKey] = [
                    'competition_id' => $result['competition_id'],
                    'competition_name' => $result['competition_name'],
                    'competition_year' => $result['competition_year'],
                    'universities' => []
                ];
            }
           
            $uniKey = $result['university_id'];
            if (!isset($groupedResults[$compKey]['universities'][$uniKey])) {
                $groupedResults[$compKey]['universities'][$uniKey] = [
                    'university_id' => $result['university_id'],
                    'university_name' => $result['university_name'],
                    'sports' => []
                ];
            }
           
            $sportKey = $result['sport_id'];
            if (!isset($groupedResults[$compKey]['universities'][$uniKey]['sports'][$sportKey])) {
                $groupedResults[$compKey]['universities'][$uniKey]['sports'][$sportKey] = [
                    'sport_id' => $result['sport_id'],
                    'sport_name' => $result['sport_name'],
                    'sport_gender' => $result['sport_gender'],
                    'athletes' => []
                ];
            }
           
            $groupedResults[$compKey]['universities'][$uniKey]['sports'][$sportKey]['athletes'][] = $result;
        }
       
        echo json_encode([
            'success' => true,
            'results' => $groupedResults,
            'total' => count($results)
        ]);
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => "Error performing search: " . $e->getMessage()
        ]);
    }
    exit();
}

// Handle AJAX request for getting sports by competition
if (isset($_GET['get_sports_by_competition'])) {
    $competitionId = intval($_GET['competition_id']);

    try {
        $stmt = $pdo->prepare("
            SELECT cs.id, cs.sport_name, cs.gender, cs.status,
                   u.name as university_name, u.id as university_id
            FROM competition_sports cs
            JOIN universities u ON cs.university_id = u.id
            WHERE cs.competition_id = ? AND cs.status = 'active'
            ORDER BY u.name, cs.sport_name
        ");
        $stmt->execute([$competitionId]);
        $sports = $stmt->fetchAll();

        echo json_encode([
            'success' => true,
            'sports' => $sports
        ]);
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => "Error fetching sports: " . $e->getMessage()
        ]);
    }
    exit();
}

// Handle AJAX request for getting competitions
if (isset($_GET['get_competitions'])) {
    try {
        $stmt = $pdo->query("SELECT id, name, year FROM competitions ORDER BY year DESC, name");
        $competitions = $stmt->fetchAll();

        echo json_encode([
            'success' => true,
            'competitions' => $competitions
        ]);
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => "Error fetching competitions: " . $e->getMessage()
        ]);
    }
    exit();
}

// Handle add competition via AJAX/POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_competition'])) {
    $name = trim($_POST['name']);
    $year = trim($_POST['year']);
    $description = trim($_POST['description'] ?? '');

    try {
        $stmt = $pdo->prepare("INSERT INTO competitions (name, year, description) VALUES (?, ?, ?)");
        $stmt->execute([$name, $year, $description]);

        $competitionId = $pdo->lastInsertId();

        // Create folder for competition
        $folderName = preg_replace('/[^a-zA-Z0-9_-]/', '', str_replace(' ', '_', $name)) . '_' . $year;
        $folderPath = 'uploads/competitions/' . $folderName;

        if (!file_exists('uploads')) {
            mkdir('uploads', 0777, true);
        }
        if (!file_exists('uploads/competitions')) {
            mkdir('uploads/competitions', 0777, true);
        }

        $folderCreated = false;
        $folderMessage = '';

        if (!file_exists($folderPath)) {
            if (mkdir($folderPath, 0777, true)) {
                $folderCreated = true;
                $folderMessage = ' Folder created successfully.';
            } else {
                $folderMessage = ' Warning: Could not create folder.';
            }
        } else {
            $folderMessage = ' Folder already exists.';
            $folderCreated = true;
        }

        echo json_encode([
            'success' => true,
            'message' => 'Competition added successfully!' . $folderMessage,
            'competition_id' => $competitionId,
            'folder_created' => $folderCreated,
            'folder_name' => $folderName
        ]);
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => "Error adding competition: " . $e->getMessage()
        ]);
    }
    exit();
}

// Handle edit competition via AJAX/POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_competition'])) {
    $id = intval($_POST['id']);
    $name = trim($_POST['name']);
    $year = trim($_POST['year']);
    $description = trim($_POST['description'] ?? '');

    try {
        $stmt = $pdo->prepare("UPDATE competitions SET name = ?, year = ?, description = ? WHERE id = ?");
        $stmt->execute([$name, $year, $description, $id]);

        echo json_encode([
            'success' => true,
            'message' => 'Competition updated successfully!'
        ]);
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => "Error updating competition: " . $e->getMessage()
        ]);
    }
    exit();
}

// Handle add university via AJAX/POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_university'])) {
    $competitionId = intval($_POST['competition_id']);
    $name = trim($_POST['name']);
    $code = trim($_POST['code']);
    $description = trim($_POST['description'] ?? '');

    try {
        $stmt = $pdo->prepare("INSERT INTO universities (competition_id, name, code, description) VALUES (?, ?, ?, ?)");
        $stmt->execute([$competitionId, $name, $code, $description]);

        $universityId = $pdo->lastInsertId();

        // Get competition details to create folder path
        $compStmt = $pdo->prepare("SELECT name, year FROM competitions WHERE id = ?");
        $compStmt->execute([$competitionId]);
        $competition = $compStmt->fetch();

        // Create folder for university within competition folder
        if ($competition) {
            $competitionFolder = preg_replace('/[^a-zA-Z0-9_-]/', '', str_replace(' ', '_', $competition['name'])) . '_' . $competition['year'];
            $universityFolder = preg_replace('/[^a-zA-Z0-9_-]/', '', str_replace(' ', '_', $name));

            $universityFolderPath = 'uploads/competitions/' . $competitionFolder . '/' . $universityFolder;

            if (!file_exists($universityFolderPath)) {
                mkdir($universityFolderPath, 0777, true);
            }
        }

        echo json_encode([
            'success' => true,
            'message' => 'University added to competition successfully!',
            'university_id' => $universityId
        ]);
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => "Error adding university: " . $e->getMessage()
        ]);
    }
    exit();
}

// Handle edit university via AJAX/POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_university'])) {
    $id = intval($_POST['id']);
    $name = trim($_POST['name']);
    $code = trim($_POST['code']);
    $description = trim($_POST['description'] ?? '');

    try {
        $stmt = $pdo->prepare("UPDATE universities SET name = ?, code = ?, description = ? WHERE id = ?");
        $stmt->execute([$name, $code, $description, $id]);

        echo json_encode([
            'success' => true,
            'message' => 'University updated successfully!'
        ]);
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => "Error updating university: " . $e->getMessage()
        ]);
    }
    exit();
}

// Handle add sport via AJAX/POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_sport'])) {
    $competitionId = intval($_POST['competition_id']);
    $universityId = intval($_POST['university_id']);
    $sportName = trim($_POST['sport_name']);
    $gender = $_POST['gender'];
    $status = $_POST['status'];

    try {
        $stmt = $pdo->prepare("
            INSERT INTO competition_sports (competition_id, university_id, sport_name, gender, status)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$competitionId, $universityId, $sportName, $gender, $status]);

        $sportId = $pdo->lastInsertId();

        // Get competition and university details to create folder path
        $compStmt = $pdo->prepare("
            SELECT c.name as competition_name, c.year, u.name as university_name
            FROM competitions c
            JOIN universities u ON u.competition_id = c.id
            WHERE c.id = ? AND u.id = ?
        ");
        $compStmt->execute([$competitionId, $universityId]);
        $details = $compStmt->fetch();

        // Create folder for sport within university folder
        if ($details) {
            $competitionFolder = preg_replace('/[^a-zA-Z0-9_-]/', '', str_replace(' ', '_', $details['competition_name'])) . '_' . $details['year'];
            $universityFolder = preg_replace('/[^a-zA-Z0-9_-]/', '', str_replace(' ', '_', $details['university_name']));
            $sportFolderName = preg_replace('/[^a-zA-Z0-9_-]/', '', str_replace(' ', '_', $sportName));

            // Add gender suffix for organization
            $genderSuffix = '';
            if ($gender === 'Male') {
                $genderSuffix = '_M';
            } elseif ($gender === 'Female') {
                $genderSuffix = '_F';
            } elseif ($gender === 'Mixed') {
                $genderSuffix = '_X';
            }

            $sportFolderPath = 'uploads/competitions/' . $competitionFolder . '/' . $universityFolder . '/' . $sportFolderName . $genderSuffix;

            if (!file_exists($sportFolderPath)) {
                mkdir($sportFolderPath, 0777, true);
            }
        }

        echo json_encode([
            'success' => true,
            'message' => 'Sport added successfully!',
            'sport_id' => $sportId
        ]);
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => "Error adding sport: " . $e->getMessage()
        ]);
    }
    exit();
}

// Handle edit sport via AJAX/POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_sport'])) {
    $id = intval($_POST['id']);
    $sportName = trim($_POST['sport_name']);
    $gender = $_POST['gender'];
    $status = $_POST['status'];

    try {
        $stmt = $pdo->prepare("
            UPDATE competition_sports
            SET sport_name = ?, gender = ?, status = ?
            WHERE id = ?
        ");
        $stmt->execute([$sportName, $gender, $status, $id]);

        echo json_encode([
            'success' => true,
            'message' => 'Sport updated successfully!'
        ]);
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => "Error updating sport: " . $e->getMessage()
        ]);
    }
    exit();
}

// Handle delete competition
if (isset($_GET['delete_competition']) && isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
    $id = intval($_GET['delete_competition']);

    try {
        // Start transaction
        $pdo->beginTransaction();

        // Get competition details to delete folder
        $stmt = $pdo->prepare("SELECT name, year FROM competitions WHERE id = ?");
        $stmt->execute([$id]);
        $competition = $stmt->fetch();

        // Delete all universities under this competition first
        $universityStmt = $pdo->prepare("SELECT id FROM universities WHERE competition_id = ?");
        $universityStmt->execute([$id]);
        $universities = $universityStmt->fetchAll();

        foreach ($universities as $university) {
            // Delete sports under this university
            $sportStmt = $pdo->prepare("SELECT id FROM competition_sports WHERE university_id = ?");
            $sportStmt->execute([$university['id']]);
            $sports = $sportStmt->fetchAll();

            foreach ($sports as $sport) {
                // Delete athletes in this sport
                $athleteStmt = $pdo->prepare("DELETE FROM athletes WHERE competition_sport_id = ?");
                $athleteStmt->execute([$sport['id']]);
            }

            // Delete sports
            $sportDeleteStmt = $pdo->prepare("DELETE FROM competition_sports WHERE university_id = ?");
            $sportDeleteStmt->execute([$university['id']]);
        }

        // Delete universities
        $universityDeleteStmt = $pdo->prepare("DELETE FROM universities WHERE competition_id = ?");
        $universityDeleteStmt->execute([$id]);

        // Delete competition
        $stmt = $pdo->prepare("DELETE FROM competitions WHERE id = ?");
        $stmt->execute([$id]);

        // Delete competition folder
        if ($competition) {
            $folderName = preg_replace('/[^a-zA-Z0-9_-]/', '', str_replace(' ', '_', $competition['name'])) . '_' . $competition['year'];
            $folderPath = 'uploads/competitions/' . $folderName;

            // Function to recursively delete directory
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

            if (file_exists($folderPath)) {
                deleteDirectory($folderPath);
            }
        }

        // Commit transaction
        $pdo->commit();

        $_SESSION['success'] = "Competition deleted successfully! All associated data and folders removed.";
    } catch (PDOException $e) {
        // Rollback transaction on error
        $pdo->rollBack();
        $_SESSION['error'] = "Error deleting competition: " . $e->getMessage();
    }

    header('Location: athletes.php');
    exit();
}

// Handle delete university
if (isset($_GET['delete_university']) && isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
    $id = intval($_GET['delete_university']);
    $competition_id = isset($_GET['competition_id']) ? intval($_GET['competition_id']) : 0;

    try {
        // Start transaction
        $pdo->beginTransaction();

        // Get university and competition details to delete folder
        $stmt = $pdo->prepare("
            SELECT u.*, c.name as competition_name, c.year
            FROM universities u
            JOIN competitions c ON u.competition_id = c.id
            WHERE u.id = ?
        ");
        $stmt->execute([$id]);
        $university = $stmt->fetch();

        // Delete sports under this university
        $sportStmt = $pdo->prepare("SELECT id FROM competition_sports WHERE university_id = ?");
        $sportStmt->execute([$id]);
        $sports = $sportStmt->fetchAll();

        foreach ($sports as $sport) {
            // Delete athletes in this sport
            $athleteStmt = $pdo->prepare("DELETE FROM athletes WHERE competition_sport_id = ?");
            $athleteStmt->execute([$sport['id']]);
        }

        // Delete sports
        $sportDeleteStmt = $pdo->prepare("DELETE FROM competition_sports WHERE university_id = ?");
        $sportDeleteStmt->execute([$id]);

        // Delete university from database
        $stmt = $pdo->prepare("DELETE FROM universities WHERE id = ?");
        $stmt->execute([$id]);

        // Delete university folder
        if ($university) {
            $competitionFolder = preg_replace('/[^a-zA-Z0-9_-]/', '', str_replace(' ', '_', $university['competition_name'])) . '_' . $university['year'];
            $universityFolder = preg_replace('/[^a-zA-Z0-9_-]/', '', str_replace(' ', '_', $university['name']));

            $universityFolderPath = 'uploads/competitions/' . $competitionFolder . '/' . $universityFolder;

            // Function to recursively delete directory
            function deleteUniversityDirectory($dir)
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
                    if (!deleteUniversityDirectory($dir . DIRECTORY_SEPARATOR . $item)) {
                        return false;
                    }
                }
                return rmdir($dir);
            }

            if (file_exists($universityFolderPath)) {
                deleteUniversityDirectory($universityFolderPath);
            }
        }

        // Commit transaction
        $pdo->commit();

        $_SESSION['success'] = "University deleted successfully! All associated athletes and folders removed.";
    } catch (PDOException $e) {
        // Rollback transaction on error
        $pdo->rollBack();
        $_SESSION['error'] = "Error deleting university: " . $e->getMessage();
    }

    header("Location: athletes.php?competition_id={$competition_id}");
    exit();
}

// Handle delete sport from university
if (isset($_GET['delete_sport']) && isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
    $id = intval($_GET['delete_sport']);

    try {
        // Start transaction
        $pdo->beginTransaction();

        // Get sport, university and competition details to delete folder
        $stmt = $pdo->prepare("
            SELECT cs.*, c.name as competition_name, c.year, u.name as university_name
            FROM competition_sports cs
            JOIN competitions c ON cs.competition_id = c.id
            JOIN universities u ON cs.university_id = u.id
            WHERE cs.id = ?
        ");
        $stmt->execute([$id]);
        $sport = $stmt->fetch();

        // Delete all athletes in this sport first
        $athleteStmt = $pdo->prepare("DELETE FROM athletes WHERE competition_sport_id = ?");
        $athleteStmt->execute([$id]);

        // Delete sport from database
        $stmt = $pdo->prepare("DELETE FROM competition_sports WHERE id = ?");
        $stmt->execute([$id]);

        // Delete sport folder
        if ($sport) {
            $competitionFolder = preg_replace('/[^a-zA-Z0-9_-]/', '', str_replace(' ', '_', $sport['competition_name'])) . '_' . $sport['year'];
            $universityFolder = preg_replace('/[^a-zA-Z0-9_-]/', '', str_replace(' ', '_', $sport['university_name']));
            $sportFolderName = preg_replace('/[^a-zA-Z0-9_-]/', '', str_replace(' ', '_', $sport['sport_name']));

            $genderSuffix = '';
            if ($sport['gender'] === 'Male') {
                $genderSuffix = '_M';
            } elseif ($sport['gender'] === 'Female') {
                $genderSuffix = '_F';
            } elseif ($sport['gender'] === 'Mixed') {
                $genderSuffix = '_X';
            }

            $sportFolderPath = 'uploads/competitions/' . $competitionFolder . '/' . $universityFolder . '/' . $sportFolderName . $genderSuffix;

            // Function to recursively delete directory
            function deleteSportDirectory($dir)
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
                    if (!deleteSportDirectory($dir . DIRECTORY_SEPARATOR . $item)) {
                        return false;
                    }
                }
                return rmdir($dir);
            }

            if (file_exists($sportFolderPath)) {
                deleteSportDirectory($sportFolderPath);
            }
        }

        // Commit transaction
        $pdo->commit();

        $_SESSION['success'] = "Sport deleted successfully! All associated athletes and folders removed.";
    } catch (PDOException $e) {
        // Rollback transaction on error
        $pdo->rollBack();
        $_SESSION['error'] = "Error deleting sport: " . $e->getMessage();
    }

    // Redirect back to the appropriate page
    if (isset($_GET['competition_id']) && isset($_GET['university_id'])) {
        header("Location: athletes.php?competition_id=" . intval($_GET['competition_id']) . "&university_id=" . intval($_GET['university_id']));
    } else {
        header('Location: athletes.php');
    }
    exit();
}

// Handle global athlete search
if (isset($_GET['global_search']) && !empty($_GET['global_search'])) {
    $searchTerm = '%' . trim($_GET['global_search']) . '%';

    try {
        $stmt = $pdo->prepare("
            SELECT
                a.*,
                c.name as competition_name,
                c.year as competition_year,
                c.id as competition_id,
                u.name as university_name,
                u.id as university_id,
                cs.sport_name,
                cs.id as sport_id,
                cs.gender as sport_gender,
                co.course_code,
                co.course_name
            FROM athletes a
            JOIN competition_sports cs ON a.competition_sport_id = cs.id
            JOIN universities u ON cs.university_id = u.id
            JOIN competitions c ON cs.competition_id = c.id
            LEFT JOIN courses co ON a.course_id = co.id
            WHERE a.student_id LIKE ?
               OR a.first_name LIKE ?
               OR a.last_name LIKE ?
               OR a.email LIKE ?
               OR CONCAT(a.first_name, ' ', a.last_name) LIKE ?
               OR CONCAT(a.last_name, ' ', a.first_name) LIKE ?
               OR co.course_code LIKE ?
               OR co.course_name LIKE ?
               OR u.name LIKE ?
               OR c.name LIKE ?
            ORDER BY c.year DESC, u.name, cs.sport_name, a.last_name, a.first_name
        ");

        $stmt->execute([
            $searchTerm,
            $searchTerm,
            $searchTerm,
            $searchTerm,
            $searchTerm,
            $searchTerm,
            $searchTerm,
            $searchTerm,
            $searchTerm,
            $searchTerm
        ]);

        $searchResults = $stmt->fetchAll();

        // Group results by competition for better display
        $groupedResults = [];
        foreach ($searchResults as $result) {
            $compKey = $result['competition_id'] . '_' . $result['competition_name'] . '_' . $result['competition_year'];
            if (!isset($groupedResults[$compKey])) {
                $groupedResults[$compKey] = [
                    'competition_id' => $result['competition_id'],
                    'competition_name' => $result['competition_name'],
                    'competition_year' => $result['competition_year'],
                    'universities' => []
                ];
            }

            $uniKey = $result['university_id'];
            if (!isset($groupedResults[$compKey]['universities'][$uniKey])) {
                $groupedResults[$compKey]['universities'][$uniKey] = [
                    'university_id' => $result['university_id'],
                    'university_name' => $result['university_name'],
                    'sports' => []
                ];
            }

            $sportKey = $result['sport_id'];
            if (!isset($groupedResults[$compKey]['universities'][$uniKey]['sports'][$sportKey])) {
                $groupedResults[$compKey]['universities'][$uniKey]['sports'][$sportKey] = [
                    'sport_id' => $result['sport_id'],
                    'sport_name' => $result['sport_name'],
                    'sport_gender' => $result['sport_gender'],
                    'athletes' => []
                ];
            }

            $groupedResults[$compKey]['universities'][$uniKey]['sports'][$sportKey]['athletes'][] = $result;
        }
    } catch (PDOException $e) {
        $searchError = "Error performing search: " . $e->getMessage();
        $searchResults = [];
        $groupedResults = [];
    }
}

// Fetch competition data for editing via AJAX
if (isset($_GET['get_competition'])) {
    $id = intval($_GET['get_competition']);

    try {
        $stmt = $pdo->prepare("SELECT * FROM competitions WHERE id = ?");
        $stmt->execute([$id]);
        $competition = $stmt->fetch();

        echo json_encode([
            'success' => true,
            'data' => $competition
        ]);
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => "Error fetching competition: " . $e->getMessage()
        ]);
    }
    exit();
}

// Fetch university data for editing via AJAX
if (isset($_GET['get_university'])) {
    $id = intval($_GET['get_university']);

    try {
        $stmt = $pdo->prepare("SELECT * FROM universities WHERE id = ?");
        $stmt->execute([$id]);
        $university = $stmt->fetch();

        echo json_encode([
            'success' => true,
            'data' => $university
        ]);
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => "Error fetching university: " . $e->getMessage()
        ]);
    }
    exit();
}

// Fetch sport data for editing via AJAX
if (isset($_GET['get_sport'])) {
    $id = intval($_GET['get_sport']);

    try {
        $stmt = $pdo->prepare("SELECT * FROM competition_sports WHERE id = ?");
        $stmt->execute([$id]);
        $sport = $stmt->fetch();

        echo json_encode([
            'success' => true,
            'data' => $sport
        ]);
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => "Error fetching sport: " . $e->getMessage()
        ]);
    }
    exit();
}

// Fetch all competitions
try {
    $stmt = $pdo->query("SELECT * FROM competitions ORDER BY year DESC, name");
    $competitions = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = "Error fetching competitions: " . $e->getMessage();
    $competitions = [];
}

// Get search query if any
$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';
$sportSearchQuery = isset($_GET['sport_search']) ? trim($_GET['sport_search']) : '';

// If a competition is selected, fetch its universities and sports
$selectedCompetition = null;
$competitionUniversities = [];
$selectedUniversity = null;
$universitySports = [];
$filteredUniversitySports = [];
$competitionAthletes = [];

if (isset($_GET['competition_id'])) {
    $competitionId = intval($_GET['competition_id']);

    try {
        // Get competition details
        $stmt = $pdo->prepare("SELECT * FROM competitions WHERE id = ?");
        $stmt->execute([$competitionId]);
        $selectedCompetition = $stmt->fetch();

        if ($selectedCompetition) {
            // Get universities for this competition with total athlete count
            $stmt = $pdo->prepare("
                SELECT u.*,
                       (SELECT COUNT(a.id)
                        FROM athletes a
                        JOIN competition_sports cs ON a.competition_sport_id = cs.id
                        WHERE cs.university_id = u.id
                       ) as total_athletes
                FROM universities u
                WHERE u.competition_id = ?
                ORDER BY u.name
            ");
            $stmt->execute([$competitionId]);
            $competitionUniversities = $stmt->fetchAll();

            // If a university is selected, get sports for that university
            if (isset($_GET['university_id'])) {
                $universityId = intval($_GET['university_id']);

                // Get university details
                $uniStmt = $pdo->prepare("SELECT * FROM universities WHERE id = ? AND competition_id = ?");
                $uniStmt->execute([$universityId, $competitionId]);
                $selectedUniversity = $uniStmt->fetch();

                if ($selectedUniversity) {
                    // Get sports for this university with athlete count
                    $sportStmt = $pdo->prepare("
                        SELECT cs.*,
                               (SELECT COUNT(*) FROM athletes a WHERE a.competition_sport_id = cs.id) as athlete_count
                        FROM competition_sports cs
                        WHERE cs.competition_id = ? AND cs.university_id = ?
                        ORDER BY cs.sport_name
                    ");
                    $sportStmt->execute([$competitionId, $universityId]);
                    $universitySports = $sportStmt->fetchAll();

                    // Filter sports based on search query
                    if (!empty($sportSearchQuery)) {
                        $filteredUniversitySports = array_filter($universitySports, function ($sport) use ($sportSearchQuery) {
                            $searchLower = strtolower($sportSearchQuery);
                            return (
                                strpos(strtolower($sport['sport_name']), $searchLower) !== false ||
                                strpos(strtolower($sport['gender']), $searchLower) !== false ||
                                strpos(strtolower($sport['status']), $searchLower) !== false
                            );
                        });
                    } else {
                        $filteredUniversitySports = $universitySports;
                    }

                    // If a sport is selected, get athletes for that sport with search
                    if (isset($_GET['sport_id'])) {
                        $sportId = intval($_GET['sport_id']);

                        if (!empty($searchQuery)) {
                            // Search in athletes
                            $searchTerm = "%{$searchQuery}%";
                            $stmt = $pdo->prepare("
                                SELECT a.*, c.course_code, c.course_name, cs.sport_name, u.name as university_name
                                FROM athletes a
                                LEFT JOIN courses c ON a.course_id = c.id
                                JOIN competition_sports cs ON a.competition_sport_id = cs.id
                                JOIN universities u ON cs.university_id = u.id
                                WHERE cs.id = ?
                                AND (a.student_id LIKE ? OR a.first_name LIKE ? OR a.last_name LIKE ?
                                     OR a.middle_initial LIKE ? OR c.course_code LIKE ? OR c.course_name LIKE ?
                                     OR a.email LIKE ?)
                                ORDER BY a.last_name, a.first_name
                            ");
                            $stmt->execute([$sportId, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
                        } else {
                            // No search, get all athletes
                            $stmt = $pdo->prepare("
                                SELECT a.*, c.course_code, c.course_name, cs.sport_name, u.name as university_name
                                FROM athletes a
                                LEFT JOIN courses c ON a.course_id = c.id
                                JOIN competition_sports cs ON a.competition_sport_id = cs.id
                                JOIN universities u ON cs.university_id = u.id
                                WHERE cs.id = ?
                                ORDER BY a.last_name, a.first_name
                            ");
                            $stmt->execute([$sportId]);
                        }
                        $competitionAthletes = $stmt->fetchAll();
                    }
                }
            }
        }
    } catch (PDOException $e) {
        $error = "Error fetching data: " . $e->getMessage();
    }
}

// Get all active sports for copy dropdown (excluding current sport)
$allSports = [];
if (isset($_GET['sport_id']) && isset($_GET['university_id']) && isset($_GET['competition_id'])) {
    try {
        $currentSportId = intval($_GET['sport_id']);
        $stmt = $pdo->prepare("
            SELECT cs.id, cs.sport_name, cs.gender, cs.competition_id,
                   c.name as competition_name, c.year, c.id as competition_id,
                   u.name as university_name, u.id as university_id
            FROM competition_sports cs
            JOIN competitions c ON cs.competition_id = c.id
            JOIN universities u ON cs.university_id = u.id
            WHERE cs.status = 'active' AND cs.id != ?
            ORDER BY c.year DESC, c.name, u.name, cs.sport_name
        ");
        $stmt->execute([$currentSportId]);
        $allSports = $stmt->fetchAll();
    } catch (PDOException $e) {
        // Silently fail
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <title>TAU Sports - Competition Management</title>
    <style>
        /* Selected athlete item styling */
        .selected-athlete-item:last-child div {
            border-bottom: none !important;
        }

        .selected-athlete-item:hover {
            background-color: #f0f0f0;
        }

        /* Bulk copy button */
        #bulkCopyBtn {
            background-color: #28a745;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            align-items: center;
            gap: 5px;
        }

        #bulkCopyBtn:hover {
            background-color: #218838;
        }

        /* Selection controls */
        #selectAllCheckbox {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .athlete-select {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        /* Sport option styling for modal */
        .sport-option {
            cursor: pointer;
            padding: 12px;
            border: 1px solid #e0e0e0;
            margin-bottom: 8px;
            border-radius: 6px;
            transition: all 0.2s;
        }

        .sport-option:hover {
            background-color: #f5f5f5;
            border-color: #0c3a1d;
        }

        .sport-option.selected {
            background-color: #e8f5e9;
            border-color: #0c3a1d;
            border-width: 2px;
        }

        /* Alert styling */
        .modal-alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 15px;
        }

        .modal-alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 15px;
        }

        /* Cascading Modal Styles */
        .competition-selector {
            margin-bottom: 20px;
        }

        .sports-container {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            padding: 10px;
            background: #fafafa;
        }

        .sport-card-item {
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 10px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .sport-card-item:hover {
            border-color: #0c3a1d;
            box-shadow: 0 2px 8px rgba(12, 58, 29, 0.1);
            transform: translateY(-2px);
        }

        .sport-card-item.selected {
            background: #e8f5e9;
            border-color: #0c3a1d;
            border-width: 2px;
        }

        .sport-card-name {
            font-weight: 600;
            color: #0c3a1d;
            font-size: 16px;
            margin-bottom: 5px;
        }

        .sport-card-details {
            font-size: 13px;
            color: #666;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .sport-card-university {
            color: #1D384D;
        }

        .sport-card-gender {
            background: #e0e0e0;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
        }

        .sport-search-box {
            margin-bottom: 15px;
        }

        .sport-search-box input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            font-size: 14px;
        }

        .sport-search-box input:focus {
            outline: none;
            border-color: #0c3a1d;
        }

        .loading-spinner {
            text-align: center;
            padding: 20px;
            color: #666;
        }

        .no-results {
            text-align: center;
            padding: 30px;
            color: #999;
        }

        .selected-sport-badge {
            background: #e8f5e9;
            border: 1px solid #0c3a1d;
            border-radius: 6px;
            padding: 10px;
            margin-top: 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .change-sport-btn {
            background: #6c757d;
            color: white;
            border: none;
            padding: 4px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
        }

        .change-sport-btn:hover {
            background: #5a6268;
        }
    </style>
    <style>
        /* Keep all existing CSS from original file */
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
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.3s;
            border: none;
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

        .btn-edit {
            background: #ffc107;
            color: #212529;
        }

        .btn-edit:hover {
            background: #e0a800;
        }

        .btn-delete {
            background: #dc3545;
            color: white;
        }

        .btn-delete:hover {
            background: #c82333;
        }

        .btn-view {
            background: #17a2b8;
            color: white;
        }

        .btn-view:hover {
            background: #138496;
        }

        .btn-copy {
            background: #6f42c1;
            color: white;
        }

        .btn-copy:hover {
            background: #5e35b1;
        }

        .btn-sm {
            padding: 5px 10px;
            font-size: 14px;
        }

        .btn-search {
            background: #0c3a1d;
            color: white;
        }

        .btn-search:hover {
            background: #0a3018;
        }

        .btn-clear {
            background: #6c757d;
            color: white;
        }

        .btn-clear:hover {
            background: #5a6268;
        }

        .alert {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            border-left: 4px solid;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left-color: #155724;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left-color: #721c24;
        }

        /* New styles for competition management */
        .breadcrumb {
            margin-bottom: 20px;
            font-size: 14px;
            color: #666;
        }

        .breadcrumb a {
            color: #0c3a1d;
            text-decoration: none;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        .competition-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .competition-card {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
            transition: all 0.3s;
        }

        .competition-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.15);
        }

        .competition-header {
            background: linear-gradient(135deg, #0c3a1d 0%, #1a5c2f 100%);
            color: white;
            padding: 20px;
        }

        .competition-year {
            font-size: 12px;
            opacity: 0.8;
            margin-bottom: 5px;
        }

        .competition-name {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .competition-description {
            font-size: 14px;
            opacity: 0.9;
        }

        .competition-actions {
            padding: 15px 20px;
            background: #f8f9fa;
            display: flex;
            gap: 10px;
        }

        .universities-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }

        .university-card {
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            overflow: hidden;
            transition: all 0.3s;
        }

        .university-card:hover {
            border-color: #0c3a1d;
            box-shadow: 0 5px 20px rgba(12, 58, 29, 0.1);
            transform: translateY(-3px);
        }

        .university-header {
            background: linear-gradient(135deg, #1D384D, #007442);
            color: white;
            padding: 15px;
        }

        .university-name {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .university-code {
            font-size: 12px;
            opacity: 0.8;
            margin-bottom: 5px;
        }

        .university-description {
            font-size: 13px;
            opacity: 0.9;
        }

        .university-actions {
            padding: 13px;
            background: #f8f9fa;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .sports-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
            margin: 20px 0;

        }

        .sport-card {
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            overflow: hidden;
            transition: all 0.3s;

        }

        .sport-header {
            background: linear-gradient(135deg, #1D384D, #007442);
            color: white;
            padding: 15px;
        }

        .sport-card:hover {
            border-color: #0c3a1d;
            box-shadow: 0 5px 15px rgba(12, 58, 29, 0.1);
        }

        .sport-name {
            font-size: 18px;
            font-weight: 600;
            color: #ffffff;
            margin-bottom: 10px;

        }

        .sport-actions {
            padding: 13px;
            background: #f8f9fa;
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .sport-details {
            font-size: 14px;
            color: #666;
            margin-bottom: 0;
            padding-left: 15px;
            padding-top: 10px;

        }

        /* Search Bar Styles */
        .search-container {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }

        .search-box {
            flex: 1;
            min-width: 300px;
            display: flex;
            gap: 10px;
        }

        .search-box input {
            flex: 1;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 16px;
            transition: all 0.3s;
        }

        .search-box input:focus {
            outline: none;
            border-color: #0c3a1d;
            box-shadow: 0 0 0 3px rgba(12, 58, 29, 0.1);
        }

        .search-stats {
            color: #666;
            font-size: 14px;
            padding: 0 10px;
        }

        .athletes-table {
            width: 100%;
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            margin-top: 20px;
        }

        .athletes-table th {
            background: linear-gradient(135deg, #0c3a1d 0%, #1a5c2f 100%);
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: 600;
        }

        .athletes-table td {
            padding: 15px;
            border-bottom: 1px solid #e0e0e0;
        }

        .athletes-table tr:hover {
            background: #f8f9fa;
        }

        .sport-badge {
            padding: 4px 12px;
            background: #e8f5e9;
            color: #0c3a1d;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #666;
            background: white;
            border-radius: 10px;
            margin: 20px 0;
        }

        .empty-state h3 {
            margin-bottom: 15px;
            color: #0c3a1d;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            color: #0c3a1d;
            text-decoration: none;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .back-link:hover {
            text-decoration: underline;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1001;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            animation: fadeIn 0.3s;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 0;
            border-radius: 10px;
            width: 90%;
            max-width: 600px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            animation: slideIn 0.3s;
            overflow: hidden;
        }

        @keyframes slideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            background: linear-gradient(135deg, #0c3a1d 0%, #1a5c2f 100%);
            color: white;
            padding: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            font-size: 20px;
            font-weight: 600;
            margin: 0;
        }

        .close-modal {
            background: none;
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
            padding: 0;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.3s;
        }

        .close-modal:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .modal-body {
            padding: 30px;
            max-height: 70vh;
            overflow-y: auto;
        }

        .form-group {
            margin-bottom: 20px;
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

        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }

        .modal-actions {
            padding: 20px 30px;
            background: #f8f9fa;
            border-top: 1px solid #e0e0e0;
            display: flex;
            gap: 15px;
            justify-content: flex-end;
        }

        .modal-alert {
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
            display: none;
        }

        .modal-alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .modal-alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* Document status tooltip */
        .status-tooltip {
            position: relative;
            cursor: help;
        }

        .status-tooltip:hover .tooltip-content {
            visibility: visible;
            opacity: 1;
        }

        .tooltip-content {
            visibility: hidden;
            opacity: 0;
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: #333;
            color: white;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 12px;
            white-space: nowrap;
            z-index: 1000;
            transition: opacity 0.3s;
            margin-bottom: 5px;
        }

        .tooltip-content::after {
            content: '';
            position: absolute;
            top: 100%;
            left: 50%;
            margin-left: -5px;
            border-width: 5px;
            border-style: solid;
            border-color: #333 transparent transparent transparent;
        }

        /* Copy Modal Styles */
        .copy-athlete-info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            border-left: 4px solid #6f42c1;
        }

        .copy-athlete-info h4 {
            color: #6f42c1;
            margin-bottom: 10px;
        }

        .info-row {
            margin-bottom: 5px;
            font-size: 14px;
        }

        .info-label {
            font-weight: 600;
            color: #555;
            display: inline-block;
            width: 100px;
        }

        .sport-option {
            padding: 10px;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            margin-bottom: 8px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .sport-option:hover {
            background: #f0f0f0;
            border-color: #6f42c1;
        }

        .sport-option.selected {
            background: #f0e6ff;
            border-color: #6f42c1;
            border-width: 2px;
        }

        .sport-option input[type="radio"] {
            margin-right: 10px;
        }

        .sport-competition-name {
            font-weight: 600;
            color: #0c3a1d;
        }

        .sport-university-name {
            font-weight: 500;
            color: #2c3e50;
            font-size: 13px;
            margin-left: 25px;
        }

        .sport-detail {
            font-size: 13px;
            color: #666;
            margin-left: 25px;
        }

        /* Global Search Styles */
        .global-search-container {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border: 2px solid #0c3a1d;
            margin-bottom: 30px;
            padding: 25px;
        }

        .global-search-box {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }

        .global-search-input {
            flex: 1;
            min-width: 300px;
            padding: 15px 20px;
            border: 2px solid #e0e0e0;
            border-radius: 50px;
            font-size: 16px;
            transition: all 0.3s;
            background: white;
        }

        .global-search-input:focus {
            outline: none;
            border-color: #0c3a1d;
            box-shadow: 0 0 0 4px rgba(12, 58, 29, 0.1);
        }

        .global-search-btn {
            padding: 15px 30px;
            border-radius: 50px;
            font-size: 16px;
            font-weight: 600;
        }

        .search-results-container {
            margin-top: 30px;
        }

        .search-result-group {
            margin-bottom: 30px;
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 3px 15px rgba(0, 0, 0, 0.08);
        }

        .search-result-header {
            background: linear-gradient(135deg, #1D384D, #007442);
            color: white;
            padding: 15px 20px;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.3s;
        }

        .search-result-header:hover {
            background: linear-gradient(135deg, #152b3a, #005c35);
        }

        .search-result-header h3 {
            font-size: 18px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .search-result-header .toggle-icon {
            font-size: 20px;
            transition: transform 0.3s;
        }

        .search-result-header.collapsed .toggle-icon {
            transform: rotate(-90deg);
        }

        .search-result-content {
            padding: 20px;
            border: 1px solid #e0e0e0;
            border-top: none;
        }

        .search-result-content.collapsed {
            display: none;
        }

        .university-group {
            margin-bottom: 20px;
            padding-left: 20px;
            border-left: 3px solid #0c3a1d;
        }

        .university-group h4 {
            color: #1D384D;
            margin-bottom: 15px;
            font-size: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .sport-group {
            margin-bottom: 15px;
            padding-left: 20px;
        }

        .sport-group h5 {
            color: #007442;
            margin-bottom: 10px;
            font-size: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .search-athlete-item {
            background: #f8f9fa;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
            transition: all 0.2s;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .search-athlete-item:hover {
            border-color: #0c3a1d;
            box-shadow: 0 3px 10px rgba(12, 58, 29, 0.1);
        }

        .search-athlete-info {
            flex: 1;
        }

        .search-athlete-name {
            font-size: 16px;
            font-weight: 600;
            color: #0c3a1d;
            margin-bottom: 5px;
        }

        .search-athlete-details {
            font-size: 14px;
            color: #666;
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }

        .search-athlete-details span {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .search-athlete-actions {
            display: flex;
            gap: 8px;
        }

        .path-badge {
            background: #e8f5e9;
            color: #0c3a1d;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }

        .no-results {
            text-align: center;
            padding: 40px;
            background: white;
            border-radius: 10px;
            color: #666;
        }

        .no-results i {
            font-size: 48px;
            color: #0c3a1d;
            margin-bottom: 15px;
            opacity: 0.5;
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

            .competition-grid {
                grid-template-columns: 1fr;
            }

            .universities-grid {
                grid-template-columns: 1fr;
            }

            .sports-grid {
                grid-template-columns: 1fr;
            }

            .athletes-table {
                display: block;
                overflow-x: auto;
            }

            .modal-content {
                margin: 10% auto;
                width: 95%;
            }

            .tooltip-content {
                white-space: normal;
                width: 200px;
            }

            .search-container {
                flex-direction: column;
            }

            .search-box {
                width: 100%;
            }

            .global-search-box {
                flex-direction: column;
            }

            .global-search-input {
                width: 100%;
            }

            .search-athlete-item {
                flex-direction: column;
                align-items: flex-start;
            }

            .search-athlete-actions {
                width: 100%;
                justify-content: flex-end;
            }
        }

        .toggle-icon {
            transition: transform 0.3s;
        }

        .collapsed .toggle-icon {
            transform: rotate(-90deg);
        }

        .collapsed+div {
            display: none;
        }

        #search-results-dropdown {
            animation: slideDown 0.2s ease-out;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        #search-results-dropdown div:hover {
            background: #f8f9fa;
        }

        /* Selected athlete item styling */
        .selected-athlete-item {
            transition: background-color 0.2s;
        }

        .selected-athlete-item:hover {
            background-color: #f5f5f5;
        }

        /* Bulk copy button animation */
        #bulkCopyBtn {
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(40, 167, 69, 0.4);
            }

            70% {
                box-shadow: 0 0 0 10px rgba(40, 167, 69, 0);
            }

            100% {
                box-shadow: 0 0 0 0 rgba(40, 167, 69, 0);
            }
        }

        /* Selection controls styling */
        #selectAllCheckbox {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .athlete-select {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        /* Bulk copy modal specific */
        #bulkCopyModal .modal-content {
            max-width: 600px;
        }

        #selectedAthletesList {
            background: #f8f9fa;
        }

        /* Warning/error styling */
        .modal-alert-warning {
            background-color: #fff3cd;
            color: #856404;
            border: 1px solid #ffeeba;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 15px;
        }

        /* Back Button Styling */
        .btn-back {
            background: #6c757d;
            color: white;
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            font-size: 14px;
            font-weight: 500;
        }

        .btn-back:hover {
            background: #5a6268;
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            color: white;
            text-decoration: none;
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            .page-title {
                flex-direction: column;
                align-items: flex-start !important;
                gap: 15px;
            }

            .page-title>div:first-child {
                width: 100%;
                justify-content: space-between;
            }

            .btn-back {
                padding: 6px 12px;
                font-size: 12px;
            }
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
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success" style="background: #d4edda; color: #155724; padding: 15px; border-radius: 6px; margin-bottom: 20px; border-left: 4px solid #28a745;">
                    <?php
                    echo $_SESSION['success'];
                    unset($_SESSION['success']);
                    ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-error" style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 6px; margin-bottom: 20px; border-left: 4px solid #dc3545;">
                    <?php
                    echo $_SESSION['error'];
                    unset($_SESSION['error']);
                    ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['warning'])): ?>
                <div class="alert alert-warning" style="background: #fff3cd; color: #856404; padding: 15px; border-radius: 6px; margin-bottom: 20px; border-left: 4px solid #ffc107;">
                    <?php
                    echo $_SESSION['warning'];
                    unset($_SESSION['warning']);
                    ?>
                </div>
            <?php endif; ?>

            <!-- In athletes.php, find the page-title div and update it -->

            <div class="page-title" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; position: relative;">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <!-- Back Button - Only show if we're not on the main competition list -->
                    <?php if ($selectedCompetition || isset($_GET['global_search']) && !empty($_GET['global_search'])): ?>
                        <a href="<?php
                                    // Determine the appropriate back URL
                                    if (isset($_GET['global_search']) && !empty($_GET['global_search'])) {
                                        // If in global search, go back to main page
                                        echo 'athletes.php';
                                    } elseif (isset($_GET['sport_id']) && isset($_GET['university_id']) && isset($_GET['competition_id'])) {
                                        // If viewing athletes in a sport, go back to sports list
                                        echo 'athletes.php?competition_id=' . $selectedCompetition['id'] . '&university_id=' . $selectedUniversity['id'];
                                    } elseif (isset($_GET['university_id']) && isset($_GET['competition_id'])) {
                                        // If viewing sports in a university, go back to university list
                                        echo 'athletes.php?competition_id=' . $selectedCompetition['id'];
                                    } elseif (isset($_GET['competition_id'])) {
                                        // If viewing universities in a competition, go back to competition list
                                        echo 'athletes.php';
                                    } else {
                                        echo '#';
                                    }
                                    ?>" class="btn btn-back" style="display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px; background: #6c757d; color: white; border-radius: 6px; text-decoration: none; transition: all 0.3s;">
                            <i class="fas fa-arrow-left"></i> Back
                        </a>
                    <?php endif; ?>

                    <span>Athlete Profiles Management</span>
                </div>
                <div style="display: flex; gap: 15px; align-items: center;">
<!-- Global Search Bar - Modified to stay on same page -->
<div style="position: relative;" id="global-search-container">
    <div style="display: flex; align-items: center; background: white; border-radius: 30px; border: 1px solid #e0e0e0; overflow: hidden; box-shadow: 0 2px 5px rgba(0,0,0,0.05); width: 300px;">
        <input type="text"
            id="global-search-input"
            placeholder="Search athletes..."
            style="padding: 8px 15px; border: none; outline: none; width: 100%; font-size: 14px;"
            autocomplete="off">
        <button id="global-search-button" style="background: none; border: none; padding: 8px 15px; color: #0c3a1d; cursor: pointer;">
            <i class="fas fa-search"></i>
        </button>
        <button id="global-search-clear" style="background: none; border: none; padding: 8px 15px; color: #999; cursor: pointer; display: none;">
            <i class="fas fa-times"></i>
        </button>
    </div>

    <!-- Search Results Dropdown -->
    <div id="search-results-dropdown" style="display: none; position: absolute; top: 100%; right: 0; width: 500px; max-width: 500px; background: white; border-radius: 10px; box-shadow: 0 5px 25px rgba(0,0,0,0.15); margin-top: 8px; z-index: 1000; border: 1px solid #e0e0e0; overflow: hidden;">
        <div id="search-results-content">
            <!-- Results will be loaded here dynamically -->
        </div>
    </div>
</div>

                    <?php if (!$selectedCompetition): ?>
                        <button onclick="openAddCompetitionModal()" class="btn btn-primary" style="padding: 8px 20px; font-size: 14px;"><i class="fas fa-plus"></i> Add Competition</button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Full Page Results (hidden by default, shown when clicking "View all results") -->
            <div id="full-results-container" style="display: none; margin-top: 30px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h3 style="color: #0c3a1d; font-size: 20px;">All Search Results</h3>
                    <button onclick="hideAllResults()" class="btn btn-sm" style="background: #6c757d; color: white;">Close Results</button>
                </div>

                <?php if (isset($_GET['global_search']) && !empty($_GET['global_search']) && !empty($searchResults)): ?>
                    <?php foreach ($groupedResults as $competition): ?>
                        <div style="margin-bottom: 25px; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 3px 15px rgba(0,0,0,0.08); border: 1px solid #e0e0e0;">
                            <div style="background: linear-gradient(135deg, #1D384D, #007442); color: white; padding: 15px 20px;">
                                <h3 style="font-size: 18px; font-weight: 600; display: flex; align-items: center; gap: 10px;">
                                    <i class="fas fa-trophy"></i>
                                    <?php echo htmlspecialchars($competition['competition_name'] . ' ' . $competition['competition_year']); ?>
                                </h3>
                            </div>
                            <div style="padding: 20px;">
                                <?php foreach ($competition['universities'] as $university): ?>
                                    <div style="margin-bottom: 25px; padding-left: 20px; border-left: 3px solid #0c3a1d;">
                                        <h4 style="color: #1D384D; margin-bottom: 15px; font-size: 16px; display: flex; align-items: center; gap: 8px;">
                                            <i class="fas fa-university"></i>
                                            <?php echo htmlspecialchars($university['university_name']); ?>
                                        </h4>

                                        <?php foreach ($university['sports'] as $sport): ?>
                                            <div style="margin-bottom: 20px; padding-left: 20px;">
                                                <h5 style="color: #007442; margin-bottom: 12px; font-size: 15px; display: flex; align-items: center; gap: 8px;">
                                                    <i class="fas fa-medal"></i>
                                                    <?php echo htmlspecialchars($sport['sport_name'] . ' (' . $sport['sport_gender'] . ')'); ?>
                                                </h5>

                                                <table class="athletes-table" style="margin-top: 0;">
                                                    <thead>
                                                        <tr>
                                                            <th>Student ID</th>
                                                            <th>Name</th>
                                                            <th>Course</th>
                                                            <th>Contact</th>
                                                            <th>Documents Status</th>
                                                            <th>Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($sport['athletes'] as $athlete): ?>
                                                            <?php
                                                            // Document status calculation
                                                            $docStatus = 0;
                                                            $group1Count = 0;
                                                            $group2Count = 0;

                                                            if (!empty($athlete['birth_certificate_status']) && $athlete['birth_certificate_status'] == 1) {
                                                                $docStatus++;
                                                                $group1Count++;
                                                            }
                                                            if (!empty($athlete['eligibility_form_status']) && $athlete['eligibility_form_status'] == 1) {
                                                                $docStatus++;
                                                                $group1Count++;
                                                            }
                                                            if (!empty($athlete['cor_status']) && $athlete['cor_status'] == 1) {
                                                                $docStatus++;
                                                                $group2Count++;
                                                            }
                                                            if (!empty($athlete['tor_status']) && $athlete['tor_status'] == 1) {
                                                                $docStatus++;
                                                                $group2Count++;
                                                            }
                                                            if (!empty($athlete['photo_status']) && $athlete['photo_status'] == 1) {
                                                                $docStatus++;
                                                            }

                                                            $statusColor = '#dc3545';
                                                            $statusText = 'Incomplete';
                                                            if ($docStatus >= 5) {
                                                                $statusColor = '#0c3a1d';
                                                                $statusText = 'Complete';
                                                            } elseif ($docStatus >= 2) {
                                                                $statusColor = '#ffc107';
                                                                $statusText = 'Partial';
                                                            } elseif ($docStatus >= 1) {
                                                                $statusColor = '#ff9800';
                                                                $statusText = 'Minimal';
                                                            }
                                                            ?>
                                                            <tr>
                                                                <td><strong><?php echo htmlspecialchars($athlete['student_id']); ?></strong></td>
                                                                <td>
                                                                    <?php echo htmlspecialchars($athlete['last_name'] . ', ' . $athlete['first_name']); ?>
                                                                    <?php if (!empty($athlete['middle_initial'])): ?>
                                                                        <?php echo ' ' . htmlspecialchars($athlete['middle_initial']); ?>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td>
                                                                    <?php
                                                                    if (!empty($athlete['course_code'])): ?>
                                                                        <strong><?php echo htmlspecialchars($athlete['course_code']); ?></strong><br>
                                                                        <span style="font-size: 12px; color: #666;"><?php echo htmlspecialchars($athlete['course_name'] ?? ''); ?></span>
                                                                    <?php elseif (!empty($athlete['course'])):
                                                                        $courseDisplay = htmlspecialchars($athlete['course']);
                                                                        if (strpos($courseDisplay, ' - ') !== false) {
                                                                            $courseParts = explode(' - ', $courseDisplay, 2);
                                                                            echo '<strong>' . htmlspecialchars($courseParts[0]) . '</strong><br>';
                                                                            echo '<span style="font-size: 12px; color: #666;">' . htmlspecialchars($courseParts[1]) . '</span>';
                                                                        } else {
                                                                            echo htmlspecialchars($courseDisplay);
                                                                        }
                                                                    else: ?>
                                                                        <span style="color: #999;">N/A</span>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td>
                                                                    <?php echo htmlspecialchars($athlete['contact_number']); ?><br>
                                                                    <small><?php echo htmlspecialchars($athlete['email']); ?></small>
                                                                </td>
                                                                <td>
                                                                    <span style="color: <?php echo $statusColor; ?>; font-weight: 600;">
                                                                        <?php echo $docStatus; ?>/5
                                                                    </span>
                                                                    <span style="font-size: 11px; margin-left: 5px; padding: 2px 6px; background: <?php echo $statusColor; ?>20; color: <?php echo $statusColor; ?>; border-radius: 10px;">
                                                                        <?php echo $statusText; ?>
                                                                    </span>
                                                                    <div style="font-size: 10px; margin-top: 3px;">
                                                                        Docs1: <?php echo $group1Count; ?>/2 | Docs2: <?php echo $group2Count; ?>/2 | Photo: <?php echo !empty($athlete['photo_status']) && $athlete['photo_status'] == 1 ? '✓' : '✗'; ?>
                                                                    </div>
                                                                </td>
                                                                <td>
                                                                    <div style="display: flex; gap: 5px;">
                                                                        <a href="view_athlete.php?id=<?php echo $athlete['id']; ?>" class="btn btn-view btn-sm"><i class="fas fa-eye"></i></a>
                                                                        <a href="add_athlete.php?id=<?php echo $athlete['id']; ?>" class="btn btn-edit btn-sm"><i class="fas fa-pencil"></i></a>
                                                                    </div>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Breadcrumb Navigation -->
            <div class="breadcrumb">
                <?php if ($selectedCompetition): ?>
                    <a href="athletes.php">All Competitions</a>
                    »
                    <a href="athletes.php?competition_id=<?php echo $selectedCompetition['id']; ?>">
                        <?php echo htmlspecialchars($selectedCompetition['name'] . ' ' . $selectedCompetition['year']); ?>
                    </a>

                    <?php if (isset($_GET['university_id']) && $selectedUniversity): ?>
                        »
                        <a href="athletes.php?competition_id=<?php echo $selectedCompetition['id']; ?>&university_id=<?php echo $selectedUniversity['id']; ?>">
                            <?php echo htmlspecialchars($selectedUniversity['name']); ?>
                        </a>
                    <?php endif; ?>

                    <?php if (isset($_GET['sport_id'])): ?>
                        <?php foreach ($universitySports as $sport): ?>
                            <?php if ($sport['id'] == $_GET['sport_id']): ?>
                                »
                                <span><?php echo htmlspecialchars($sport['sport_name']); ?></span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php else: ?>
                    <span>All Competitions</span>
                <?php endif; ?>
            </div>

            <?php if (!$selectedCompetition): ?>
                <!-- Competition List View -->
                <div class="competition-grid">
                    <?php if (empty($competitions)): ?>
                        <div class="empty-state">
                            <h3>No competitions found</h3>
                            <p>Start by adding your first competition</p>
                            <button onclick="openAddCompetitionModal()" class="btn btn-primary" style="margin-top: 20px;">
                                ➕ Add First Competition
                            </button>
                        </div>
                    <?php else: ?>
                        <?php foreach ($competitions as $competition): ?>
                            <div class="competition-card">
                                <div class="competition-header">
                                    <div class="competition-year"><?php echo htmlspecialchars($competition['year']); ?></div>
                                    <div class="competition-name"><?php echo htmlspecialchars($competition['name']); ?></div>
                                    <div class="competition-description"><?php echo htmlspecialchars($competition['description'] ?? 'No description'); ?></div>
                                </div>
                                <div class="competition-actions">
                                    <a href="athletes.php?competition_id=<?php echo $competition['id']; ?>" class="btn btn-view btn-sm"><i class="fas fa-eye"></i> View Universities</a>
                                    <button onclick="openEditCompetitionModal(<?php echo $competition['id']; ?>)" class="btn btn-edit btn-sm"><i class="fas fa-pencil"></i> Edit</button>
                                    <?php if ($_SESSION['role'] === 'admin'): ?>
                                        <a href="athletes.php?delete_competition=<?php echo $competition['id']; ?>"
                                            class="btn btn-delete btn-sm"
                                            onclick="return confirm('⚠️ WARNING: Are you sure you want to delete this competition?\n\nThis will permanently delete:\n- All universities under this competition\n- All sports under those universities\n- All athletes in those sports\n- All uploaded files and folders\n\nThis action CANNOT be undone!')">
                                            <i class="fas fa-trash"></i> Delete
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

            <?php elseif (!isset($_GET['university_id'])): ?>
                <!-- University List View for Selected Competition -->
                <div class="page-title">
                    <span><?php echo htmlspecialchars($selectedCompetition['name'] . ' ' . $selectedCompetition['year']); ?> - Universities</span>
                    <button onclick="openAddUniversityModal(<?php echo $selectedCompetition['id']; ?>)" class="btn btn-primary"><i class="fas fa-plus"></i> Add University</button>
                </div>

                <?php
                // Calculate statistics
                $totalUniversities = count($competitionUniversities);
                $totalAthletesInCompetition = 0;
                $totalSportsInCompetition = 0;
                $totalMaleAthletes = 0;
                $totalFemaleAthletes = 0;
                $totalMixedAthletes = 0;

                foreach ($competitionUniversities as $university) {
                    $totalAthletesInCompetition += $university['total_athletes'] ?? 0;

                    // Get sport count for this university
                    $sportCountStmt = $pdo->prepare("SELECT COUNT(*) FROM competition_sports WHERE university_id = ?");
                    $sportCountStmt->execute([$university['id']]);
                    $totalSportsInCompetition += $sportCountStmt->fetchColumn();

                    // Get gender counts for athletes in this university
                    $genderStmt = $pdo->prepare("
                        SELECT a.gender, COUNT(*) as count
                        FROM athletes a
                        JOIN competition_sports cs ON a.competition_sport_id = cs.id
                        WHERE cs.university_id = ?
                        GROUP BY a.gender
                    ");
                    $genderStmt->execute([$university['id']]);
                    $genderCounts = $genderStmt->fetchAll();

                    foreach ($genderCounts as $genderCount) {
                        $gender = strtolower($genderCount['gender']);
                        $count = $genderCount['count'];

                        if ($gender === 'male') {
                            $totalMaleAthletes += $count;
                        } elseif ($gender === 'female') {
                            $totalFemaleAthletes += $count;
                        } else {
                            $totalMixedAthletes += $count;
                        }
                    }
                }

                $avgAthletesPerUniversity = $totalUniversities > 0 ? round($totalAthletesInCompetition / $totalUniversities, 1) : 0;
                $avgSportsPerUniversity = $totalUniversities > 0 ? round($totalSportsInCompetition / $totalUniversities, 1) : 0;
                ?>

                <!-- Summary Stats -->
                <div style="display: flex; justify-content: flex-end; gap: 15px; margin-bottom: 25px; flex-wrap: wrap;">
                    <div style="background: white; padding: 12px 18px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); min-width: 130px;">
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 5px;">
                            <i class="fas fa-university" style="color: #0c3a1d; font-size: 18px;"></i>
                            <div style="font-size: 13px; color: #666;">Universities</div>
                        </div>
                        <div style="font-size: 24px; font-weight: 700; color: #0c3a1d; text-align: center;"><?php echo $totalUniversities; ?></div>
                    </div>

                    <div style="background: white; padding: 12px 18px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); min-width: 130px;">
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 5px;">
                            <i class="fas fa-users" style="color: #0c3a1d; font-size: 18px;"></i>
                            <div style="font-size: 13px; color: #666;">Total Athletes</div>
                        </div>
                        <div style="font-size: 24px; font-weight: 700; color: #0c3a1d; text-align: center;"><?php echo $totalAthletesInCompetition; ?></div>
                    </div>

                    <div style="background: white; padding: 12px 18px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); min-width: 130px;">
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 5px;">
                            <i class="fas fa-mars" style="color: #2196F3; font-size: 18px;"></i>
                            <div style="font-size: 13px; color: #666;">Male</div>
                        </div>
                        <div style="font-size: 24px; font-weight: 700; color: #2196F3; text-align: center;"><?php echo $totalMaleAthletes; ?></div>
                    </div>

                    <div style="background: white; padding: 12px 18px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); min-width: 130px;">
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 5px;">
                            <i class="fas fa-venus" style="color: #E91E63; font-size: 18px;"></i>
                            <div style="font-size: 13px; color: #666;">Female</div>
                        </div>
                        <div style="font-size: 24px; font-weight: 700; color: #E91E63; text-align: center;"><?php echo $totalFemaleAthletes; ?></div>
                    </div>

                    <?php if ($totalMixedAthletes > 0): ?>
                        <div style="background: white; padding: 12px 18px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); min-width: 130px;">
                            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 5px;">
                                <i class="fas fa-venus-mars" style="color: #9C27B0; font-size: 18px;"></i>
                                <div style="font-size: 13px; color: #666;">Mixed/Other</div>
                            </div>
                            <div style="font-size: 24px; font-weight: 700; color: #9C27B0; text-align: center;"><?php echo $totalMixedAthletes; ?></div>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (empty($competitionUniversities)): ?>
                    <div class="empty-state">
                        <h3>No universities added to this competition</h3>
                        <p>Add universities to start managing sports and athletes</p>
                        <button onclick="openAddUniversityModal(<?php echo $selectedCompetition['id']; ?>)" class="btn btn-primary" style="margin-top: 20px;">
                            ➕ Add First University
                        </button>
                    </div>
                <?php else: ?>
                    <div class="universities-grid">
                        <?php foreach ($competitionUniversities as $university): ?>
                            <div class="university-card">
                                <div class="university-header">
                                    <div class="university-name"><?php echo htmlspecialchars($university['name']); ?></div>
                                    <div class="university-code"><?php echo htmlspecialchars($university['code'] ?? 'No code'); ?></div>
                                    <div class="university-description"><?php echo htmlspecialchars($university['description'] ?? 'No description'); ?></div>
                                </div>
                                <div style="padding: 15px; background: #f8f9fa; border-bottom: 1px solid #e0e0e0;">
                                    <div style="display: flex; align-items: center; justify-content: space-between;">
                                        <span style="font-weight: 600; color: #0c3a1d;">
                                            <i class="fas fa-users"></i> Total Athletes:
                                        </span>
                                        <span style="font-size: 24px; font-weight: 700; color: #1a5c2f;">
                                            <?php echo $university['total_athletes'] ?? 0; ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="university-actions">
                                    <a href="athletes.php?competition_id=<?php echo $selectedCompetition['id']; ?>&university_id=<?php echo $university['id']; ?>"
                                        class="btn btn-view btn-sm"><i class="fas fa-eye"></i> View Sports</a>
                                    <button onclick="openEditUniversityModal(<?php echo $university['id']; ?>)" class="btn btn-edit btn-sm"><i class="fas fa-pencil"></i> Edit</button>
                                    <?php if ($_SESSION['role'] === 'admin'): ?>
                                        <a href="athletes.php?delete_university=<?php echo $university['id']; ?>&competition_id=<?php echo $selectedCompetition['id']; ?>"
                                            class="btn btn-delete btn-sm"
                                            onclick="return confirm('⚠️ WARNING: Are you sure you want to delete this university?\n\nThis will permanently delete:\n- All sports under this university\n- All athletes in those sports\n- All uploaded files and folders\n\nThis action CANNOT be undone!')">
                                            <i class="fas fa-trash"></i> Delete
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>


            <?php elseif (!isset($_GET['sport_id'])): ?>
                <!-- Sport List View for Selected University -->
                <div class="page-title">
                    <span><?php echo htmlspecialchars($selectedUniversity['name']); ?> - Sports</span>
                    <button onclick="openAddSportModal(<?php echo $selectedCompetition['id']; ?>, <?php echo $selectedUniversity['id']; ?>)" class="btn btn-primary"><i class="fas fa-plus"></i> Add Sport</button>
                </div>

                <?php
                // Calculate statistics for THIS UNIVERSITY ONLY
                $totalSportsInUniversity = count($universitySports);
                $totalAthletesInUniversity = 0;
                $totalMaleAthletes = 0;
                $totalFemaleAthletes = 0;
                $totalMixedAthletes = 0;

                // Get gender counts for athletes in this university
                $genderStmt = $pdo->prepare("
                        SELECT a.gender, COUNT(*) as count
                        FROM athletes a
                        JOIN competition_sports cs ON a.competition_sport_id = cs.id
                        WHERE cs.university_id = ?
                        GROUP BY a.gender
                    ");
                $genderStmt->execute([$selectedUniversity['id']]);
                $genderCounts = $genderStmt->fetchAll();

                foreach ($genderCounts as $genderCount) {
                    $gender = strtolower($genderCount['gender']);
                    $count = $genderCount['count'];

                    $totalAthletesInUniversity += $count;

                    if ($gender === 'male') {
                        $totalMaleAthletes += $count;
                    } elseif ($gender === 'female') {
                        $totalFemaleAthletes += $count;
                    } else {
                        $totalMixedAthletes += $count;
                    }
                }
                ?>

                <!-- Sport Search Bar -->
                <div class="search-container">
                    <form method="GET" action="" style="display: flex; gap: 15px; width: 100%; flex-wrap: wrap;">
                        <input type="hidden" name="competition_id" value="<?php echo $selectedCompetition['id']; ?>">
                        <input type="hidden" name="university_id" value="<?php echo $selectedUniversity['id']; ?>">

                        <div class="search-box">
                            <input type="text" name="sport_search" placeholder="Search Sports..."
                                value="<?php echo htmlspecialchars($sportSearchQuery); ?>" autocomplete="off">
                            <button type="submit" class="btn btn-search"><i class="fas fa-magnifying-glass"></i> Search</button>
                            <?php if (!empty($sportSearchQuery)): ?>
                                <a href="athletes.php?competition_id=<?php echo $selectedCompetition['id']; ?>&university_id=<?php echo $selectedUniversity['id']; ?>"
                                    class="btn btn-clear">✖ Clear</a>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($sportSearchQuery)): ?>
                        <?php endif; ?>
                    </form>
                </div>

                <!-- Summary Stats -->
                <div style="display: flex; justify-content: flex-end; gap: 15px; margin-bottom: 25px; flex-wrap: wrap;">
                    <div style="background: white; padding: 12px 18px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); min-width: 130px;">
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 5px;">
                            <i class="fas fa-medal" style="color: #9c27b0; font-size: 18px;"></i>
                            <div style="font-size: 13px; color: #666;">Total Sports</div>
                        </div>
                        <div style="font-size: 24px; font-weight: 700; color: #9c27b0; text-align: center;"><?php echo $totalSportsInUniversity; ?></div>
                    </div>

                    <div style="background: white; padding: 12px 18px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); min-width: 130px;">
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 5px;">
                            <i class="fas fa-users" style="color: #0c3a1d; font-size: 18px;"></i>
                            <div style="font-size: 13px; color: #666;">Total Athletes</div>
                        </div>
                        <div style="font-size: 24px; font-weight: 700; color: #0c3a1d; text-align: center;"><?php echo $totalAthletesInUniversity; ?></div>
                    </div>

                    <div style="background: white; padding: 12px 18px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); min-width: 130px;">
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 5px;">
                            <i class="fas fa-mars" style="color: #2196F3; font-size: 18px;"></i>
                            <div style="font-size: 13px; color: #666;">Male Athletes</div>
                        </div>
                        <div style="font-size: 24px; font-weight: 700; color: #2196F3; text-align: center;"><?php echo $totalMaleAthletes; ?></div>
                    </div>

                    <div style="background: white; padding: 12px 18px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); min-width: 130px;">
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 5px;">
                            <i class="fas fa-venus" style="color: #E91E63; font-size: 18px;"></i>
                            <div style="font-size: 13px; color: #666;">Female Athletes</div>
                        </div>
                        <div style="font-size: 24px; font-weight: 700; color: #E91E63; text-align: center;"><?php echo $totalFemaleAthletes; ?></div>
                    </div>

                    <?php if ($totalMixedAthletes > 0): ?>
                        <div style="background: white; padding: 12px 18px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); min-width: 130px;">
                            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 5px;">
                                <i class="fas fa-venus-mars" style="color: #9C27B0; font-size: 18px;"></i>
                                <div style="font-size: 13px; color: #666;">Mixed/Other</div>
                            </div>
                            <div style="font-size: 24px; font-weight: 700; color: #9C27B0; text-align: center;"><?php echo $totalMixedAthletes; ?></div>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (empty($universitySports)): ?>
                    <div class="empty-state">
                        <h3>No sports added to <?php echo htmlspecialchars($selectedUniversity['name']); ?></h3>
                        <p>Add sports to start managing athletes</p>
                        <button onclick="openAddSportModal(<?php echo $selectedCompetition['id']; ?>, <?php echo $selectedUniversity['id']; ?>)" class="btn btn-primary" style="margin-top: 20px;">
                            ➕ Add First Sport
                        </button>
                    </div>
                <?php elseif (empty($filteredUniversitySports)): ?>
                    <div class="empty-state">
                        <h3>No sports found matching "<?php echo htmlspecialchars($sportSearchQuery); ?>"</h3>
                        <p>Try different search terms or clear the search</p>
                        <a href="athletes.php?competition_id=<?php echo $selectedCompetition['id']; ?>&university_id=<?php echo $selectedUniversity['id']; ?>"
                            class="btn btn-primary" style="margin-top: 20px;">
                            Clear Search
                        </a>
                    </div>
                <?php else: ?>
                    <div class="sports-grid">
                        <?php foreach ($filteredUniversitySports as $sport): ?>
                            <div class="sport-card">
                                <div class="sport-header">
                                    <div class="sport-name"><?php echo htmlspecialchars($sport['sport_name']); ?></div>
                                </div>
                                <div class="sport-details">
                                    Gender: <?php echo htmlspecialchars($sport['gender']); ?><br>
                                    <strong>Total Players: <?php echo $sport['athlete_count'] ?? 0; ?></strong><br>
                                    Status: <span style="color: <?php echo $sport['status'] === 'active' ? '#0c3a1d' : '#dc3545'; ?>">
                                        <?php echo ucfirst($sport['status']); ?>
                                    </span>
                                </div>
                                <div class="sport-actions" style="display: flex; gap: 5px; flex-wrap: wrap;">
                                    <a href="athletes.php?competition_id=<?php echo $selectedCompetition['id']; ?>&university_id=<?php echo $selectedUniversity['id']; ?>&sport_id=<?php echo $sport['id']; ?>&sport_search=<?php echo urlencode($sportSearchQuery); ?>"
                                        class="btn btn-view btn-sm"><i class="fas fa-eye"></i> View Athletes</a>
                                    <button onclick="openEditSportModal(<?php echo $sport['id']; ?>)" class="btn btn-edit btn-sm"><i class="fas fa-pencil"></i> Edit</button>
                                    <?php if ($_SESSION['role'] === 'admin'): ?>
                                        <a href="athletes.php?delete_sport=<?php echo $sport['id']; ?>&competition_id=<?php echo $selectedCompetition['id']; ?>&university_id=<?php echo $selectedUniversity['id']; ?>"
                                            class="btn btn-delete btn-sm"
                                            onclick="return confirm('⚠️ WARNING: Are you sure you want to delete this sport?\n\nThis will permanently delete:\n- All athletes in this sport\n- All uploaded files and folders\n\nThis action CANNOT be undone!')">
                                            <i class="fas fa-trash"></i> Delete
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if (!empty($sportSearchQuery)): ?>
                        <div style="margin-top: 20px; text-align: center; color: #666;">
                            Showing <?php echo count($filteredUniversitySports); ?> of <?php echo count($universitySports); ?> total sports
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

            <?php else: ?>
                <!-- Athlete List View for Selected Sport -->
                <?php
                $currentSport = null;
                foreach ($universitySports as $sport) {
                    if ($sport['id'] == $_GET['sport_id']) {
                        $currentSport = $sport;
                        break;
                    }
                }
                ?>

                <?php if ($currentSport): ?>
                    <div class="page-title">
                        <span><?php echo htmlspecialchars($currentSport['sport_name']); ?> - Athletes (<?php echo htmlspecialchars($selectedUniversity['name']); ?>)</span>
                        <div style="display: flex; gap: 10px;">
                            <?php if (!empty($allSports) && $_SESSION['role'] === 'admin'): ?>
                                <button onclick="openBulkCopyModal()" class="btn btn-copy" id="bulkCopyBtn" style="display: none;">
                                    <i class="fas fa-copy"></i> Bulk Copy (<span id="selectedCount">0</span>)
                                </button>
                            <?php endif; ?>
                            <a href="add_athlete.php?competition_sport_id=<?php echo $currentSport['id']; ?>" class="btn btn-primary"><i class="fas fa-plus"></i> Add Athlete</a>
                        </div>
                    </div>

                    <!-- Search Bar for Athletes -->
                    <div class="search-container">
                        <form method="GET" action="" style="display: flex; gap: 15px; width: 100%; flex-wrap: wrap;">
                            <input type="hidden" name="competition_id" value="<?php echo $selectedCompetition['id']; ?>">
                            <input type="hidden" name="university_id" value="<?php echo $selectedUniversity['id']; ?>">
                            <input type="hidden" name="sport_id" value="<?php echo $currentSport['id']; ?>">
                            <?php if (!empty($sportSearchQuery)): ?>
                                <input type="hidden" name="sport_search" value="<?php echo htmlspecialchars($sportSearchQuery); ?>">
                            <?php endif; ?>

                            <div class="search-box">
                                <input type="text" name="search" placeholder="Search by ID, name, course, email..."
                                    value="<?php echo htmlspecialchars($searchQuery); ?>" autocomplete="off">
                                <button type="submit" class="btn btn-search"><i class="fas fa-magnifying-glass"></i> Search</button>
                                <?php if (!empty($searchQuery)): ?>
                                    <a href="athletes.php?competition_id=<?php echo $selectedCompetition['id']; ?>&university_id=<?php echo $selectedUniversity['id']; ?>&sport_id=<?php echo $currentSport['id']; ?><?php echo !empty($sportSearchQuery) ? '&sport_search=' . urlencode($sportSearchQuery) : ''; ?>"
                                        class="btn btn-clear">✖ Clear</a>
                                <?php endif; ?>
                            </div>

                            <?php if (!empty($searchQuery)): ?>
                                <div class="search-stats">
                                    Found <?php echo count($competitionAthletes); ?> result(s) for "<?php echo htmlspecialchars($searchQuery); ?>"
                                </div>
                            <?php endif; ?>
                        </form>
                    </div>

                    <?php if (empty($competitionAthletes)): ?>
                        <div class="empty-state">
                            <?php if (!empty($searchQuery)): ?>
                                <h3>No athletes found matching "<?php echo htmlspecialchars($searchQuery); ?>"</h3>
                                <p>Try different search terms or clear the search</p>
                                <a href="athletes.php?competition_id=<?php echo $selectedCompetition['id']; ?>&university_id=<?php echo $selectedUniversity['id']; ?>&sport_id=<?php echo $currentSport['id']; ?><?php echo !empty($sportSearchQuery) ? '&sport_search=' . urlencode($sportSearchQuery) : ''; ?>"
                                    class="btn btn-primary" style="margin-top: 20px;">
                                    Clear Search
                                </a>
                            <?php else: ?>
                                <h3>No athletes added to this sport</h3>
                                <p>Add athletes to start managing profiles</p>
                                <a href="add_athlete.php?competition_sport_id=<?php echo $currentSport['id']; ?>" class="btn btn-primary" style="margin-top: 20px;">
                                    ➕ Add First Athlete
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <!-- Add Select All Controls -->
                        <div style="margin-bottom: 15px; display: flex; align-items: center; gap: 20px; background: #f8f9fa; padding: 10px 15px; border-radius: 8px;">
                            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                <input type="checkbox" id="selectAllCheckbox" onclick="toggleSelectAll(this)">
                                <strong>Select All</strong>
                            </label>
                            <span style="color: #666;">
                                <span id="selectedCountDisplay">0</span> of <?php echo count($competitionAthletes); ?> selected
                            </span>
                            <button onclick="clearSelections()" class="btn btn-sm" style="background: #6c757d; color: white;">Clear Selection</button>
                        </div>

                        <table class="athletes-table">
                            <thead>
                                <th style="width: 40px;">Select</th>
                                <th>Student ID</th>
                                <th>Name</th>
                                <th>Contact</th>
                                <th>Documents Status</th>
                                <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($competitionAthletes as $athlete): ?>
                                    <?php
                                    // Document status calculation
                                    $docStatus = 0;
                                    $group1Count = 0;
                                    $group2Count = 0;

                                    if (!empty($athlete['birth_certificate_status']) && $athlete['birth_certificate_status'] == 1) {
                                        $docStatus++;
                                        $group1Count++;
                                    }
                                    if (!empty($athlete['eligibility_form_status']) && $athlete['eligibility_form_status'] == 1) {
                                        $docStatus++;
                                        $group1Count++;
                                    }
                                    if (!empty($athlete['cor_status']) && $athlete['cor_status'] == 1) {
                                        $docStatus++;
                                        $group2Count++;
                                    }
                                    if (!empty($athlete['tor_status']) && $athlete['tor_status'] == 1) {
                                        $docStatus++;
                                        $group2Count++;
                                    }
                                    if (!empty($athlete['photo_status']) && $athlete['photo_status'] == 1) {
                                        $docStatus++;
                                    }

                                    $statusColor = '#dc3545';
                                    $statusText = 'Incomplete';
                                    if ($docStatus >= 5) {
                                        $statusColor = '#0c3a1d';
                                        $statusText = 'Complete';
                                    } elseif ($docStatus >= 2) {
                                        $statusColor = '#ffc107';
                                        $statusText = 'Partial';
                                    } elseif ($docStatus >= 1) {
                                        $statusColor = '#ff9800';
                                        $statusText = 'Minimal';
                                    }

                                    $tooltipText = "📋 Document Status:\n";
                                    $tooltipText .= "Documents 1 (Personal): {$group1Count}/2\n";
                                    $tooltipText .= "Documents 2 (Academic): {$group2Count}/2\n";
                                    $tooltipText .= "Photo: " . (!empty($athlete['photo_status']) && $athlete['photo_status'] == 1 ? '✓' : '✗') . "\n\n";
                                    $checkedDocs = [];
                                    if (!empty($athlete['birth_certificate_status']) && $athlete['birth_certificate_status'] == 1) $checkedDocs[] = "PSA Birth Certificate";
                                    if (!empty($athlete['eligibility_form_status']) && $athlete['eligibility_form_status'] == 1) $checkedDocs[] = "Eligibility Form";
                                    if (!empty($athlete['cor_status']) && $athlete['cor_status'] == 1) $checkedDocs[] = "COR";
                                    if (!empty($athlete['tor_status']) && $athlete['tor_status'] == 1) $checkedDocs[] = "TOR";
                                    if (!empty($athlete['photo_status']) && $athlete['photo_status'] == 1) $checkedDocs[] = "2x2 Photo";
                                    $tooltipText .= empty($checkedDocs) ? 'No documents checked' : '✓ ' . implode("\n✓ ", $checkedDocs);
                                    ?>
                                    <tr>
                                        <td style="text-align: center;">
                                            <input type="checkbox" class="athlete-select" value="<?php echo $athlete['id']; ?>"
                                                data-name="<?php echo htmlspecialchars($athlete['last_name'] . ', ' . $athlete['first_name']); ?>"
                                                data-student-id="<?php echo htmlspecialchars($athlete['student_id']); ?>"
                                                onchange="updateSelectedCount()">
                                        </td>
                                        <td><strong><?php echo htmlspecialchars($athlete['student_id']); ?></strong></td>
                                        <td>
                                            <?php echo htmlspecialchars($athlete['last_name'] . ', ' . $athlete['first_name']); ?>
                                            <?php if (!empty($athlete['middle_initial'])): ?>
                                                <?php echo ' ' . htmlspecialchars($athlete['middle_initial']); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($athlete['contact_number']); ?><br>
                                            <small><?php echo htmlspecialchars($athlete['email']); ?></small>
                                        </td>
                                        <td>
                                            <div class="status-tooltip" style="display: flex; flex-direction: column; gap: 5px;">
                                                <div style="display: flex; align-items: center; gap: 10px;">
                                                    <span style="color: <?php echo $statusColor; ?>; font-weight: 600;">
                                                        <i class="fas fa-file"></i> <?php echo $docStatus; ?>/5
                                                    </span>
                                                    <span style="font-size: 11px; padding: 2px 8px; background: <?php echo $statusColor; ?>20; color: <?php echo $statusColor; ?>; border-radius: 12px;">
                                                        <?php echo $statusText; ?>
                                                    </span>
                                                </div>
                                                <div style="display: flex; gap: 15px; font-size: 11px;">
                                                    <span title="Documents 1: Birth Cert, Eligibility">
                                                        Docs1: <strong style="color: <?php echo $group1Count == 2 ? '#0c3a1d' : ($group1Count > 0 ? '#ffc107' : '#dc3545'); ?>"><?php echo $group1Count; ?>/2</strong>
                                                    </span>
                                                    <span title="Documents 2: COR, TOR">
                                                        Docs2: <strong style="color: <?php echo $group2Count == 2 ? '#0c3a1d' : ($group2Count > 0 ? '#ffc107' : '#dc3545'); ?>"><?php echo $group2Count; ?>/2</strong>
                                                    </span>
                                                    <span title="2x2 Photo">
                                                        Photo: <strong style="color: <?php echo (!empty($athlete['photo_status']) && $athlete['photo_status'] == 1) ? '#0c3a1d' : '#dc3545'; ?>"><?php echo (!empty($athlete['photo_status']) && $athlete['photo_status'] == 1) ? '✓' : '✗'; ?></strong>
                                                    </span>
                                                </div>
                                                <div class="tooltip-content">
                                                    <?php echo nl2br(htmlspecialchars($tooltipText)); ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="actions" style="display: flex; gap: 5px; flex-wrap: wrap;">
                                                <a href="view_athlete.php?id=<?php echo $athlete['id']; ?>" class="btn btn-view btn-sm"><i class="fas fa-eye"></i> View</a>
                                                <a href="add_athlete.php?id=<?php echo $athlete['id']; ?>" class="btn btn-edit btn-sm"><i class="fas fa-pencil"></i> Edit</a>
                                                <?php if (!empty($allSports) && $_SESSION['role'] === 'admin'): ?>
                                                    <?php
                                                    $safeAthleteName = htmlspecialchars($athlete['last_name'] . ', ' . $athlete['first_name'], ENT_QUOTES);
                                                    $safeStudentId = htmlspecialchars($athlete['student_id'], ENT_QUOTES);
                                                    $currentSportName = $currentSport['sport_name'] ?? '';
                                                    $competitionName = $selectedCompetition['name'] ?? '';
                                                    $universityName = $selectedUniversity['name'] ?? '';
                                                    $year = $selectedCompetition['year'] ?? '';
                                                    $currentSportInfo = $currentSportName . ' (' . $universityName . ' - ' . $competitionName . ' ' . $year . ')';
                                                    $safeSportInfo = htmlspecialchars($currentSportInfo, ENT_QUOTES);
                                                    ?>
                                                    <button onclick="openCopyAthleteModal(<?php echo $athlete['id']; ?>, '<?php echo $safeAthleteName; ?>', '<?php echo $safeStudentId; ?>', '<?php echo $safeSportInfo; ?>', <?php echo $selectedCompetition['id']; ?>, <?php echo $selectedUniversity['id']; ?>, <?php echo $currentSport['id']; ?>)" class="btn btn-copy btn-sm"><i class="fas fa-copy"></i> Copy</button>
                                                <?php endif; ?>
                                                <?php if ($_SESSION['role'] === 'admin'): ?>
                                                    <a href="athletes.php?delete=<?php echo $athlete['id']; ?>&competition_id=<?php echo isset($selectedCompetition['id']) ? $selectedCompetition['id'] : 0; ?>&university_id=<?php echo isset($selectedUniversity['id']) ? $selectedUniversity['id'] : 0; ?>&sport_id=<?php echo isset($currentSport['id']) ? $currentSport['id'] : 0; ?>"
                                                        class="btn btn-delete btn-sm"
                                                        onclick="return confirm('⚠️ WARNING: Are you sure you want to delete this athlete?\n\nThis will permanently delete:\n- All athlete information\n- All uploaded documents\n- Athlete folder\n\nThis action CANNOT be undone!')">
                                                        <i class="fas fa-trash"></i> Delete
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <div style="margin-top: 20px; text-align: center; color: #666;">
                            Showing <?php echo count($competitionAthletes); ?> athlete(s) in <?php echo htmlspecialchars($currentSport['sport_name']); ?> (<?php echo htmlspecialchars($selectedUniversity['name']); ?>)
                            <?php if (!empty($searchQuery)): ?>
                                matching "<?php echo htmlspecialchars($searchQuery); ?>"
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="alert alert-error">Sport not found!</div>
                <?php endif; ?>
            <?php endif; ?>
        </main>
    </div>

    <!-- Add Competition Modal -->
    <div id="addCompetitionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add New Competition</h3>
                <button class="close-modal" onclick="closeAddCompetitionModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="competitionAlert" class="modal-alert"></div>
                <form id="addCompetitionForm">
                    <div class="form-group">
                        <label for="competition_name" class="required">Competition Name</label>
                        <input type="text" id="competition_name" name="name" required
                            placeholder="e.g., SCUAA, SUC, NCAA">
                    </div>

                    <div class="form-group">
                        <label for="competition_year" class="required">Year</label>
                        <input type="text" id="competition_year" name="year" required
                            placeholder="e.g., 2026" maxlength="4"
                            pattern="\d{4}">
                    </div>

                    <div class="form-group">
                        <label for="competition_description">Description</label>
                        <textarea id="competition_description" name="description"
                            placeholder="Optional description of the competition"
                            rows="4"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeAddCompetitionModal()">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="submitCompetitionForm()">➕ Add Competition</button>
            </div>
        </div>
    </div>

    <!-- Edit Competition Modal -->
    <div id="editCompetitionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Competition</h3>
                <button class="close-modal" onclick="closeEditCompetitionModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="editCompetitionAlert" class="modal-alert"></div>
                <form id="editCompetitionForm">
                    <input type="hidden" id="edit_competition_id" name="id">

                    <div class="form-group">
                        <label for="edit_competition_name" class="required">Competition Name</label>
                        <input type="text" id="edit_competition_name" name="name" required
                            placeholder="e.g., SCUAA, SUC, NCAA">
                    </div>

                    <div class="form-group">
                        <label for="edit_competition_year" class="required">Year</label>
                        <input type="text" id="edit_competition_year" name="year" required
                            placeholder="e.g., 2026" maxlength="4"
                            pattern="\d{4}">
                    </div>

                    <div class="form-group">
                        <label for="edit_competition_description">Description</label>
                        <textarea id="edit_competition_description" name="description"
                            placeholder="Optional description of the competition"
                            rows="4"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeEditCompetitionModal()">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="submitEditCompetitionForm()">💾 Update Competition</button>
            </div>
        </div>
    </div>

    <!-- Add University Modal -->
    <div id="addUniversityModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add University to Competition</h3>
                <button class="close-modal" onclick="closeAddUniversityModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="universityAlert" class="modal-alert"></div>
                <form id="addUniversityForm">
                    <input type="hidden" id="university_competition_id" name="competition_id">

                    <div class="form-group">
                        <label for="university_name" class="required">University Name</label>
                        <input type="text" id="university_name" name="name" required
                            placeholder="e.g., Tarlac Agricultural University">
                    </div>

                    <div class="form-group">
                        <label for="university_code">University Code</label>
                        <input type="text" id="university_code" name="code"
                            placeholder="e.g., TAU, UP, DLSU">
                    </div>

                    <div class="form-group">
                        <label for="university_description">Description</label>
                        <textarea id="university_description" name="description"
                            placeholder="Optional description of the university"
                            rows="4"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeAddUniversityModal()">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="submitUniversityForm()">➕ Add University</button>
            </div>
        </div>
    </div>

    <!-- Edit University Modal -->
    <div id="editUniversityModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit University</h3>
                <button class="close-modal" onclick="closeEditUniversityModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="editUniversityAlert" class="modal-alert"></div>
                <form id="editUniversityForm">
                    <input type="hidden" id="edit_university_id" name="id">

                    <div class="form-group">
                        <label for="edit_university_name" class="required">University Name</label>
                        <input type="text" id="edit_university_name" name="name" required
                            placeholder="e.g., Tarlac Agricultural University">
                    </div>

                    <div class="form-group">
                        <label for="edit_university_code">University Code</label>
                        <input type="text" id="edit_university_code" name="code"
                            placeholder="e.g., TAU, UP, DLSU">
                    </div>

                    <div class="form-group">
                        <label for="edit_university_description">Description</label>
                        <textarea id="edit_university_description" name="description"
                            placeholder="Optional description of the university"
                            rows="4"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeEditUniversityModal()">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="submitEditUniversityForm()">💾 Update University</button>
            </div>
        </div>
    </div>

    <!-- Add Sport Modal -->
    <div id="addSportModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add Sport to University</h3>
                <button class="close-modal" onclick="closeAddSportModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="sportAlert" class="modal-alert"></div>
                <form id="addSportForm">
                    <input type="hidden" id="sport_competition_id" name="competition_id">
                    <input type="hidden" id="sport_university_id" name="university_id">

                    <div class="form-group">
                        <label for="sport_name" class="required">Sport Name</label>
                        <input type="text" id="sport_name" name="sport_name" required
                            placeholder="e.g., Basketball, Volleyball, Swimming">
                    </div>

                    <div class="form-group">
                        <label for="sport_gender" class="required">Gender Category</label>
                        <select id="sport_gender" name="gender" required>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                            <option value="Mixed">Mixed</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="sport_status" class="required">Status</label>
                        <select id="sport_status" name="status" required>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeAddSportModal()">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="submitSportForm()">➕ Add Sport</button>
            </div>
        </div>
    </div>

    <!-- Edit Sport Modal -->
    <div id="editSportModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Sport</h3>
                <button class="close-modal" onclick="closeEditSportModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="editSportAlert" class="modal-alert"></div>
                <form id="editSportForm">
                    <input type="hidden" id="edit_sport_id" name="id">

                    <div class="form-group">
                        <label for="edit_sport_name" class="required">Sport Name</label>
                        <input type="text" id="edit_sport_name" name="sport_name" required
                            placeholder="e.g., Basketball, Volleyball, Swimming">
                    </div>

                    <div class="form-group">
                        <label for="edit_sport_gender" class="required">Gender Category</label>
                        <select id="edit_sport_gender" name="gender" required>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                            <option value="Mixed">Mixed</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="edit_sport_status" class="required">Status</label>
                        <select id="edit_sport_status" name="status" required>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeEditSportModal()">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="submitEditSportForm()">💾 Update Sport</button>
            </div>
        </div>
    </div>

    <!-- Copy Athlete Modal with Cascading Selection -->
    <div id="copyAthleteModal" class="modal">
        <div class="modal-content" style="max-width: 700px;">
            <div class="modal-header">
                <h3>Copy Athlete to Another Sport</h3>
                <button class="close-modal" onclick="closeCopyAthleteModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="copyAlert" class="modal-alert"></div>

                <div class="copy-athlete-info">
                    <h4>Copying Athlete:</h4>
                    <div class="info-row">
                        <span class="info-label">Name:</span>
                        <span id="copyAthleteName"></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Student ID:</span>
                        <span id="copyAthleteId"></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Current Sport:</span>
                        <span id="copyCurrentSport"></span>
                    </div>
                </div>

                <form id="copyAthleteForm" method="POST" action="athletes.php">
                    <input type="hidden" name="copy_athlete_action" value="copy">
                    <input type="hidden" name="source_athlete_id" id="copyAthleteSourceId">
                    <input type="hidden" name="current_competition_id" id="currentCompetitionId">
                    <input type="hidden" name="current_university_id" id="currentUniversityId">
                    <input type="hidden" name="current_sport_id" id="currentSportId">
                    <input type="hidden" name="target_sport_id" id="targetSportId" value="">

                    <div class="form-group">
                        <label class="required">Select Target Sport:</label>

                        <!-- Display selected sport after confirmation -->
                        <div id="selectedSportDisplay" style="display: none;" class="selected-sport-badge">
                            <div>
                                <i class="fas fa-check-circle" style="color: #0c3a1d;"></i>
                                <strong id="selectedSportName"></strong><br>
                                <small id="selectedSportDetails" style="color: #666;"></small>
                            </div>
                            <button type="button" class="change-sport-btn" onclick="openSportSelectionModal()">
                                <i class="fas fa-pencil"></i> Change
                            </button>
                        </div>

                        <!-- Button to open sport selection modal -->
                        <button type="button" id="selectSportBtn" class="btn btn-primary" style="width: 100%;" onclick="openSportSelectionModal()">
                            <i class="fas fa-search"></i> Select Target Sport
                        </button>
                    </div>
                </form>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeCopyAthleteModal()">Cancel</button>
                <button type="button" class="btn btn-copy" onclick="submitCopyAthlete()" id="confirmCopyBtn" disabled>📋 Copy Athlete</button>
            </div>
        </div>
    </div>

    <!-- Sport Selection Modal (Cascading with University) -->
    <div id="sportSelectionModal" class="modal">
        <div class="modal-content" style="max-width: 700px;">
            <div class="modal-header">
                <h3>Select Target Sport</h3>
                <button class="close-modal" onclick="closeSportSelectionModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="sportSelectionAlert" class="modal-alert"></div>

                <!-- Competition Dropdown -->
                <div class="form-group competition-selector">
                    <label for="competitionSelector" class="required">Select Competition</label>
                    <select id="competitionSelector" class="form-control" onchange="loadUniversitiesByCompetition()">
                        <option value="">-- Select a competition --</option>
                    </select>
                </div>

                <!-- University Dropdown (initially hidden) -->
                <div class="form-group competition-selector" id="universityContainer" style="display: none;">
                    <label for="universitySelector" class="required">Select University</label>
                    <select id="universitySelector" class="form-control" onchange="loadSportsByUniversity()">
                        <option value="">-- Select a university --</option>
                    </select>
                </div>

                <!-- Loading Indicator -->
                <div id="sportsLoading" class="loading-spinner" style="display: none;">
                    <i class="fas fa-spinner fa-spin"></i> Loading sports...
                </div>

                <!-- Sports Container -->
                <div id="sportsContainer" style="display: none;">
                    <div class="form-group">
                        <label class="required">Select Sport</label>

                        <!-- Search Box for Sports -->
                        <div class="sport-search-box">
                            <input type="text" id="sportSearchInput" placeholder="Search sports by name, or gender..." onkeyup="filterSportsList()">
                        </div>

                        <!-- Sports List -->
                        <div id="sportsList" class="sports-container">
                            <!-- Sports will be loaded here dynamically -->
                        </div>
                    </div>
                </div>

                <!-- No Competition Selected Message -->
                <div id="noCompetitionMessage" class="no-results" style="display: none; text-align: center; padding: 40px;">
                    <i class="fas fa-trophy" style="font-size: 48px; color: #ccc;"></i>
                    <p>Please select a competition to continue.</p>
                </div>

                <!-- No University Selected Message -->
                <div id="noUniversityMessage" class="no-results" style="display: none; text-align: center; padding: 40px;">
                    <i class="fas fa-university" style="font-size: 48px; color: #ccc;"></i>
                    <p>Please select a university to view available sports.</p>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeSportSelectionModal()">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="confirmSportSelection()" id="confirmSportSelectionBtn" disabled>Confirm Selection</button>
            </div>
        </div>
    </div>

    <!-- Bulk Sport Selection Modal (Cascading with University) -->
    <div id="bulkSportSelectionModal" class="modal">
        <div class="modal-content" style="max-width: 700px;">
            <div class="modal-header">
                <h3>Select Target Sport for Bulk Copy</h3>
                <button class="close-modal" onclick="closeBulkSportSelectionModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="bulkSportSelectionAlert" class="modal-alert"></div>

                <!-- Competition Dropdown -->
                <div class="form-group competition-selector">
                    <label for="bulkCompetitionSelector" class="required">Select Competition</label>
                    <select id="bulkCompetitionSelector" class="form-control" onchange="loadBulkUniversitiesByCompetition()">
                        <option value="">-- Select a competition --</option>
                    </select>
                </div>

                <!-- University Dropdown (initially hidden) -->
                <div class="form-group competition-selector" id="bulkUniversityContainer" style="display: none;">
                    <label for="bulkUniversitySelector" class="required">Select University</label>
                    <select id="bulkUniversitySelector" class="form-control" onchange="loadBulkSportsByUniversity()">
                        <option value="">-- Select a university --</option>
                    </select>
                </div>

                <!-- Loading Indicator -->
                <div id="bulkSportsLoading" class="loading-spinner" style="display: none;">
                    <i class="fas fa-spinner fa-spin"></i> Loading sports...
                </div>

                <!-- Sports Container -->
                <div id="bulkSportsContainer" style="display: none;">
                    <div class="form-group">
                        <label class="required">Select Sport</label>

                        <!-- Search Box for Sports -->
                        <div class="sport-search-box">
                            <input type="text" id="bulkSportSearchInput" placeholder="Search sports by name or gender..." onkeyup="filterBulkSportsList()">
                        </div>

                        <!-- Sports List -->
                        <div id="bulkSportsList" class="sports-container">
                            <!-- Sports will be loaded here dynamically -->
                        </div>
                    </div>
                </div>

                <!-- No Competition Selected Message -->
                <div id="bulkNoCompetitionMessage" class="no-results" style="display: none; text-align: center; padding: 40px;">
                    <i class="fas fa-trophy" style="font-size: 48px; color: #ccc;"></i>
                    <p>Please select a competition to continue.</p>
                </div>

                <!-- No University Selected Message -->
                <div id="bulkNoUniversityMessage" class="no-results" style="display: none; text-align: center; padding: 40px;">
                    <i class="fas fa-university" style="font-size: 48px; color: #ccc;"></i>
                    <p>Please select a university to view available sports.</p>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeBulkSportSelectionModal()">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="confirmBulkSportSelection()" id="confirmBulkSportSelectionBtn" disabled>Confirm Selection</button>
            </div>
        </div>
    </div>

    <!-- Bulk Copy Athlete Modal -->
    <div id="bulkCopyModal" class="modal">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">
                <h3>Bulk Copy Athletes to Another Sport</h3>
                <button class="close-modal" onclick="closeBulkCopyModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="bulkCopyAlert" class="modal-alert" style="display: none;"></div>

                <div class="copy-athlete-info" style="margin-bottom: 20px;">
                    <h4>Selected Athletes (<span id="bulkSelectedCount">0</span>):</h4>
                    <div id="selectedAthletesList" style="max-height: 200px; overflow-y: auto; border: 1px solid #e0e0e0; border-radius: 6px; padding: 10px; margin-bottom: 15px; background: #f8f9fa;">
                        <!-- Will be populated by JavaScript -->
                    </div>
                </div>

                <form id="bulkCopyForm" method="POST" action="athletes.php">
                    <input type="hidden" name="bulk_copy_action" value="bulk_copy">
                    <input type="hidden" name="current_competition_id" id="bulkCurrentCompetitionId" value="<?php echo isset($selectedCompetition['id']) ? $selectedCompetition['id'] : ''; ?>">
                    <input type="hidden" name="current_university_id" id="bulkCurrentUniversityId" value="<?php echo isset($selectedUniversity['id']) ? $selectedUniversity['id'] : ''; ?>">
                    <input type="hidden" name="current_sport_id" id="bulkCurrentSportId" value="<?php echo isset($currentSport['id']) ? $currentSport['id'] : ''; ?>">
                    <input type="hidden" name="target_sport_id" id="bulkTargetSportId" value="">

                    <div class="form-group">
                        <label class="required">Select Target Sport:</label>

                        <!-- Display selected sport after confirmation -->
                        <div id="bulkSelectedSportDisplay" style="display: none;" class="selected-sport-badge">
                            <div>
                                <i class="fas fa-check-circle" style="color: #0c3a1d;"></i>
                                <strong id="bulkSelectedSportName"></strong><br>
                                <small id="bulkSelectedSportDetails" style="color: #666;"></small>
                            </div>
                            <button type="button" class="change-sport-btn" onclick="openBulkSportSelectionModal()">
                                <i class="fas fa-pencil"></i> Change
                            </button>
                        </div>

                        <!-- Button to open sport selection modal -->
                        <button type="button" id="bulkSelectSportBtn" class="btn btn-primary" style="width: 100%;" onclick="openBulkSportSelectionModal()">
                            <i class="fas fa-search"></i> Select Target Sport
                        </button>
                    </div>
                </form>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeBulkCopyModal()">Cancel</button>
                <button type="button" class="btn btn-copy" onclick="submitBulkCopy()" id="bulkConfirmCopyBtn" disabled>📋 Copy Selected Athletes</button>
            </div>
        </div>
    </div>

    <!-- Bulk Sport Selection Modal (Cascading) -->
    <div id="bulkSportSelectionModal" class="modal">
        <div class="modal-content" style="max-width: 700px;">
            <div class="modal-header">
                <h3>Select Target Sport for Bulk Copy</h3>
                <button class="close-modal" onclick="closeBulkSportSelectionModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="bulkSportSelectionAlert" class="modal-alert"></div>

                <!-- Competition Dropdown -->
                <div class="form-group competition-selector">
                    <label for="bulkCompetitionSelector" class="required">Select Competition</label>
                    <select id="bulkCompetitionSelector" class="form-control" onchange="loadBulkSportsByCompetition()">
                        <option value="">-- Select a competition --</option>
                    </select>
                </div>

                <!-- Loading Indicator -->
                <div id="bulkSportsLoading" class="loading-spinner" style="display: none;">
                    <i class="fas fa-spinner fa-spin"></i> Loading sports...
                </div>

                <!-- Sports Container -->
                <div id="bulkSportsContainer" style="display: none;">
                    <div class="form-group">
                        <label class="required">Select Sport</label>

                        <!-- Search Box for Sports -->
                        <div class="sport-search-box">
                            <input type="text" id="bulkSportSearchInput" placeholder="Search sports by name, university, or gender..." onkeyup="filterBulkSportsList()">
                        </div>

                        <!-- Sports List -->
                        <div id="bulkSportsList" class="sports-container">
                            <!-- Sports will be loaded here dynamically -->
                        </div>
                    </div>
                </div>

                <!-- No Competition Selected Message -->
                <div id="bulkNoCompetitionMessage" class="no-results" style="display: none; text-align: center; padding: 40px;">
                    <i class="fas fa-trophy" style="font-size: 48px; color: #ccc;"></i>
                    <p>Please select a competition to view available sports.</p>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeBulkSportSelectionModal()">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="confirmBulkSportSelection()" id="confirmBulkSportSelectionBtn" disabled>Confirm Selection</button>
            </div>
        </div>
    </div>

    <script>
        // Global variables for copy athlete
        let selectedCompetitionId = null;
        let selectedUniversityId = null;
        let selectedSportData = null;
        let currentSportSelectionCallback = null;

        // Variables for bulk copy
        let bulkSelectedCompetitionId = null;
        let bulkSelectedUniversityId = null;
        let bulkSelectedSportData = null;

        // Store all sports data for filtering
        let allSportsData = [];
        let allBulkSportsData = [];

        // Store universities data
        let universitiesData = [];
        let bulkUniversitiesData = [];

        // Load competitions for selection modal
        function loadCompetitions() {
            fetch('athletes.php?get_competitions')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const competitionSelect = document.getElementById('competitionSelector');
                        const bulkCompetitionSelect = document.getElementById('bulkCompetitionSelector');

                        competitionSelect.innerHTML = '<option value="">-- Select a competition --</option>';
                        bulkCompetitionSelect.innerHTML = '<option value="">-- Select a competition --</option>';

                        data.competitions.forEach(comp => {
                            const option = `<option value="${comp.id}">${comp.name} ${comp.year}</option>`;
                            competitionSelect.innerHTML += option;
                            bulkCompetitionSelect.innerHTML += option;
                        });
                    }
                })
                .catch(error => {
                    console.error('Error loading competitions:', error);
                });
        }

        // Load sports by competition for individual copy
        function loadSportsByCompetition() {
            const competitionId = document.getElementById('competitionSelector').value;
            const sportsContainer = document.getElementById('sportsContainer');
            const noCompetitionMessage = document.getElementById('noCompetitionMessage');
            const loadingSpinner = document.getElementById('sportsLoading');
            const sportsListDiv = document.getElementById('sportsList');
            const confirmBtn = document.getElementById('confirmSportSelectionBtn');

            if (!competitionId) {
                sportsContainer.style.display = 'none';
                noCompetitionMessage.style.display = 'block';
                confirmBtn.disabled = true;
                allSportsData = [];
                return;
            }

            selectedCompetitionId = competitionId;
            sportsContainer.style.display = 'none';
            noCompetitionMessage.style.display = 'none';
            loadingSpinner.style.display = 'block';
            confirmBtn.disabled = true;

            fetch(`athletes.php?get_sports_by_competition&competition_id=${competitionId}`)
                .then(response => response.json())
                .then(data => {
                    loadingSpinner.style.display = 'none';

                    if (data.success && data.sports.length > 0) {
                        allSportsData = data.sports;
                        displaySportsList(allSportsData);
                        sportsContainer.style.display = 'block';
                        confirmBtn.disabled = true;
                    } else {
                        sportsListDiv.innerHTML = '<div class="no-results"><i class="fas fa-futbol"></i><p>No sports available for this competition.</p></div>';
                        sportsContainer.style.display = 'block';
                        confirmBtn.disabled = true;
                    }
                })
                .catch(error => {
                    loadingSpinner.style.display = 'none';
                    console.error('Error loading sports:', error);
                    sportsListDiv.innerHTML = '<div class="no-results"><i class="fas fa-exclamation-triangle"></i><p>Error loading sports. Please try again.</p></div>';
                    sportsContainer.style.display = 'block';
                });
        }

        // Display sports list
        function displaySportsList(sports) {
            const sportsListDiv = document.getElementById('sportsList');

            if (sports.length === 0) {
                sportsListDiv.innerHTML = '<div class="no-results"><i class="fas fa-futbol"></i><p>No sports match your search.</p></div>';
                return;
            }

            let html = '';
            sports.forEach(sport => {
                const genderBadgeClass = sport.gender === 'Male' ? '👨' : (sport.gender === 'Female' ? '👩' : '👥');
                html += `
                <div class="sport-card-item" onclick="selectSportItem(${sport.id}, '${escapeHtml(sport.sport_name)}', '${escapeHtml(sport.university_name)}', '${escapeHtml(sport.gender)}')">
                    <div class="sport-card-name">${escapeHtml(sport.sport_name)}</div>
                    <div class="sport-card-details">
                        <span class="sport-card-gender">${genderBadgeClass} ${escapeHtml(sport.gender)}</span>
                        <span><i class="fas fa-tag"></i> ${escapeHtml(sport.status)}</span>
                    </div>
                </div>
            `;
            });
            sportsListDiv.innerHTML = html;
        }

        // Filter sports list
        function filterSportsList() {
            const searchTerm = document.getElementById('sportSearchInput').value.toLowerCase();
            const filtered = allSportsData.filter(sport => {
                return sport.sport_name.toLowerCase().includes(searchTerm) ||
                    sport.gender.toLowerCase().includes(searchTerm);
            });
            displaySportsList(filtered);
        }

        // Filter bulk sports list
        function filterBulkSportsList() {
            const searchTerm = document.getElementById('bulkSportSearchInput').value.toLowerCase();
            const filtered = allBulkSportsData.filter(sport => {
                return sport.sport_name.toLowerCase().includes(searchTerm) ||
                    sport.gender.toLowerCase().includes(searchTerm);
            });
            displayBulkSportsList(filtered);
        }

        // Load bulk universities by competition
        function loadBulkUniversitiesByCompetition() {
            const competitionId = document.getElementById('bulkCompetitionSelector').value;
            const universityContainer = document.getElementById('bulkUniversityContainer');
            const universitySelect = document.getElementById('bulkUniversitySelector');
            const sportsContainer = document.getElementById('bulkSportsContainer');
            const noCompetitionMessage = document.getElementById('bulkNoCompetitionMessage');
            const noUniversityMessage = document.getElementById('bulkNoUniversityMessage');
            const confirmBtn = document.getElementById('confirmBulkSportSelectionBtn');

            if (!competitionId) {
                universityContainer.style.display = 'none';
                sportsContainer.style.display = 'none';
                noCompetitionMessage.style.display = 'block';
                noUniversityMessage.style.display = 'none';
                confirmBtn.disabled = true;
                bulkSelectedCompetitionId = null;
                return;
            }

            bulkSelectedCompetitionId = competitionId;
            universityContainer.style.display = 'block';
            noCompetitionMessage.style.display = 'none';
            noUniversityMessage.style.display = 'none';
            sportsContainer.style.display = 'none';
            confirmBtn.disabled = true;

            // Reset university select
            universitySelect.innerHTML = '<option value="">-- Loading universities... --</option>';
            universitySelect.disabled = true;

            fetch(`athletes.php?get_universities_by_competition&competition_id=${competitionId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.universities.length > 0) {
                        bulkUniversitiesData = data.universities;
                        universitySelect.innerHTML = '<option value="">-- Select a university --</option>';
                        data.universities.forEach(uni => {
                            universitySelect.innerHTML += `<option value="${uni.id}">${uni.name} ${uni.code ? '(' + uni.code + ')' : ''}</option>`;
                        });
                        universitySelect.disabled = false;
                    } else {
                        universitySelect.innerHTML = '<option value="">-- No universities found --</option>';
                        universitySelect.disabled = true;
                        noUniversityMessage.style.display = 'block';
                    }
                })
                .catch(error => {
                    console.error('Error loading universities:', error);
                    universitySelect.innerHTML = '<option value="">-- Error loading universities --</option>';
                    universitySelect.disabled = true;
                });
        }

        // Load bulk sports by university
        function loadBulkSportsByUniversity() {
            const universityId = document.getElementById('bulkUniversitySelector').value;
            const sportsContainer = document.getElementById('bulkSportsContainer');
            const loadingSpinner = document.getElementById('bulkSportsLoading');
            const sportsListDiv = document.getElementById('bulkSportsList');
            const confirmBtn = document.getElementById('confirmBulkSportSelectionBtn');
            const noUniversityMessage = document.getElementById('bulkNoUniversityMessage');

            if (!universityId) {
                sportsContainer.style.display = 'none';
                noUniversityMessage.style.display = 'block';
                confirmBtn.disabled = true;
                allBulkSportsData = [];
                bulkSelectedUniversityId = null;
                return;
            }

            bulkSelectedUniversityId = universityId;
            sportsContainer.style.display = 'none';
            noUniversityMessage.style.display = 'none';
            loadingSpinner.style.display = 'block';
            confirmBtn.disabled = true;

            fetch(`athletes.php?get_sports_by_university&university_id=${universityId}&competition_id=${bulkSelectedCompetitionId}`)
                .then(response => response.json())
                .then(data => {
                    loadingSpinner.style.display = 'none';

                    if (data.success && data.sports.length > 0) {
                        allBulkSportsData = data.sports;
                        displayBulkSportsList(allBulkSportsData);
                        sportsContainer.style.display = 'block';
                        confirmBtn.disabled = true;
                    } else {
                        sportsListDiv.innerHTML = '<div class="no-results"><i class="fas fa-futbol"></i><p>No sports available for this university.</p></div>';
                        sportsContainer.style.display = 'block';
                        confirmBtn.disabled = true;
                    }
                })
                .catch(error => {
                    loadingSpinner.style.display = 'none';
                    console.error('Error loading sports:', error);
                    sportsListDiv.innerHTML = '<div class="no-results"><i class="fas fa-exclamation-triangle"></i><p>Error loading sports. Please try again.</p></div>';
                    sportsContainer.style.display = 'block';
                });
        }

        // Display bulk sports list
        function displayBulkSportsList(sports) {
            const sportsListDiv = document.getElementById('bulkSportsList');

            if (sports.length === 0) {
                sportsListDiv.innerHTML = '<div class="no-results"><i class="fas fa-futbol"></i><p>No sports match your search.</p></div>';
                return;
            }

            let html = '';
            sports.forEach(sport => {
                const genderBadgeClass = sport.gender === 'Male' ? '👨' : (sport.gender === 'Female' ? '👩' : '👥');
                html += `
                <div class="sport-card-item" onclick="selectBulkSportItem(${sport.id}, '${escapeHtml(sport.sport_name)}', '${escapeHtml(sport.university_name)}', '${escapeHtml(sport.gender)}')">
                    <div class="sport-card-name">${escapeHtml(sport.sport_name)}</div>
                    <div class="sport-card-details">
                        <span class="sport-card-gender">${genderBadgeClass} ${escapeHtml(sport.gender)}</span>
                        <span><i class="fas fa-tag"></i> ${escapeHtml(sport.status)}</span>
                    </div>
                </div>
            `;
            });
            sportsListDiv.innerHTML = html;
        }

        // Escape HTML to prevent XSS
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }







       

        // Select sport item
        function selectSportItem(sportId, sportName, universityName, gender) {
            // Remove selected class from all items
            document.querySelectorAll('#sportsList .sport-card-item').forEach(item => {
                item.classList.remove('selected');
            });

            // Add selected class to clicked item
            event.currentTarget.classList.add('selected');

            // Get the selected university name from the dropdown
            const universitySelect = document.getElementById('universitySelector');
            const selectedUniversityText = universitySelect.options[universitySelect.selectedIndex]?.text || universityName;

            // Store selected sport data with university info
            selectedSportData = {
                id: sportId,
                name: sportName,
                university: selectedUniversityText,
                gender: gender
            };

            // Enable confirm button
            document.getElementById('confirmSportSelectionBtn').disabled = false;
        }

        // Select bulk sport item
        function selectBulkSportItem(sportId, sportName, universityName, gender) {
            // Remove selected class from all items
            document.querySelectorAll('#bulkSportsList .sport-card-item').forEach(item => {
                item.classList.remove('selected');
            });

            // Add selected class to clicked item
            event.currentTarget.classList.add('selected');

            // Get the selected university name from the dropdown
            const universitySelect = document.getElementById('bulkUniversitySelector');
            const selectedUniversityText = universitySelect.options[universitySelect.selectedIndex]?.text || universityName;

            // Store selected sport data
            bulkSelectedSportData = {
                id: sportId,
                name: sportName,
                university: selectedUniversityText,
                gender: gender
            };

            // Enable confirm button
            document.getElementById('confirmBulkSportSelectionBtn').disabled = false;
        }

        // Confirm sport selection for individual copy
        function confirmSportSelection() {
            if (!selectedSportData) {
                const alertDiv = document.getElementById('sportSelectionAlert');
                alertDiv.className = 'modal-alert-error';
                alertDiv.innerHTML = 'Please select a sport first.';
                alertDiv.style.display = 'block';
                setTimeout(() => {
                    alertDiv.style.display = 'none';
                }, 3000);
                return;
            }

            // Update the main copy form with selected sport
            document.getElementById('targetSportId').value = selectedSportData.id;
            document.getElementById('selectedSportName').innerHTML = `${escapeHtml(selectedSportData.name)} (${escapeHtml(selectedSportData.gender)})`;
            document.getElementById('selectedSportDetails').innerHTML = `<i class="fas fa-university"></i> ${escapeHtml(selectedSportData.university)}`;
            document.getElementById('selectedSportDisplay').style.display = 'flex';
            document.getElementById('selectSportBtn').style.display = 'none';
            document.getElementById('confirmCopyBtn').disabled = false;

            // Close the selection modal
            closeSportSelectionModal();
        }

 function confirmBulkSportSelection() {
    if (!bulkSelectedSportData) {
        const alertDiv = document.getElementById('bulkSportSelectionAlert');
        alertDiv.className = 'modal-alert-error';
        alertDiv.innerHTML = 'Please select a sport first.';
        alertDiv.style.display = 'block';
        setTimeout(() => {
            alertDiv.style.display = 'none';
        }, 3000);
        return;
    }
   
    // Update the bulk copy form with selected sport
    document.getElementById('bulkTargetSportId').value = bulkSelectedSportData.id;
    document.getElementById('bulkSelectedSportName').innerHTML = `${escapeHtml(bulkSelectedSportData.name)} (${escapeHtml(bulkSelectedSportData.gender)})`;
    document.getElementById('bulkSelectedSportDetails').innerHTML = `<i class="fas fa-university"></i> ${escapeHtml(bulkSelectedSportData.university)}`;
    document.getElementById('bulkSelectedSportDisplay').style.display = 'flex';
    document.getElementById('bulkSelectSportBtn').style.display = 'none';
    document.getElementById('bulkConfirmCopyBtn').disabled = false;
   
    // Close the selection modal
    closeBulkSportSelectionModal();
   
    // Reopen the bulk copy modal
    const bulkCopyModal = document.getElementById('bulkCopyModal');
    if (bulkCopyModal) {
        bulkCopyModal.style.display = 'block';
        bulkCopyModal.style.zIndex = '1002';
    }
}

        // Load bulk sports by competition
        function loadBulkSportsByCompetition() {
            const competitionId = document.getElementById('bulkCompetitionSelector').value;
            const sportsContainer = document.getElementById('bulkSportsContainer');
            const noCompetitionMessage = document.getElementById('bulkNoCompetitionMessage');
            const loadingSpinner = document.getElementById('bulkSportsLoading');
            const sportsListDiv = document.getElementById('bulkSportsList');
            const confirmBtn = document.getElementById('confirmBulkSportSelectionBtn');

            if (!competitionId) {
                sportsContainer.style.display = 'none';
                noCompetitionMessage.style.display = 'block';
                confirmBtn.disabled = true;
                allBulkSportsData = [];
                return;
            }

            bulkSelectedCompetitionId = competitionId;
            sportsContainer.style.display = 'none';
            noCompetitionMessage.style.display = 'none';
            loadingSpinner.style.display = 'block';
            confirmBtn.disabled = true;

            fetch(`athletes.php?get_sports_by_competition&competition_id=${competitionId}`)
                .then(response => response.json())
                .then(data => {
                    loadingSpinner.style.display = 'none';

                    if (data.success && data.sports.length > 0) {
                        allBulkSportsData = data.sports;
                        displayBulkSportsList(allBulkSportsData);
                        sportsContainer.style.display = 'block';
                        confirmBtn.disabled = true;
                    } else {
                        sportsListDiv.innerHTML = '<div class="no-results"><i class="fas fa-futbol"></i><p>No sports available for this competition.</p></div>';
                        sportsContainer.style.display = 'block';
                        confirmBtn.disabled = true;
                    }
                })
                .catch(error => {
                    loadingSpinner.style.display = 'none';
                    console.error('Error loading sports:', error);
                    sportsListDiv.innerHTML = '<div class="no-results"><i class="fas fa-exclamation-triangle"></i><p>Error loading sports. Please try again.</p></div>';
                    sportsContainer.style.display = 'block';
                });
        }

        // Open sport selection modal for individual copy
        function openSportSelectionModal() {
            // Reset selections
            selectedSportData = null;
            selectedCompetitionId = null;
            selectedUniversityId = null;
            document.getElementById('competitionSelector').value = '';
            document.getElementById('universitySelector').value = '';
            document.getElementById('universityContainer').style.display = 'none';
            document.getElementById('sportSearchInput').value = '';
            document.getElementById('sportsContainer').style.display = 'none';
            document.getElementById('noCompetitionMessage').style.display = 'block';
            document.getElementById('noUniversityMessage').style.display = 'none';
            document.getElementById('confirmSportSelectionBtn').disabled = true;
            document.getElementById('sportSelectionAlert').style.display = 'none';

            // Load competitions if not loaded yet
            if (document.getElementById('competitionSelector').options.length <= 1) {
                loadCompetitions();
            }

            document.getElementById('sportSelectionModal').style.display = 'block';
        }

function openBulkSportSelectionModal() {
    // Close bulk copy modal temporarily
    const bulkCopyModal = document.getElementById('bulkCopyModal');
    if (bulkCopyModal) {
        bulkCopyModal.style.display = 'none';
    }
   
    // Reset selections
    bulkSelectedSportData = null;
    bulkSelectedCompetitionId = null;
    bulkSelectedUniversityId = null;
    document.getElementById('bulkCompetitionSelector').value = '';
    document.getElementById('bulkUniversitySelector').value = '';
    document.getElementById('bulkUniversityContainer').style.display = 'none';
    document.getElementById('bulkSportSearchInput').value = '';
    document.getElementById('bulkSportsContainer').style.display = 'none';
    document.getElementById('bulkNoCompetitionMessage').style.display = 'block';
    document.getElementById('bulkNoUniversityMessage').style.display = 'none';
    document.getElementById('confirmBulkSportSelectionBtn').disabled = true;
    document.getElementById('bulkSportSelectionAlert').style.display = 'none';
   
    // Load competitions if not loaded yet
    if (document.getElementById('bulkCompetitionSelector').options.length <= 1) {
        loadCompetitions();
    }
   
    const sportSelectionModal = document.getElementById('bulkSportSelectionModal');
    if (sportSelectionModal) {
        sportSelectionModal.style.display = 'block';
        sportSelectionModal.style.zIndex = '1003';
    }
}

        // Close sport selection modal
        function closeSportSelectionModal() {
            document.getElementById('sportSelectionModal').style.display = 'none';
        }

function closeBulkSportSelectionModal() {
    const modal = document.getElementById('bulkSportSelectionModal');
    if (modal) {
        modal.style.display = 'none';
    }
}


        // Open copy athlete modal (updated)
        function openCopyAthleteModal(athleteId, athleteName, studentId, currentSport, competitionId, universityId, sportId) {
            // Reset selections
            selectedSportData = null;
            document.getElementById('targetSportId').value = '';
            document.getElementById('selectedSportDisplay').style.display = 'none';
            document.getElementById('selectSportBtn').style.display = 'block';
            document.getElementById('confirmCopyBtn').disabled = true;

            // Set athlete info
            document.getElementById('copyAthleteModal').style.display = 'block';
            document.getElementById('copyAlert').style.display = 'none';
            document.getElementById('copyAthleteSourceId').value = athleteId;
            document.getElementById('copyAthleteName').textContent = athleteName;
            document.getElementById('copyAthleteId').textContent = studentId;
            document.getElementById('copyCurrentSport').textContent = currentSport || 'Not specified';
            document.getElementById('currentCompetitionId').value = competitionId;
            document.getElementById('currentUniversityId').value = universityId;
            document.getElementById('currentSportId').value = sportId;
        }

        function closeCopyAthleteModal() {
            document.getElementById('copyAthleteModal').style.display = 'none';
        }

        function submitCopyAthlete() {
            const targetSportId = document.getElementById('targetSportId').value;

            if (!targetSportId) {
                const alertDiv = document.getElementById('copyAlert');
                alertDiv.className = 'modal-alert-error';
                alertDiv.innerHTML = 'Please select a target sport first.';
                alertDiv.style.display = 'block';
                setTimeout(() => {
                    alertDiv.style.display = 'none';
                }, 3000);
                return;
            }

            // Submit the form
            document.getElementById('copyAthleteForm').submit();
        }

        // Bulk copy functions
        let selectedAthletes = [];

        function toggleSelectAll(checkbox) {
            const athleteCheckboxes = document.querySelectorAll('.athlete-select');
            athleteCheckboxes.forEach(cb => {
                cb.checked = checkbox.checked;
            });
            updateSelectedCount();
        }

        function updateSelectedCount() {
            selectedAthletes = [];
            const checkboxes = document.querySelectorAll('.athlete-select:checked');

            checkboxes.forEach(cb => {
                selectedAthletes.push({
                    id: cb.value,
                    name: cb.dataset.name,
                    student_id: cb.dataset.studentId
                });
            });

            const count = selectedAthletes.length;
            const selectedCountDisplay = document.getElementById('selectedCountDisplay');
            if (selectedCountDisplay) {
                selectedCountDisplay.textContent = count;
            }

            const bulkCopyBtn = document.getElementById('bulkCopyBtn');
            if (bulkCopyBtn) {
                if (count > 0) {
                    bulkCopyBtn.style.display = 'inline-flex';
                    document.getElementById('selectedCount').textContent = count;
                } else {
                    bulkCopyBtn.style.display = 'none';
                }
            }
        }

        function clearSelections() {
            const selectAllCheckbox = document.getElementById('selectAllCheckbox');
            if (selectAllCheckbox) {
                selectAllCheckbox.checked = false;
            }

            document.querySelectorAll('.athlete-select').forEach(cb => {
                cb.checked = false;
            });
            selectedAthletes = [];
            updateSelectedCount();
        }

function openBulkCopyModal() {
    if (selectedAthletes.length === 0) {
        alert('Please select at least one athlete to copy.');
        return;
    }

    // Close any open modals first
    closeAllModals();
   
    // Reset selections
    bulkSelectedSportData = null;
    document.getElementById('bulkTargetSportId').value = '';
    document.getElementById('bulkSelectedSportDisplay').style.display = 'none';
    document.getElementById('bulkSelectSportBtn').style.display = 'block';
    document.getElementById('bulkConfirmCopyBtn').disabled = true;

    const modal = document.getElementById('bulkCopyModal');
    if (modal) {
        modal.style.display = 'block';
        // Ensure modal is on top
        modal.style.zIndex = '1002';
    }

    const alertDiv = document.getElementById('bulkCopyAlert');
    if (alertDiv) {
        alertDiv.style.display = 'none';
    }

    // Update selected count
    const bulkSelectedCount = document.getElementById('bulkSelectedCount');
    if (bulkSelectedCount) {
        bulkSelectedCount.textContent = selectedAthletes.length;
    }

    // Populate selected athletes list
    const athletesList = document.getElementById('selectedAthletesList');
    if (athletesList) {
        athletesList.innerHTML = '';
        selectedAthletes.forEach(athlete => {
            const div = document.createElement('div');
            div.className = 'selected-athlete-item';
            div.innerHTML = `
                <div style="display: flex; align-items: center; gap: 10px; padding: 8px; border-bottom: 1px solid #e0e0e0;">
                    <i class="fas fa-user" style="color: #0c3a1d;"></i>
                    <div>
                        <strong>${escapeHtml(athlete.name)}</strong><br>
                        <small style="color: #666;">${escapeHtml(athlete.student_id)}</small>
                    </div>
                </div>
            `;
            athletesList.appendChild(div);
        });
    }
}

function closeBulkCopyModal() {
    const modal = document.getElementById('bulkCopyModal');
    if (modal) {
        modal.style.display = 'none';
    }
}
function closeAllModals() {
    const modals = [
        'copyAthleteModal',
        'sportSelectionModal',
        'bulkCopyModal',
        'bulkSportSelectionModal',
        'addCompetitionModal',
        'editCompetitionModal',
        'addUniversityModal',
        'editUniversityModal',
        'addSportModal',
        'editSportModal'
    ];
   
    modals.forEach(modalId => {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.style.display = 'none';
        }
    });
}

        function submitBulkCopy() {
            const targetSportId = document.getElementById('bulkTargetSportId').value;
            const alertDiv = document.getElementById('bulkCopyAlert');

            if (!targetSportId) {
                if (alertDiv) {
                    alertDiv.className = 'modal-alert-error';
                    alertDiv.innerHTML = 'Please select a target sport first.';
                    alertDiv.style.display = 'block';
                } else {
                    alert('Please select a target sport.');
                }
                return;
            }

            if (selectedAthletes.length === 0) {
                if (alertDiv) {
                    alertDiv.className = 'modal-alert-error';
                    alertDiv.innerHTML = 'No athletes selected for copying.';
                    alertDiv.style.display = 'block';
                } else {
                    alert('No athletes selected for copying.');
                }
                return;
            }

            // Create form and submit
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'athletes.php';

            // Add bulk copy action
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'bulk_copy_action';
            actionInput.value = 'bulk_copy';
            form.appendChild(actionInput);

            // Add target sport
            const targetSportInput = document.createElement('input');
            targetSportInput.type = 'hidden';
            targetSportInput.name = 'target_sport_id';
            targetSportInput.value = targetSportId;
            form.appendChild(targetSportInput);

            // Add current context
            const currentCompInput = document.createElement('input');
            currentCompInput.type = 'hidden';
            currentCompInput.name = 'current_competition_id';
            currentCompInput.value = document.getElementById('bulkCurrentCompetitionId').value;
            form.appendChild(currentCompInput);

            const currentUniInput = document.createElement('input');
            currentUniInput.type = 'hidden';
            currentUniInput.name = 'current_university_id';
            currentUniInput.value = document.getElementById('bulkCurrentUniversityId').value;
            form.appendChild(currentUniInput);

            const currentSportInput = document.createElement('input');
            currentSportInput.type = 'hidden';
            currentSportInput.name = 'current_sport_id';
            currentSportInput.value = document.getElementById('bulkCurrentSportId').value;
            form.appendChild(currentSportInput);

            // Add selected athletes
            selectedAthletes.forEach((athlete) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'selected_athletes[]';
                input.value = athlete.id;
                form.appendChild(input);
            });

            // Add to body and submit
            document.body.appendChild(form);
            form.submit();
        }

        // Add Competition Modal Functions
        function openAddCompetitionModal() {
            document.getElementById('addCompetitionModal').style.display = 'block';
            document.getElementById('competitionAlert').style.display = 'none';
            document.getElementById('addCompetitionForm').reset();

            // Set current year as default
            const yearInput = document.getElementById('competition_year');
            if (yearInput && !yearInput.value) {
                const currentYear = new Date().getFullYear();
                yearInput.value = currentYear;
            }
        }

        function closeAddCompetitionModal() {
            document.getElementById('addCompetitionModal').style.display = 'none';
        }

        function submitCompetitionForm() {
            const form = document.getElementById('addCompetitionForm');
            const alertDiv = document.getElementById('competitionAlert');

            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }

            const formData = new FormData(form);
            formData.append('add_competition', 'true');

            const submitBtn = document.querySelector('#addCompetitionModal .btn-primary');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '⏳ Adding...';
            submitBtn.disabled = true;

            fetch('athletes.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alertDiv.className = 'modal-alert-success';
                        alertDiv.innerHTML = data.message;
                        alertDiv.style.display = 'block';

                        form.reset();

                        setTimeout(() => {
                            window.location.reload();
                        }, 1500);
                    } else {
                        alertDiv.className = 'modal-alert-error';
                        alertDiv.innerHTML = data.message;
                        alertDiv.style.display = 'block';

                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                    }
                })
                .catch(error => {
                    alertDiv.className = 'modal-alert-error';
                    alertDiv.innerHTML = 'Network error. Please try again.';
                    alertDiv.style.display = 'block';

                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                });
        }

        // Edit Competition Modal Functions
        function openEditCompetitionModal(competitionId) {
            document.getElementById('editCompetitionModal').style.display = 'block';
            document.getElementById('editCompetitionAlert').style.display = 'none';

            fetch(`athletes.php?get_competition=${competitionId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const comp = data.data;
                        document.getElementById('edit_competition_id').value = comp.id;
                        document.getElementById('edit_competition_name').value = comp.name;
                        document.getElementById('edit_competition_year').value = comp.year;
                        document.getElementById('edit_competition_description').value = comp.description || '';
                    } else {
                        alert('Error loading competition data');
                        closeEditCompetitionModal();
                    }
                })
                .catch(error => {
                    alert('Error loading competition data');
                    closeEditCompetitionModal();
                });
        }

        function closeEditCompetitionModal() {
            document.getElementById('editCompetitionModal').style.display = 'none';
        }

        function submitEditCompetitionForm() {
            const form = document.getElementById('editCompetitionForm');
            const alertDiv = document.getElementById('editCompetitionAlert');

            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }

            const formData = new FormData(form);
            formData.append('edit_competition', 'true');

            const submitBtn = document.querySelector('#editCompetitionModal .btn-primary');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '⏳ Updating...';
            submitBtn.disabled = true;

            fetch('athletes.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alertDiv.className = 'modal-alert-success';
                        alertDiv.innerHTML = data.message;
                        alertDiv.style.display = 'block';

                        setTimeout(() => {
                            window.location.reload();
                        }, 1500);
                    } else {
                        alertDiv.className = 'modal-alert-error';
                        alertDiv.innerHTML = data.message;
                        alertDiv.style.display = 'block';

                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                    }
                })
                .catch(error => {
                    alertDiv.className = 'modal-alert-error';
                    alertDiv.innerHTML = 'Network error. Please try again.';
                    alertDiv.style.display = 'block';

                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                });
        }

        // Add University Modal Functions
        function openAddUniversityModal(competitionId) {
            document.getElementById('university_competition_id').value = competitionId;
            document.getElementById('addUniversityModal').style.display = 'block';
            document.getElementById('universityAlert').style.display = 'none';
            document.getElementById('addUniversityForm').reset();
        }

        function closeAddUniversityModal() {
            document.getElementById('addUniversityModal').style.display = 'none';
        }

        function submitUniversityForm() {
            const form = document.getElementById('addUniversityForm');
            const alertDiv = document.getElementById('universityAlert');

            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }

            const formData = new FormData(form);
            formData.append('add_university', 'true');

            const submitBtn = document.querySelector('#addUniversityModal .btn-primary');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '⏳ Adding...';
            submitBtn.disabled = true;

            fetch('athletes.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alertDiv.className = 'modal-alert-success';
                        alertDiv.innerHTML = data.message;
                        alertDiv.style.display = 'block';

                        form.reset();

                        setTimeout(() => {
                            window.location.reload();
                        }, 1500);
                    } else {
                        alertDiv.className = 'modal-alert-error';
                        alertDiv.innerHTML = data.message;
                        alertDiv.style.display = 'block';

                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                    }
                })
                .catch(error => {
                    alertDiv.className = 'modal-alert-error';
                    alertDiv.innerHTML = 'Network error. Please try again.';
                    alertDiv.style.display = 'block';

                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                });
        }

        // Edit University Modal Functions
        function openEditUniversityModal(universityId) {
            document.getElementById('editUniversityModal').style.display = 'block';
            document.getElementById('editUniversityAlert').style.display = 'none';

            fetch(`athletes.php?get_university=${universityId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const uni = data.data;
                        document.getElementById('edit_university_id').value = uni.id;
                        document.getElementById('edit_university_name').value = uni.name;
                        document.getElementById('edit_university_code').value = uni.code || '';
                        document.getElementById('edit_university_description').value = uni.description || '';
                    } else {
                        alert('Error loading university data');
                        closeEditUniversityModal();
                    }
                })
                .catch(error => {
                    alert('Error loading university data');
                    closeEditUniversityModal();
                });
        }

        function closeEditUniversityModal() {
            document.getElementById('editUniversityModal').style.display = 'none';
        }

        function submitEditUniversityForm() {
            const form = document.getElementById('editUniversityForm');
            const alertDiv = document.getElementById('editUniversityAlert');

            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }

            const formData = new FormData(form);
            formData.append('edit_university', 'true');

            const submitBtn = document.querySelector('#editUniversityModal .btn-primary');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '⏳ Updating...';
            submitBtn.disabled = true;

            fetch('athletes.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alertDiv.className = 'modal-alert-success';
                        alertDiv.innerHTML = data.message;
                        alertDiv.style.display = 'block';

                        setTimeout(() => {
                            window.location.reload();
                        }, 1500);
                    } else {
                        alertDiv.className = 'modal-alert-error';
                        alertDiv.innerHTML = data.message;
                        alertDiv.style.display = 'block';

                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                    }
                })
                .catch(error => {
                    alertDiv.className = 'modal-alert-error';
                    alertDiv.innerHTML = 'Network error. Please try again.';
                    alertDiv.style.display = 'block';

                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                });
        }

        // Add Sport Modal Functions
        function openAddSportModal(competitionId, universityId) {
            document.getElementById('sport_competition_id').value = competitionId;
            document.getElementById('sport_university_id').value = universityId;
            document.getElementById('addSportModal').style.display = 'block';
            document.getElementById('sportAlert').style.display = 'none';
            document.getElementById('addSportForm').reset();
        }

        function closeAddSportModal() {
            document.getElementById('addSportModal').style.display = 'none';
        }

        function submitSportForm() {
            const form = document.getElementById('addSportForm');
            const alertDiv = document.getElementById('sportAlert');

            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }

            const formData = new FormData(form);
            formData.append('add_sport', 'true');

            const submitBtn = document.querySelector('#addSportModal .btn-primary');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '⏳ Adding...';
            submitBtn.disabled = true;

            fetch('athletes.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alertDiv.className = 'modal-alert-success';
                        alertDiv.innerHTML = data.message;
                        alertDiv.style.display = 'block';

                        form.reset();

                        setTimeout(() => {
                            window.location.reload();
                        }, 1500);
                    } else {
                        alertDiv.className = 'modal-alert-error';
                        alertDiv.innerHTML = data.message;
                        alertDiv.style.display = 'block';

                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                    }
                })
                .catch(error => {
                    alertDiv.className = 'modal-alert-error';
                    alertDiv.innerHTML = 'Network error. Please try again.';
                    alertDiv.style.display = 'block';

                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                });
        }

        // Edit Sport Modal Functions
        function openEditSportModal(sportId) {
            document.getElementById('editSportModal').style.display = 'block';
            document.getElementById('editSportAlert').style.display = 'none';

            fetch(`athletes.php?get_sport=${sportId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const sport = data.data;
                        document.getElementById('edit_sport_id').value = sport.id;
                        document.getElementById('edit_sport_name').value = sport.sport_name;
                        document.getElementById('edit_sport_gender').value = sport.gender;
                        document.getElementById('edit_sport_status').value = sport.status;
                    } else {
                        alert('Error loading sport data');
                        closeEditSportModal();
                    }
                })
                .catch(error => {
                    alert('Error loading sport data');
                    closeEditSportModal();
                });
        }

        function closeEditSportModal() {
            document.getElementById('editSportModal').style.display = 'none';
        }

        function submitEditSportForm() {
            const form = document.getElementById('editSportForm');
            const alertDiv = document.getElementById('editSportAlert');

            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }

            const formData = new FormData(form);
            formData.append('edit_sport', 'true');

            const submitBtn = document.querySelector('#editSportModal .btn-primary');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '⏳ Updating...';
            submitBtn.disabled = true;

            fetch('athletes.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alertDiv.className = 'modal-alert-success';
                        alertDiv.innerHTML = data.message;
                        alertDiv.style.display = 'block';

                        setTimeout(() => {
                            window.location.reload();
                        }, 1500);
                    } else {
                        alertDiv.className = 'modal-alert-error';
                        alertDiv.innerHTML = data.message;
                        alertDiv.style.display = 'block';

                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                    }
                })
                .catch(error => {
                    alertDiv.className = 'modal-alert-error';
                    alertDiv.innerHTML = 'Network error. Please try again.';
                    alertDiv.style.display = 'block';

                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                });
        }

        // Toggle search result groups
        function toggleGroup(header) {
            header.classList.toggle('collapsed');
            const content = header.nextElementSibling;
            if (content) {
                content.classList.toggle('collapsed');
            }
        }

        // Show all results
        function showAllResults(event) {
            if (event) event.preventDefault();
            document.getElementById('search-results-dropdown').style.display = 'none';
            document.getElementById('full-results-container').style.display = 'block';
            document.getElementById('full-results-container').scrollIntoView({
                behavior: 'smooth'
            });
        }

        // Hide all results
        function hideAllResults() {
            document.getElementById('full-results-container').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modals = [
                'addCompetitionModal',
                'editCompetitionModal',
                'addUniversityModal',
                'editUniversityModal',
                'addSportModal',
                'editSportModal',
                'copyAthleteModal',
                'sportSelectionModal',
                'bulkCopyModal',
                'bulkSportSelectionModal'
            ];

            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (event.target == modal) {
                    if (modalId === 'addCompetitionModal') closeAddCompetitionModal();
                    if (modalId === 'editCompetitionModal') closeEditCompetitionModal();
                    if (modalId === 'addUniversityModal') closeAddUniversityModal();
                    if (modalId === 'editUniversityModal') closeEditUniversityModal();
                    if (modalId === 'addSportModal') closeAddSportModal();
                    if (modalId === 'editSportModal') closeEditSportModal();
                    if (modalId === 'copyAthleteModal') closeCopyAthleteModal();
                    if (modalId === 'sportSelectionModal') closeSportSelectionModal();
                    if (modalId === 'bulkCopyModal') closeBulkCopyModal();
                    if (modalId === 'bulkSportSelectionModal') closeBulkSportSelectionModal();
                }
            });
        };

        // Close modal with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeAddCompetitionModal();
                closeEditCompetitionModal();
                closeAddUniversityModal();
                closeEditUniversityModal();
                closeAddSportModal();
                closeEditSportModal();
                closeCopyAthleteModal();
                closeSportSelectionModal();
                closeBulkCopyModal();
                closeBulkSportSelectionModal();
            }
        });

        // Initialize search dropdown behavior
        document.addEventListener('click', function(event) {
            const container = document.getElementById('global-search-container');
            const dropdown = document.getElementById('search-results-dropdown');

            if (container && dropdown && !container.contains(event.target)) {
                dropdown.style.display = 'none';
            }
        });

        document.getElementById('global-search-input')?.addEventListener('focus', function() {
            const dropdown = document.getElementById('search-results-dropdown');
            if (dropdown) {
                dropdown.style.display = 'block';
            }
        });

        <?php if (isset($_GET['global_search']) && !empty($_GET['global_search'])): ?>
            window.addEventListener('load', function() {
                const dropdown = document.getElementById('search-results-dropdown');
                if (dropdown) {
                    dropdown.style.display = 'block';
                }
            });
        <?php endif; ?>

        // Initialize count on load
        window.addEventListener('load', function() {
            updateSelectedCount();
        });
        // Load universities by competition for individual copy
        function loadUniversitiesByCompetition() {
            const competitionId = document.getElementById('competitionSelector').value;
            const universityContainer = document.getElementById('universityContainer');
            const universitySelect = document.getElementById('universitySelector');
            const sportsContainer = document.getElementById('sportsContainer');
            const noCompetitionMessage = document.getElementById('noCompetitionMessage');
            const noUniversityMessage = document.getElementById('noUniversityMessage');
            const confirmBtn = document.getElementById('confirmSportSelectionBtn');

            if (!competitionId) {
                universityContainer.style.display = 'none';
                sportsContainer.style.display = 'none';
                noCompetitionMessage.style.display = 'block';
                noUniversityMessage.style.display = 'none';
                confirmBtn.disabled = true;
                selectedCompetitionId = null;
                return;
            }

            selectedCompetitionId = competitionId;
            universityContainer.style.display = 'block';
            noCompetitionMessage.style.display = 'none';
            noUniversityMessage.style.display = 'none';
            sportsContainer.style.display = 'none';
            confirmBtn.disabled = true;

            // Reset university select
            universitySelect.innerHTML = '<option value="">-- Loading universities... --</option>';
            universitySelect.disabled = true;

            fetch(`athletes.php?get_universities_by_competition&competition_id=${competitionId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.universities.length > 0) {
                        universitiesData = data.universities;
                        universitySelect.innerHTML = '<option value="">-- Select a university --</option>';
                        data.universities.forEach(uni => {
                            universitySelect.innerHTML += `<option value="${uni.id}">${uni.name} ${uni.code ? '(' + uni.code + ')' : ''}</option>`;
                        });
                        universitySelect.disabled = false;
                    } else {
                        universitySelect.innerHTML = '<option value="">-- No universities found --</option>';
                        universitySelect.disabled = true;
                        noUniversityMessage.style.display = 'block';
                    }
                })
                .catch(error => {
                    console.error('Error loading universities:', error);
                    universitySelect.innerHTML = '<option value="">-- Error loading universities --</option>';
                    universitySelect.disabled = true;
                });
        }

        // Load sports by university for individual copy
        function loadSportsByUniversity() {
            const universityId = document.getElementById('universitySelector').value;
            const sportsContainer = document.getElementById('sportsContainer');
            const loadingSpinner = document.getElementById('sportsLoading');
            const sportsListDiv = document.getElementById('sportsList');
            const confirmBtn = document.getElementById('confirmSportSelectionBtn');
            const noUniversityMessage = document.getElementById('noUniversityMessage');

            if (!universityId) {
                sportsContainer.style.display = 'none';
                noUniversityMessage.style.display = 'block';
                confirmBtn.disabled = true;
                allSportsData = [];
                selectedUniversityId = null;
                return;
            }

            selectedUniversityId = universityId;
            sportsContainer.style.display = 'none';
            noUniversityMessage.style.display = 'none';
            loadingSpinner.style.display = 'block';
            confirmBtn.disabled = true;

            fetch(`athletes.php?get_sports_by_university&university_id=${universityId}&competition_id=${selectedCompetitionId}`)
                .then(response => response.json())
                .then(data => {
                    loadingSpinner.style.display = 'none';

                    if (data.success && data.sports.length > 0) {
                        allSportsData = data.sports;
                        displaySportsList(allSportsData);
                        sportsContainer.style.display = 'block';
                        confirmBtn.disabled = true;
                    } else {
                        sportsListDiv.innerHTML = '<div class="no-results"><i class="fas fa-futbol"></i><p>No sports available for this university.</p></div>';
                        sportsContainer.style.display = 'block';
                        confirmBtn.disabled = true;
                    }
                })
                .catch(error => {
                    loadingSpinner.style.display = 'none';
                    console.error('Error loading sports:', error);
                    sportsListDiv.innerHTML = '<div class="no-results"><i class="fas fa-exclamation-triangle"></i><p>Error loading sports. Please try again.</p></div>';
                    sportsContainer.style.display = 'block';
                });
        }

        // Global search functionality - AJAX based, stays on same page
let searchTimeout = null;
let currentSearchTerm = '';

function performGlobalSearch() {
    const searchInput = document.getElementById('global-search-input');
    const searchTerm = searchInput.value.trim();
    const clearBtn = document.getElementById('global-search-clear');
    const dropdown = document.getElementById('search-results-dropdown');
    const resultsContent = document.getElementById('search-results-content');
   
    // Show/hide clear button
    if (searchTerm.length > 0) {
        clearBtn.style.display = 'block';
    } else {
        clearBtn.style.display = 'none';
    }
   
    // Don't search if term is too short
    if (searchTerm.length < 2) {
        dropdown.style.display = 'none';
        return;
    }
   
    currentSearchTerm = searchTerm;
   
    // Show loading state
    resultsContent.innerHTML = '<div style="padding: 20px; text-align: center;"><i class="fas fa-spinner fa-spin"></i> Searching...</div>';
    dropdown.style.display = 'block';
   
    // Fetch search results via AJAX
    fetch(`athletes.php?ajax_global_search=${encodeURIComponent(searchTerm)}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (data.total === 0) {
                    resultsContent.innerHTML = `
                        <div style="padding: 30px 20px; text-align: center;">
                            <i class="fas fa-user-slash" style="font-size: 32px; color: #ccc; margin-bottom: 10px;"></i>
                            <div style="font-weight: 600; color: #333; margin-bottom: 5px;">No athletes found</div>
                            <div style="color: #999; font-size: 13px;">No results match "${escapeHtml(searchTerm)}"</div>
                        </div>
                    `;
                } else {
                    let html = `
                        <div style="padding: 10px 15px; background: #f8f9fa; border-bottom: 1px solid #e0e0e0; font-size: 13px; color: #666;">
                            <i class="fas fa-info-circle" style="color: #0c3a1d;"></i>
                            Found <strong>${data.total}</strong> result(s)
                        </div>
                        <div style="max-height: 400px; overflow-y: auto;">
                    `;
                   
                    // Loop through results
                    for (const compKey in data.results) {
                        const competition = data.results[compKey];
                        html += `
                            <div style="border-bottom: 1px solid #f0f0f0;">
                                <div style="background: #f5f5f5; padding: 8px 15px; font-weight: 600; color: #0c3a1d; font-size: 13px; display: flex; align-items: center; gap: 8px;">
                                    <i class="fas fa-trophy" style="font-size: 12px;"></i>
                                    ${escapeHtml(competition.competition_name)} ${escapeHtml(competition.competition_year)}
                                </div>
                        `;
                       
                        for (const uniKey in competition.universities) {
                            const university = competition.universities[uniKey];
                            for (const sportKey in university.sports) {
                                const sport = university.sports[sportKey];
                                for (const athlete of sport.athletes) {
                                    html += `
                                        <div style="padding: 10px 15px; border-bottom: 1px solid #f0f0f0; transition: background 0.2s;">
                                            <div style="display: flex; justify-content: space-between; align-items: center; gap: 10px;">
                                                <div style="flex: 1; min-width: 0;">
                                                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 3px;">
                                                        <span style="font-weight: 600; color: #0c3a1d; font-size: 14px;">
                                                            ${escapeHtml(athlete.last_name + ', ' + athlete.first_name)}
                                                            ${athlete.middle_initial ? ' ' + escapeHtml(athlete.middle_initial) : ''}
                                                        </span>
                                                        <span style="background: #e8f5e9; padding: 2px 6px; border-radius: 4px; font-size: 10px; color: #0c3a1d; font-weight: 500;">
                                                            ${escapeHtml(athlete.student_id)}
                                                        </span>
                                                    </div>
                                                    <div style="font-size: 12px; color: #666; display: flex; flex-wrap: wrap; gap: 10px;">
                                                        <span><i class="fas fa-university" style="margin-right: 3px;"></i> ${escapeHtml(university.university_name)}</span>
                                                        <span><i class="fas fa-medal" style="margin-right: 3px;"></i> ${escapeHtml(sport.sport_name)} (${escapeHtml(sport.sport_gender)})</span>
                                                        <span><i class="fas fa-graduation-cap" style="margin-right: 3px;"></i>
                                                            ${athlete.course_code ? escapeHtml(athlete.course_code) : 'N/A'}
                                                        </span>
                                                    </div>
                                                </div>
                                                <div style="display: flex; gap: 5px;">
                                                    <a href="view_athlete.php?id=${athlete.id}" style="background: #17a2b8; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px; text-decoration: none;" title="View"><i class="fas fa-eye"></i></a>
                                                    <a href="javascript:void(0)" onclick="goToAthleteLocation(${athlete.competition_id}, ${athlete.university_id}, ${athlete.sport_id})"
                                                        style="background: #0c3a1d; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px; text-decoration: none;"
                                                        title="Go to Location">
                                                        <i class="fas fa-location-dot"></i>
                                                    </a>
                                                    ${<?php echo $_SESSION['role'] === 'admin' ? 'true' : 'false'; ?> ? `<a href="add_athlete.php?id=${athlete.id}" style="background: #ffc107; color: #212529; padding: 4px 8px; border-radius: 4px; font-size: 11px; text-decoration: none;" title="Edit"><i class="fas fa-pencil"></i></a>` : ''}
                                                </div>
                                            </div>
                                        </div>
                                    `;
                                }
                            }
                        }
                        html += `</div>`;
                    }
                   
                    html += `</div>`;
                    resultsContent.innerHTML = html;
                }
            } else {
                resultsContent.innerHTML = `
                    <div style="padding: 20px; text-align: center; color: #721c24;">
                        <i class="fas fa-exclamation-triangle"></i> ${escapeHtml(data.message)}
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Search error:', error);
            resultsContent.innerHTML = `
                <div style="padding: 20px; text-align: center; color: #721c24;">
                    <i class="fas fa-exclamation-triangle"></i> Network error. Please try again.
                </div>
            `;
        });
}

// Function to navigate to athlete location without page reload for search
function goToAthleteLocation(competitionId, universityId, sportId) {
    window.location.href = `athletes.php?competition_id=${competitionId}&university_id=${universityId}&sport_id=${sportId}`;
}

// Clear search
function clearGlobalSearch() {
    const searchInput = document.getElementById('global-search-input');
    const clearBtn = document.getElementById('global-search-clear');
    const dropdown = document.getElementById('search-results-dropdown');
   
    searchInput.value = '';
    clearBtn.style.display = 'none';
    dropdown.style.display = 'none';
    currentSearchTerm = '';
}

// Escape HTML to prevent XSS
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Initialize global search event listeners
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('global-search-input');
    const searchButton = document.getElementById('global-search-button');
    const clearButton = document.getElementById('global-search-clear');
    const dropdown = document.getElementById('search-results-dropdown');
   
    if (searchInput) {
        // Search on input with debounce
        searchInput.addEventListener('input', function() {
            if (searchTimeout) {
                clearTimeout(searchTimeout);
            }
            searchTimeout = setTimeout(() => {
                performGlobalSearch();
            }, 300);
        });
       
        // Search on Enter key
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                if (searchTimeout) {
                    clearTimeout(searchTimeout);
                }
                performGlobalSearch();
            }
        });
    }
   
    if (searchButton) {
        searchButton.addEventListener('click', function() {
            performGlobalSearch();
        });
    }
   
    if (clearButton) {
        clearButton.addEventListener('click', function() {
            clearGlobalSearch();
        });
    }
   
    // Close dropdown when clicking outside
    document.addEventListener('click', function(event) {
        const container = document.getElementById('global-search-container');
        if (container && !container.contains(event.target)) {
            dropdown.style.display = 'none';
        }
    });
   
    // Keep dropdown open when clicking inside results
    if (dropdown) {
        dropdown.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    }
});
    </script>
</body>

</html>