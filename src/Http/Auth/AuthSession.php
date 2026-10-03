<?php

declare(strict_types=1);

namespace App\Http\Auth;

use App\Domain\User\User;
use App\Domain\User\UserRepository;
use App\Http\Security\CsrfToken;
use App\Http\Session\Session;

/** Přihlášený uživatel v session: čtení, přihlášení a odhlášení. */
final readonly class AuthSession
{
    private const string USER_KEY = 'user_id';

    public function __construct(
        private Session $session,
        private UserRepository $users,
        private CsrfToken $csrf,
    ) {}

    /** Přihlášený uživatel; smazaný uživatel se ze session odstraní a vrací se null. */
    public function user(): ?User
    {
        $id = $this->session->get(self::USER_KEY);
        if (!is_int($id)) {
            return null;
        }

        $user = $this->users->findById($id);
        if ($user === null) {
            $this->session->remove(self::USER_KEY);
        }

        return $user;
    }

    public function signIn(User $user): void
    {
        $this->session->regenerateId();
        $this->session->set(self::USER_KEY, $user->id);
        $this->csrf->rotate();
    }

    public function signOut(): void
    {
        $this->session->invalidate();
    }
}
