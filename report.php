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

// Get filter parameters
$selected_sport = isset($_GET['sport']) ? intval($_GET['sport']) : '';
$selected_competition = isset($_GET['competition']) ? intval($_GET['competition']) : '';
$search_name = isset($_GET['search_name']) ? trim($_GET['search_name']) : '';
$sort_by = isset($_GET['sort_by']) ? $_GET['sort_by'] : 'sport_name_asc';

// Handle AJAX request for getting ALL records for print
if (isset($_GET['get_all_for_print']) && $_GET['get_all_for_print'] == '1') {
    // Build query with filters (NO pagination)
    $where_conditions = [];
    $params = [];
    $types = "";

    // Competition filter
    if (!empty($selected_competition)) {
        $where_conditions[] = "comp.id = ?";
        $params[] = $selected_competition;
        $types .= "i";
    }

    // Sport filter
    if (!empty($selected_sport)) {
        $where_conditions[] = "a.competition_sport_id = ?";
        $params[] = $selected_sport;
        $types .= "i";
    }

    // Name search filter
    if (!empty($search_name)) {
        $where_conditions[] = "(a.first_name LIKE ? OR a.last_name LIKE ? OR CONCAT(a.first_name, ' ', a.last_name) LIKE ?)";
        $search_term = "%" . $search_name . "%";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
        $types .= "sss";
    }

    $where_sql = "";
    if (!empty($where_conditions)) {
        $where_sql = "WHERE " . implode(" AND ", $where_conditions);
    }

    // Determine sorting
    switch ($sort_by) {
        case 'sport_name_asc':
            $order_by = "ORDER BY cs.sport_name ASC, a.last_name ASC, a.first_name ASC";
            break;
        case 'sport_name_desc':
            $order_by = "ORDER BY cs.sport_name DESC, a.last_name ASC, a.first_name ASC";
            break;
        case 'athlete_name_asc':
            $order_by = "ORDER BY a.last_name ASC, a.first_name ASC, cs.sport_name ASC";
            break;
        case 'athlete_name_desc':
            $order_by = "ORDER BY a.last_name DESC, a.first_name DESC, cs.sport_name ASC";
            break;
        case 'competition_asc':
            $order_by = "ORDER BY comp.name ASC, cs.sport_name ASC, a.last_name ASC, a.first_name ASC";
            break;
        case 'competition_desc':
            $order_by = "ORDER BY comp.name DESC, cs.sport_name ASC, a.last_name ASC, a.first_name ASC";
            break;
        default:
            $order_by = "ORDER BY cs.sport_name ASC, a.last_name ASC, a.first_name ASC";
    }

    $query = "SELECT a.id, a.first_name, a.middle_initial, a.last_name,
              cs.sport_name,
              comp.name as competition_name,
              comp.year as competition_year
              FROM athletes a
              LEFT JOIN competition_sports cs ON a.competition_sport_id = cs.id
              LEFT JOIN competitions comp ON cs.competition_id = comp.id
              " . $where_sql . " 
              " . $order_by;

    if (!empty($params)) {
        $stmt = $conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $conn->query($query);
    }

    // Get filter names for display
    $filtered_sport_name = '';
    if (!empty($selected_sport)) {
        $sport_info_query = "SELECT cs.sport_name, comp.name as competition_name 
                            FROM competition_sports cs
                            LEFT JOIN competitions comp ON cs.competition_id = comp.id
                            WHERE cs.id = ?";
        $sport_info_stmt = $conn->prepare($sport_info_query);
        $sport_info_stmt->bind_param("i", $selected_sport);
        $sport_info_stmt->execute();
        $sport_info_result = $sport_info_stmt->get_result();
        if ($sport_info = $sport_info_result->fetch_assoc()) {
            $filtered_sport_name = $sport_info['sport_name'] . ' (' . ($sport_info['competition_name'] ?? 'No Competition') . ')';
        }
    }

    $filtered_competition_name = '';
    if (!empty($selected_competition)) {
        $comp_info_query = "SELECT name, year FROM competitions WHERE id = ?";
        $comp_info_stmt = $conn->prepare($comp_info_query);
        $comp_info_stmt->bind_param("i", $selected_competition);
        $comp_info_stmt->execute();
        $comp_info_result = $comp_info_stmt->get_result();
        if ($comp_info = $comp_info_result->fetch_assoc()) {
            $filtered_competition_name = $comp_info['name'] . ' (' . $comp_info['year'] . ')';
        }
    }

    // Output HTML for printing
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>TAU Athletes Report - Print</title>
        <style>
            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                margin: 0;
                padding: 20px;
                background: white;
            }
            
            .print-header {
                text-align: center;
                margin-bottom: 30px;
                padding-bottom: 20px;
                border-bottom: 2px solid #0c3a1d;
            }
            
            .print-header h1 {
                color: #0c3a1d;
                font-size: 24px;
                margin-bottom: 5px;
            }
            
            .print-header h2 {
                color: #1a5c2f;
                font-size: 18px;
                margin-bottom: 5px;
                font-weight: normal;
            }
            
            .print-header p {
                color: #666;
                font-size: 12px;
                margin: 5px 0;
            }
            
            .filter-info {
                background: #f5f5f5;
                padding: 10px 15px;
                margin-bottom: 20px;
                border-left: 4px solid #0c3a1d;
                font-size: 13px;
            }
            
            .filter-info p {
                margin: 5px 0;
            }
            
            table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 20px;
            }
            
            th {
                background: #0c3a1d;
                color: white;
                padding: 10px 8px;
                text-align: left;
                font-size: 13px;
            }
            
            td {
                padding: 8px;
                border-bottom: 1px solid #ddd;
                font-size: 12px;
            }
            
            .print-footer {
                text-align: center;
                margin-top: 30px;
                padding-top: 20px;
                border-top: 1px solid #ddd;
                font-size: 11px;
                color: #666;
            }
            
            @media print {
                body {
                    padding: 0;
                    margin: 0;
                }
                
                @page {
                    margin: 1.5cm;
                }
                
                .no-print {
                    display: none;
                }
            }
        </style>
    </head>
    <body>
        <div class="print-header">
            <h1>TARLAC AGRICULTURAL UNIVERSITY</h1>
            <h2>Sports Development Office - Athletes Report</h2>
            <?php if (!empty($filtered_competition_name)): ?>
                <p><strong>Competition Filter:</strong> <?php echo htmlspecialchars($filtered_competition_name); ?></p>
            <?php endif; ?>
            <?php if (!empty($filtered_sport_name)): ?>
                <p><strong>Sport Filter:</strong> <?php echo htmlspecialchars($filtered_sport_name); ?></p>
            <?php endif; ?>
            <?php if (!empty($search_name)): ?>
                <p><strong>Name Search:</strong> "<?php echo htmlspecialchars($search_name); ?>"</p>
            <?php endif; ?>
            <p><strong>Generated on:</strong> <?php echo date('F j, Y') . ' at ' . date('g:i A'); ?></p>
            <p><strong>Total Athletes:</strong> <?php echo $result->num_rows; ?></p>
        </div>
        
        <div class="filter-info">
            <p><strong>Sort By:</strong> 
                <?php
                switch ($sort_by) {
                    case 'sport_name_asc': echo 'Sport Name (A to Z)'; break;
                    case 'sport_name_desc': echo 'Sport Name (Z to A)'; break;
                    case 'athlete_name_asc': echo 'Athlete Name (A to Z)'; break;
                    case 'athlete_name_desc': echo 'Athlete Name (Z to A)'; break;
                    case 'competition_asc': echo 'Competition (A to Z)'; break;
                    case 'competition_desc': echo 'Competition (Z to A)'; break;
                    default: echo 'Sport Name (A to Z)';
                }
                ?>
            </p>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Sport</th>
                    <th>Athlete Name</th>
                    <th>Competition</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($result && $result->num_rows > 0): ?>
                    <?php $counter = 1; ?>
                    <?php while($row = $result->fetch_assoc()): ?>
                        <?php 
                        $full_name = trim($row['last_name'] . ', ' . $row['first_name'] . ' ' . ($row['middle_initial'] ? $row['middle_initial'] . ' ' : ''));
                        $sport_display = $row['sport_name'] ? $row['sport_name'] : 'Not Assigned';
                        $competition_display = $row['competition_name'] ? $row['competition_name'] . ' (' . $row['competition_year'] . ')' : 'N/A';
                        ?>
                        <tr>
                            <td><?php echo $counter++; ?></td>
                            <td><?php echo htmlspecialchars($sport_display); ?></td>
                            <td><?php echo htmlspecialchars($full_name); ?></td>
                            <td><?php echo htmlspecialchars($competition_display); ?></td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4" style="text-align: center; padding: 50px;">
                            No athletes found matching your criteria.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <div class="print-footer">
            <p>© <?php echo date('Y'); ?> Tarlac Agricultural University - Sports Development Office</p>
            <p>This is a system-generated report</p>
        </div>
        
        <script>
            // Automatically trigger print when page loads
            window.onload = function() {
                window.print();
                setTimeout(function() {
                    window.close();
                }, 1000);
            };
        </script>
    </body>
    </html>
    <?php
    exit();
}

