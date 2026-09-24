<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailResend\Provider;

use Grav\Plugin\Email\Providers\Inbound\InboundCapable;

/**
 * {@see ResendProvider}, plus the Email plugin's promise that it can receive
 * mail.
 *
 * Why a second class rather than `implements InboundCapable` on the first: a
 * class whose interface does not exist is a fatal error when it loads, and an
 * Email plugin from before inbound mail has no `InboundCapable`. So the plugin
 * registers this one only when `interface_exists(InboundCapable::class)`, and
 * the plain provider otherwise, which sends and reports deliveries exactly as
 * before. Everything, `inbound()` included, lives on the parent.
 */
final class ResendInboundProvider extends ResendProvider implements InboundCapable
{
}
