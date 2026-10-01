<?php

namespace App\Http\Requests\SuperAdmin;

/**
 * Same rules as creating a fee; the unique-name check ignores the fee being
 * edited (StoreFeeRequest reads the `fee` route parameter).
 */
class UpdateFeeRequest extends StoreFeeRequest {}
