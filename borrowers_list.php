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

// Handle status update if user is admin/director
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_status'])) {
    $borrower_id = intval($_POST['borrower_id']);
    $new_status = $conn->real_escape_string($_POST['status']);
    $disapproval_remarks = $conn->real_escape_string($_POST['disapproval_remarks'] ?? '');
    
    $update_sql = "UPDATE borrowers SET status = '$new_status', disapproval_remarks = '$disapproval_remarks' WHERE id = $borrower_id";
    $conn->query($update_sql);
    
    // Redirect to refresh the page
    header('Location: borrowers_list.php?updated=1');
    exit();
}

// Handle delete if user has permission
if (isset($_GET['delete'])) {
    $borrower_id = intval($_GET['delete']);
    
    // First delete related items (foreign key constraint)
    $conn->query("DELETE FROM borrowed_items WHERE borrower_id = $borrower_id");
    // Then delete borrower
    $conn->query("DELETE FROM borrowers WHERE id = $borrower_id");
    
    header('Location: borrowers_list.php?deleted=1');
    exit();
}

// Pagination
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$records_per_page = 10;
$offset = ($page - 1) * $records_per_page;

// Search functionality
$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $conn->real_escape_string($_GET['status']) : '';

// Build query with filters
$where_clauses = [];
if (!empty($search)) {
    $where_clauses[] = "(name LIKE '%$search%' OR id_number LIKE '%$search%' OR contact_number LIKE '%$search%')";
}
if (!empty($status_filter)) {
    $where_clauses[] = "status = '$status_filter'";
}

$where_sql = '';
if (!empty($where_clauses)) {
    $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
}

// Get total records for pagination
$total_sql = "SELECT COUNT(*) as total FROM borrowers $where_sql";
$total_result = $conn->query($total_sql);
$total_row = $total_result->fetch_assoc();
$total_records = $total_row['total'];
$total_pages = ceil($total_records / $records_per_page);

