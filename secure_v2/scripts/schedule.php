<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

\SecureV2\Config::load();

try {
    $result = \SecureV2\Controllers\GroupController::runSchedule();
    echo json_encode(['ok' => true, 'result' => $result], JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
