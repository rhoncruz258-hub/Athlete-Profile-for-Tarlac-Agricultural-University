<?php
session_start();
require_once 'db_config.php';

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

$is_admin = ($_SESSION['role'] === 'admin');

// Handle form submissions
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_schedule'])) {
        try {
            $stmt = $pdo->prepare("INSERT INTO class_schedules (subject_code, subject_name, instructor, room, day_of_week, 
                                  start_time, end_time, sport_category, year_level, course, created_by) 
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            $created_by = $pdo->query("SELECT id FROM users WHERE username = '" . $_SESSION['username'] . "'")->fetch()['id'];
            
            $stmt->execute([
                $_POST['subject_code'],
                $_POST['subject_name'],
                $_POST['instructor'],
                $_POST['room'],
                $_POST['day_of_week'],
                $_POST['start_time'],
                $_POST['end_time'],
                $_POST['sport_category'],
                $_POST['year_level'],
                $_POST['course'],
                $created_by
            ]);
            
            $message = 'Class schedule added successfully!';
            $message_type = 'success';
        } catch (PDOException $e) {
            $message = 'Error adding schedule: ' . $e->getMessage();
            $message_type = 'error';
        }
    }
    
    if (isset($_POST['update_schedule'])) {
        try {
            $stmt = $pdo->prepare("UPDATE class_schedules SET subject_code = ?, subject_name = ?, instructor = ?, 
                                  room = ?, day_of_week = ?, start_time = ?, end_time = ?, sport_category = ?, 
                                  year_level = ?, course = ? WHERE id = ?");
            
            $stmt->execute([
                $_POST['subject_code'],
                $_POST['subject_name'],
                $_POST['instructor'],
                $_POST['room'],
                $_POST['day_of_week'],
                $_POST['start_time'],
                $_POST['end_time'],
                $_POST['sport_category'],
                $_POST['year_level'],
                $_POST['course'],
                $_POST['schedule_id']
            ]);
            
            $message = 'Schedule updated successfully!';
            $message_type = 'success';
        } catch (PDOException $e) {
            $message = 'Error updating schedule: ' . $e->getMessage();
            $message_type = 'error';
        }
    }
    
    if (isset($_POST['delete_schedule'])) {
        try {
            $stmt = $pdo->prepare("DELETE FROM class_schedules WHERE id = ?");
            $stmt->execute([$_POST['schedule_id']]);
            
            $message = 'Schedule deleted successfully!';
            $message_type = 'success';
        } catch (PDOException $e) {
            $message = 'Error deleting schedule: ' . $e->getMessage();
            $message_type = 'error';
        }
    }
    
    if (isset($_POST['record_attendance'])) {
        try {
            // Delete existing attendance for this date if any
            $stmt = $pdo->prepare("DELETE FROM schedule_attendance WHERE schedule_id = ? AND attendance_date = ?");
            $stmt->execute([$_POST['schedule_id'], $_POST['attendance_date']]);
            
            // Insert new attendance records
            if (isset($_POST['attendance'])) {
                $recorded_by = $pdo->query("SELECT id FROM users WHERE username = '" . $_SESSION['username'] . "'")->fetch()['id'];
                
                $insertStmt = $pdo->prepare("INSERT INTO schedule_attendance (schedule_id, athlete_id, attendance_date, status, time_in, time_out, notes, recorded_by) 
                                           VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                
                foreach ($_POST['attendance'] as $athlete_id => $data) {
                    $insertStmt->execute([
                        $_POST['schedule_id'],
                        $athlete_id,
                        $_POST['attendance_date'],
                        $data['status'],
                        $data['time_in'],
                        $data['time_out'],
                        $data['notes'],
                        $recorded_by
                    ]);
                }
                
                $message = 'Attendance recorded successfully!';
                $message_type = 'success';
            }
        } catch (PDOException $e) {
            $message = 'Error recording attendance: ' . $e->getMessage();
            $message_type = 'error';
        }
    }
}

// Get filter values
$filter_day = $_GET['day'] ?? '';
$filter_sport = $_GET['sport'] ?? '';
$filter_course = $_GET['course'] ?? '';

// Define time slots (matching the image)
$time_slots = [
    ['07:30:00', '08:50:00'],
    ['08:50:00', '09:50:00'],
    ['09:50:00', '10:50:00'],
    ['10:50:00', '11:50:00'],
    ['11:50:00', '12:50:00'],
    ['12:50:00', '13:50:00'],
    ['13:50:00', '14:50:00'],
    ['14:50:00', '15:50:00'],
    ['15:50:00', '16:50:00']
];

$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

// Fetch all schedules with filters
$schedules_query = "SELECT * FROM class_schedules WHERE 1=1";
$params = [];

if ($filter_day) {
    $schedules_query .= " AND day_of_week = ?";
    $params[] = $filter_day;
}

if ($filter_sport && $filter_sport !== 'All') {
    $schedules_query .= " AND (sport_category = ? OR sport_category = 'All')";
    $params[] = $filter_sport;
}

if ($filter_course && $filter_course !== 'All') {
    $schedules_query .= " AND (course = ? OR course = 'All')";
    $params[] = $filter_course;
}

$schedules_query .= " ORDER BY 
    FIELD(day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'),
    start_time";

$stmt = $pdo->prepare($schedules_query);
$stmt->execute($params);
$all_schedules = $stmt->fetchAll();

// Organize schedules by day and time
$schedule_grid = [];
foreach ($days as $day) {
    $schedule_grid[$day] = [];
    foreach ($time_slots as $slot) {
        $schedule_grid[$day][$slot[0] . '-' . $slot[1]] = [];
    }
}

foreach ($all_schedules as $schedule) {
    if (in_array($schedule['day_of_week'], $days)) {
        $time_key = $schedule['start_time'] . '-' . $schedule['end_time'];
        if (isset($schedule_grid[$schedule['day_of_week']][$time_key])) {
            $schedule_grid[$schedule['day_of_week']][$time_key][] = $schedule;
        }
    }
}

// Get unique sports and courses for filters
$sports = $pdo->query("SELECT DISTINCT sport_category FROM class_schedules WHERE sport_category != 'All' ORDER BY sport_category")->fetchAll();
$courses = $pdo->query("SELECT DISTINCT course FROM class_schedules WHERE course != 'All' ORDER BY course")->fetchAll();

// Get today's date for attendance
$today = date('Y-m-d');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TAU Sports - Class Schedule</title>
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
            box-shadow: 0 3px 10px rgba(0,0,0,0.2);
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
        
        .nav-icon {
            margin-right: 10px;
            color: #0c3a1d;
        }
        
        .content {
            flex: 1;
            padding: 30px;
            overflow-x: auto;
        }
        
        .page-title {
            color: #0c3a1d;
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e0e0e0;
        }
        
        .action-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding: 20px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
        }
        
        .btn {
            padding: 10px 25px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }
        
        .btn-primary {
            background: #0c3a1d;
            color: white;
        }
        
        .btn-primary:hover {
            background: #1a5c2f;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(12, 58, 29, 0.3);
        }
        
        .btn-success {
            background: #28a745;
            color: white;
        }
        
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        
        .btn-warning {
            background: #ffc107;
            color: #212529;
        }
        
        .btn-info {
            background: #17a2b8;
            color: white;
        }
        
        .filters {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .filter-select {
            padding: 8px 15px;
            border: 1px solid #ddd;
            border-radius: 6px;
            background: white;
            color: #333;
            font-size: 14px;
            min-width: 150px;
        }
        
        .schedule-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            overflow: hidden;
            margin-top: 20px;
        }
        
        .schedule-header {
            background: linear-gradient(135deg, #0c3a1d 0%, #1a5c2f 100%);
            color: white;
            padding: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .schedule-title {
            font-size: 24px;
            font-weight: 700;
        }
        
        .current-date {
            font-size: 14px;
            opacity: 0.9;
        }
        
        .schedule-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .schedule-table th {
            background: #f8f9fa;
            color: #0c3a1d;
            font-weight: 600;
            padding: 15px;
            text-align: center;
            border: 1px solid #e0e0e0;
        }
        
        .schedule-table td {
            padding: 10px;
            border: 1px solid #e0e0e0;
            vertical-align: top;
            min-width: 180px;
            height: 80px;
        }
        
        .time-cell {
            background: #f8f9fa;
            font-weight: 600;
            color: #0c3a1d;
            text-align: center;
            width: 120px;
        }
        
        .schedule-item {
            background: #e8f5e9;
            border-left: 4px solid #0c3a1d;
            padding: 8px;
            margin-bottom: 5px;
            border-radius: 4px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .schedule-item:hover {
            background: #d4edda;
            transform: translateX(2px);
            box-shadow: 0 3px 8px rgba(0,0,0,0.1);
        }
        
        .schedule-subject {
            font-weight: 600;
            color: #0c3a1d;
            margin-bottom: 2px;
        }
        
        .schedule-details {
            font-size: 11px;
            color: #666;
            line-height: 1.3;
        }
        
        .empty-slot {
            text-align: center;
            color: #999;
            font-style: italic;
            font-size: 12px;
            padding: 10px;
        }
        
        .actions-cell {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }
        
        .action-btn {
            padding: 3px 8px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 11px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            transition: all 0.2s;
        }
        
        .action-btn-view {
            background: #e3f2fd;
            color: #1976d2;
        }
        
        .action-btn-edit {
            background: #e8f5e9;
            color: #2e7d32;
        }
        
        .action-btn-delete {
            background: #fce4ec;
            color: #c2185b;
        }
        
        .action-btn-attendance {
            background: #fff3cd;
            color: #856404;
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1001;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        
        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 30px;
            border-radius: 10px;
            width: 80%;
            max-width: 800px;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e0e0e0;
        }
        
        .modal-title {
            color: #0c3a1d;
            font-size: 24px;
            font-weight: 700;
        }
        
        .close-btn {
            font-size: 28px;
            font-weight: bold;
            color: #999;
            cursor: pointer;
            transition: color 0.3s;
        }
        
        .close-btn:hover {
            color: #333;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #555;
        }
        
        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            transition: border 0.3s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #0c3a1d;
            box-shadow: 0 0 0 3px rgba(12, 58, 29, 0.1);
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
        .alert {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .attendance-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .attendance-table th {
            background: #f8f9fa;
            padding: 10px;
            text-align: left;
            border-bottom: 2px solid #e0e0e0;
        }
        
        .attendance-table td {
            padding: 10px;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-present { background: #d4edda; color: #155724; }
        .status-absent { background: #f8d7da; color: #721c24; }
        .status-late { background: #fff3cd; color: #856404; }
        .status-excused { background: #d1ecf1; color: #0c5460; }
        
        .no-data {
            text-align: center;
            padding: 40px;
            color: #999;
            font-style: italic;
        }
        
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 15px;
            font-size: 11px;
            font-weight: 600;
            margin-right: 5px;
            margin-bottom: 5px;
        }
        
        .badge-sport { background: #e3f2fd; color: #1976d2; }
        .badge-course { background: #e8f5e9; color: #2e7d32; }
        .badge-year { background: #fff3cd; color: #856404; }
        
        .footer {
            text-align: center;
            padding: 20px;
            color: #777;
            font-size: 12px;
            border-top: 1px solid #e0e0e0;
            margin-top: 40px;
            background: white;
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
                <li class="nav-item"><a href="dashboard.php" class="nav-link">🏠 Dashboard</a></li>
                <li class="nav-item"><a href="athletes.php" class="nav-link">👥 Athlete Profiles</a></li>
                <li class="nav-item"><a href="#" class="nav-link">📊 Sports Analytics</a></li>
                <li class="nav-item"><a href="eventmanagement.php" class="nav-link">📅 Event Management</a></li>
                <li class="nav-item"><a href="class_schedule.php" class="nav-link active">📚 Class Schedule</a></li>
                <li class="nav-item"><a href="#" class="nav-link">🏆 Achievements</a></li>
                <li class="nav-item"><a href="#" class="nav-link">📋 Reports</a></li>
                <li class="nav-item"><a href="#" class="nav-link">⚙️ System Settings</a></li>
            </ul>
        </nav>
        
        <main class="content">
            <h1 class="page-title">CLASS SCHEDULE</h1>
            
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
            
            <div class="action-bar">
                <div>
                    <button onclick="openModal('addScheduleModal')" class="btn btn-primary">
                        ➕ Add Schedule
                    </button>
                    <button onclick="printSchedule()" class="btn btn-info">
                        🖨️ Print Schedule
                    </button>
                </div>
                
                <div class="filters">
                    <select class="filter-select" onchange="window.location.href='class_schedule.php?day='+this.value">
                        <option value="">All Days</option>
                        <?php foreach ($days as $day): ?>
                            <option value="<?php echo $day; ?>" <?php echo $filter_day === $day ? 'selected' : ''; ?>>
                                <?php echo $day; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <select class="filter-select" onchange="window.location.href='class_schedule.php?sport='+this.value">
                        <option value="All">All Sports</option>
                        <?php foreach ($sports as $sport): ?>
                            <option value="<?php echo $sport['sport_category']; ?>" <?php echo $filter_sport === $sport['sport_category'] ? 'selected' : ''; ?>>
                                <?php echo $sport['sport_category']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <select class="filter-select" onchange="window.location.href='class_schedule.php?course='+this.value">
                        <option value="All">All Courses</option>
                        <?php foreach ($courses as $course): ?>
                            <option value="<?php echo $course['course']; ?>" <?php echo $filter_course === $course['course'] ? 'selected' : ''; ?>>
                                <?php echo $course['course']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="schedule-container">
                <div class="schedule-header">
                    <div>
                        <div class="schedule-title">SPORTS TRAINING SCHEDULE</div>
                        <div class="current-date"><?php echo date('F d, Y'); ?></div>
                    </div>
                    <div>
                        <button onclick="exportSchedule()" class="btn btn-success">📥 Export</button>
                    </div>
                </div>
                
                <table class="schedule-table">
                    <thead>
                        <tr>
                            <th>TIME</th>
                            <?php foreach ($days as $day): ?>
                                <th><?php echo strtoupper($day); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($time_slots as $slot): ?>
                            <?php
                            $time_key = $slot[0] . '-' . $slot[1];
                            $time_display = date('g:i A', strtotime($slot[0])) . ' - ' . date('g:i A', strtotime($slot[1]));
                            ?>
                            <tr>
                                <td class="time-cell"><?php echo $time_display; ?></td>
                                
                                <?php foreach ($days as $day): ?>
                                    <td>
                                        <?php if (!empty($schedule_grid[$day][$time_key])): ?>
                                            <?php foreach ($schedule_grid[$day][$time_key] as $schedule): ?>
                                                <div class="schedule-item" onclick="viewScheduleDetails(<?php echo $schedule['id']; ?>)">
                                                    <div class="schedule-subject"><?php echo htmlspecialchars($schedule['subject_code']); ?></div>
                                                    <div class="schedule-details">
                                                        <div><strong><?php echo htmlspecialchars($schedule['subject_name']); ?></strong></div>
                                                        <div>📌 <?php echo htmlspecialchars($schedule['room']); ?></div>
                                                        <div>👨‍🏫 <?php echo htmlspecialchars($schedule['instructor']); ?></div>
                                                        <?php if ($schedule['sport_category'] !== 'All'): ?>
                                                            <span class="badge badge-sport">⚽ <?php echo htmlspecialchars($schedule['sport_category']); ?></span>
                                                        <?php endif; ?>
                                                        <?php if ($schedule['year_level'] !== 'All'): ?>
                                                            <span class="badge badge-year">🎓 <?php echo htmlspecialchars($schedule['year_level']); ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php if ($is_admin): ?>
                                                        <div class="actions-cell">
                                                            <button onclick="event.stopPropagation(); editSchedule(<?php echo $schedule['id']; ?>)" 
                                                                    class="action-btn action-btn-edit">Edit</button>
                                                            <button onclick="event.stopPropagation(); confirmDelete(<?php echo $schedule['id']; ?>)" 
                                                                    class="action-btn action-btn-delete">Delete</button>
                                                            <button onclick="event.stopPropagation(); takeAttendance(<?php echo $schedule['id']; ?>)" 
                                                                    class="action-btn action-btn-attendance">Attendance</button>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <div class="empty-slot">No class scheduled</div>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="footer">
                Tarlac Agricultural University Class Schedule System © <?php echo date('Y'); ?>
            </div>
        </main>
    </div>
    
    <!-- Add Schedule Modal -->
    <div id="addScheduleModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Add Class Schedule</h2>
                <span class="close-btn" onclick="closeModal('addScheduleModal')">&times;</span>
            </div>
            <form method="POST">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Subject Code *</label>
                        <input type="text" class="form-control" name="subject_code" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Subject Name *</label>
                        <input type="text" class="form-control" name="subject_name" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Instructor *</label>
                        <input type="text" class="form-control" name="instructor" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Room *</label>
                        <input type="text" class="form-control" name="room" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Day of Week *</label>
                        <select class="form-control" name="day_of_week" required>
                            <option value="Monday">Monday</option>
                            <option value="Tuesday">Tuesday</option>
                            <option value="Wednesday">Wednesday</option>
                            <option value="Thursday">Thursday</option>
                            <option value="Friday">Friday</option>
                            <option value="Saturday">Saturday</option>
                            <option value="Sunday">Sunday</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Sport Category</label>
                        <select class="form-control" name="sport_category">
                            <option value="All">All Sports</option>
                            <option value="Basketball">Basketball</option>
                            <option value="Football">Football</option>
                            <option value="Swimming">Swimming</option>
                            <option value="Volleyball">Volleyball</option>
                            <option value="Badminton">Badminton</option>
                            <option value="Table Tennis">Table Tennis</option>
                            <option value="Chess">Chess</option>
                            <option value="Athletics">Athletics</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Start Time *</label>
                        <select class="form-control" name="start_time" required>
                            <?php
                            for ($hour = 7; $hour <= 16; $hour++) {
                                for ($minute = 0; $minute < 60; $minute += 30) {
                                    if ($hour == 16 && $minute > 50) continue;
                                    $time = sprintf('%02d:%02d:00', $hour, $minute);
                                    $display = date('g:i A', strtotime($time));
                                    echo "<option value='$time'>$display</option>";
                                }
                            }
                            ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">End Time *</label>
                        <select class="form-control" name="end_time" required>
                            <?php
                            for ($hour = 7; $hour <= 17; $hour++) {
                                for ($minute = 0; $minute < 60; $minute += 30) {
                                    if ($hour == 7 && $minute < 30) continue;
                                    if ($hour == 17 && $minute > 0) continue;
                                    $time = sprintf('%02d:%02d:00', $hour, $minute);
                                    $display = date('g:i A', strtotime($time));
                                    echo "<option value='$time'>$display</option>";
                                }
                            }
                            ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Year Level</label>
                        <select class="form-control" name="year_level">
                            <option value="All">All Levels</option>
                            <option value="1st Year">1st Year</option>
                            <option value="2nd Year">2nd Year</option>
                            <option value="3rd Year">3rd Year</option>
                            <option value="4th Year">4th Year</option>
                            <option value="5th Year">5th Year</option>
                            <option value="Varsity">Varsity</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Course</label>
                        <select class="form-control" name="course">
                            <option value="All">All Courses</option>
                            <option value="BSIT">BSIT</option>
                            <option value="BSCS">BSCS</option>
                            <option value="BSGE">BSGE</option>
                            <option value="BSE">BSE</option>
                            <option value="BSA">BSA</option>
                            <option value="BSHM">BSHM</option>
                        </select>
                    </div>
                </div>
                
                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('addScheduleModal')">Cancel</button>
                    <button type="submit" name="add_schedule" class="btn btn-primary">Add Schedule</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Edit Schedule Modal (Dynamically loaded) -->
    <div id="editScheduleModal" class="modal">
        <div class="modal-content">
            <!-- Content will be loaded via AJAX -->
        </div>
    </div>
    
    <!-- Attendance Modal (Dynamically loaded) -->
    <div id="attendanceModal" class="modal">
        <div class="modal-content">
            <!-- Content will be loaded via AJAX -->
        </div>
    </div>
    
    <script>
        // Modal functions
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        // View schedule details
        function viewScheduleDetails(scheduleId) {
            alert('Schedule ID: ' + scheduleId + '\n\nIn full implementation, this would show:\n- Complete schedule details\n- Attendance records\n- Registered athletes\n- Room capacity\n- Equipment requirements');
        }
        
        // Edit schedule
        function editSchedule(scheduleId) {
            fetch('ajax_get_schedule.php?id=' + scheduleId)
                .then(response => response.text())
                .then(data => {
                    document.getElementById('editScheduleModal').innerHTML = data;
                    openModal('editScheduleModal');
                })
                .catch(error => {
                    alert('Error loading schedule data');
                });
        }
        
        // Delete schedule
        function confirmDelete(scheduleId) {
            if (confirm('Are you sure you want to delete this schedule? This action cannot be undone.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="schedule_id" value="${scheduleId}">
                    <input type="hidden" name="delete_schedule" value="1">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // Take attendance
        function takeAttendance(scheduleId) {
            fetch('ajax_attendance_form.php?id=' + scheduleId)
                .then(response => response.text())
                .then(data => {
                    document.getElementById('attendanceModal').innerHTML = data;
                    openModal('attendanceModal');
                })
                .catch(error => {
                    alert('Error loading attendance form');
                });
        }
        
        // Print schedule
        function printSchedule() {
            const printContent = document.querySelector('.schedule-container').cloneNode(true);
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <html>
                <head>
                    <title>Class Schedule - <?php echo date('Y-m-d'); ?></title>
                    <style>
                        body { font-family: Arial, sans-serif; }
                        .schedule-table { border-collapse: collapse; width: 100%; }
                        .schedule-table th, .schedule-table td { 
                            border: 1px solid #ddd; 
                            padding: 8px; 
                            text-align: left; 
                        }
                        .schedule-table th { 
                            background-color: #f2f2f2; 
                            font-weight: bold;
                        }
                        .schedule-header { 
                            background-color: #0c3a1d; 
                            color: white; 
                            padding: 20px; 
                            margin-bottom: 20px;
                        }
                        .schedule-title { 
                            font-size: 24px; 
                            font-weight: bold; 
                            margin-bottom: 10px;
                        }
                        .no-print { display: none; }
                    </style>
                </head>
                <body>
                    <div class="schedule-header">
                        <div class="schedule-title">TAU SPORTS CLASS SCHEDULE</div>
                        <div>Printed on: <?php echo date('F d, Y g:i A'); ?></div>
                    </div>
                    ${printContent.innerHTML}
                </body>
                </html>
            `);
            printWindow.document.close();
            printWindow.print();
        }
        
        // Export schedule
        function exportSchedule() {
            const csv = [];
            csv.push('Time,Monday,Tuesday,Wednesday,Thursday,Friday');
            
            <?php foreach ($time_slots as $slot): ?>
                <?php
                $time_key = $slot[0] . '-' . $slot[1];
                $time_display = date('g:i A', strtotime($slot[0])) . ' - ' . date('g:i A', strtotime($slot[1]));
                ?>
                const row = ['<?php echo $time_display; ?>'];
                
                <?php foreach ($days as $day): ?>
                    <?php if (!empty($schedule_grid[$day][$time_key])): ?>
                        <?php 
                        $classes = [];
                        foreach ($schedule_grid[$day][$time_key] as $schedule) {
                            $classes[] = $schedule['subject_code'] . ' - ' . $schedule['subject_name'] . ' (' . $schedule['room'] . ')';
                        }
                        ?>
                        row.push('<?php echo implode("; ", $classes); ?>');
                    <?php else: ?>
                        row.push('');
                    <?php endif; ?>
                <?php endforeach; ?>
                
                csv.push(row.join(','));
            <?php endforeach; ?>
            
            const csvContent = csv.join('\n');
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = 'class_schedule_<?php echo date('Y-m-d'); ?>.csv';
            link.click();
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
    </script>
</body>
</html>