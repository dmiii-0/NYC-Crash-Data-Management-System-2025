<?php
// Enable error display temporarily for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/conn/conn.php';

$errors = [];
$registered_msg = "";

// If redirected from register.php with ?registered=1
if (isset($_GET["registered"])) {
    $registered_msg = "Account created successfully. You can log in now.";
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email    = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($email === "" || $password === "") {
        $errors[] = "Email and password are required.";
    } else {
        $db = new Database();
        $pdo = $db->getConn();

        if ($pdo) {
            $stmt = $pdo->prepare(
                "SELECT id, first_name, last_name, email, password_hash
                 FROM users
                 WHERE email = :email"
            );
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user["password_hash"])) {
                $_SESSION["user_id"]    = $user["id"];
                $_SESSION["user_name"]  = $user["first_name"] . " " . $user["last_name"];
                $_SESSION["user_email"] = $user["email"];

                header("Location: dashboard.php");
                exit;
            } else {
                $errors[] = "Invalid email or password.";
            }
        } else {
            $errors[] = "Database connection failed.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>DevPortal - Login</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="stylesheet" href="style.css" />
</head>
<body class="dark auth-body">
  <div class="auth-card card">
    <div class="auth-header">
      <div class="logo">DevPortal</div>
      <h1>Welcome back</h1>
      <p>Log in to access your dashboard.</p>
    </div>

    <?php if (!empty($registered_msg)): ?>
      <div class="card" style="margin-bottom:0.9rem; padding:0.75rem; border-color:#22c55e;">
        <p style="font-size:0.85rem; color:#bbf7d0;">
          <?php echo htmlspecialchars($registered_msg); ?>
        </p>
      </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
      <div class="card" style="margin-bottom:0.9rem; padding:0.75rem; border-color:#f97373;">
        <ul style="list-style:none; font-size:0.85rem; color:#fecaca;">
          <?php foreach ($errors as $e): ?>
            <li><?php echo htmlspecialchars($e); ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form class="auth-form" method="post" action="login.php">
      <label class="field">
        <span>Email</span>
        <input type="email" name="email" placeholder="you@devmail.com" required />
      </label>

      <label class="field">
        <span>Password</span>
        <input type="password" name="password" placeholder="••••••••" required />
      </label>

      <div class="field-inline">
        <label class="checkbox">
          <input type="checkbox" />
          <span>Remember me</span>
        </label>
        <a href="#" class="link">Forgot password?</a>
      </div>

      <button type="submit" class="btn btn-primary btn-full">
        Login
      </button>
    </form>

    <p class="auth-alt">
      New here?
      <a href="register.php" class="link">Create an account</a>
    </p>

    <a href="index.html" class="auth-back link-quiet">← Back to landing</a>
  </div>
</body>
</html>