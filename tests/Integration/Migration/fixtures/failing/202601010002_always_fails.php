<?php

declare(strict_types=1);

use App\Infrastructure\Migration\Migration;

return new class implements Migration {
    public function up(PDO $pdo): void
    {
        throw new RuntimeException('migrace selhala');
    }

    public function down(PDO $pdo): void {}
};
