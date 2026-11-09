<?php

namespace App\Exceptions;

use Illuminate\Http\Exceptions\HttpResponseException;

/** A controlled API denial, including direct calls to the creation action. */
final class PaymentLinkAuthorizationDenied extends HttpResponseException {}
