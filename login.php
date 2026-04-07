<?php
session_start();
require_once 'db_config.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password';
    } else {
        try {
            // For now, use simple comparison
            if ($username === 'admin' && $password === 'admin') {
                $_SESSION['user_id'] = 1;
                $_SESSION['username'] = 'admin';
                $_SESSION['role'] = 'admin';
                $_SESSION['logged_in'] = true;
                
                // Update database with proper hash
                $hash = password_hash('admin', PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (username, password, role) 
                                      VALUES (?, ?, ?) 
                                      ON DUPLICATE KEY UPDATE password = ?");
                $stmt->execute(['admin', $hash, 'admin', $hash]);
                
                header('Location: dashboard.php');
                exit();
            } else {
                $error = 'Invalid username or password';
            }
        } catch(PDOException $e) {
            $error = 'Login error: ' . $e->getMessage();
        }
    }
}

// If user is already logged in, redirect to dashboard
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header('Location: dashboard.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TAU Sports Development - Login</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #0c3a1d 0%, #1a5c2f 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        
        .login-wrapper {
            display: flex;
            width: 100%;
            max-width: 1000px;
            min-height: 600px;
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
        }
        
        .left-panel {
            flex: 1;
            background: linear-gradient(135deg, #0c3a1d 0%, #1a5c2f 100%);
            padding: 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            color: white;
            position: relative;
            overflow: hidden;
        }
        
        .left-panel::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" preserveAspectRatio="none"><path d="M0,0 L100,0 L100,100 Z" fill="white" fill-opacity="0.05"/></svg>');
            animation: float 20s linear infinite;
        }
        
        @keyframes float {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .logo-container {
            text-align: center;
            margin-bottom: 40px;
            position: relative;
            z-index: 1;
            width: 100%;
        }
        
        .logo-img {
            max-width: 200px;
            height: auto;
            margin-bottom: 20px;
            filter: drop-shadow(0 4px 6px rgba(0,0,0,0.1));
        }
        
        .logo-row {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 20px;
            margin-bottom: 25px;
            width: 100%;
        }
        
        .logo-circle {
            width: 120px;
            height: 120px;
            background: linear-gradient(45deg, #ffd700, #ffed4e);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 5px solid white;
            box-shadow: 0 8px 25px rgba(0,0,0,0.2);
            overflow: hidden;
        }
        
        .logo-circle img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .logo-placeholder {
            width: 120px;
            height: 120px;
            background: linear-gradient(45deg, #ffd700, #ffed4e);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 5px solid white;
            box-shadow: 0 8px 25px rgba(0,0,0,0.2);
        }
        
        .logo-text {
            text-align: center;
            color: #0c3a1d;
            font-weight: bold;
            padding: 10px;
        }
        
        .logo-text .main {
            font-size: 20px;
            line-height: 1.1;
            margin-bottom: 5px;
        }
        
        .logo-text .sub {
            font-size: 10px;
            line-height: 1.2;
        }
        
        .logo-text .year {
            font-size: 10px;
            margin-top: 8px;
            color: #1a5c2f;
            font-weight: bold;
        }
        
        .university-name {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 5px;
            color: #ffd700;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
        }
        
        .established {
            font-size: 16px;
            color: #b0ffc9;
            margin-bottom: 10px;
        }
        
        .motto {
            font-style: italic;
            font-size: 14px;
            color: #d4ffdc;
            text-align: center;
            max-width: 300px;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid rgba(255,255,255,0.2);
        }
        
        .sports-logo {
            max-width: 250px;
            height: auto;
            margin-top: 30px;
            filter: drop-shadow(0 4px 8px rgba(0,0,0,0.2));
        }
        
        .sports-title {
            font-size: 18px;
            font-weight: bold;
            margin-top: 15px;
            color: #ffd700;
            text-align: center;
        }
        
        .right-panel {
            flex: 1;
            padding: 60px 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            background: #ffffff;
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 40px;
        }
        
        .login-header h2 {
            color: #0c3a1d;
            font-size: 32px;
            margin-bottom: 10px;
            font-weight: 700;
        }
        
        .login-header p {
            color: #666;
            font-size: 16px;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #0c3a1d;
            font-weight: 600;
            font-size: 14px;
        }
        
        .form-group input {
            width: 100%;
            padding: 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 16px;
            transition: all 0.3s;
            background: #f9f9f9;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #0c3a1d;
            background: white;
            box-shadow: 0 0 0 3px rgba(12, 58, 29, 0.1);
        }
        
        .login-btn {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #0c3a1d 0%, #1a5c2f 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            margin-top: 10px;
        }
        
        .login-btn:hover {
            background: linear-gradient(135deg, #0a3018 0%, #154d24 100%);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(12, 58, 29, 0.2);
        }
        
        .error {
            background: #ffeaea;
            color: #c62828;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 25px;
            text-align: center;
            border-left: 4px solid #c62828;
            font-size: 14px;
        }
        
        .credentials-note {
            background: #f0f8ff;
            padding: 15px;
            border-radius: 8px;
            margin-top: 30px;
            border-left: 4px solid #0c3a1d;
        }
        
        .credentials-note h4 {
            color: #0c3a1d;
            margin-bottom: 10px;
            font-size: 16px;
        }
        
        .credentials-note p {
            color: #555;
            font-size: 14px;
            margin-bottom: 5px;
        }
        
        .credential-item {
            display: flex;
            justify-content: space-between;
            margin-top: 8px;
            padding: 8px;
            background: white;
            border-radius: 5px;
        }
        
        .credential-label {
            font-weight: 600;
            color: #0c3a1d;
        }
        
        .credential-value {
            font-family: monospace;
            color: #1a5c2f;
            font-weight: bold;
        }
        
        .footer {
            text-align: center;
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #eee;
            color: #777;
            font-size: 12px;
        }
        
        @media (max-width: 768px) {
            .login-wrapper {
                flex-direction: column;
                max-width: 450px;
            }
            
            .left-panel {
                padding: 30px 20px;
            }
            
            .logo-row {
                flex-direction: column;
                gap: 15px;
            }
            
            .right-panel {
                padding: 40px 20px;
            }
        }
    </style>
</head>
<body>
    <div class="login-wrapper">
        <!-- Left Panel - University Branding -->
        <div class="left-panel">
            <div class="logo-container">
                <div class="logo-row">
                    <!-- Left Logo -->
                    <div class="logo-circle">
                        <!-- Replace with actual image -->
                        <img src="taulogo.png" alt="">
                       <!--  <div class="logo-text">
                            <div class="main">TAU</div>
                            <div class="sub">LOGO 1</div>
                            <div class="year">1945</div>
                        </div> -->
                    </div>
                    
                    <!-- Right Logo -->
                    <div class="logo-circle">
                        <!-- Replace with actual image -->
                        <img src="sdologo.png" alt=""> 
                        <!-- <div class="logo-text">
                            <div class="main">SPORTS</div>
                            <div class="sub">DEVELOPMENT</div>
                            <div class="year">TAU</div>
                        </div>-->
                    </div>
                </div>
                
                <div class="university-name">TARLAC AGRICULTURAL UNIVERSITY</div>
                <div class="established">Established 1945</div>
                <div class="motto">"Cultivating Minds, Harvesting Futures"</div>
            </div>
            
            <div class="sports-logo-placeholder" style="
                width: 250px;
                height: 120px;
                background: linear-gradient(45deg, #0c3a1d, #1a5c2f);
                border-radius: 10px;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 30px auto 0;
                position: relative;
                overflow: hidden;
            ">
                <div style="
                    width: 100%;
                    height: 40px;
                    background: #ffd700;
                    position: absolute;
                    top: 0;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-weight: bold;
                    color: #0c3a1d;
                    font-size: 18px;
                ">
                    SPORTS OFFICE
                </div>
                <div style="text-align: center; color: white; padding-top: 40px;">
                    <div style="font-size: 24px; font-weight: bold; line-height: 1.2;">TAU<br>SPORTS</div>
                    <div style="font-size: 14px; margin-top: 5px; color: #ffd700;">DEVELOPMENT PROGRAM</div>
                </div>
            </div>
        </div>
        
        <!-- Right Panel - Login Form -->
        <div class="right-panel">
            <div class="login-header">
                <h2>Athlete Profile System</h2>
                <p>Sports Development Office Dashboard</p>
            </div>
            
            <?php if ($error): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" required placeholder="Enter your username" value="admin">
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required placeholder="Enter your password" value="admin">
                </div>
                
                <button type="submit" class="login-btn">Login to Dashboard</button>
            </form>
            
            <div class="credentials-note">
                <h4>Default Login Credentials</h4>
                <p>Use these credentials for system access:</p>
                <div class="credential-item">
                    <span class="credential-label">Username:</span>
                    <span class="credential-value">admin</span>
                </div>
                <div class="credential-item">
                    <span class="credential-label">Password:</span>
                    <span class="credential-value">admin</span>
                </div>
            </div>
            
            <div class="footer">
                &copy; <?php echo date('Y'); ?> Tarlac Agricultural University - Sports Development Office
                <br>Version 1.0 | Secure Access Only
            </div>
        </div>
    </div>
</body>
</html>