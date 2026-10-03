<?php

declare(strict_types=1);

namespace App\Infrastructure\Migration;

/** Jedna změna schématu; každý soubor v database/migrations/ vrací instanci. */
interface Migration
{
    public function up(\PDO $pdo): void;

    public function down(\PDO $pdo): void;
}