// Fetch borrowers with pagination
$sql = "SELECT * FROM borrowers $where_sql ORDER BY created_at DESC LIMIT $offset, $records_per_page";
$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <title>Borrowers List | TAU Sports</title>
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
        
        .filters-section {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: center;
            justify-content: space-between;
        }
        
        .search-box {
            flex: 1;
            min-width: 300px;
            display: flex;
            gap: 10px;
        }
        
        .search-box input {
            flex: 1;
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
            font-family: 'Times New Roman';
        }
        
        .search-box button {
            padding: 10px 20px;
            background: #0c3a1d;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
        }
        
        .status-filter select {
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
            font-family: 'Times New Roman';
            min-width: 150px;
        }
        
        .btn-add {
            background: #28a745;
            color: white;
            text-decoration: none;
            padding: 10px 25px;
            border-radius: 5px;
            font-weight: 600;
            display: inline-block;
            transition: background 0.3s;
        }
        
        .btn-add:hover {
            background: #218838;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            padding: 12px 20px;
            border-radius: 5px;
            margin-bottom: 20px;
            font-family: Arial, sans-serif;
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
        
        .status-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
            text-transform: uppercase;
        }
        
        .status-approved {
            background: #d4edda;
            color: #155724;
        }
        
        .status-pending {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-disapproved {
            background: #f8d7da;
            color: #721c24;
        }
        
        .status-returned {
            background: #cce5ff;
            color: #004085;
        }
        
        .action-buttons {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }
        
        .btn-action {
            padding: 5px 10px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-view {
            background: #17a2b8;
            color: white;
        }
        
        .btn-edit {
            background: #ffc107;
            color: #333;
        }
        
        .btn-delete {
            background: #dc3545;
            color: white;
        }
        
        .btn-print {
            background: #6c757d;
            color: white;
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
        
        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        
        .modal-content {
            background-color: white;
            margin: 10% auto;
            padding: 30px;
            border-radius: 10px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 5px 30px rgba(0,0,0,0.3);
        }
        
        .modal h3 {
            color: #0c3a1d;
            margin-bottom: 20px;
        }
        
        .modal select, .modal textarea {
            width: 100%;
            padding: 10px;
            margin-bottom: 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-family: 'Times New Roman';
        }
        
        .modal textarea {
            min-height: 100px;
            resize: vertical;
        }
        
        .modal-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        
        .btn-modal-save {
            background: #28a745;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
        }
        
        .btn-modal-close {
            background: #6c757d;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
        }
        
        @media (max-width: 768px) {
            .filters-section {
                flex-direction: column;
                align-items: stretch;
            }
            
            .search-box {
                min-width: auto;
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
                <li class="nav-item"><a href="report.php" class="nav-link"><i class="fas fa-chart-bar"></i> Athletes Report</a></li>
                <li class="nav-item"><a href="borrowers_form.php" class="nav-link"><i class="fas fa-file"></i> Borrowers Form</a></li>
                <li class="nav-item"><a href="borrowers_list.php" class="nav-link active"><i class="fas fa-clipboard"></i> Borrowers List</a></li>
            </ul>
        </nav>
        
        <main class="content">
            <h1 class="page-title">Borrowers List</h1>
            
            <?php if(isset($_GET['updated'])): ?>
            <div class="alert-success">✓ Status updated successfully!</div>
            <?php endif; ?>
            
            <?php if(isset($_GET['deleted'])): ?>
            <div class="alert-success">✓ Record deleted successfully!</div>
            <?php endif; ?>
            
            <div class="filters-section">
                <form method="GET" class="search-box" id="searchForm">
                    <input type="text" name="search" placeholder="Search by name, ID number, or contact..." value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit">Search</button>
                </form>
                
                <div class="status-filter">
                    <select name="status" form="searchForm" onchange="this.form.submit()">
                        <option value="">All Status</option>
                        <option value="Pending" <?php echo $status_filter == 'Pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="Approved" <?php echo $status_filter == 'Approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="Disapproved" <?php echo $status_filter == 'Disapproved' ? 'selected' : ''; ?>>Disapproved</option>
                        <option value="Returned" <?php echo $status_filter == 'Returned' ? 'selected' : ''; ?>>Returned</option>
                    </select>
                </div>
                
                <a href="borrowers_form.php" class="btn-add">+ New Borrowers Form</a>
            </div>
            
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Date</th>
                            <th>Borrower</th>
                            <th>Type</th>
                            <th>College/Unit</th>
                            <th>Contact</th>
                            <th>ID Number</th>
                            <th>Return Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result->num_rows > 0): ?>
                            <?php while($row = $result->fetch_assoc()): ?>
                                <tr>
                                    <td>#<?php echo $row['id']; ?></td>
                                    <td><?php echo date('M d, Y', strtotime($row['borrow_date'])); ?></td>
                                    <td><?php echo htmlspecialchars($row['name']); ?></td>
                                    <td>
                                        <?php 
                                        echo $row['borrower_type'];
                                        if ($row['borrower_type'] == 'Others' && !empty($row['other_type'])) {
                                            echo ' (' . htmlspecialchars($row['other_type']) . ')';
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($row['college_unit']); ?></td>
                                    <td><?php echo htmlspecialchars($row['contact_number']); ?></td>
                                    <td><?php echo htmlspecialchars($row['id_number']); ?></td>
                                    <td>
                                        <?php 
                                        if (!empty($row['return_date'])) {
                                            echo date('M d, Y', strtotime($row['return_date']));
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                        $status_class = '';
                                        switch($row['status']) {
                                            case 'Approved':
                                                $status_class = 'status-approved';
                                                break;
                                            case 'Disapproved':
                                                $status_class = 'status-disapproved';
                                                break;
                                            case 'Returned':
                                                $status_class = 'status-returned';
                                                break;
                                            default:
                                                $status_class = 'status-pending';
                                        }
                                        ?>
                                        <span class="status-badge <?php echo $status_class; ?>">
                                            <?php echo $row['status']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="borrowers_form.php?view_id=<?php echo $row['id']; ?>" class="btn-action btn-view">View</a>
                                            
                                            <?php if($_SESSION['role'] == 'admin' || $_SESSION['role'] == 'director'): ?>
                                                <button onclick="openStatusModal(<?php echo $row['id']; ?>, '<?php echo $row['status']; ?>', '<?php echo htmlspecialchars($row['disapproval_remarks'] ?? ''); ?>')" class="btn-action btn-edit">Status</button>
                                            <?php endif; ?>
                                            
                                            <a href="borrowers_form.php?view_id=<?php echo $row['id']; ?>" class="btn-action btn-print" target="_blank">Print</a>
                                            
                                            <?php if($_SESSION['role'] == 'admin'): ?>
                                                <a href="?delete=<?php echo $row['id']; ?>" class="btn-action btn-delete" onclick="return confirm('Are you sure you want to delete this record? This action cannot be undone.')">Delete</a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="10" style="text-align: center; padding: 50px; color: #666;">
                                    No borrowers records found.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if($total_pages > 1): ?>
            <div class="pagination">
                <?php if($page > 1): ?>
                    <a href="?page=<?php echo ($page - 1); ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>">Previous</a>
                <?php endif; ?>
                
                <?php for($i = 1; $i <= $total_pages; $i++): ?>
                    <?php if($i == $page): ?>
                        <span class="active"><?php echo $i; ?></span>
                    <?php else: ?>
                        <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>"><?php echo $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                
                <?php if($page < $total_pages): ?>
                    <a href="?page=<?php echo ($page + 1); ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>">Next</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </main>
    </div>
    
    <!-- Status Update Modal -->
    <div id="statusModal" class="modal">
        <div class="modal-content">
            <h3>Update Status</h3>
            <form method="POST" id="statusForm">
                <input type="hidden" name="borrower_id" id="modal_borrower_id">
                
                <label for="modal_status">Status:</label>
                <select name="status" id="modal_status" required>
                    <option value="Pending">Pending</option>
                    <option value="Approved">Approved</option>
                    <option value="Disapproved">Disapproved</option>
                    <option value="Returned">Returned</option>
                </select>
                
                <label for="modal_remarks">Remarks for Disapproval (if applicable):</label>
                <textarea name="disapproval_remarks" id="modal_remarks" placeholder="Enter reason for disapproval..."></textarea>
                
                <div class="modal-actions">
                    <button type="button" onclick="closeStatusModal()" class="btn-modal-close">Cancel</button>
                    <button type="submit" name="update_status" class="btn-modal-save">Update Status</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function openStatusModal(borrowerId, currentStatus, remarks) {
            document.getElementById('statusModal').style.display = 'block';
            document.getElementById('modal_borrower_id').value = borrowerId;
            document.getElementById('modal_status').value = currentStatus;
            document.getElementById('modal_remarks').value = remarks;
            
            // Enable/disable remarks based on status
            toggleRemarksField();
        }
        
        function closeStatusModal() {
            document.getElementById('statusModal').style.display = 'none';
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            var modal = document.getElementById('statusModal');
            if (event.target == modal) {
                closeStatusModal();
            }
        }
        
        // Toggle remarks field based on status selection
        document.getElementById('modal_status').addEventListener('change', function() {
            toggleRemarksField();
        });
        
        function toggleRemarksField() {
            var status = document.getElementById('modal_status').value;
            var remarks = document.getElementById('modal_remarks');
            
            if (status === 'Disapproved') {
                remarks.disabled = false;
                remarks.required = true;
            } else {
                remarks.disabled = true;
                remarks.required = false;
                remarks.value = '';
            }
        }
    </script>
</body>
</html>
<?php $conn->close(); ?>