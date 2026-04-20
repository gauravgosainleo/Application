<?php
// One-time installer: creates the required MySQL tables.
// Open this page in the browser once after uploading, then delete it.

require_once __DIR__ . '/db.php';

try {
    $pdo = db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS meetings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        meeting_code VARCHAR(32) NOT NULL UNIQUE,
        name VARCHAR(150) NOT NULL,
        description TEXT NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_meeting_code (meeting_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS participants (
        id INT AUTO_INCREMENT PRIMARY KEY,
        meeting_code VARCHAR(32) NOT NULL,
        peer_id VARCHAR(64) NOT NULL,
        display_name VARCHAR(100) NOT NULL,
        joined_at DATETIME NOT NULL,
        last_seen DATETIME NOT NULL,
        UNIQUE KEY uniq_peer (meeting_code, peer_id),
        INDEX idx_last_seen (meeting_code, last_seen)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS signals (
        id INT AUTO_INCREMENT PRIMARY KEY,
        meeting_code VARCHAR(32) NOT NULL,
        from_peer VARCHAR(64) NOT NULL,
        to_peer VARCHAR(64) NOT NULL,
        signal_type VARCHAR(32) NOT NULL,
        payload MEDIUMTEXT NOT NULL,
        created_at DATETIME NOT NULL,
        delivered TINYINT(1) NOT NULL DEFAULT 0,
        INDEX idx_inbox (meeting_code, to_peer, delivered, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    echo "<h2>Koenig video calling app &mdash; installed OK</h2>";
    echo "<p>All tables created/verified. Please <strong>delete install.php</strong> from the server now.</p>";
    echo '<p><a href="index.php">Go to the app</a></p>';
} catch (Throwable $e) {
    http_response_code(500);
    echo "<h2>Install failed</h2>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
    echo "<p>Verify the credentials in <code>config.php</code> and that the database exists.</p>";
}