// Fetch all competitions for filter dropdown
$competitions_query = "SELECT id, name, year FROM competitions ORDER BY name";
$competitions_result = $conn->query($competitions_query);
$competitions_list = [];
if ($competitions_result && $competitions_result->num_rows > 0) {
    while($row = $competitions_result->fetch_assoc()) {
        $competitions_list[] = $row;
    }
}

// Fetch sports based on selected competition
$sports_query = "SELECT DISTINCT cs.id, cs.sport_name, comp.name as competition_name 
                 FROM competition_sports cs
                 LEFT JOIN competitions comp ON cs.competition_id = comp.id
                 WHERE cs.status = 'active' OR cs.status IS NULL";

if (!empty($selected_competition)) {
    $sports_query .= " AND cs.competition_id = " . intval($selected_competition);
}

$sports_query .= " ORDER BY comp.name, cs.sport_name";
$sports_result = $conn->query($sports_query);
$sports_list = [];
if ($sports_result && $sports_result->num_rows > 0) {
    while($row = $sports_result->fetch_assoc()) {
        $sports_list[] = $row;
    }
}

// Pagination
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$records_per_page = 15;
$offset = ($page - 1) * $records_per_page;

// Build query with filters
$where_conditions = [];
$params = [];
$types = "";

// Competition filter
if (!empty($selected_competition)) {
    $where_conditions[] = "comp.id = ?";
    $params[] = $selected_competition;
    $types .= "i";
}

// Sport filter
if (!empty($selected_sport)) {
    $where_conditions[] = "a.competition_sport_id = ?";
    $params[] = $selected_sport;
    $types .= "i";
}

// Name search filter
if (!empty($search_name)) {
    $where_conditions[] = "(a.first_name LIKE ? OR a.last_name LIKE ? OR CONCAT(a.first_name, ' ', a.last_name) LIKE ?)";
    $search_term = "%" . $search_name . "%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= "sss";
}

