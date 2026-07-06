<?php

namespace GuiBranco\GStracciniBot\Handlers;

/**
 * Handles user events shared by the HTTP webhook entry point
 * (Src/users.php) and the queue worker (Src/Workers/users.php).
 */
class UsersHandler implements IHandler
{
    public function handleItem($user)
    {
    }
}
