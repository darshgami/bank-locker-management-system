<?php
/**
 * 404.php - Custom Error Page
 * Prevents blank screens for non-existent routes.
 */
require_once 'config/config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 - Page Not Found | <?= h(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --teal: #00c9b1;
            --bg: #0f172a;
            --card: #1e293b;
            --text: #e2e8f0;
            --text-muted: #94a3b8;
        }
        body {
            margin: 0;
            font-family: 'Inter', sans-serif;
            background-color: var(--bg);
            color: var(--text);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
        }
        .error-container {
            max-width: 500px;
            padding: 40px;
            background: var(--card);
            border-radius: 20px;
            box-shadow: 0 20px 50px rgba(0,0,0,0.3);
            border: 1px solid rgba(255,255,255,0.05);
        }
        h1 {
            font-size: 6rem;
            margin: 0;
            color: var(--teal);
            font-weight: 800;
        }
        h2 { margin-top: 0; font-size: 1.5rem; }
        p { color: var(--text-muted); margin-bottom: 30px; line-height: 1.6; }
        .btn {
            display: inline-block;
            background: var(--teal);
            color: #000;
            padding: 14px 28px;
            text-decoration: none;
            border-radius: 10px;
            font-weight: 600;
            transition: 0.3s;
        }
        .btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(0, 201, 177, 0.2);
        }
    </style>
</head>
<body>
    <div class="error-container">
        <h1>404</h1>
        <h2>Oops! Page Not Found</h2>
        <p>The page you are looking for might have been removed, had its name changed, or is temporarily unavailable.</p>
        <a href="<?= BASE_URL ?>/index.php" class="btn">
            <i class="fas fa-home"></i> Back to Dashboard
        </a>
    </div>
</body>
</html>
