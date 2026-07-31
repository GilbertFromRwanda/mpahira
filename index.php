<?php
require_once __DIR__ . '/config/database.php';

// Only show the splash once per session; repeat visits go straight to the shop.
if (!empty($_SESSION['seen_welcome'])) {
    redirect('shop');
}
$_SESSION['seen_welcome'] = true;

$base = relative_base();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mpahira</title>
    <link rel="icon" type="image/png" href="<?= $base ?>assets/images/mpahira_logo.png">
    <meta http-equiv="refresh" content="5;url=<?= $base ?>shop">
    <style>
        html, body {
            height: 100%;
            margin: 0;
            background: #2f9e44;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .welcome-logo-wrap {
            opacity: 0;
            animation: welcomeIn 0.6s ease-out forwards;
        }
        .welcome-logo {
            display: block;
            width: min(70vw, 320px);
            height: auto;
            border-radius: 24px;
            animation: welcomeFloat 2.4s ease-in-out 0.6s infinite;
        }
        @keyframes welcomeIn {
            to { opacity: 1; }
        }
        @keyframes welcomeFloat {
            0%, 100% { transform: translateY(0) scale(1); }
            50% { transform: translateY(-10px) scale(1.04); }
        }
        @media (prefers-reduced-motion: reduce) {
            .welcome-logo-wrap { animation: none; opacity: 1; }
            .welcome-logo { animation: none; }
        }
    </style>
</head>
<body>
    <div class="welcome-logo-wrap">
        <img class="welcome-logo" src="<?= $base ?>assets/images/mpahira_logo.png" alt="Mpahira">
    </div>
    <script>
        setTimeout(function () {
            window.location.replace('<?= $base ?>shop');
        }, 3000);
    </script>
</body>
</html>
