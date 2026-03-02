<?php
// Run this file to setup the BugCatcher database
// Access: http://localhost/01-bugcatcher/setup.php

echo "<h2>Setting up BugCatcher Database...</h2>";

// Connect to MySQL
$conn = new mysqli("127.0.0.1", "root", "", "", 3306);

if ($conn->connect_error) {
    $conn = new mysqli("127.0.0.1", "root", "", "", 3307);
    if ($conn->connect_error) {
        die("Cannot connect to MySQL. Make sure XAMPP is running!<br>Error: " . $conn->connect_error);
    }
}

echo "Connected to MySQL<br>";

// Create bug_catcher database
$sql = "CREATE DATABASE IF NOT EXISTS bug_catcher CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci";
if ($conn->query($sql)) {
    echo "Database 'bug_catcher' created<br>";
} else {
    die("Error creating database: " . $conn->error);
}

// Select database
$conn->select_db("bug_catcher");

// Create users table with login fields
$sql = "CREATE TABLE IF NOT EXISTS users (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','user') DEFAULT 'user',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
$conn->query($sql);
echo "Table 'users' created<br>";

// Create labels table
$sql = "CREATE TABLE IF NOT EXISTS labels (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    color VARCHAR(20) DEFAULT '#cccccc'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
$conn->query($sql);
echo "Table 'labels' created<br>";

// Create issues table
$sql = "CREATE TABLE IF NOT EXISTS issues (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT DEFAULT NULL,
    status ENUM('open','closed') DEFAULT 'open',
    author_id INT(11) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (author_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
$conn->query($sql);
echo "Table 'issues' created<br>";

// Create issue_labels table
$sql = "CREATE TABLE IF NOT EXISTS issue_labels (
    issue_id INT(11) NOT NULL,
    label_id INT(11) NOT NULL,
    PRIMARY KEY(issue_id, label_id),
    FOREIGN KEY (issue_id) REFERENCES issues(id) ON DELETE CASCADE,
    FOREIGN KEY (label_id) REFERENCES labels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
$conn->query($sql);
echo "Table 'issue_labels' created<br>";

// Insert default labels
$labels = [
    ['bug', 'Something is not working', '#d73a4a'],
    ['documentation', 'Improvements or additions to documentation', '#0075ca'],
    ['duplicate', 'This issue already exists', '#cfd3d7'],
    ['enhancement', 'New feature or request', '#a2eeef'],
    ['good first issue', 'Good for newcomers', '#7057ff'],
    ['help wanted', 'Extra attention is needed', '#008672'],
    ['invalid', 'This does not seem right', '#e4e669'],
    ['question', 'Further information is requested', '#d876e3'],
    ['wontfix', 'This will not be worked on', '#000000']
];

$check = $conn->query("SELECT COUNT(*) as count FROM labels")->fetch_assoc()['count'];
if ($check == 0) {
    $stmt = $conn->prepare("INSERT INTO labels (name, description, color) VALUES (?, ?, ?)");
    foreach ($labels as $label) {
        $stmt->bind_param("sss", $label[0], $label[1], $label[2]);
        $stmt->execute();
    }
    echo "Default labels inserted<br>";
}

// Insert default users
$check = $conn->query("SELECT COUNT(*) as count FROM users")->fetch_assoc()['count'];
if ($check == 0) {
    $adminPass = password_hash("password", PASSWORD_DEFAULT);
    $userPass = password_hash("password", PASSWORD_DEFAULT);

    $conn->query("INSERT INTO users (username, email, password, role) VALUES ('admin', 'admin@bugcatcher.com', '$adminPass', 'admin')");
    $conn->query("INSERT INTO users (username, email, password, role) VALUES ('user', 'user@bugcatcher.com', '$userPass', 'user')");

    echo "Default users created<br>";
}

// Insert sample issues
$check = $conn->query("SELECT COUNT(*) as count FROM issues")->fetch_assoc()['count'];
if ($check == 0) {
    $conn->query("INSERT INTO issues (title, description, status, author_id) VALUES
        ('System Error', 'System showing error message', 'open', 1),
        ('Login Bug', 'Cannot login sometimes', 'open', 1)");
    echo "Sample issues created<br>";
}

echo "<br><h3>BugCatcher Database Setup Complete!</h3>";
echo "<a href='index.php' style='font-size:18px;'>Go to Landing Page</a>";

$conn->close();
?>
