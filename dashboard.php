<?php
session_start();
require_once 'db_config.php';

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

// Initialize stats array with default values
$stats = [
    'total_athletes' => 0,
    'total_staff' => 0,
    'active_sports' => 0,
    'total_courses' => 0,
    'recent_athletes' => [],
    'gender_distribution' => [],
    'top_sports' => [],
    'all_sports' => [], // Will contain all sports with athlete counts
    'login_time' => isset($_SESSION['login_time']) ? $_SESSION['login_time'] : time()
];

// Get user info from session with defaults
$username = isset($_SESSION['username']) ? $_SESSION['username'] : 'User';
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : 'staff';

try {
    // Check athletes table for total athletes
    try {
        $pdo->query("SELECT 1 FROM athletes LIMIT 1");
        
        // Total Athletes
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM athletes");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['total_athletes'] = $result['total'] ?? 0;
        
        // Athletes by Course
        $stmt = $pdo->query("SELECT COUNT(DISTINCT course) as total FROM athletes WHERE course IS NOT NULL AND course != ''");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['total_courses'] = $result['total'] ?? 0;
        
        // Recent Athletes (last 4 added)
        $stmt = $pdo->query("SELECT 
                            a.first_name, 
                            a.middle_initial, 
                            a.last_name,
                            cs.sport_name as sport,
                            cs.gender as sport_gender,
                            a.year_level,
                            a.course,
                            a.created_at
                        FROM athletes a
                        LEFT JOIN competition_sports cs ON a.competition_sport_id = cs.id
                        ORDER BY a.created_at DESC LIMIT 4");
        $recent = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format full name for each athlete
        foreach ($recent as &$athlete) {
            $middle = !empty($athlete['middle_initial']) ? $athlete['middle_initial'] . ' ' : '';
            $athlete['full_name'] = trim($athlete['first_name'] . ' ' . $middle . $athlete['last_name']);
            
            // Format sport with gender if available
            if (!empty($athlete['sport']) && !empty($athlete['sport_gender'])) {
                $athlete['sport_display'] = $athlete['sport'] . ' (' . $athlete['sport_gender'] . ')';
            } else {
                $athlete['sport_display'] = $athlete['sport'] ?? 'No Sport Assigned';
            }
        }
        $stats['recent_athletes'] = $recent;
        
        // Athletes by Gender
        $stmt = $pdo->query("SELECT 
                            gender, 
                            COUNT(*) as count 
                        FROM athletes 
                        WHERE gender IS NOT NULL AND gender != ''
                        GROUP BY gender");
        $stats['gender_distribution'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        error_log("Athletes table check failed: " . $e->getMessage());
    }
    
    // Check competition_sports table for ALL sports with athlete counts
    try {
        // Get all active sports with athlete counts
        $stmt = $pdo->query("SELECT 
                            cs.id,
                            cs.sport_name,
                            cs.gender,
                            cs.status,
                            COUNT(a.id) as athlete_count,
                            cs.max_players
                        FROM competition_sports cs
                        LEFT JOIN athletes a ON cs.id = a.competition_sport_id
                        WHERE cs.status = 'active'
                        GROUP BY cs.id, cs.sport_name, cs.gender, cs.status, cs.max_players
                        ORDER BY cs.sport_name, cs.gender");
        $stats['all_sports'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Count total active sports
        $stats['active_sports'] = count($stats['all_sports']);
        
        // Get top sports by athlete count (for other uses)
        $stats['top_sports'] = array_slice($stats['all_sports'], 0, 5);
        
    } catch (PDOException $e) {
        error_log("Competition sports table check failed: " . $e->getMessage());
        $stats['all_sports'] = [];
        $stats['active_sports'] = 0;
    }
    
    // Check users table for total staff
    try {
        $pdo->query("SELECT 1 FROM users LIMIT 1");
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM users");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['total_staff'] = $result['total'] ?? 0;
    } catch (PDOException $e) {
        $stats['total_staff'] = 0;
        error_log("Users table check failed: " . $e->getMessage());
    }
    
} catch(PDOException $e) {
    // Log error but don't display to users
    error_log("Dashboard error: " . $e->getMessage());
}

// Prepare data for chart
$chart_labels = [];
$chart_data = [];
$chart_colors = [];

// Generate colors for chart
function generateColors($count) {
    $colors = [];
    $baseColors = [
        '#0c3a1d', '#1a5c2f', '#2e7d32', '#388e3c', '#43a047', '#4caf50', '#66bb6a', '#81c784', '#a5d6a7', '#c8e6c9',
        '#00695c', '#00796b', '#00897b', '#009688', '#26a69a', '#4db6ac', '#80cbc4', '#b2dfdb',
        '#01579b', '#0277bd', '#0288d1', '#039be5', '#03a9f4', '#29b6f6', '#4fc3f7', '#81d4fa', '#b3e5fc',
        '#4527a0', '#5e35b1', '#673ab7', '#7e57c2', '#9575cd', '#b39ddb', '#d1c4e9'
    ];
    
    for ($i = 0; $i < $count; $i++) {
        $colors[] = $baseColors[$i % count($baseColors)];
    }
    return $colors;
}

// Prepare chart data from all_sports
foreach ($stats['all_sports'] as $sport) {
    // Format label with gender
    $label = $sport['sport_name'];
    if (!empty($sport['gender'])) {
        $label .= ' (' . $sport['gender'] . ')';
    }
    $chart_labels[] = $label;
    $chart_data[] = $sport['athlete_count'];
}
$chart_colors = generateColors(count($chart_labels));

// Calculate total athletes from all sports
$total_athletes_from_sports = array_sum(array_column($stats['all_sports'], 'athlete_count'));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <title>TAU Sports - Dashboard</title>
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
            height: 100vh;
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #0c3a1d 0%, #1a5c2f 100%);
            color: white;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 12px rgba(12, 58, 29, 0.2);
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            height: 80px;
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
            overflow: hidden;
        }
        
        .logo-circle img {
            width: 100%;
            height: 100%;
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
            height: calc(100vh - 80px);
            margin-top: 80px;
            overflow: hidden;
        }
        
        .sidebar {
            width: 250px;
            background: white;
            border-right: 1px solid #e0e0e0;
            padding: 30px 0;
            box-shadow: 2px 0 10px rgba(0,0,0,0.05);
            height: 100%;
            overflow-y: auto;
            position: fixed;
            left: 0;
            top: 80px;
            bottom: 0;
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
        
        .nav-link i {
            margin-right: 10px;
            color: #0c3a1d;
            width: 20px;
            text-align: center;
        }
        
        .content {
            flex: 1;
            margin-left: 250px;
            padding: 30px;
            overflow-y: auto;
            height: 100%;
        }
        
        .page-title {
            color: #0c3a1d;
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e0e0e0;
        }
        
        .dashboard-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 25px;
            margin-top: 20px;
        }
        
        .card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            transition: transform 0.3s, box-shadow 0.3s;
            border-top: 4px solid #0c3a1d;
        }
        
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(12, 58, 29, 0.15);
        }
        
        .card h3 {
            color: #0c3a1d;
            margin-bottom: 15px;
            font-size: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .card h3 i {
            color: #0c3a1d;
            font-size: 24px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 30px;
        }
        
        .stat-card {
            background: linear-gradient(135deg, #0c3a1d, #1a5c2f);
            color: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 5px 15px rgba(12, 58, 29, 0.2);
        }
        
        .stat-number {
            font-size: 36px;
            font-weight: bold;
            margin-bottom: 5px;
            color: #ffd700;
        }
        
        .stat-label {
            font-size: 14px;
            opacity: 0.9;
        }
        
        .footer {
            text-align: center;
            padding: 20px;
            color: #777;
            font-size: 12px;
            border-top: 1px solid #e0e0e0;
            margin-top: 40px;
            background: white;
            border-radius: 10px;
        }
        
        .no-data {
            color: #999;
            font-style: italic;
            text-align: center;
            padding: 20px;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            padding-bottom: 8px;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .info-label {
            font-weight: 600;
            color: #555;
        }
        
        .info-value {
            color: #0c3a1d;
            font-weight: 500;
        }
        
        .chart-container {
            position: relative;
            height: 350px;
            width: 100%;
            margin-top: 15px;
        }
        
        .chart-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-top: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
        }
        
        .legend-color {
            width: 16px;
            height: 16px;
            border-radius: 4px;
        }
        
        .chart-wrapper {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            align-items: start;
        }
        
        .sports-stats {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            max-height: 350px;
            overflow-y: auto;
        }
        
        .sports-stats h4 {
            color: #0c3a1d;
            margin-bottom: 10px;
            font-size: 16px;
            border-bottom: 1px solid #ddd;
            padding-bottom: 5px;
        }
        
        .sport-stat-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px dashed #e0e0e0;
        }
        
        .sport-stat-name {
            font-weight: 500;
            color: #555;
            flex: 1;
        }
        
        .sport-stat-count {
            background: #0c3a1d;
            color: white;
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            min-width: 40px;
            text-align: center;
        }
        
        .sport-gender-badge {
            font-size: 10px;
            color: #888;
            margin-left: 5px;
        }
        
        .total-athletes-badge {
            background: #ffd700;
            color: #0c3a1d;
            padding: 3px 12px;
            border-radius: 20px;
            font-weight: bold;
            font-size: 14px;
        }
        
        .sport-meta {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .sport-status {
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 10px;
            background: #e8f5e9;
            color: #2e7d32;
        }
        
        .recent-athlete-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .athlete-info small {
            color: #888;
            font-size: 11px;
        }
        
        .sport-badge {
            display: inline-block;
            padding: 4px 12px;
            background: #e8f5e9;
            color: #2e7d32;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .gender-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 600;
            margin-right: 10px;
            margin-bottom: 5px;
        }
        
        .gender-male {
            background-color: #e3f2fd;
            color: #1976d2;
        }
        
        .gender-female {
            background-color: #fce4ec;
            color: #c2185b;
        }
        
        .gender-other {
            background-color: #f3e5f5;
            color: #7b1fa2;
        }
        
        .action-link {
            color: #0c3a1d;
            text-decoration: none;
            font-weight: 500;
        }
        
        .action-link:hover {
            text-decoration: underline;
        }
        
        @media (max-width: 768px) {
            .chart-wrapper {
                grid-template-columns: 1fr;
            }
            
            .sidebar {
                width: 200px;
            }
            
            .content {
                margin-left: 200px;
            }
        }
        
    </style>
</head>
<body>
    <div class="header">
        <div class="header-left">
            <div class="university-badge">
                <div class="logo-circle">
                    <img src="taulogo.png" alt="TAU Logo">
                </div> 
                <div class="logo-circle">
                    <img src="sdologo.png" alt="SDO Logo">
                </div>
                <div class="university-info">
                    <h1>TARLAC AGRICULTURAL UNIVERSITY</h1>
                    <div class="subtitle">Sports Development Office</div>
                </div>
            </div>
        </div>
        
        <div class="user-info">
            <div class="user-welcome">
                <div class="user-name">Welcome, <?php echo htmlspecialchars($username); ?></div>
                <div class="user-role"><?php echo htmlspecialchars(strtoupper($user_role)); ?></div>
            </div>
            <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>
    
    <div class="main-container">
        <nav class="sidebar">
            <ul class="nav-menu">
                <li class="nav-item"><a href="dashboard.php" class="nav-link active"><i class="fas fa-home"></i> Dashboard</a></li>
                <li class="nav-item"><a href="athletes.php" class="nav-link"><i class="fas fa-users"></i> Athlete Profiles</a></li>
                <li class="nav-item"><a href="report.php" class="nav-link"><i class="fas fa-chart-bar"></i> Athletes Report</a></li>
                <li class="nav-item"><a href="borrowers_form.php" class="nav-link"><i class="fas fa-file"></i> Borrowers Form</a></li>
                <li class="nav-item"><a href="borrowers_list.php" class="nav-link"><i class="fas fa-clipboard"></i> Borrowers List</a></li>
            </ul>
        </nav>
        
        <main class="content">
            <h1 class="page-title"><i class="fas fa-tachometer-alt" style="margin-right: 10px;"></i> Athlete Management Dashboard</h1>
            
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($stats['total_athletes']); ?></div>
                    <div class="stat-label">Total Athletes</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($stats['active_sports']); ?></div>
                    <div class="stat-label">Active Sports</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($stats['total_staff']); ?></div>
                    <div class="stat-label">System Users</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($stats['total_courses']); ?></div>
                    <div class="stat-label">Courses Represented</div>
                </div>
            </div>
            
            <div class="dashboard-cards">
                <!-- Sports Distribution Chart Card -->
                <div class="card">
                    <h3><i class="fas fa-chart-pie"></i> Sports Distribution Overview</h3>
                    <div class="chart-wrapper">
                        <div class="chart-container">
                            <canvas id="sportsChart"></canvas>
                        </div>
                        <div class="sports-stats">
                            <h4><i class="fas fa-list"></i> Athletes per Sport</h4>
                            <?php if (!empty($stats['all_sports'])): ?>
                                <div style="text-align: right; margin-bottom: 10px;">
                                    <span class="total-athletes-badge">
                                        Total: <?php echo number_format($total_athletes_from_sports); ?> athletes
                                    </span>
                                </div>
                                <?php foreach ($stats['all_sports'] as $sport): ?>
                                    <div class="sport-stat-item">
                                        <span class="sport-stat-name">
                                            <i class="fas fa-medal" style="color: #ffd700; font-size: 12px;"></i>
                                            <?php echo htmlspecialchars($sport['sport_name']); ?>
                                            <span class="sport-gender-badge">(<?php echo htmlspecialchars($sport['gender']); ?>)</span>
                                        </span>
                                        <span class="sport-stat-count">
                                            <?php echo number_format($sport['athlete_count']); ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="no-data">No sports data available</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Legend for chart colors -->
                    <?php if (!empty($stats['all_sports'])): ?>
                        <div class="chart-legend">
                            <?php foreach ($stats['all_sports'] as $index => $sport): ?>
                                <div class="legend-item">
                                    <div class="legend-color" style="background-color: <?php echo $chart_colors[$index]; ?>"></div>
                                    <span><?php echo htmlspecialchars($sport['sport_name']); ?> (<?php echo htmlspecialchars($sport['gender']); ?>): <?php echo $sport['athlete_count']; ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- System Information Card -->
                <div class="card">
                    <h3><i class="fas fa-info-circle"></i> System Information</h3>
                    <div class="info-row">
                        <div class="info-label"><i class="fas fa-user"></i> Logged in as:</div>
                        <div class="info-value"><?php echo htmlspecialchars($username); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label"><i class="fas fa-user-tag"></i> User Role:</div>
                        <div class="info-value"><?php echo htmlspecialchars(ucfirst($user_role)); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label"><i class="fas fa-clock"></i> Login Time:</div>
                        <div class="info-value"><?php echo date('F j, Y g:i A', $stats['login_time']); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label"><i class="fas fa-calendar"></i> Current Time:</div>
                        <div class="info-value"><?php echo date('F j, Y g:i A'); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label"><i class="fas fa-database"></i> Database Status:</div>
                        <div class="info-value" style="color: #4CAF50;"><i class="fas fa-circle"></i> Connected</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label"><i class="fas fa-address-card"></i> Total Records:</div>
                        <div class="info-value">
                            <?php echo number_format($stats['total_athletes']); ?> 
                            athlete<?php echo $stats['total_athletes'] != 1 ? 's' : ''; ?>
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-label"><i class="fas fa-calendar-alt"></i> Current Semester:</div>
                        <div class="info-value">2nd Semester 2025-2026</div>
                    </div>
                </div>

                <!-- Recent Athletes Card -->
                <div class="card">
                    <h3><i class="fas fa-user-clock"></i> Recent Athletes</h3>
                    <?php if (!empty($stats['recent_athletes'])): ?>
                        <?php foreach ($stats['recent_athletes'] as $athlete): ?>
                            <div class="recent-athlete-item">
                                <div class="athlete-info">
                                    <strong><?php echo htmlspecialchars($athlete['full_name'] ?? 'N/A'); ?></strong><br>
                                    <small>
                                        <i class="fas fa-graduation-cap"></i> <?php echo htmlspecialchars($athlete['course'] ?? 'N/A'); ?> - 
                                        <i class="fas fa-layer-group"></i> <?php echo htmlspecialchars($athlete['year_level'] ?? 'N/A'); ?>
                                    </small>
                                </div>
                                <div>
                                    <span class="sport-badge">
                                        <i class="fas fa-futbol"></i> 
                                        <?php echo htmlspecialchars($athlete['sport_display'] ?? $athlete['sport'] ?? 'N/A'); ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <div style="text-align: center; margin-top: 15px;">
                            <a href="athletes.php" class="action-link"><i class="fas fa-arrow-right"></i> View All Athletes</a>
                        </div>
                    <?php else: ?>
                        <p class="no-data"><i class="fas fa-user-slash"></i> No athletes in the system</p>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="footer">
                <i class="fas fa-university"></i> Tarlac Agricultural University Sports Development System © <?php echo date('Y'); ?>
                | <i class="fas fa-shield-alt"></i> For authorized personnel only
                | <i class="fas fa-users"></i> <?php echo number_format($stats['total_athletes']); ?> athletes registered
                | <i class="fas fa-sports"></i> <?php echo number_format($stats['active_sports']); ?> active sports
            </div>
        </main>
    </div>

    <script>
        // Create the chart
        const ctx = document.getElementById('sportsChart').getContext('2d');
        
        // Chart data from PHP
        const chartLabels = <?php echo json_encode($chart_labels); ?>;
        const chartData = <?php echo json_encode($chart_data); ?>;
        const chartColors = <?php echo json_encode($chart_colors); ?>;
        
        new Chart(ctx, {
            type: 'pie',
            data: {
                labels: chartLabels,
                datasets: [{
                    data: chartData,
                    backgroundColor: chartColors,
                    borderWidth: 1,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false // Hide default legend (we have custom legend)
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.raw || 0;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                return `${label}: ${value} athletes (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>