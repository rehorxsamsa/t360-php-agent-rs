<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Application\User\CreateAdmin;
use App\Application\User\CreateAdminFailed;
use App\Console\Command;
use App\Console\Output;
use App\Domain\User\EmailAddress;

/** admin:vytvor --email=… --jmeno=… [--heslo=…] – vytvoří administrátorský účet. */
final readonly class CreateAdminCommand implements Command
{
    private const int GENERATED_PASSWORD_BYTES = 10;

    public function __construct(private CreateAdmin $createAdmin) {}

    public function run(array $arguments, Output $output): int
    {
        $options = $this->parseOptions($arguments);
        if ($options === null || ($options['email'] ?? '') === '' || ($options['jmeno'] ?? '') === '') {
            $this->printUsage($output);

            return 1;
        }

        $email = $options['email'];
        $name = $options['jmeno'];

        $generated = !isset($options['heslo']);
        $password = $options['heslo'] ?? bin2hex(random_bytes(self::GENERATED_PASSWORD_BYTES));

        try {
            $id = $this->createAdmin->handle($email, $name, $password);
        } catch (CreateAdminFailed $exception) {
            $output->error($exception->getMessage());

            return 1;
        }

        $output->line(sprintf('Vytvořen administrátor %s (ID %d).', EmailAddress::normalize($email), $id));
        if ($generated) {
            $output->line(sprintf('Vygenerované heslo: %s – uložte si ho, znovu se nezobrazí.', $password));
        }

        return 0;
    }

    /**
     * @param list<string> $arguments
     * @return array<string, string>|null null při neznámém nebo špatně zapsaném argumentu
     */
    private function parseOptions(array $arguments): ?array
    {
        $options = [];
        foreach ($arguments as $argument) {
            if (preg_match('/^--(email|jmeno|heslo)=(.*)$/s', $argument, $matches) !== 1) {
                return null;
            }
            $options[$matches[1]] = $matches[2];
        }

        return $options;
    }

    private function printUsage(Output $output): void
    {
        $output->error('Použití: php bin/konzole admin:vytvor --email=<e-mail> --jmeno=<jméno> [--heslo=<heslo>]');
        $output->error('Bez --heslo se heslo vygeneruje a vypíše jednou.');
    }
}
