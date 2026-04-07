<?php
session_start();
require_once 'db_config.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

$competitionId = isset($_GET['competition_id']) ? intval($_GET['competition_id']) : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sportName = trim($_POST['sport_name']);
    $gender = $_POST['gender'];
    $maxPlayers = intval($_POST['max_players']);
    $status = $_POST['status'];

    try {
        $stmt = $pdo->prepare("
            INSERT INTO competition_sports (competition_id, sport_name, gender, max_players, status) 
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$competitionId, $sportName, $gender, $maxPlayers, $status]);
        
        $_SESSION['success'] = "Sport added to competition successfully!";
        header("Location: athletes.php?competition_id=$competitionId");
        exit();
    } catch(PDOException $e) {
        $error = "Error adding sport: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Sport - TAU Sports</title>
</head>
<body>
    <!-- Use similar header and sidebar -->
    <div class="content">
        <div class="page-title">Add Sport to Competition</div>
        
        <div class="form-container">
            <form method="POST" action="">
                <div class="form-group">
                    <label for="sport_name" class="required">Sport Name</label>
                    <input type="text" id="sport_name" name="sport_name" required 
                           placeholder="e.g., Basketball, Volleyball, Swimming">
                </div>
                
<div class="form-group">
    <label for="gender" class="required">Gender Category</label>
    <select id="gender" name="gender" required>
        <option value="Male">Male</option>
        <option value="Female">Female</option>
        <option value="Mixed">Mixed (allows both male and female athletes)</option>
    </select>
    <small style="color: #666; display: block; margin-top: 5px;">
        For Mixed sports, athletes can be either Male or Female.
    </small>
</div>
                <div class="form-group">
                    <label for="max_players">Maximum Players (0 for unlimited)</label>
                    <input type="number" id="max_players" name="max_players" 
                           min="0" value="0">
                </div>
                
                <div class="form-group">
                    <label for="status" class="required">Status</label>
                    <select id="status" name="status" required>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                
                <div class="form-actions">
                    <a href="athletes.php?competition_id=<?php echo $competitionId; ?>" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary">➕ Add Sport</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>