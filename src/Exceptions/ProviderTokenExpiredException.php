<?php

namespace Ronu\LaravelFederatedAuth\Exceptions;

/**
 * The provider token was authentic but had already expired when verified.
 */
class ProviderTokenExpiredException extends InvalidProviderTokenException {}
