<?php

declare(strict_types=1);

use App\Infrastructure\Migration\Migration;

return new class implements Migration {
    public function up(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE fixture_b (id INT PRIMARY KEY) ENGINE=InnoDB');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE fixture_b');
    }
};
