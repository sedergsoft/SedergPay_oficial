<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use yii\db\Connection;

// Get database config
$dsn = getenv('SEDERG_DB_DSN') ?: 'pgsql:host=127.0.0.1;port=5432;dbname=sederg_pay';
$user = getenv('SEDERG_DB_USER') ?: 'sederg_app';
$password = getenv('SEDERG_DB_PASSWORD') ?: '';

$db = new Connection([
    'dsn' => $dsn,
    'username' => $user,
    'password' => $password,
    'charset' => 'utf8',
]);

$db->open();

// Check if user already exists
$existing = $db->createCommand('SELECT id, email, role, status FROM users WHERE email = :email')
    ->bindValue(':email', 'admin@sederg.pay')
    ->queryOne();

if ($existing) {
    echo "User already exists: id={$existing['id']}, email={$existing['email']}, role={$existing['role']}, status={$existing['status']}\n";
} else {
    $publicId = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', 
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), 
        mt_rand(0, 0xffff), 
        mt_rand(0, 0x0fff) | 0x4000, 
        mt_rand(0, 0x3fff) | 0x8000, 
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
    
    $passwordHash = password_hash('Admin123456!', PASSWORD_DEFAULT);
    
    $result = $db->createCommand()->insert('users', [
        'id' => 1,
        'public_id' => $publicId,
        'email' => 'admin@sederg.pay',
        'phone_e164' => null,
        'password_hash' => $passwordHash,
        'status' => 'active',
        'role' => 'admin',
    ])->execute();
    
    echo "Admin user created successfully!\n";
    echo "Email: admin@sederg.pay\n";
    echo "Password: Admin123456!\n";
    echo "Role: admin\n";
}

$db->close();