// Build WHERE clause
$where_sql = "";
if (!empty($where_conditions)) {
    $where_sql = "WHERE " . implode(" AND ", $where_conditions);
}

// Determine sorting
switch ($sort_by) {
    case 'sport_name_asc':
        $order_by = "ORDER BY cs.sport_name ASC, a.last_name ASC, a.first_name ASC";
        break;
    case 'sport_name_desc':
        $order_by = "ORDER BY cs.sport_name DESC, a.last_name ASC, a.first_name ASC";
        break;
    case 'athlete_name_asc':
        $order_by = "ORDER BY a.last_name ASC, a.first_name ASC, cs.sport_name ASC";
        break;
    case 'athlete_name_desc':
        $order_by = "ORDER BY a.last_name DESC, a.first_name DESC, cs.sport_name ASC";
        break;
    case 'competition_asc':
        $order_by = "ORDER BY comp.name ASC, cs.sport_name ASC, a.last_name ASC, a.first_name ASC";
        break;
    case 'competition_desc':
        $order_by = "ORDER BY comp.name DESC, cs.sport_name ASC, a.last_name ASC, a.first_name ASC";
        break;
    default:
        $order_by = "ORDER BY cs.sport_name ASC, a.last_name ASC, a.first_name ASC";
}

// Get total records for pagination
$count_query = "SELECT COUNT(*) as total FROM athletes a 
                LEFT JOIN competition_sports cs ON a.competition_sport_id = cs.id
                LEFT JOIN competitions comp ON cs.competition_id = comp.id
                " . $where_sql;

if (!empty($params)) {
    $stmt = $conn->prepare($count_query);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $total_result = $stmt->get_result();
} else {
    $total_result = $conn->query($count_query);
}

$total_row = $total_result->fetch_assoc();
$total_records = $total_row['total'];
$total_pages = ceil($total_records / $records_per_page);

// Fetch athletes with pagination
$query = "SELECT a.id, a.first_name, a.middle_initial, a.last_name,
          cs.sport_name,
          comp.name as competition_name,
          comp.year as competition_year
          FROM athletes a
          LEFT JOIN competition_sports cs ON a.competition_sport_id = cs.id
          LEFT JOIN competitions comp ON cs.competition_id = comp.id
          " . $where_sql . " 
          " . $order_by . " 
          LIMIT ?, ?";

$params[] = $offset;
$params[] = $records_per_page;
$types .= "ii";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// Get filter names for display
$filtered_sport_name = '';
if (!empty($selected_sport)) {
    $sport_info_query = "SELECT cs.sport_name, comp.name as competition_name 
                        FROM competition_sports cs
                        LEFT JOIN competitions comp ON cs.competition_id = comp.id
                        WHERE cs.id = ?";
    $sport_info_stmt = $conn->prepare($sport_info_query);
    $sport_info_stmt->bind_param("i", $selected_sport);
    $sport_info_stmt->execute();
    $sport_info_result = $sport_info_stmt->get_result();
    if ($sport_info = $sport_info_result->fetch_assoc()) {
        $filtered_sport_name = $sport_info['sport_name'] . ' (' . ($sport_info['competition_name'] ?? 'No Competition') . ')';
    }
}

$filtered_competition_name = '';
if (!empty($selected_competition)) {
    $comp_info_query = "SELECT name, year FROM competitions WHERE id = ?";
    $comp_info_stmt = $conn->prepare($comp_info_query);
    $comp_info_stmt->bind_param("i", $selected_competition);
    $comp_info_stmt->execute();
    $comp_info_result = $comp_info_stmt->get_result();
    if ($comp_info = $comp_info_result->fetch_assoc()) {
        $filtered_competition_name = $comp_info['name'] . ' (' . $comp_info['year'] . ')';
    }
}

// Handle export requests
if (isset($_GET['export'])) {
    $export_type = $_GET['export'];
    
    if ($export_type == 'excel') {
        exportToExcel($conn, $selected_sport, $selected_competition, $search_name, $sort_by, $filtered_sport_name, $filtered_competition_name);
    } elseif ($export_type == 'word') {
        exportToWord($conn, $selected_sport, $selected_competition, $search_name, $sort_by, $filtered_sport_name, $filtered_competition_name);
    }
}

