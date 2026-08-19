<?php

namespace App\Exceptions;

use RuntimeException;

class SocialLoginProviderException extends RuntimeException
{
    public static function unavailable(): self
    {
        return new self(__('Sign-in is temporarily unavailable. Please try again.'));
    }

    public static function invalidState(): self
    {
        return new self(__('Your sign-in session expired. Please try again.'));
    }

    public static function emailNotVerified(): self
    {
        return new self(__('Your corporate email could not be verified. Contact your administrator.'));
    }

    public static function accountNotProvisioned(): self
    {
        return new self(__('This account has not been provisioned for Oceanix. Contact your administrator.'));
    }

    public static function accountInactive(): self
    {
        return new self(__('This account is not active. Contact your administrator.'));
    }
}
