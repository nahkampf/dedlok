<?php

declare(strict_types=1);

namespace App\Chat;

use RuntimeException;

/** A chat action that was not allowed; the message is already the sentence for the player. */
final class ChatRefused extends RuntimeException {}
