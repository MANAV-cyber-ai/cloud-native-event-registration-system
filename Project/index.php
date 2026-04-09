<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>College Event Portal</title>
    <meta name="description" content="College Event Registration landing page for Tech Fest, Cultural Events, and Workshops.">
    <link rel="stylesheet" href="assets/css/site.css">
</head>
<body>
    <?php
    require_once __DIR__ . '/config/auth.php';
    $user = currentAuthUser();
    ?>
    <header class="topbar glass">
        <div class="container topbar-inner">
            <a href="index.php" class="logo">College Event Portal</a>
            <nav class="nav-links" aria-label="Main navigation">
                <a href="index.php" class="active">Home</a>
                <?php if ($user !== null && strtoupper((string) ($user['role'] ?? '')) === 'ADMIN'): ?>
                    <a href="admin.php">Admin Dashboard</a>
                    <a href="logout.php">Logout</a>
                <?php elseif ($user !== null): ?>
                    <a href="student-dashboard.php">Dashboard</a>
                    <a href="register.php">Register Event</a>
                    <a href="logout.php">Logout</a>
                <?php else: ?>
                    <a href="signup.php">Create Account</a>
                    <a href="login.php">Login</a>
                <?php endif; ?>
            </nav>
        </div>
    </header>

    <main class="container">
        <section class="hero-card stunning">
            <div class="hero-text">
                <h1>Welcome to College Event Registration</h1>
                <p>
                    Register for Tech Fest, Cultural Events, AI Workshops, Robotics sessions,
                    and seminars through one modern portal.
                </p>
                <div class="inline-actions">
                    <?php if ($user === null): ?>
                        <a class="btn btn-primary" href="signup.php">Create Account</a>
                        <a class="btn btn-ghost" href="login.php">Login</a>
                    <?php else: ?>
                        <a class="btn btn-primary" href="<?= strtoupper((string) ($user['role'] ?? '')) === 'ADMIN' ? 'admin.php' : 'register.php'; ?>">Continue</a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="hero-image-wrap">
                <img src="assets/images/campus-illustration.svg" alt="College campus illustration">
            </div>
        </section>

        <section class="stats-grid showcase">
            <article><p>Account-First Flow</p><h3>Signup -> Login -> Register</h3></article>
            <article><p>Student Profile</p><h3>Editable, Detailed, Cloud-Ready</h3></article>
            <article><p>Admin Controls</p><h3>Add Events, Manage Participation</h3></article>
            <article><p>Scalable Design</p><h3>Built for GCP Migration</h3></article>
        </section>
    </main>

    <footer class="footer">
        <div class="container">
            <p>&copy; <?php echo date('Y'); ?> College Event Portal. All rights reserved.</p>
        </div>
    </footer>
</body>
</html>
