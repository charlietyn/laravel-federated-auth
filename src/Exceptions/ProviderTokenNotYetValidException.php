<?php

namespace Ronu\LaravelFederatedAuth\Exceptions;

/**
 * The provider token is authentic but its temporal claims are not valid yet.
 *
 * Usually indicates clock skew between the provider and the application host.
 */
class ProviderTokenNotYetValidException extends InvalidProviderTokenException {}