function exportToExcel($conn, $selected_sport, $selected_competition, $search_name, $sort_by, $filtered_sport_name, $filtered_competition_name) {
    // Build query for export (ALL records, no pagination)
    $where_conditions = [];
    
    if (!empty($selected_competition)) {
        $where_conditions[] = "comp.id = " . intval($selected_competition);
    }
    
    if (!empty($selected_sport)) {
        $where_conditions[] = "a.competition_sport_id = " . intval($selected_sport);
    }
    
    if (!empty($search_name)) {
        $search_term = $conn->real_escape_string($search_name);
        $where_conditions[] = "(a.first_name LIKE '%$search_term%' OR a.last_name LIKE '%$search_term%' OR CONCAT(a.first_name, ' ', a.last_name) LIKE '%$search_term%')";
    }
    
    $where_sql = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";
    
    // Determine sorting for export
    switch ($sort_by) {
        case 'sport_name_asc':
            $order_by = "ORDER BY cs.sport_name ASC, a.last_name ASC, a.first_name ASC";
            break;
        case 'sport_name_desc':
            $order_by = "ORDER BY cs.sport_name DESC, a.last_name ASC, a.first_name ASC";
            break;
        case 'athlete_name_asc':
            $order_by = "ORDER BY a.last_name ASC, a.first_name ASC, cs.sport_name ASC";
            break;
        case 'athlete_name_desc':
            $order_by = "ORDER BY a.last_name DESC, a.first_name DESC, cs.sport_name ASC";
            break;
        case 'competition_asc':
            $order_by = "ORDER BY comp.name ASC, cs.sport_name ASC, a.last_name ASC, a.first_name ASC";
            break;
        case 'competition_desc':
            $order_by = "ORDER BY comp.name DESC, cs.sport_name ASC, a.last_name ASC, a.first_name ASC";
            break;
        default:
            $order_by = "ORDER BY cs.sport_name ASC, a.last_name ASC, a.first_name ASC";
    }
    
    $query = "SELECT a.first_name, a.middle_initial, a.last_name,
              cs.sport_name,
              comp.name as competition_name,
              comp.year as competition_year
              FROM athletes a
              LEFT JOIN competition_sports cs ON a.competition_sport_id = cs.id
              LEFT JOIN competitions comp ON cs.competition_id = comp.id
              " . $where_sql . " 
              " . $order_by;
    
    $result = $conn->query($query);
    
    // Set headers for Excel download
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="TAU_Athletes_Report_' . date('Y-m-d') . '.xls"');
    header('Cache-Control: max-age=0');
    header('Pragma: public');
    
    // Create HTML table for Excel
    echo '<html>';
    echo '<head>';
    echo '<meta charset="UTF-8">';
    echo '<title>TAU Athletes Report</title>';
    echo '<style>';
    echo 'body { font-family: Arial, sans-serif; margin: 20px; }';
    echo 'h1 { color: #0c3a1d; }';
    echo 'h2 { color: #1a5c2f; }';
    echo 'table { border-collapse: collapse; width: 100%; margin-top: 20px; }';
    echo 'th { background-color: #0c3a1d; color: white; padding: 8px; border: 1px solid #0c3a1d; }';
    echo 'td { padding: 6px; border: 1px solid #ddd; }';
    echo '.filter-info { margin: 20px 0; padding: 10px; background: #f5f5f5; }';
    echo '</style>';
    echo '</head>';
    echo '<body>';
    
    // Report Header
    echo '<h1>TARLAC AGRICULTURAL UNIVERSITY</h1>';
    echo '<h2>Sports Development Office - Athletes Report</h2>';
    
    // Filter Information
    echo '<div class="filter-info">';
    if (!empty($selected_competition) && $filtered_competition_name) {
        echo '<p><strong>Competition Filter:</strong> ' . htmlspecialchars(strip_tags($filtered_competition_name)) . '</p>';
    }
    if (!empty($selected_sport) && $filtered_sport_name) {
        echo '<p><strong>Sport Filter:</strong> ' . htmlspecialchars(strip_tags($filtered_sport_name)) . '</p>';
    }
    if (!empty($search_name)) {
        echo '<p><strong>Name Search:</strong> "' . htmlspecialchars($search_name) . '"</p>';
    }
    echo '<p><strong>Generated on:</strong> ' . date('F j, Y') . ' at ' . date('g:i A') . '</p>';
    echo '<p><strong>Total Athletes:</strong> ' . $result->num_rows . '</p>';
    echo '</div>';
    
    // Data Table
    echo '<table border="1" cellpadding="5" cellspacing="0">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>#</th>';
    echo '<th>Sport</th>';
    echo '<th>Athlete Name</th>';
    echo '<th>Competition</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    
    $counter = 1;
    while($row = $result->fetch_assoc()) {
        $full_name = trim($row['last_name'] . ', ' . $row['first_name'] . ' ' . ($row['middle_initial'] ? $row['middle_initial'] . ' ' : ''));
        $sport_display = $row['sport_name'] ? $row['sport_name'] : 'Not Assigned';
        $competition_display = $row['competition_name'] ? $row['competition_name'] . ' (' . $row['competition_year'] . ')' : 'N/A';
        
        echo '<tr>';
        echo '<td>' . $counter++ . '</td>';
        echo '<td>' . htmlspecialchars($sport_display) . '</td>';
        echo '<td>' . htmlspecialchars($full_name) . '</td>';
        echo '<td>' . htmlspecialchars($competition_display) . '</td>';
        echo '</tr>';
    }
    
    echo '</tbody>';
    echo '</table>';
    
    // Footer
    echo '<div style="margin-top: 30px; text-align: center; font-size: 11px; color: #666;">';
    echo '<p>© ' . date('Y') . ' Tarlac Agricultural University - Sports Development Office</p>';
    echo '<p>This is a system-generated report</p>';
    echo '</div>';
    
    echo '</body>';
    echo '</html>';
    exit();
}

