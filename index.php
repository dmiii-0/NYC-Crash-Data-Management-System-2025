<?php
// index.php in /htdocs (root)

// Redirect root to your app in /php
header("Location: /php/index.html", true, 302);
exit;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Redirecting...</title>
</head>
<body>
  <p>If you are not redirected automatically, <a href="/php/index.html">click here</a>.</p>
</body>
</html>