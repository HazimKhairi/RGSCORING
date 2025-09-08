<?php
require_once 'config/database.php';
require_once 'auth.php';

startSecureSession();

// Redirect if already logged in
if (isLoggedIn()) {
    header("Location: dashboard.php");
    exit();
}

$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    
    if (!empty($username) && !empty($password)) {
        $auth = new Auth();
        if ($auth->login($username, $password)) {
            header("Location: dashboard.php");
            exit();
        } else {
            $error_message = "Invalid username or password";
        }
    } else {
        $error_message = "Please fill in all fields";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gymnastics Scoring System - Login</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            height: 100vh;
            overflow: hidden;
        }

        .login-container {
            display: flex;
            height: 100vh;
            width: 100%;
        }

        /* Left Side - Background Image */
        .background-section {
            flex: 1;
            background: url('login-bg.png') center center;
            background-size: cover;
            position: relative;
            overflow: hidden;
        }

        /* Right Side - Login Form */
        .login-form-section {
            flex: 1;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 3rem 2rem;
            position: relative;
        }

        .form-wrapper {
            width: 100%;
            max-width: 400px;
        }

        .profile-avatar {
            width: 80px;
            height: 80px;
            background: #E8E8E8;
            border-radius: 50%;
            margin: 0 auto 2rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: #666;
        }

        .welcome-text {
            text-align: center;
            margin-bottom: 2.5rem;
        }

        .welcome-text h1 {
            font-size: 2rem;
            color: #2D3748;
            margin-bottom: 0.5rem;
            font-weight: 600;
            font-family: 'Poppins', sans-serif;
        }

        .welcome-text p {
            color: #718096;
            font-size: 0.95rem;
            line-height: 1.5;
            font-weight: 400;
        }

        .login-form {
            margin-bottom: 1.5rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #4A5568;
            font-size: 0.9rem;
            font-weight: 500;
        }

        .form-group input {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid #E2E8F0;
            border-radius: 8px;
            font-size: 0.95rem;
            font-family: 'Poppins', sans-serif;
            transition: all 0.3s ease;
            background: #F7FAFC;
        }

        .form-group input:focus {
            outline: none;
            border-color: #805AD5;
            background: white;
            box-shadow: 0 0 0 3px rgba(128, 90, 213, 0.1);
        }

        .form-group input::placeholder {
            color: #A0AEC0;
            font-weight: 400;
        }

        .forgot-password {
            text-align: right;
            margin-bottom: 2rem;
        }

        .forgot-password a {
            color: #805AD5;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
        }

        .forgot-password a:hover {
            text-decoration: underline;
        }

        .login-btn {
            width: 100%;
            padding: 0.875rem;
            background: #805AD5;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            font-family: 'Poppins', sans-serif;
            cursor: pointer;
            transition: all 0.3s ease;
            text-transform: none;
            letter-spacing: 0;
        }

        .login-btn:hover {
            background: #6B46C1;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(128, 90, 213, 0.4);
        }

        .login-btn:active {
            transform: translateY(0);
        }

        .additional-options {
            text-align: center;
            margin-top: 2rem;
        }

        .signup-link {
            color: #718096;
            font-size: 0.9rem;
            font-weight: 400;
        }

        .signup-link a {
            color: #805AD5;
            text-decoration: none;
            font-weight: 600;
        }

        .signup-link a:hover {
            text-decoration: underline;
        }

        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            text-align: center;
            font-weight: 500;
            font-family: 'Poppins', sans-serif;
        }

        .alert-error {
            background: #FED7D7;
            color: #C53030;
            border: 1px solid #FEB2B2;
        }

        .alert-success {
            background: #C6F6D5;
            color: #22543D;
            border: 1px solid #9AE6B4;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .login-container {
                flex-direction: column;
            }
            
            .background-section {
                display: none;
            }
            
            .login-form-section {
                flex: none;
                height: 100vh;
                padding: 2rem 1rem;
            }
            
            .welcome-text h1 {
                font-size: 1.75rem;
            }
            
            .form-wrapper {
                max-width: 100%;
            }
        }

        @media (max-width: 480px) {
            .login-form-section {
                padding: 1.5rem 1rem;
            }
            
            .welcome-text h1 {
                font-size: 1.5rem;
            }
            
            .profile-avatar {
                width: 60px;
                height: 60px;
                font-size: 1.5rem;
                margin-bottom: 1.5rem;
            }
        }

        /* Loading Animation */
        .loading {
            opacity: 0.7;
            pointer-events: none;
        }

        .loading .login-btn {
            background: #A0AEC0;
            cursor: not-allowed;
        }

        .loading .login-btn::after {
            content: '';
            width: 16px;
            height: 16px;
            margin-left: 10px;
            border: 2px solid white;
            border-radius: 50%;
            border-top-color: transparent;
            display: inline-block;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <!-- Left Side - Background Image -->
        <div class="background-section">
        </div>

        <!-- Right Side - Login Form -->
        <div class="login-form-section">
            <div class="form-wrapper">
                <div class="profile-avatar">
                    👤
                </div>

                <div class="welcome-text">
                    <h1>Welcome Back</h1>
                    <p>Welcome back! Please enter your details to sign in to your account and continue your gymnastics journey.</p>
                </div>

                <?php if ($error_message): ?>
                    <div class="alert alert-error"><?php echo htmlspecialchars($error_message); ?></div>
                <?php endif; ?>

                <?php if ($success_message): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
                <?php endif; ?>

                <form method="POST" action="" class="login-form" id="loginForm">
                    <div class="form-group">
                        <label for="username">Email Address</label>
                        <input type="text" id="username" name="username" placeholder="Enter your email or username" required 
                               value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                    </div>

                    <div class="form-group">
                        <label for="password">Enter Password</label>
                        <input type="password" id="password" name="password" placeholder="Enter your password" required>
                    </div>

                    <div class="forgot-password">
                        <a href="leaderboard.php">Forgot Password?</a>
                    </div>

                    <button type="submit" class="login-btn" id="loginBtn">Login</button>
                </form>

                <div class="additional-options">
                    <p class="signup-link">
                        Don't have an account? <a href="leaderboard.php">View Live Scores</a>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Auto-focus on username field
        document.getElementById('username').focus();
        
        // Enhanced form validation and loading state
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const username = document.getElementById('username').value.trim();
            const password = document.getElementById('password').value;
            
            if (!username || !password) {
                e.preventDefault();
                alert('Please fill in all fields');
                return false;
            }
            
            // Add loading state
            const form = this;
            const btn = document.getElementById('loginBtn');
            form.classList.add('loading');
            btn.textContent = 'Signing In...';
        });
        
        // Add smooth input interactions
        document.querySelectorAll('.form-group input').forEach(input => {
            input.addEventListener('focus', function() {
                this.parentElement.style.transform = 'translateY(-2px)';
            });
            
            input.addEventListener('blur', function() {
                this.parentElement.style.transform = 'translateY(0)';
            });
        });
    </script>
</body>
</html>