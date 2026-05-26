<?php
session_start();
require_once __DIR__ . '/conn/conn.php';

$errors = [];
$first_name = $last_name = $email = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $first_name = trim($_POST["first_name"] ?? "");
    $last_name  = trim($_POST["last_name"] ?? "");
    $email      = trim($_POST["email"] ?? "");
    $password   = $_POST["password"] ?? "";
    $confirm    = $_POST["confirm_password"] ?? "";

    if ($first_name === "" || $last_name === "" || $email === "" || $password === "" || $confirm === "") {
        $errors[] = "All fields are required.";
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format.";
    }

    if (strlen($password) < 6) {
        $errors[] = "Password must be at least 6 characters.";
    }

    if ($password !== $confirm) {
        $errors[] = "Passwords do not match.";
    }

    if (empty($errors)) {
        $db = new Database();
        $pdo = $db->getConn();

        // Check if email already exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
        $stmt->execute([':email' => $email]);
        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
            $errors[] = "An account with this email already exists.";
        }
    }

    if (empty($errors)) {
        $db = new Database();
        $pdo = $db->getConn();

        $password_hash = password_hash($password, PASSWORD_DEFAULT); // secure hash [web:62][web:88]

        $stmt = $pdo->prepare(
            "INSERT INTO users (first_name, last_name, email, password_hash)
             VALUES (:first_name, :last_name, :email, :password_hash)"
        );
        $ok = $stmt->execute([
            ':first_name'    => $first_name,
            ':last_name'     => $last_name,
            ':email'         => $email,
            ':password_hash' => $password_hash
        ]);

        if ($ok) {
            header("Location: login.php?registered=1");
            exit;
        } else {
            $errors[] = "Something went wrong while creating your account.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>DevPortal - Register</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="stylesheet" href="style.css" />
</head>
<body class="dark auth-body">
  <div class="auth-card card">
    <div class="auth-header">
      <div class="logo">DevPortal</div>
      <h1>Create your account</h1>
      <p>Spin up your dashboard in a few seconds.</p>
    </div>

    <?php if (!empty($errors)): ?>
      <div class="card" style="margin-bottom:0.9rem; padding:0.75rem; border-color:#f97373;">
        <ul style="list-style:none; font-size:0.85rem; color:#fecaca;">
          <?php foreach ($errors as $e): ?>
            <li><?php echo htmlspecialchars($e); ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form class="auth-form" method="post" action="register.php">
      <div class="field-row">
        <label class="field">
          <span>First name</span>
          <input type="text" name="first_name" placeholder="Ada"
                 value="<?php echo htmlspecialchars($first_name); ?>" required />
        </label>
        <label class="field">
          <span>Last name</span>
          <input type="text" name="last_name" placeholder="Lovelace"
                 value="<?php echo htmlspecialchars($last_name); ?>" required />
        </label>
      </div>

      <label class="field">
        <span>Email</span>
        <input type="email" name="email" placeholder="you@devmail.com"
               value="<?php echo htmlspecialchars($email); ?>" required />
      </label>

      <label class="field">
        <span>Password</span>
        <input type="password" name="password" placeholder="Min. 8 characters" required />
      </label>

      <label class="field">
        <span>Confirm password</span>
        <input type="password" name="confirm_password" placeholder="Repeat password" required />
      </label>

      <button type="submit" class="btn btn-primary btn-full">
        Create account
      </button>
    </form>

    <p class="auth-alt">
      Already have an account?
      <a href="login.php" class="link">Log in</a>
    </p>

    <a href="index.html" class="auth-back link-quiet">← Back to landing</a>
  </div>
</body>
</html>