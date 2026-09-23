<?php

declare(strict_types=1);

require __DIR__ . '/../../private_html/bootstrap.php';

header('Content-Type: application/json');

$currentUser = Auth::user();
if ($currentUser === null) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

Csrf::requireValid();

$theme = ($_POST['theme'] ?? '') === 'light' ? 'light' : 'dark';

$stmt = Database::pdo()->prepare('UPDATE users SET theme_preference = :theme WHERE id = :id');
$stmt->execute(['theme' => $theme, 'id' => $currentUser['id']]);

echo json_encode(['ok' => true]);