function exportToWord($conn, $selected_sport, $selected_competition, $search_name, $sort_by, $filtered_sport_name, $filtered_competition_name) {
    // Build query for export (ALL records, no pagination)
    $where_conditions = [];
    
    if (!empty($selected_competition)) {
        $where_conditions[] = "comp.id = " . intval($selected_competition);
    }
    
    if (!empty($selected_sport)) {
        $where_conditions[] = "a.competition_sport_id = " . intval($selected_sport);
    }
    
    if (!empty($search_name)) {
        $search_term = $conn->real_escape_string($search_name);
        $where_conditions[] = "(a.first_name LIKE '%$search_term%' OR a.last_name LIKE '%$search_term%' OR CONCAT(a.first_name, ' ', a.last_name) LIKE '%$search_term%')";
    }
    
    $where_sql = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";
    
    // Determine sorting for export
    switch ($sort_by) {
        case 'sport_name_asc':
            $order_by = "ORDER BY cs.sport_name ASC, a.last_name ASC, a.first_name ASC";
            break;
        case 'sport_name_desc':
            $order_by = "ORDER BY cs.sport_name DESC, a.last_name ASC, a.first_name ASC";
            break;
        case 'athlete_name_asc':
            $order_by = "ORDER BY a.last_name ASC, a.first_name ASC, cs.sport_name ASC";
            break;
        case 'athlete_name_desc':
            $order_by = "ORDER BY a.last_name DESC, a.first_name DESC, cs.sport_name ASC";
            break;
        case 'competition_asc':
            $order_by = "ORDER BY comp.name ASC, cs.sport_name ASC, a.last_name ASC, a.first_name ASC";
            break;
        case 'competition_desc':
            $order_by = "ORDER BY comp.name DESC, cs.sport_name ASC, a.last_name ASC, a.first_name ASC";
            break;
        default:
            $order_by = "ORDER BY cs.sport_name ASC, a.last_name ASC, a.first_name ASC";
    }
    
    $query = "SELECT a.first_name, a.middle_initial, a.last_name,
              cs.sport_name,
              comp.name as competition_name,
              comp.year as competition_year
              FROM athletes a
              LEFT JOIN competition_sports cs ON a.competition_sport_id = cs.id
              LEFT JOIN competitions comp ON cs.competition_id = comp.id
              " . $where_sql . " 
              " . $order_by;
    
    $result = $conn->query($query);
    
    // Set headers for Word download
    header('Content-Type: application/msword');
    header('Content-Disposition: attachment; filename="TAU_Athletes_Report_' . date('Y-m-d') . '.doc"');
    header('Cache-Control: max-age=0');
    header('Pragma: public');
    
    // Create Word document with proper table formatting
    echo '<!DOCTYPE html>';
    echo '<html>';
    echo '<head>';
    echo '<meta charset="UTF-8">';
    echo '<title>TAU Athletes Report</title>';
    echo '<style>';
    echo 'body { font-family: "Times New Roman", Times, serif; margin: 1in; }';
    echo 'h1 { color: #0c3a1d; font-size: 24pt; margin-bottom: 5pt; text-align: center; }';
    echo 'h2 { color: #1a5c2f; font-size: 18pt; margin-top: 0; text-align: center; }';
    echo 'table { border-collapse: collapse; width: 100%; margin-top: 20pt; }';
    echo 'th { background: #0c3a1d; color: white; padding: 8pt; text-align: left; border: 1px solid #0c3a1d; }';
    echo 'td { padding: 6pt; border: 1px solid #ddd; }';
    echo '.header-info { margin-bottom: 20pt; }';
    echo '.filter-info { background: #f5f5f5; padding: 10pt; border-left: 4pt solid #0c3a1d; margin-bottom: 15pt; }';
    echo '.footer { margin-top: 30pt; text-align: center; font-size: 10pt; color: #666; }';
    echo '</style>';
    echo '</head>';
    echo '<body>';
    
    // Report Header
    echo '<div class="header-info">';
    echo '<h1>TARLAC AGRICULTURAL UNIVERSITY</h1>';
    echo '<h2>Sports Development Office - Athletes Report</h2>';
    
    // Filter Information
    echo '<div class="filter-info">';
    if (!empty($selected_competition) && $filtered_competition_name) {
        echo '<p><strong>Competition Filter:</strong> ' . htmlspecialchars(strip_tags($filtered_competition_name)) . '</p>';
    }
    if (!empty($selected_sport) && $filtered_sport_name) {
        echo '<p><strong>Sport Filter:</strong> ' . htmlspecialchars(strip_tags($filtered_sport_name)) . '</p>';
    }
    if (!empty($search_name)) {
        echo '<p><strong>Name Search:</strong> "' . htmlspecialchars($search_name) . '"</p>';
    }
    echo '<p><strong>Generated on:</strong> ' . date('F j, Y') . ' at ' . date('g:i A') . '</p>';
    echo '<p><strong>Total Athletes:</strong> ' . $result->num_rows . '</p>';
    echo '</div>';
    echo '</div>';
    
    // Data Table with proper borders
    echo '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse: collapse; width: 100%;">';
    echo '<thead>';
    echo '<tr style="background: #0c3a1d;">';
    echo '<th style="color: white; padding: 8pt; border: 1px solid #0c3a1d;">#</th>';
    echo '<th style="color: white; padding: 8pt; border: 1px solid #0c3a1d;">Sport</th>';
    echo '<th style="color: white; padding: 8pt; border: 1px solid #0c3a1d;">Athlete Name</th>';
    echo '<th style="color: white; padding: 8pt; border: 1px solid #0c3a1d;">Competition</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    
    $counter = 1;
    while($row = $result->fetch_assoc()) {
        $full_name = trim($row['last_name'] . ', ' . $row['first_name'] . ' ' . ($row['middle_initial'] ? $row['middle_initial'] . ' ' : ''));
        $sport_display = $row['sport_name'] ? $row['sport_name'] : 'Not Assigned';
        $competition_display = $row['competition_name'] ? $row['competition_name'] . ' (' . $row['competition_year'] . ')' : 'N/A';
        
        echo '<tr>';
        echo '<td style="padding: 6pt; border: 1px solid #ddd;">' . $counter++ . '</td>';
        echo '<td style="padding: 6pt; border: 1px solid #ddd;">' . htmlspecialchars($sport_display) . '</td>';
        echo '<td style="padding: 6pt; border: 1px solid #ddd;">' . htmlspecialchars($full_name) . '</td>';
        echo '<td style="padding: 6pt; border: 1px solid #ddd;">' . htmlspecialchars($competition_display) . '</td>';
        echo '</tr>';
    }
    
    echo '</tbody>';
    echo '</table>';
    
    // Footer
    echo '<div class="footer">';
    echo '<p>© ' . date('Y') . ' Tarlac Agricultural University - Sports Development Office</p>';
    echo '<p>This is a system-generated report</p>';
    echo '</div>';
    
    echo '</body>';
    echo '</html>';
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <title>Athletes Report | TAU Sports</title>
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
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
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
            position: relative;
        }
        
        .sidebar {
            width: 250px;
            background: white;
            border-right: 1px solid #e0e0e0;
            padding: 30px 0;
            box-shadow: 2px 0 10px rgba(0,0,0,0.05);
            position: sticky;
            top: 80px;
            height: calc(100vh - 80px);
            overflow-y: auto;
            align-self: flex-start;
        }
        
        .sidebar::-webkit-scrollbar {
            width: 5px;
        }
        
        .sidebar::-webkit-scrollbar-track {
            background: #f1f1f1;
        }
        
        .sidebar::-webkit-scrollbar-thumb {
            background: #0c3a1d;
            border-radius: 5px;
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
        
        .filters-section {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
        
        .filter-row {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: center;
        }
        
        .filter-row:first-child {
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .filter-group {
            flex: 1;
            min-width: 200px;
        }
        
        .filter-group label {
            display: block;
            margin-bottom: 5px;
            font-size: 12px;
            font-weight: 600;
            color: #666;
            text-transform: uppercase;
        }
        
        .filter-group select,
        .filter-group input {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .filter-group input {
            border: 1px solid #ddd;
            transition: border-color 0.3s;
        }
        
        .filter-group input:focus {
            outline: none;
            border-color: #0c3a1d;
            box-shadow: 0 0 0 3px rgba(12, 58, 29, 0.1);
        }
        
        .search-box {
            position: relative;
        }
        
        .search-box i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
        }
        
        .search-box input {
            padding-left: 35px;
        }
        
        .filter-actions {
            display: flex;
            gap: 10px;
            align-items: flex-end;
        }
        
        .filter-actions button {
            padding: 10px 20px;
            background: #0c3a1d;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            min-width: 100px;
        }
        
        .filter-actions button:hover {
            background: #1a5c2f;
        }
        
        .btn-reset {
            background: #6c757d !important;
        }
        
        .btn-reset:hover {
            background: #5a6268 !important;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            margin-left: auto;
        }
        
        .btn-print {
            background: #28a745;
            color: white;
            text-decoration: none;
            padding: 10px 20px;
            border-radius: 5px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: background 0.3s;
            border: none;
            cursor: pointer;
        }
        
        .btn-print:hover {
            background: #218838;
        }
        
        .btn-excel {
            background: #217346;
            color: white;
            text-decoration: none;
            padding: 10px 20px;
            border-radius: 5px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: background 0.3s;
        }
        
        .btn-excel:hover {
            background: #1a5e38;
        }
        
        .btn-word {
            background: #2b5797;
            color: white;
            text-decoration: none;
            padding: 10px 20px;
            border-radius: 5px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: background 0.3s;
        }
        
        .btn-word:hover {
            background: #1e3f6e;
        }
        
        .stats-bar {
            background: white;
            padding: 15px 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .total-count {
            font-weight: bold;
            color: #0c3a1d;
            font-size: 18px;
        }
        
        .active-filters {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .filter-tag {
            background: #ffd700;
            color: #0c3a1d;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .filter-tag i {
            font-size: 12px;
        }
        
        .clear-filters {
            color: #666;
            text-decoration: none;
            font-size: 13px;
            padding: 5px 10px;
            border-radius: 20px;
            background: #f0f0f0;
        }
        
        .clear-filters:hover {
            background: #e0e0e0;
        }
        
        .table-container {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        
        th {
            background: #0c3a1d;
            color: white;
            padding: 12px 10px;
            font-weight: 600;
            text-align: left;
        }
        
        td {
            padding: 12px 10px;
            border-bottom: 1px solid #e0e0e0;
            vertical-align: middle;
        }
        
        tr:hover {
            background: #f8f9fa;
        }
        
        .pagination {
            margin-top: 20px;
            display: flex;
            justify-content: center;
            gap: 5px;
        }
        
        .pagination a, .pagination span {
            padding: 8px 12px;
            border: 1px solid #ddd;
            text-decoration: none;
            color: #333;
            border-radius: 3px;
        }
        
        .pagination a:hover {
            background: #f0f0f0;
        }
        
        .pagination .active {
            background: #0c3a1d;
            color: white;
            border-color: #0c3a1d;
        }
        
        .footer {
            margin-top: 20px;
            text-align: center;
            color: #6c757d;
            font-size: 12px;
        }
        
        .no-results {
            text-align: center;
            padding: 50px !important;
            color: #666;
            font-style: italic;
        }
        
        @media (max-width: 768px) {
            .filter-row {
                flex-direction: column;
                align-items: stretch;
            }
            
            .filter-group {
                min-width: auto;
            }
            
            .action-buttons {
                flex-wrap: wrap;
                margin-left: 0;
                justify-content: stretch;
            }
            
            .action-buttons a,
            .action-buttons button {
                flex: 1;
                text-align: center;
                justify-content: center;
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
                <li class="nav-item"><a href="report.php" class="nav-link active"><i class="fas fa-chart-bar"></i> Athletes Report</a></li>
                <li class="nav-item"><a href="borrowers_form.php" class="nav-link"><i class="fas fa-file"></i> Borrowers Form</a></li>
                <li class="nav-item"><a href="borrowers_list.php" class="nav-link"><i class="fas fa-clipboard"></i> Borrowers List</a></li>
            </ul>
        </nav>
        
        <main class="content">
            <h1 class="page-title">Athletes Report</h1>
            
            <div class="filters-section">
                <form method="GET" id="filterForm">
                    <div class="filter-row">
                        <div class="filter-group">
                            <label for="competition"><i class="fas fa-trophy"></i> Filter by Competition</label>
                            <select name="competition" id="competition">
                                <option value="">All Competitions</option>
                                <?php foreach ($competitions_list as $competition): ?>
                                    <option value="<?php echo $competition['id']; ?>" <?php echo $selected_competition == $competition['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($competition['name'] . ' (' . $competition['year'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label for="sport"><i class="fas fa-filter"></i> Filter by Sport</label>
                            <select name="sport" id="sport">
                                <option value="">All Sports</option>
                                <?php foreach ($sports_list as $sport): ?>
                                    <option value="<?php echo $sport['id']; ?>" <?php echo $selected_sport == $sport['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($sport['sport_name'] . ' (' . ($sport['competition_name'] ?? 'No Competition') . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label for="sort_by"><i class="fas fa-sort"></i> Sort by</label>
                            <select name="sort_by" id="sort_by">
                                <option value="sport_name_asc" <?php echo $sort_by == 'sport_name_asc' ? 'selected' : ''; ?>>Sport Name (A to Z)</option>
                                <option value="sport_name_desc" <?php echo $sort_by == 'sport_name_desc' ? 'selected' : ''; ?>>Sport Name (Z to A)</option>
                                <option value="athlete_name_asc" <?php echo $sort_by == 'athlete_name_asc' ? 'selected' : ''; ?>>Athlete Name (A to Z)</option>
                                <option value="athlete_name_desc" <?php echo $sort_by == 'athlete_name_desc' ? 'selected' : ''; ?>>Athlete Name (Z to A)</option>
                                <option value="competition_asc" <?php echo $sort_by == 'competition_asc' ? 'selected' : ''; ?>>Competition (A to Z)</option>
                                <option value="competition_desc" <?php echo $sort_by == 'competition_desc' ? 'selected' : ''; ?>>Competition (Z to A)</option>
                            </select>
                        </div>
                        
                        <div class="filter-actions">
                            <button type="submit">Apply Filters</button>
                            <a href="?" class="btn-reset" style="padding: 10px 20px; background: #6c757d; color: white; text-decoration: none; border-radius: 5px; text-align: center;">Reset</a>
                        </div>
                    </div>
                    
                    <div class="filter-row">
                        <div class="filter-group search-box">
                            <label for="search_name"><i class="fas fa-search"></i> Search Athlete by Name</label>
                            <i class="fas fa-search"></i>
                            <input type="text" name="search_name" id="search_name" 
                                   placeholder="Enter first name, last name, or full name..." 
                                   value="<?php echo htmlspecialchars($search_name); ?>">
                        </div>
                        
                        <div class="action-buttons">
                            <button type="button" onclick="printAllRecords()" class="btn-print">
                                <i class="fas fa-print"></i> Print All Records
                            </button>
                            <a href="?export=excel<?php 
                                echo !empty($selected_competition) ? '&competition=' . $selected_competition : '';
                                echo !empty($selected_sport) ? '&sport=' . $selected_sport : ''; 
                                echo !empty($search_name) ? '&search_name=' . urlencode($search_name) : '';
                                echo '&sort_by=' . urlencode($sort_by);
                            ?>" class="btn-excel" onclick="return confirmExport()">
                                <i class="fas fa-file-excel"></i> Export Excel
                            </a>
                            <a href="?export=word<?php 
                                echo !empty($selected_competition) ? '&competition=' . $selected_competition : '';
                                echo !empty($selected_sport) ? '&sport=' . $selected_sport : ''; 
                                echo !empty($search_name) ? '&search_name=' . urlencode($search_name) : '';
                                echo '&sort_by=' . urlencode($sort_by);
                            ?>" class="btn-word" onclick="return confirmExport()">
                                <i class="fas fa-file-word"></i> Export Word
                            </a>
                        </div>
                    </div>
                </form>
            </div>
            
            <div class="stats-bar">
                <span>Total Athletes: <span class="total-count"><?php echo $total_records; ?></span></span>
                
                <div class="active-filters">
                    <?php if (!empty($selected_competition) && $filtered_competition_name): ?>
                        <span class="filter-tag">
                            <i class="fas fa-trophy"></i> <?php echo htmlspecialchars($filtered_competition_name); ?>
                        </span>
                    <?php endif; ?>
                    
                    <?php if (!empty($selected_sport) && $filtered_sport_name): ?>
                        <span class="filter-tag">
                            <i class="fas fa-filter"></i> <?php echo htmlspecialchars($filtered_sport_name); ?>
                        </span>
                    <?php endif; ?>
                    
                    <?php if (!empty($search_name)): ?>
                        <span class="filter-tag">
                            <i class="fas fa-search"></i> "<?php echo htmlspecialchars($search_name); ?>"
                        </span>
                    <?php endif; ?>
                    
                    <?php if (!empty($selected_competition) || !empty($selected_sport) || !empty($search_name)): ?>
                        <a href="?" class="clear-filters">
                            <i class="fas fa-times"></i> Clear All Filters
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="table-container">
                <table id="athletesTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Sport</th>
                            <th>Athlete Name</th>
                            <th>Competition</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result && $result->num_rows > 0): ?>
                            <?php $counter = $offset + 1; ?>
                            <?php while($row = $result->fetch_assoc()): ?>
                                <?php 
                                $full_name = trim($row['last_name'] . ', ' . $row['first_name'] . ' ' . ($row['middle_initial'] ? $row['middle_initial'] . ' ' : ''));
                                $sport_display = $row['sport_name'] ? $row['sport_name'] : 'Not Assigned';
                                $competition_display = $row['competition_name'] ? $row['competition_name'] . ' (' . $row['competition_year'] . ')' : 'N/A';
                                ?>
                                <tr>
                                    <td><?php echo $counter++; ?></td>
                                    <td><?php echo htmlspecialchars($sport_display); ?></td>
                                    <td><?php echo htmlspecialchars($full_name); ?></td>
                                    <td><?php echo htmlspecialchars($competition_display); ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" class="no-results">
                                    <i class="fas fa-search" style="font-size: 24px; margin-bottom: 10px; display: block;"></i>
                                    No athletes found matching your criteria.
                                    <?php if (!empty($search_name) || !empty($selected_sport) || !empty($selected_competition)): ?>
                                        <br><a href="?" style="color: #0c3a1d; text-decoration: underline; margin-top: 10px; display: inline-block;">Clear filters and try again</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if($total_pages > 1): ?>
            <div class="pagination">
                <?php if($page > 1): ?>
                    <a href="?page=<?php echo ($page - 1); ?>&competition=<?php echo urlencode($selected_competition); ?>&sport=<?php echo urlencode($selected_sport); ?>&search_name=<?php echo urlencode($search_name); ?>&sort_by=<?php echo urlencode($sort_by); ?>">Previous</a>
                <?php endif; ?>
                
                <?php for($i = 1; $i <= $total_pages; $i++): ?>
                    <?php if($i == $page): ?>
                        <span class="active"><?php echo $i; ?></span>
                    <?php else: ?>
                        <a href="?page=<?php echo $i; ?>&competition=<?php echo urlencode($selected_competition); ?>&sport=<?php echo urlencode($selected_sport); ?>&search_name=<?php echo urlencode($search_name); ?>&sort_by=<?php echo urlencode($sort_by); ?>"><?php echo $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                
                <?php if($page < $total_pages): ?>
                    <a href="?page=<?php echo ($page + 1); ?>&competition=<?php echo urlencode($selected_competition); ?>&sport=<?php echo urlencode($selected_sport); ?>&search_name=<?php echo urlencode($search_name); ?>&sort_by=<?php echo urlencode($sort_by); ?>">Next</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <div class="footer">
                <p>© <?php echo date('Y'); ?> Tarlac Agricultural University - Sports Development Office</p>
            </div>
        </main>
    </div>
    
    <script>
        // Print function that fetches ALL records based on current filters
        function printAllRecords() {
            const totalRecords = <?php echo $total_records; ?>;
            
            if (totalRecords === 0) {
                alert('No records to print.');
                return;
            }
            
            // Show loading state
            const printBtn = document.querySelector('.btn-print');
            const originalText = printBtn.innerHTML;
            printBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Preparing print...';
            printBtn.disabled = true;
            
            // Get current filter parameters
            const params = new URLSearchParams();
            params.append('get_all_for_print', '1');
            
            <?php if (!empty($selected_competition)): ?>
                params.append('competition', '<?php echo $selected_competition; ?>');
            <?php endif; ?>
            
            <?php if (!empty($selected_sport)): ?>
                params.append('sport', '<?php echo $selected_sport; ?>');
            <?php endif; ?>
            
            <?php if (!empty($search_name)): ?>
                params.append('search_name', '<?php echo urlencode($search_name); ?>');
            <?php endif; ?>
            
            <?php if (!empty($sort_by)): ?>
                params.append('sort_by', '<?php echo $sort_by; ?>');
            <?php endif; ?>
            
            // Open a new window for printing
            const printWindow = window.open('', '_blank', 'width=1000,height=800,toolbar=yes,scrollbars=yes,resizable=yes');
            printWindow.document.write('<html><head><title>Loading Report...</title></head><body style="text-align:center; padding-top:100px;">');
            printWindow.document.write('<h2>Loading athletes report...</h2>');
            printWindow.document.write('<p>Please wait while we prepare your document.</p>');
            printWindow.document.write('</body></html>');
            
            // Fetch all records
            fetch('report.php?' + params.toString())
                .then(response => response.text())
                .then(html => {
                    printWindow.document.open();
                    printWindow.document.write(html);
                    printWindow.document.close();
                })
                .catch(error => {
                    console.error('Error:', error);
                    printWindow.document.write('<p style="color:red;">Error loading data. Please try again.</p>');
                    alert('Error loading data for printing. Please try again.');
                })
                .finally(() => {
                    printBtn.innerHTML = originalText;
                    printBtn.disabled = false;
                });
        }
        
        // Confirm export function
        function confirmExport() {
            return confirm('Export this report?');
        }
        
        // Auto-submit form when competition, sport, or sort changes
        document.getElementById('competition').addEventListener('change', function() {
            document.getElementById('filterForm').submit();
        });
        
        document.getElementById('sport').addEventListener('change', function() {
            document.getElementById('filterForm').submit();
        });
        
        document.getElementById('sort_by').addEventListener('change', function() {
            document.getElementById('filterForm').submit();
        });
        
        // Add search delay to prevent too many submissions while typing
        let searchTimeout;
        document.getElementById('search_name').addEventListener('keyup', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                if (this.value.length >= 2 || this.value.length === 0) {
                    document.getElementById('filterForm').submit();
                }
            }, 500);
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>