<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailResend\Provider;

/**
 * "When did this happen", out of whatever Resend put in `created_at`.
 *
 * Their moments are ISO 8601 with a fractional second and a `Z` on the end —
 * `2026-11-22T23:41:12.126Z` — which `strtotime` reads. Their domain endpoints
 * spell the same thing with a space and an offset instead
 * (`2026-04-26 20:21:26.347412+00`), which `strtotime` also reads, so one
 * function covers both.
 *
 * Answering null for a payload with no moment at all, rather than `time()`, is
 * the point: the caller stamps a null with the moment the request arrived, and
 * it is the one that has a clock. A parser that reached for `time()` would be a
 * parser a test could not pin.
 *
 * ## The sanity window
 *
 * A moment before 2000 or more than a day in the future is treated as no moment
 * at all. Both turn up in the wild — a zero timestamp from a provider's own
 * placeholder, and a clock skewed forward on a sending host — and both would
 * otherwise put a point at the wrong end of a campaign's chart forever.
 *
 * This came out of the KahunaCart Newsletter add-on with the rest of the
 * parser. It is copied rather than shared because six parsers used it there and
 * each one has moved to the plugin of the provider it reads: a shared helper
 * across six plugins would be a package to release every time one provider
 * changed a date format.
 */
final class Moment
{
    /** Nothing before this is a real event. 2000-01-01. */
    public const FLOOR = 946684800;

    /** How far ahead of now a provider's clock may be. */
    public const FUTURE_TOLERANCE = 86400;

    private function __construct()
    {
    }

    /**
     * A moment in seconds, or null when there is not one to be had.
     *
     * @param mixed    $value the raw field, whatever type it arrived as
     * @param int|null $now   the receiver's clock, for the future check; null
     *        skips that check, which is what a parser unit test wants
     */
    public static function parse(mixed $value, ?int $now = null): ?int
    {
        $at = self::read($value);

        if ($at === null || $at < self::FLOOR) {
            return null;
        }

        if ($now !== null && $at > $now + self::FUTURE_TOLERANCE) {
            return null;
        }

        return $at;
    }

    private static function read(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value;
        }

        if (\is_float($value)) {
            return (int)$value;
        }

        if (!\is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        // Unix seconds as a string, checked before `strtotime`, which reads a
        // bare `1739187601` as a date in the year 1739 on some builds and as
        // nothing at all on others. Resend does not send one today; a provider
        // that starts to should not silently lose every timestamp.
        if (preg_match('/^\d{9,11}(\.\d+)?$/', $value) === 1) {
            return (int)$value;
        }

        $at = strtotime($value);

        return $at === false ? null : $at;
    }
}
