---
paths:
  - "src/**/*.php"
  - "bin/**"
  - "public/**/*.php"
---
# Pravidla pro PHP soubory
- První řádek po `<?php`: `declare(strict_types=1);`
- Žádné `var_dump`, `print_r`, `dd`, `die`, `exit` v produkčním kódu.
- Žádné `$_GET/$_POST/$_SESSION/$_SERVER` mimo `App\Http\Pozadavek` a `App\Infrastructure\Session`.
- Žádné `eval`, `exec`, `shell_exec`, `system`, `unserialize` na vstupu.
- Nová veřejná metoda = test.
