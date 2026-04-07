<?php
session_start();
require_once 'db_config.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $year = trim($_POST['year']);
    $description = trim($_POST['description'] ?? '');

    try {
        $stmt = $pdo->prepare("INSERT INTO competitions (name, year, description) VALUES (?, ?, ?)");
        $stmt->execute([$name, $year, $description]);
        
        $_SESSION['success'] = "Competition added successfully!";
        header('Location: athletes.php');
        exit();
    } catch(PDOException $e) {
        $error = "Error adding competition: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Competition - TAU Sports</title>
 <style>
        /* Copy all CSS from athletes.php header, sidebar, etc. */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f7fa; color: #333; }
        .header { background: linear-gradient(135deg, #0c3a1d 0%, #1a5c2f 100%); color: white; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 12px rgba(12, 58, 29, 0.2); position: sticky; top: 0; z-index: 1000; }
        .header-left { display: flex; align-items: center; gap: 20px; }
        .university-badge { display: flex; align-items: center; gap: 10px; }
        .logo-circle { width: 50px; height: 50px; background: linear-gradient(45deg, #ffd700, #ffed4e); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; color: #0c3a1d; border: 3px solid white; box-shadow: 0 3px 10px rgba(0,0,0,0.2); }
        .university-info h1 { font-size: 20px; font-weight: 700; margin-bottom: 2px; }
        .university-info .subtitle { font-size: 12px; color: #b0ffc9; }
        .user-info { display: flex; align-items: center; gap: 20px; }
        .user-welcome { text-align: right; }
        .user-name { font-weight: 600; font-size: 16px; }
        .user-role { font-size: 12px; color: #ffd700; background: rgba(0,0,0,0.2); padding: 2px 10px; border-radius: 10px; margin-top: 2px; display: inline-block; }
        .logout-btn { background: white; color: #0c3a1d; border: none; padding: 8px 20px; border-radius: 6px; cursor: pointer; font-weight: 600; text-decoration: none; display: inline-block; transition: all 0.3s; }
        .logout-btn:hover { background: #f0f0f0; transform: translateY(-1px); box-shadow: 0 3px 10px rgba(0,0,0,0.1); }
        .main-container { display: flex; min-height: calc(100vh - 80px); }
        .sidebar { width: 250px; background: white; border-right: 1px solid #e0e0e0; padding: 30px 0; box-shadow: 2px 0 10px rgba(0,0,0,0.05); }
        .nav-menu { list-style: none; }
        .nav-item { margin-bottom: 5px; }
        .nav-link { display: block; padding: 15px 30px; color: #555; text-decoration: none; font-weight: 500; transition: all 0.3s; border-left: 4px solid transparent; }
        .nav-link:hover { background: #f8f9fa; color: #0c3a1d; border-left: 4px solid #0c3a1d; }
        .nav-link.active { background: linear-gradient(to right, rgba(12, 58, 29, 0.1), transparent); color: #0c3a1d; border-left: 4px solid #0c3a1d; font-weight: 600; }
        .content { flex: 1; padding: 30px; }
        
        /* Form specific styles */
        .page-title { color: #0c3a1d; font-size: 28px; font-weight: 700; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #e0e0e0; }
        
        .form-container {
            background: white;
            border-radius: 10px;
            padding: 30px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
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
        }
    </style>
</head>
<body>
    <!-- Use similar header and sidebar as add_athlete.php -->
    <div class="content">
        <div class="page-title">Add New Competition</div>
        
        <div class="form-container">
            <form method="POST" action="">
                <div class="form-group">
                    <label for="name" class="required">Competition Name</label>
                    <input type="text" id="name" name="name" required 
                           placeholder="e.g., SCUAA, SUC, NCAA">
                </div>
                
                <div class="form-group">
                    <label for="year" class="required">Year</label>
                    <input type="text" id="year" name="year" required 
                           placeholder="e.g., 2026" maxlength="4"
                           pattern="\d{4}">
                </div>
                
                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" 
                              placeholder="Optional description of the competition"
                              rows="4"></textarea>
                </div>
                
                <div class="form-actions">
                    <a href="athletes.php" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary">➕ Add Competition</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>