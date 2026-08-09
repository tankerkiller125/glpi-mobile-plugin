<?php

namespace GlpiPlugin\Glpimobile;

use RuntimeException;

/**
 * GLPI refused a stored refresh token: the device genuinely must re-pair.
 *
 * Deliberately distinct from a transport or server failure. Treating those the
 * same is how a busy server ends up signing every technician out — the app
 * wipes its credentials on a definite rejection only.
 */
final class SessionRejected extends RuntimeException
{
}
