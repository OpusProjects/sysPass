<?php
declare(strict_types=1);
/**
 * sysPass
 *
 * @author nuxsmin
 * @link https://syspass.org
 * @copyright 2012-2024, Rubén Domínguez nuxsmin@$syspass.org
 *
 * This file is part of sysPass.
 *
 * sysPass is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * sysPass is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with sysPass.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace SP\Domain\Http\Adapters;

use SP\Domain\Core\Exceptions\InvalidArgumentException;
use SP\Domain\Core\Exceptions\SPException;

use function SP\__;
use function SP\__u;
use function SP\logger;

/**
 * Class Address
 */
final class Address
{
    public const PATTERN_IP_ADDRESS = '#^(?<address>[\d]{1,3}\.[\d]{1,3}\.[\d]{1,3}\.[\d]{1,3})(?:/(?:(?<mask>[\d]{1,3}\.[\d]{1,3}\.[\d]{1,3}\.[\d]{1,3})|(?<cidr>[\d]{1,2})))?$#';

    /**
     * @throws InvalidArgumentException
     */
    public static function toBinary(string $address): string
    {
        if (!filter_var($address, FILTER_VALIDATE_IP)
            || ($binAddress = @inet_pton($address)) === false
        ) {
            logger(sprintf('%s : %s', __('Invalid IP'), $address));

            throw new InvalidArgumentException(__u('Invalid IP'), SPException::ERROR, $address);
        }

        return $binAddress;
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function fromBinary(string $address): string
    {
        $stringAddress = @inet_ntop($address);

        if ($stringAddress === false) {
            logger(sprintf('%s : %s', __('Invalid IP'), $address));

            throw new InvalidArgumentException(__u('Invalid IP'), SPException::ERROR, $address);
        }

        return $stringAddress;
    }

    /**
     * Parses an IPv4 address from either "192.168.0.1", "192.168.0.0/255.255.255.0" or "192.168.0.0/24" formats
     *
     * @return array<int|string, string> The regex matches, with "address", "mask" and "cidr" named groups
     * @throws InvalidArgumentException
     */
    public static function parse4(string $address): array
    {
        // The pattern checks the shape only: it takes any three digits as an octet and any two as a
        // prefix. So `999.1.1.1` was stored and then refused by check() on every request it should
        // have matched, and `10.0.0.0/33` reached cidrToDec() and died with an ArithmeticError (a
        // negative shift) — a 500, where every other bad address is answered as "Invalid IP".
        if (!preg_match(self::PATTERN_IP_ADDRESS, $address, $matches)
            || !filter_var($matches['address'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            || (($matches['mask'] ?? '') !== ''
                && !filter_var($matches['mask'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
            || (isset($matches['cidr']) && (int)$matches['cidr'] > 32)
        ) {
            throw new InvalidArgumentException(__u('Invalid IP'), SPException::ERROR, $address);
        }

        return $matches;
    }

    /**
     * Checks whether an IP address is included within $inAddress and $inMask
     *
     * @throws InvalidArgumentException
     */
    public static function check(
        string $address,
        string $inAddress,
        string $inMask
    ): bool {
        if (!filter_var($address, FILTER_VALIDATE_IP)
            || !filter_var($inAddress, FILTER_VALIDATE_IP)
            || !filter_var($inMask, FILTER_VALIDATE_IP)
        ) {
            throw new InvalidArgumentException(__u('Invalid IP'), SPException::ERROR, $address);
        }

        // Compared as packed bytes, not through ip2long(), which understands IPv4 only and answers
        // `false` for anything else. filter_var() above accepts IPv6, so two IPv6 addresses used to
        // reach this line and compare `false & false === false & false` — every IPv6 client matched
        // every configured network, whatever the mask said.
        //
        // PHP's `&` on two strings is a bytewise AND, so the same expression serves both families:
        // four bytes for IPv4, sixteen for IPv6.
        $binAddress = self::toBinary($address);
        $binInAddress = self::toBinary($inAddress);
        $binMask = self::toBinary($inMask);

        // An address and a network from different families are not the same network. Their packed
        // forms differ in length, and `&` would silently truncate to the shorter of the two.
        if (strlen($binAddress) !== strlen($binMask) || strlen($binInAddress) !== strlen($binMask)) {
            return false;
        }

        return ($binAddress & $binMask) === ($binInAddress & $binMask);
    }

    /**
     * Converts a CIDR mask into decimal
     *
     * @throws InvalidArgumentException
     */
    public static function cidrToDec(int $bits): string
    {
        // Outside 0–32 the shift below is negative or meaningless, and a negative shift is an
        // ArithmeticError, not an exception any caller expects.
        if ($bits < 0 || $bits > 32) {
            throw new InvalidArgumentException(__u('Invalid IP'), SPException::ERROR, (string)$bits);
        }

        return long2ip(-1 << (32 - $bits));
    }
}
