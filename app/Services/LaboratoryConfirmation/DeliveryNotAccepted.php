<?php

namespace App\Services\LaboratoryConfirmation;

use RuntimeException;

/** Only for a provable rejection, never for a timeout or lost acknowledgement. */
class DeliveryNotAccepted extends RuntimeException {}
